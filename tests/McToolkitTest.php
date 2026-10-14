<?php

/*
 * Regression tests for the minecraft-toolkit plugin (v1.0.4 bug-fix pass).
 *
 * They run inside a Pelican panel checkout, because the plugin is only meaningful there:
 *
 *   1. copy this plugin to <panel>/plugins/minecraft-toolkit
 *   2. copy this file to <panel>/tests/Filament/Server/McToolkitTest.php
 *   3. composer install && vendor/bin/pest tests/Filament/Server/McToolkitTest.php
 *
 * The panel never loads plugins during tests, so the plugin is wired up by hand below;
 * wings is replaced by an in-memory filesystem (FakeWings), while mcjars, Modrinth, Hangar
 * and Spiget are called for real (network needed).
 */

use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use McToolkit\Filament\Admin\Pages\McAdminSettingsPage;
use McToolkit\Filament\Server\Pages\MapPage;
use McToolkit\Filament\Server\Pages\ModpacksPage;
use McToolkit\Filament\Server\Pages\ModsPage;
use McToolkit\Filament\Server\Pages\PerformancePage;
use McToolkit\Filament\Server\Pages\PlayersPage;
use McToolkit\Filament\Server\Pages\PluginsPage;
use McToolkit\Filament\Server\Pages\PropertiesPage;
use McToolkit\Filament\Server\Pages\VersionsPage;
use McToolkit\Filament\Server\Pages\WorldsPage;
use McToolkit\Providers\McToolkitServiceProvider;
use McToolkit\Providers\RouteProvider;
use McToolkit\Services\Nbt\NbtReader;

use function Pest\Livewire\livewire;

/* ------------------------------------------------------------------ */
/*  Plugin bootstrap                                                   */
/* ------------------------------------------------------------------ */

function mctBoot(): void
{
    $base = base_path('plugins/minecraft-toolkit');

    $loader = require base_path('vendor/autoload.php');
    $loader->setPsr4('McToolkit\\', $base.'/src/');

    config()->set('minecraft-toolkit', require $base.'/config/minecraft-toolkit.php');
    app('translator')->addNamespace('minecraft-toolkit', $base.'/lang');
    app('view')->addNamespace('minecraft-toolkit', $base.'/resources/views');
    app()->register(McToolkitServiceProvider::class);
    app()->register(RouteProvider::class);

    Artisan::call('migrate', ['--path' => $base.'/database/migrations', '--realpath' => true, '--force' => true]);
}

/* ------------------------------------------------------------------ */
/*  NBT / region builders                                              */
/* ------------------------------------------------------------------ */

function nbtS(string $s): string { return pack('n', strlen($s)).$s; }
function nbtTag(int $type, string $name, string $payload): string { return chr($type).nbtS($name).$payload; }
function nbtCompound(array $named): string { return implode('', $named).chr(0); }
function nbtI32(int $v): string { return pack('N', $v & 0xFFFFFFFF); }
function nbtI64(int $v): string { return pack('J', $v); }
function nbtI8(int $v): string { return pack('c', $v); }
function nbtIntArray(array $v): string { return nbtI32(count($v)).implode('', array_map('nbtI32', $v)); }
function nbtLongArray(array $v): string { return nbtI32(count($v)).implode('', array_map('nbtI64', $v)); }
function nbtList(int $type, array $payloads): string { return chr($type).nbtI32(count($payloads)).implode('', $payloads); }

/** Non-spanning (1.16+) long packing. */
function packLongs(array $values, int $bits, int $longs): array
{
    $per = intdiv(64, $bits);
    $out = array_fill(0, $longs, 0);
    foreach ($values as $i => $v) {
        $out[intdiv($i, $per)] |= ($v << (($i % $per) * $bits));
    }

    return $out;
}

/** Deeply nested compound to exercise skip logic. */
function deepCompound(int $depth): string
{
    $payload = nbtCompound([nbtTag(8, 'id', nbtS('minecraft:diamond_sword'))]);
    for ($i = 0; $i < $depth; $i++) {
        $payload = nbtCompound([nbtTag(10, 'n'.$i, $payload), nbtTag(9, 'l'.$i, nbtList(3, [nbtI32(1), nbtI32(2)]))]);
    }

    return $payload;
}

function buildLevelDat(): string
{
    $data = nbtCompound([
        nbtTag(11, 'WanderingTraderId', nbtIntArray([1, -2, 3, -4])),
        nbtTag(10, 'Player', deepCompound(12)),
        nbtTag(3, 'DataVersion', nbtI32(4189)),
        nbtTag(8, 'LevelName', nbtS('Tëst World 🌍')),
        nbtTag(4, 'LastPlayed', nbtI64(1760400000000)),
        nbtTag(3, 'GameType', nbtI32(0)),
        nbtTag(1, 'hardcore', nbtI8(0)),
        nbtTag(3, 'SpawnX', nbtI32(100)),
        nbtTag(3, 'SpawnY', nbtI32(64)),
        nbtTag(3, 'SpawnZ', nbtI32(-50)),
        nbtTag(10, 'Version', nbtCompound([nbtTag(8, 'Name', nbtS('1.21.4')), nbtTag(3, 'Id', nbtI32(4189)), nbtTag(1, 'Snapshot', nbtI8(0))])),
        nbtTag(10, 'WorldGenSettings', nbtCompound([nbtTag(4, 'seed', nbtI64(-123456789012345)), nbtTag(10, 'dimensions', nbtCompound([]))])),
    ]);

    return gzencode(nbtTag(10, '', nbtCompound([nbtTag(10, 'Data', $data)])));
}

/** One 1.18+ chunk at (0,0): stone up to y=4, grass_block at y=5, air above. */
function buildChunk(): string
{
    $blocks = [];
    for ($i = 0; $i < 4096; $i++) {
        $y = intdiv($i, 256);
        $blocks[$i] = $y <= 4 ? 2 : ($y === 5 ? 1 : 0); // palette: 0 air, 1 grass_block, 2 stone
    }

    $palette = nbtList(10, [
        nbtCompound([nbtTag(8, 'Name', nbtS('minecraft:air'))]),
        nbtCompound([nbtTag(8, 'Name', nbtS('minecraft:grass_block')), nbtTag(10, 'Properties', nbtCompound([nbtTag(8, 'snowy', nbtS('false'))]))]),
        nbtCompound([nbtTag(8, 'Name', nbtS('minecraft:stone'))]),
    ]);

    $sectionY0 = nbtCompound([
        nbtTag(1, 'Y', nbtI8(0)),
        nbtTag(10, 'block_states', nbtCompound([
            nbtTag(9, 'palette', $palette),
            nbtTag(12, 'data', nbtLongArray(packLongs($blocks, 4, 256))),
        ])),
        nbtTag(10, 'biomes', nbtCompound([nbtTag(9, 'palette', nbtList(8, [nbtS('minecraft:plains')]))])),
    ]);

    $airSection = fn (int $y) => nbtCompound([
        nbtTag(1, 'Y', nbtI8($y)),
        nbtTag(10, 'block_states', nbtCompound([nbtTag(9, 'palette', nbtList(10, [nbtCompound([nbtTag(8, 'Name', nbtS('minecraft:air'))])]))])),
    ]);

    // WORLD_SURFACE: 9 bits, 7 per long, 37 longs; value = top block y - (-64) + 1.
    $heights = array_fill(0, 256, 5 + 64 + 1);

    $root = nbtCompound([
        nbtTag(3, 'DataVersion', nbtI32(4189)),
        nbtTag(9, 'block_entities', nbtList(10, [deepCompound(14)])),
        nbtTag(10, 'structures', deepCompound(10)),
        nbtTag(3, 'xPos', nbtI32(0)),
        nbtTag(3, 'zPos', nbtI32(0)),
        nbtTag(3, 'yPos', nbtI32(-4)),
        nbtTag(8, 'Status', nbtS('minecraft:full')),
        nbtTag(10, 'Heightmaps', nbtCompound([nbtTag(12, 'WORLD_SURFACE', nbtLongArray(packLongs($heights, 9, 37)))])),
        nbtTag(9, 'sections', nbtList(10, [$airSection(-4), $sectionY0, $airSection(1)])),
    ]);

    return nbtTag(10, '', $root);
}

function buildRegion(): string
{
    $chunk = gzcompress(buildChunk());
    $record = pack('N', strlen($chunk) + 1).chr(2).$chunk;
    $sectors = (int) ceil(strlen($record) / 4096);
    $record = str_pad($record, $sectors * 4096, "\0");

    $locations = str_repeat("\0", 4096);
    $locations = substr_replace($locations, pack('N', (2 << 8) | $sectors), 0, 4);
    $timestamps = str_repeat("\0", 4096);

    return $locations.$timestamps.$record;
}

/* ------------------------------------------------------------------ */
/*  Fake wings                                                         */
/* ------------------------------------------------------------------ */

final class FakeWings
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    public array $dirs = [];

    public array $commands = [];

    public array $writes = [];

    public array $pulls = [];

    public array $power = [];

    public string $state = 'running';

    public function put(string $path, string $content): void
    {
        $path = trim($path, '/');
        $this->files[$path] = $content;
        $parts = explode('/', $path);
        array_pop($parts);
        $acc = '';
        foreach ($parts as $p) {
            $acc = ltrim($acc.'/'.$p, '/');
            $this->dirs[$acc] = true;
        }
    }

    public function mkdir(string $path): void
    {
        $this->put(trim($path, '/').'/.keep', '');
        unset($this->files[trim($path, '/').'/.keep']);
    }

    public function list(string $dir): ?array
    {
        $dir = trim($dir, '/');
        if ($dir !== '' && ! isset($this->dirs[$dir])) {
            return null;
        }
        $prefix = $dir === '' ? '' : $dir.'/';
        $rows = [];
        foreach ($this->files as $path => $content) {
            if (str_starts_with($path, $prefix) && ! str_contains(substr($path, strlen($prefix)), '/')) {
                $rows[] = $this->row(substr($path, strlen($prefix)), false, strlen($content));
            }
        }
        foreach (array_keys($this->dirs) as $path) {
            if ($path !== '' && str_starts_with($path, $prefix) && ! str_contains(substr($path, strlen($prefix)), '/')) {
                $rows[] = $this->row(substr($path, strlen($prefix)), true, 4096);
            }
        }

        return $rows;
    }

    public function row(string $name, bool $dir, int $size): array
    {
        return [
            'name' => $name, 'created' => '2026-10-01T10:00:00Z', 'modified' => '2026-10-10T10:00:00Z',
            'mode' => $dir ? 'drwxr-xr-x' : '-rw-r--r--', 'mode_bits' => $dir ? '755' : '644', 'size' => $size,
            'directory' => $dir, 'file' => ! $dir, 'symlink' => false, 'mime' => $dir ? 'inode/directory' : 'application/octet-stream',
        ];
    }

    public function handle(Request $request, Server $server)
    {
        $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:'.$server->node->daemon_token_id.')', 'Content-Type' => 'application/json'];
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $base = '/api/servers/'.$server->uuid;

        if ($path === $base) {
            return Http::response(['state' => $this->state, 'is_suspended' => false, 'utilization' => [
                'memory_bytes' => 1_500_000_000, 'memory_limit_bytes' => 4_294_967_296, 'cpu_absolute' => 37.5,
                'network' => ['rx_bytes' => 1, 'tx_bytes' => 2], 'uptime' => 3_600_000, 'state' => $this->state, 'disk_bytes' => 123456,
            ]], 200, $headers);
        }

        return match ($path) {
            $base.'/files/list-directory' => ($l = $this->list((string) ($query['directory'] ?? '/'))) === null
                ? Http::response(['error' => 'not found'], 404, $headers)
                : Http::response($l, 200, $headers),
            $base.'/files/contents' => isset($this->files[trim((string) $query['file'], '/')])
                ? Http::response($this->files[trim((string) $query['file'], '/')], 200, ['Content-Type' => 'text/plain'] + $headers)
                : Http::response(['error' => 'not found'], 404, $headers),
            $base.'/files/write' => (function () use ($query, $request, $headers) {
                $this->put((string) $query['file'], $request->body());
                $this->writes[] = (string) $query['file'];

                return Http::response('', 204, $headers);
            })(),
            $base.'/files/delete' => (function () use ($request, $headers) {
                $root = trim((string) ($request['root'] ?? ''), '/');
                foreach ((array) $request['files'] as $f) {
                    $p = ltrim($root.'/'.trim($f, '/'), '/');
                    unset($this->files[$p], $this->dirs[$p]);
                    foreach (array_keys($this->files) as $k) { if (str_starts_with($k, $p.'/')) { unset($this->files[$k]); } }
                    foreach (array_keys($this->dirs) as $k) { if (str_starts_with($k, $p.'/')) { unset($this->dirs[$k]); } }
                }

                return Http::response('', 204, $headers);
            })(),
            $base.'/files/create-directory' => (function () use ($request, $headers) {
                $this->mkdir(trim((string) $request['path'], '/').'/'.$request['name']);

                return Http::response('', 204, $headers);
            })(),
            $base.'/commands' => (function () use ($request, $headers) {
                $this->commands = array_merge($this->commands, (array) $request['commands']);

                return Http::response('', 204, $headers);
            })(),
            $base.'/power' => (function () use ($request, $headers) {
                $signal = (string) $request['action'];
                $this->power[] = $signal;
                $this->state = in_array($signal, ['stop', 'kill'], true) ? 'offline' : 'running';

                return Http::response('', 204, $headers);
            })(),
            $base.'/files/pull' => (function () use ($request, $headers) {
                $url = (string) $request['url'];
                $name = (string) ($request['file_name'] ?? '') ?: basename((string) parse_url($url, PHP_URL_PATH));
                $path = trim((string) $request['root'], '/').'/'.$name;
                $this->pulls[] = ['url' => $url, 'path' => ltrim($path, '/'), 'foreground' => (bool) ($request['foreground'] ?? false)];
                $this->put($path, 'PULLED '.$url);

                return Http::response($this->row($name, false, strlen('PULLED '.$url)), 200, $headers);
            })(),
            $base.'/files/rename' => (function () use ($request, $headers) {
                $root = trim((string) $request['root'], '/');
                foreach ((array) $request['files'] as $pair) {
                    $from = ltrim($root.'/'.trim($pair['from'], '/'), '/');
                    $to = ltrim($root.'/'.trim($pair['to'], '/'), '/');
                    if (isset($this->files[$to]) || isset($this->dirs[$to])) {
                        return Http::response(['error' => 'destination exists'], 400, $headers);
                    }
                    if (isset($this->files[$from])) {
                        $this->put($to, $this->files[$from]);
                        unset($this->files[$from]);
                    } elseif (isset($this->dirs[$from])) {
                        foreach ($this->files as $k => $v) { if (str_starts_with($k, $from.'/')) { $this->put($to.substr($k, strlen($from)), $v); unset($this->files[$k]); } }
                        foreach (array_keys($this->dirs) as $k) { if ($k === $from || str_starts_with($k, $from.'/')) { $this->dirs[$to.substr($k, strlen($from))] = true; unset($this->dirs[$k]); } }
                    }
                }

                return Http::response('', 204, $headers);
            })(),
            $base.'/files/compress' => (function () use ($request, $headers) {
                $name = ((string) ($request['name'] ?? '') ?: 'archive').'.'.((string) ($request['extension'] ?? '') ?: 'tar.gz');
                $this->put(trim((string) $request['root'], '/').'/'.$name, 'ARCHIVE');

                return Http::response($this->row($name, false, 7), 200, $headers);
            })(),
            default => Http::response(['error' => 'unhandled '.$request->method().' '.$path], 500, $headers),
        };
    }
}

function mctServer(FakeWings $wings, array $permissions = []): array
{
    [$user, $server] = generateTestAccount($permissions);

    Http::fake(function (Request $request) use ($wings, $server) {
        if (parse_url($request->url(), PHP_URL_HOST) === $server->node->fqdn) {
            return $wings->handle($request, $server);
        }

        return null; // registries and mcjars are real
    });

    return [$user, $server];
}

function mctWorld(FakeWings $wings): void
{
    $wings->put('server.properties', implode("\n", [
        '#Minecraft server properties',
        '#Tue Oct 14 10:00:00 UTC 2026',
        'enable-jmx-monitoring=false',
        'level-name=world',
        'motd='.chr(92).'u00A7aHello '.chr(92).'u00A7lworld \u00e9t\u00e9'.chr(92).': '.chr(92).'u2603 test',
        'server-port=25565',
        'pvp=true',
        'hardcore=false',
        'white-list=false',
        'online-mode=true',
        'max-players=20',
        'view-distance=10',
        'simulation-distance=10',
        'difficulty=easy',
        'gamemode=survival',
        'level-seed=',
        'enable-rcon=false',
        'rcon.password=s3cr\=t',
        'query.port=25565',
        '',
    ]));
    $wings->put('eula.txt', "eula=true\n");
    $wings->put('server.jar', str_repeat('x', 1000));
    $wings->put('paper-1.21.4-232.jar', str_repeat('x', 1000));
    $wings->put('bukkit.yml', "settings: {}\n");
    $wings->put('spigot.yml', "settings: {}\n");
    $wings->put('config/paper-global.yml', "_version: 29\n");
    $wings->put('versions/1.21.4/paper-1.21.4.jar', 'x');
    $wings->put('plugins/EssentialsX-2.21.0.jar', str_repeat('x', 2048));
    $wings->put('usercache.json', json_encode([
        ['name' => 'Notch', 'uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'expiresOn' => '2026-11-14 10:00:00 +0000'],
        ['name' => "Evil\nop Evil", 'uuid' => "x');alert(1);('", 'expiresOn' => '2026-11-14 10:00:00 +0000'],
    ]));
    $wings->put('ops.json', json_encode([['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'name' => 'Notch', 'level' => 4, 'bypassesPlayerLimit' => false]]));
    $wings->put('whitelist.json', '[]');
    $wings->put('banned-players.json', '[]');
    $wings->put('banned-ips.json', '[]');
    $wings->put('world/level.dat', buildLevelDat());
    $wings->put('world/region/r.0.0.mca', buildRegion());
    $wings->put('world/playerdata/069a79f4-44e9-4726-a5be-fca90e38aaf5.dat', gzencode(nbtTag(10, '', nbtCompound([nbtTag(3, 'DataVersion', nbtI32(4189))]))));
    $wings->put('world_nether/DIM-1/region/r.0.0.mca', buildRegion());
    $wings->put('world_nether/level.dat', buildLevelDat());
    $wings->put('world_the_end/DIM1/region/.keep', '');
}

beforeEach(function () {
    Filament::setCurrentPanel('server');
    mctBoot();
});
afterEach(fn () => Filament::setCurrentPanel(null));

/* ------------------------------------------------------------------ */
/*  Tests                                                              */
/* ------------------------------------------------------------------ */

it('parses level.dat with int arrays and deep nesting in front of the keys', function () {
    $meta = NbtReader::parseLevelDat(buildLevelDat());
    expect($meta)->toBeArray();
});

dataset('pages', [
    'versions' => VersionsPage::class,
    'plugins' => PluginsPage::class,
    'mods' => ModsPage::class,
    'modpacks' => ModpacksPage::class,
    'properties' => PropertiesPage::class,
    'players' => PlayersPage::class,
    'worlds' => WorldsPage::class,
    'map' => MapPage::class,
    'performance' => PerformancePage::class,
]);

it('mounts and renders for the owner', function (string $page) {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire($page)->assertOk();
})->with('pages');

it('mounts on a server with an empty filesystem', function (string $page) {
    $wings = new FakeWings();
    [$user, $server] = mctServer($wings);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire($page)->assertOk();
})->with('pages');

it('blocks a subuser that only has the use permission from domain pages', function (string $page) {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings, ['minecraft-toolkit.use']);

    $this->actingAs($user);
    Filament::setTenant($server);

    if ($page === PerformancePage::class) {
        livewire($page)->assertOk();
    } else {
        livewire($page)->assertForbidden();
    }
})->with('pages');

it('renders the admin settings page for a root admin', function () {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->syncRoles(Role::getRootAdmin());
    $this->actingAs($admin);

    livewire(McAdminSettingsPage::class)->assertOk();
});

it('renders a map tile with real blocks', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);

    $response = $this->getJson("/server/{$server->uuid_short}/mc-toolkit/map/tile?world=world&dim=overworld&rx=0&rz=0&v=1760090400");
    $response->assertOk();

    // The chunk at region-local (0,0) holds real blocks: its first pixel must not be fog.
    $pixels = base64_decode((string) $response->json('tile.pixels'));
    expect(bin2hex(substr($pixels, 0, 3)))->not->toBe('0b0e14')
        ->and(bin2hex(substr($pixels, 4 * 200, 3)))->toBe('0b0e14');
});

it('does not serve map tiles to guests or other users', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [, $server] = mctServer($wings);

    $this->getJson("/server/{$server->uuid_short}/mc-toolkit/map/tile?world=world&dim=overworld&rx=0&rz=0")->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson("/server/{$server->uuid_short}/mc-toolkit/map/tile?world=world&dim=overworld&rx=0&rz=0")->assertForbidden();
});

it('loads booleans as booleans and saves one group without touching other values', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $before = $wings->files['server.properties'];
    $c = livewire(PropertiesPage::class)->assertOk();
    $data = $c->get('data');
    expect($data['pvp'])->toBeTrue()
        ->and($data['hardcore'])->toBeFalse()
        ->and($data['online-mode'])->toBeTrue();

    // Second request (baseline must survive), change one players-group value.
    $c->set('data.max-players', 42);
    $c->call('saveGroup', 'general')->assertHasNoErrors();

    $after = $wings->files['server.properties'];
    expect($after)->toContain('max-players=42')
        ->toContain('online-mode=true')
        ->toContain('hardcore=false')
        ->toContain('view-distance=10')
        ->toContain('rcon.password=s3cr\=t')
        ->toContain('server-port=25565')
        ->toContain(collect(explode("\n", $before))->first(fn ($l) => str_starts_with($l, 'motd=')))
        ->not->toContain("\n\n");

    // Saving another group afterwards must not zero out the first group's values.
    $c->set('data.view-distance', 12)->call('saveGroup', 'world')->assertHasNoErrors();
    expect($wings->files['server.properties'])->toContain('max-players=42')->toContain('view-distance=12')->toContain('online-mode=true');
});

it('shows real registry results on the Plugins page', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(PluginsPage::class)->assertOk();
    $payload = $c->instance()->browsePayload();
    expect($payload['items'])->not->toBeEmpty()
        ->and($payload['sources_used'])->toContain('modrinth');

    $c->set('query', 'luckperms');
    $search = $c->instance()->browsePayload();
    expect(collect($search['items'])->pluck('title')->implode(' '))->toContain('LuckPerms');

    $c->call('toggleCompat');
    $compat = $c->instance()->browsePayload();
    expect($compat['items'])->not->toBeEmpty();
});

it('lists players without the hostile usercache entry', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(PlayersPage::class)->call('setFilter', 'all')->assertOk()->assertSee('Notch')->assertDontSee('alert(1)')->assertDontSee('Evil');
});

it('shows the world with its metadata', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(WorldsPage::class)->assertOk()->assertSee('world')->assertSee('1.21.4');
    livewire(VersionsPage::class)->assertOk()->assertSee('Paper');
});

it('sends player commands only for valid names', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(PlayersPage::class)->assertOk();
    $c->set('whitelistData.addWhitelistName', "Bad\nname")->call('addToWhitelist');
    expect($wings->commands)->toBe([]);
    $c->set('whitelistData.addWhitelistName', 'Steve_01')->call('addToWhitelist');
    expect($wings->commands)->toContain('whitelist add Steve_01');
});

it('switches the server software through mcjars without touching worlds or plugins', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(VersionsPage::class)->assertOk();
    $version = $c->get('versions')[0]['id'] ?? null;
    expect($version)->not->toBeNull();

    $c->call('askInstall', $version)->assertSet('pending.version', $version);
    \Illuminate\Support\Facades\Exceptions::fake();
    $c->call('runInstall');
    \Illuminate\Support\Facades\Exceptions::assertNothingReported();


    expect($wings->pulls)->not->toBeEmpty()
        ->and(collect($wings->pulls)->every(fn ($p) => $p['foreground']))->toBeTrue()
        ->and($wings->power)->toContain('stop')
        ->and($wings->files)->toHaveKey('world/level.dat')
        ->and($wings->files)->toHaveKey('plugins/EssentialsX-2.21.0.jar')
        ->and($wings->files)->toHaveKey('server.properties')
        ->and(collect(array_keys($wings->files))->filter(fn ($f) => str_starts_with($f, '.mctoolkit-staging'))->all())->toBe([]);
});

dataset('installs', [
    'modrinth' => ['modrinth', 'Vebnzrzj'],
    'spigot' => ['spigot', '28140'],
    'hangar' => ['hangar', 'ViaVersion'],
]);

it('installs a plugin into plugins/ and keeps tracking it after a refresh', function (string $source, string $id) {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(PluginsPage::class)->assertOk();
    $c->call('install', $source, $id);

    $rows = \McToolkit\Models\InstalledContent::query()->where('server_id', $server->id)->get(['kind', 'registry', 'project_id', 'file_name', 'version'])->toArray();

    expect($rows)->not->toBeEmpty();
    $file = $rows[0]['file_name'];
    $onDisk = str_contains($file, '/') ? $file : 'plugins/'.$file;
    expect($wings->files)->toHaveKey($onDisk);

    $c->call('refreshContent');
    expect(\McToolkit\Models\InstalledContent::query()->where('server_id', $server->id)->count())->toBe(count($rows));
})->with('installs');

it('hands a world download to the node only for an authorised, signed request', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $url = "/server/{$server->uuid_short}/mc-toolkit/download/world";

    $this->actingAs($user);
    $q = \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, 'world');

    $ok = $this->get($url.'?'.http_build_query(['world' => 'world'] + $q));
    $ok->assertRedirect();
    expect($ok->headers->get('Location'))->toStartWith($server->node->getConnectionAddress().'/download/file?token=');

    $this->get($url.'?'.http_build_query(['world' => 'world']))->assertStatus(401);
    $this->get($url.'?'.http_build_query(['world' => '../etc'] + \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, '../etc')))->assertStatus(422);
    $this->get($url.'?'.http_build_query(['world' => 'plugins'] + \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, 'plugins')))->assertStatus(404);

    $this->actingAs(User::factory()->create());
    $this->get($url.'?'.http_build_query(['world' => 'world'] + \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, 'world')))->assertForbidden();
});
