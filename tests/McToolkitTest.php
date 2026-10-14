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

    /**
     * files/decompress support: URL regex => [relative path => content] unpacked next to a
     * pulled archive (pulled files hold "PULLED <url>").
     *
     * @var array<string, array<string, string>>
     */
    public array $archives = [];

    /** Optional hook run after every files/pull: fn (FakeWings $wings, string $url, string $path). */
    public $onPull = null;

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

                if (is_callable($this->onPull)) {
                    ($this->onPull)($this, $url, ltrim($path, '/'));
                }

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
            $base.'/files/decompress' => (function () use ($request, $headers) {
                $root = trim((string) $request['root'], '/');
                $archive = ltrim($root.'/'.trim((string) $request['file'], '/'), '/');
                $content = $this->files[$archive] ?? '';
                $url = str_starts_with($content, 'PULLED ') ? substr($content, 7) : '';
                foreach ($this->archives as $pattern => $entries) {
                    if ($url !== '' && preg_match($pattern, $url) === 1) {
                        foreach ($entries as $entry => $data) {
                            $this->put(ltrim($root.'/'.$entry, '/'), $data);
                        }

                        return Http::response('', 204, $headers);
                    }
                }

                return Http::response(['error' => 'unsupported archive'], 400, $headers);
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

/* ================================================================== */
/*  Minecraft 26.x world layout — BEGIN                                */
/*  One level folder: dimensions/minecraft/<dim>/region, players/,     */
/*  seed in world_gen_settings.dat, string block-state palettes.       */
/*  Shapes mirror a real Paper 26.3 world (DataVersion 5023).          */
/* ================================================================== */

/** 26.x level.dat: no WorldGenSettings, `hardcore` under difficulty_settings. */
function mct26LevelDat(): string
{
    $data = nbtCompound([
        nbtTag(10, 'difficulty_settings', nbtCompound([nbtTag(1, 'locked', nbtI8(0)), nbtTag(8, 'difficulty', nbtS('hard')), nbtTag(1, 'hardcore', nbtI8(1))])),
        nbtTag(8, 'Bukkit.Version', nbtS('Paper/26.3-143-ff3655a (MC: 26.3)/26.3.build.143-beta')),
        nbtTag(10, 'spawn', nbtCompound([nbtTag(11, 'pos', nbtIntArray([700, 85, -300])), nbtTag(5, 'pitch', pack('G', 0.0)), nbtTag(8, 'dimension', nbtS('minecraft:overworld'))])),
        nbtTag(4, 'LastPlayed', nbtI64(1791113721483)),
        nbtTag(3, 'GameType', nbtI32(1)),
        nbtTag(10, 'DataPacks', nbtCompound([nbtTag(9, 'Enabled', nbtList(8, [nbtS('vanilla'), nbtS('paper')]))])),
        nbtTag(3, 'DataVersion', nbtI32(5023)),
        nbtTag(8, 'LevelName', nbtS('Armikan')),
        nbtTag(9, 'version_history', nbtList(3, [nbtI32(5023)])),
        nbtTag(10, 'Version', nbtCompound([nbtTag(8, 'Name', nbtS('26.3')), nbtTag(8, 'Series', nbtS('main')), nbtTag(3, 'Id', nbtI32(5023))])),
    ]);

    return gzencode(nbtTag(10, '', nbtCompound([nbtTag(10, 'Data', $data)])));
}

/** 26.x saved-data file that took the seed over from level.dat. */
function mct26WorldGenSettings(int $seed): string
{
    return gzencode(nbtTag(10, '', nbtCompound([
        nbtTag(3, 'DataVersion', nbtI32(5023)),
        nbtTag(10, 'data', nbtCompound([
            nbtTag(1, 'generate_structures', nbtI8(1)),
            nbtTag(10, 'dimensions', nbtCompound([nbtTag(10, 'minecraft:overworld', nbtCompound([nbtTag(8, 'type', nbtS('minecraft:overworld'))]))])),
            nbtTag(4, 'seed', nbtI64($seed)),
        ])),
    ])));
}

/**
 * One 26.x chunk (blocks as in buildChunk(): stone up to y=4, a surface block at y=5).
 * Plain palettes are TAG_String lists; a palette mixing states with and without properties
 * is a compound list of `{id, properties}` and `{"": id}` entries.
 */
function mct26Chunk(bool $mixed): string
{
    $blocks = [];
    for ($i = 0; $i < 4096; $i++) {
        $y = intdiv($i, 256);
        $blocks[$i] = $y <= 4 ? 2 : ($y === 5 ? 1 : 0);
    }

    $palette = $mixed
        ? nbtList(10, [
            nbtCompound([nbtTag(8, '', nbtS('minecraft:air'))]),
            nbtCompound([nbtTag(8, 'id', nbtS('minecraft:water')), nbtTag(10, 'properties', nbtCompound([nbtTag(8, 'level', nbtS('0'))]))]),
            nbtCompound([nbtTag(8, '', nbtS('minecraft:sand'))]),
        ])
        : nbtList(8, [nbtS('minecraft:air'), nbtS('minecraft:grass_block'), nbtS('minecraft:stone')]);

    $section = nbtCompound([
        nbtTag(1, 'Y', nbtI8(0)),
        nbtTag(10, 'biomes', nbtCompound([nbtTag(9, 'palette', nbtList(8, [nbtS('minecraft:plains')]))])),
        nbtTag(10, 'block_states', nbtCompound([
            nbtTag(12, 'data', nbtLongArray(packLongs($blocks, 4, 256))),
            nbtTag(9, 'palette', $palette),
        ])),
    ]);

    $air = fn (int $y) => nbtCompound([
        nbtTag(1, 'Y', nbtI8($y)),
        nbtTag(10, 'block_states', nbtCompound([nbtTag(9, 'palette', nbtList(8, [nbtS('minecraft:air')]))])),
    ]);

    return nbtTag(10, '', nbtCompound([
        nbtTag(3, 'xPos', nbtI32(0)),
        nbtTag(3, 'yPos', nbtI32(-4)),
        nbtTag(9, 'sections', nbtList(10, [$air(-4), $section, $air(1)])),
        nbtTag(8, 'Status', nbtS('minecraft:full')),
        nbtTag(3, 'DataVersion', nbtI32(5023)),
    ]));
}

/** Region with a string-palette chunk at local (0,0) and a mixed-palette chunk at (1,0). */
function mct26Region(): string
{
    $locations = str_repeat("\0", 4096);
    $body = '';
    $sector = 2;

    foreach ([0 => false, 1 => true] as $index => $mixed) {
        $chunk = gzcompress(mct26Chunk($mixed));
        $record = pack('N', strlen($chunk) + 1).chr(2).$chunk;
        $sectors = (int) ceil(strlen($record) / 4096);
        $body .= str_pad($record, $sectors * 4096, "\0");
        $locations = substr_replace($locations, pack('N', ($sector << 8) | $sectors), $index * 4, 4);
        $sector += $sectors;
    }

    return $locations.str_repeat("\0", 4096).$body;
}

/** Same server as mctWorld(), but its `world` is a 26.x level folder (no world_nether/world_the_end). */
function mct26World(FakeWings $wings): void
{
    mctWorld($wings);

    foreach (array_keys($wings->files) as $path) {
        if (str_starts_with($path, 'world')) {
            unset($wings->files[$path]);
        }
    }

    foreach (array_keys($wings->dirs) as $path) {
        if (str_starts_with($path, 'world')) {
            unset($wings->dirs[$path]);
        }
    }

    $uuid = '069a79f4-44e9-4726-a5be-fca90e38aaf5';
    $dims = 'world/dimensions/minecraft';

    $wings->put('world/level.dat', mct26LevelDat());
    $wings->put('world/level.dat_old', mct26LevelDat());
    $wings->put('world/session.lock', 'x');
    $wings->put('world/data/minecraft/scoreboard.dat', gzencode(nbtTag(10, '', nbtCompound([nbtTag(3, 'DataVersion', nbtI32(5023))]))));
    $wings->put('world/datapacks/limbo.zip', 'PK');
    $wings->put($dims.'/overworld/region/r.0.0.mca', mct26Region());
    $wings->put($dims.'/overworld/entities/r.0.0.mca', str_repeat("\0", 8192));
    $wings->put($dims.'/overworld/data/minecraft/world_gen_settings.dat', mct26WorldGenSettings(-9132444186617892091));
    $wings->put($dims.'/overworld/paper-world.yml', "_version: 31\n");
    $wings->put($dims.'/the_nether/region/r.0.0.mca', mct26Region());
    $wings->put($dims.'/the_end/region/r.-1.0.mca', mct26Region());
    $wings->put('world/players/data/'.$uuid.'.dat', gzencode(nbtTag(10, '', nbtCompound([nbtTag(3, 'DataVersion', nbtI32(5023))]))));
    $wings->put('world/players/stats/'.$uuid.'.json', '{"stats":{},"DataVersion":5023}');
    $wings->put('world/players/advancements/'.$uuid.'.json', '{"DataVersion":5023}');
}

it('reads a 26.x level.dat and the seed that moved to world_gen_settings.dat', function () {
    expect(NbtReader::parseLevelDat(mct26LevelDat()))->toMatchArray([
        'version' => '26.3',
        'data_version' => 5023,
        'level_name' => 'Armikan',
        'game_type' => 1,
        'hardcore' => true,
        'last_played' => '2026-10-04T11:35:21Z',
        'seed' => null,
        'spawn_x' => 700,
        'spawn_y' => 85,
        'spawn_z' => -300,
    ])
        ->and(NbtReader::parseWorldGenSeed(mct26WorldGenSettings(-9132444186617892091)))->toBe('-9132444186617892091')
        ->and(NbtReader::parseLevelDat(buildLevelDat()))->toMatchArray(['hardcore' => false, 'seed' => '-123456789012345']);
});

it('lists a 26.x world as one row that maps all three dimensions, next to a classic world', function () {
    $wings = new FakeWings();
    mct26World($wings);
    $wings->put('legacy/level.dat', buildLevelDat());
    $wings->put('legacy/region/r.0.0.mca', buildRegion());
    [, $server] = mctServer($wings);

    $service = app(\McToolkit\Services\Worlds\WorldsService::class);
    $rows = collect($service->listWorlds($server))->keyBy('name');

    expect($rows->keys()->all())->toBe(['world', 'legacy'])
        ->and($rows['world'])->toMatchArray([
            'default' => true,
            'dimension' => 'overworld',
            'linked' => false,
            'members' => [],
            'maps' => ['overworld', 'nether', 'end'],
            'seed' => '-9132444186617892091',
            'game_mode' => 'creative',
            'hardcore' => true,
            'title' => 'Armikan',
            'version' => '26.3',
            'spawn' => ['x' => 700, 'y' => 85, 'z' => -300],
            'approximate' => false,
        ])
        // the size walk reaches dimensions/minecraft/<dim>/region/*.mca
        ->and($rows['world']['size'])->toBeGreaterThan(3 * strlen(mct26Region()))
        ->and($rows['legacy'])->toMatchArray(['maps' => ['overworld'], 'version' => '1.21.4', 'seed' => '-123456789012345', 'hardcore' => false])
        ->and($service->affectedPaths($server, 'world'))->toBe(['world'])
        ->and($service->knownWorldNames($server))->toBe(['world', 'legacy']);
});

it('keeps pre-upgrade split-world leftovers linked to a 26.x world', function () {
    $wings = new FakeWings();
    mct26World($wings);
    $wings->put('world_nether/DIM-1/region/r.0.0.mca', buildRegion());
    [, $server] = mctServer($wings);

    $service = app(\McToolkit\Services\Worlds\WorldsService::class);

    expect(collect($service->listWorlds($server, false))->map(fn ($r) => [$r['name'], $r['maps'], $r['linked']])->all())->toBe([
        ['world', ['overworld', 'nether', 'end'], false],
        ['world_nether', ['nether'], true],
    ])->and($service->affectedPaths($server, 'world'))->toBe(['world', 'world_nether']);
});

it('draws every dimension of a 26.x world on the map, string palettes included', function () {
    $wings = new FakeWings();
    mct26World($wings);
    $wings->put('legacy/region/r.0.0.mca', buildRegion());
    $wings->put('.toolkit/map.json', json_encode(['players' => [
        ['name' => 'Notch', 'x' => 10, 'y' => 70, 'z' => 10, 'dimension' => 'minecraft:the_nether', 'world' => 'world_nether'],
        ['name' => 'Steve', 'x' => 1, 'y' => 70, 'z' => 1, 'dimension' => 'minecraft:overworld', 'world' => 'world'],
        ['name' => 'Alex', 'x' => 1, 'y' => 70, 'z' => 1, 'dimension' => 'minecraft:the_nether', 'world' => 'other_nether'],
    ], 'time' => 1000]));
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $regionsOf = fn ($c) => collect($c->get('regionMeta')['regions'])->map(fn ($r) => [$r['rx'], $r['rz']])->all();

    $c = livewire(MapPage::class)->assertOk();
    expect(collect($c->get('worlds'))->pluck('key')->all())->toBe(['world', 'world@nether', 'world@end', 'legacy'])
        ->and($c->get('selectedWorld'))->toBe('world')
        ->and($regionsOf($c))->toBe([[0, 0]])
        ->and(collect($c->get('pins'))->pluck('name')->all())->toBe(['Steve']);

    // Paper 26.x still names its nether level `world_nether`, now stored inside `world/`.
    $c->call('selectWorld', 'world@nether');
    expect(collect($c->get('pins'))->pluck('name')->all())->toBe(['Notch']);

    $c->call('selectWorld', 'world@end');
    expect($regionsOf($c))->toBe([[-1, 0]]);

    $tile = fn (string $world, string $dim, int $rx = 0): string => base64_decode((string) $this->getJson(
        "/server/{$server->uuid_short}/mc-toolkit/map/tile?world={$world}&dim={$dim}&rx={$rx}&rz=0&v=1760090400"
    )->assertOk()->json('tile.pixels'));

    $overworld = $tile('world', 'overworld');

    // String palette renders exactly like the classic {Name: …} palette of the same blocks.
    expect(substr($overworld, 0, 4))->toBe(substr($tile('legacy', 'overworld'), 0, 4))
        ->and(bin2hex(substr($overworld, 0, 3)))->not->toBe('0b0e14')
        // mixed {id, properties} / {"": id} palette (chunk 1 starts at pixel 8 at coarse 2)
        ->and(bin2hex(substr($overworld, 4 * 8, 3)))->not->toBe('0b0e14')
        ->and(substr($overworld, 4 * 8, 3))->not->toBe(substr($overworld, 0, 3))
        ->and(bin2hex(substr($overworld, 4 * 16, 3)))->toBe('0b0e14')
        ->and(bin2hex(substr($tile('world', 'nether'), 0, 3)))->not->toBe('0b0e14')
        ->and(bin2hex(substr($tile('world', 'end', -1), 0, 3)))->not->toBe('0b0e14');
});

it('finds a 26.x world inside an unpacked import archive', function () {
    $wings = new FakeWings();
    [, $server] = mctServer($wings);

    $wings->put('.toolkit/import-abc/pack/README.txt', 'hi');
    $wings->put('.toolkit/import-abc/pack/MyWorld/level.dat', mct26LevelDat());
    $wings->put('.toolkit/import-abc/pack/MyWorld/dimensions/minecraft/overworld/region/r.0.0.mca', 'x');
    $wings->put('.toolkit/import-abc/pack/MyWorld/players/data/x.dat', 'x');

    $service = app(\McToolkit\Services\Worlds\WorldsService::class);
    $locate = (new ReflectionClass($service))->getMethod('locateWorld');

    expect($locate->invoke($service, $server, '.toolkit/import-abc'))->toBe('.toolkit/import-abc/pack/MyWorld');

    // Without level.dat the dimensions/ folder alone still marks the world folder.
    unset($wings->files['.toolkit/import-abc/pack/MyWorld/level.dat']);
    expect($locate->invoke($service, $server, '.toolkit/import-abc'))->toBe('.toolkit/import-abc/pack/MyWorld');
});

it('badges Paper 26.x bridge levels that have no folder of their own', function () {
    $wings = new FakeWings();
    mct26World($wings);
    $wings->put('.toolkit/performance.json', json_encode(['tps' => 20, 'worlds' => [
        ['name' => 'world', 'players' => 1, 'chunks' => 100],
        ['name' => 'world_nether', 'players' => 0, 'chunks' => 10],
        ['name' => 'world_the_end', 'players' => 0, 'chunks' => 5],
    ]]));
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $badges = collect(livewire(PerformancePage::class)->assertOk()->get('worlds'))->pluck('badge', 'name')->all();

    expect($badges)->toBe(['world' => 'Default', 'world_nether' => 'Nether', 'world_the_end' => 'End']);
});

it('mounts the world-facing pages on a 26.x world', function (string $page) {
    $wings = new FakeWings();
    mct26World($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire($page)->assertOk();
})->with([
    'players' => PlayersPage::class,
    'worlds' => WorldsPage::class,
    'map' => MapPage::class,
    'performance' => PerformancePage::class,
]);

/* ================================================================== */
/*  Minecraft 26.x world layout — END                                  */
/* ================================================================== */

/* ================================================================== */
/*  v1.0.5 UI regressions (stylesheet, Livewire hooks, wire args) — START */
/* ================================================================== */

it('keeps the Map page alive across Livewire updates (no private hydrate{Prop} hooks)', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    // Every update after mount runs Livewire's hydrate{Property}() hooks; a private
    // hydrateWorlds() made each of them throw BadMethodCallException.
    livewire(MapPage::class)->assertOk()->call('$refresh')->assertOk();
});

it('has no non-public method named like a Livewire property hook on any page', function () {
    $pages = [VersionsPage::class, PluginsPage::class, ModsPage::class, ModpacksPage::class, PropertiesPage::class,
        PlayersPage::class, WorldsPage::class, MapPage::class, PerformancePage::class, McAdminSettingsPage::class];

    $offenders = [];

    foreach ($pages as $page) {
        $class = new ReflectionClass($page);
        $props = array_map(fn (ReflectionProperty $p): string => ucfirst($p->getName()), $class->getProperties(ReflectionProperty::IS_PUBLIC));

        foreach ($class->getMethods() as $method) {
            if ($method->isPublic()) {
                continue;
            }

            foreach (['hydrate', 'dehydrate', 'updating', 'updated'] as $prefix) {
                if (str_starts_with($method->getName(), $prefix) && in_array(substr($method->getName(), strlen($prefix)), $props, true)) {
                    $offenders[] = $page.'::'.$method->getName();
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('ships a prebuilt stylesheet and inlines it as one closed style element', function () {
    $css = base_path('plugins/minecraft-toolkit/resources/dist/toolkit.css');

    expect(is_file($css))->toBeTrue()
        ->and(filesize($css))->toBeGreaterThan(10_000);

    $tag = (new ReflectionMethod(McToolkit\McToolkitPlugin::class, 'stylesheetTag'))->invoke(null);

    expect($tag)->toStartWith('<style id="mc-toolkit-styles">')
        ->and($tag)->toEndWith('</style>')
        ->and(substr_count($tag, '</style'))->toBe(1)
        // utilities the views rely on, incl. the Filament palette mapping
        ->and($tag)->toContain('.grid')
        ->and($tag)->toContain('var(--primary-600)');
});

it('renders wire arguments the browser can parse on every page', function (string $page) {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $html = livewire($page)->assertOk()->html();

    // Blade directives are not compiled inside <x-…> component attributes: an @js(...)
    // reached the browser verbatim and every such button threw a SyntaxError.
    expect($html)->not->toContain('@js(')
        // A raw double quote inside an Alpine handler closes the attribute early.
        ->and($html)->not->toContain('new Event("input"');
})->with('pages');

/* ================================================================== */
/*  v1.0.5 UI regressions — END                                        */
/* ================================================================== */

/* ================================================================== */
/*  Background installs (queued jobs) — START                          */
/*  Fully qualified names only, so this section needs no imports.      */
/* ================================================================== */

/** Fake Modrinth project `mctkpack` (one .mrpack version) + its archive contents in FakeWings. */
function mctqPack(FakeWings $wings, string $loaderKey = 'fabric-loader', string $loaderVersion = '0.16.10', string $mc = '1.20.1'): string
{
    $id = 'mctkpack';
    $mrpack = 'https://cdn.modrinth.com/data/mctkpack/versions/1.0.0/Test-Pack-1.0.0.mrpack';

    Http::fake(function (Request $request) use ($id, $mrpack, $mc) {
        if (parse_url($request->url(), PHP_URL_HOST) !== 'api.modrinth.com') {
            return null;
        }

        return match (parse_url($request->url(), PHP_URL_PATH)) {
            "/v2/project/{$id}/version" => Http::response([[
                'id' => 'v1', 'version_number' => '1.0.0', 'version_type' => 'release',
                'loaders' => ['fabric'], 'game_versions' => [$mc], 'dependencies' => [],
                'files' => [['filename' => 'Test-Pack-1.0.0.mrpack', 'url' => $mrpack, 'primary' => true, 'size' => 1234, 'hashes' => ['sha1' => str_repeat('a', 40)]]],
            ]], 200),
            "/v2/project/{$id}" => Http::response(['id' => $id, 'slug' => 'test-pack', 'title' => 'Test Pack'], 200),
            default => null,
        };
    });

    $wings->archives['#^'.preg_quote($mrpack, '#').'$#'] = [
        'modrinth.index.json' => json_encode([
            'formatVersion' => 1, 'game' => 'minecraft', 'versionId' => '1.0.0', 'name' => 'Test Pack',
            'files' => [
                ['path' => 'mods/sodium.jar', 'downloads' => ['https://cdn.modrinth.com/data/AANobbMI/versions/x/sodium.jar'], 'env' => ['server' => 'required', 'client' => 'required'], 'fileSize' => 10],
                ['path' => 'mods/client-only.jar', 'downloads' => ['https://cdn.modrinth.com/data/x/versions/y/client.jar'], 'env' => ['server' => 'unsupported', 'client' => 'required'], 'fileSize' => 10],
            ],
            'dependencies' => ['minecraft' => $mc, $loaderKey => $loaderVersion],
        ]),
        'overrides/config/test.toml' => "a = 1\n",
    ];

    return $id;
}

function mctqNotes(Server $server): string
{
    return implode("\n", \McToolkit\Support\ServerTask::get($server)['notes'] ?? []);
}

it('tracks one task per server: start, begin, progress, cancel, stale and dead states', function () {
    [$user, $server] = mctServer(new FakeWings());
    $T = \McToolkit\Support\ServerTask::class;

    $token = $T::start($server, 'software', 'Paper 1.21.4', $user->id);
    expect($token)->toBeString()
        ->and($T::start($server, 'modpack', 'Other', $user->id))->toBeNull()
        ->and($T::present($server))->toMatchArray(['state' => 'queued', 'active' => true, 'can_cancel' => true, 'queue_stalled' => false])
        ->and($T::present($server))->not->toHaveKey('id');

    // Queued for more than two minutes: the worker is probably not running.
    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(3));
    expect($T::present($server)['queue_stalled'])->toBeTrue()
        ->and($T::cancel($server))->toBeTrue()
        ->and($T::get($server))->toBeNull()
        ->and($T::begin($server, $token))->toBeFalse(); // the cancelled job bails out

    $token = $T::start($server, 'software', 'Paper 1.21.4', $user->id);
    expect($T::begin($server, $token))->toBeTrue()
        ->and($T::begin($server, $token))->toBeFalse() // second delivery
        ->and($T::cancel($server))->toBeFalse()
        ->and($T::step($server, $token, 'download', 1, 4))->toBeTrue()
        ->and($T::present($server))->toMatchArray(['state' => 'running', 'step' => 'download', 'done' => 1, 'total' => 4, 'percent' => 25])
        ->and($T::step($server, 'not-the-token', 'swap'))->toBeFalse()
        ->and($T::dismiss($server))->toBeFalse();

    // No heartbeat for over ten minutes: dead, dismissable, no longer blocking.
    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(11));
    expect($T::present($server))->toMatchArray(['dead' => true, 'active' => false, 'can_dismiss' => true]);

    $next = $T::start($server, 'software', 'Fabric 1.20.1', $user->id);
    expect($next)->toBeString()
        ->and($T::step($server, $token, 'swap'))->toBeFalse() // the old run lost its entry
        ->and($T::begin($server, $next))->toBeTrue()
        ->and($T::note($server, $next, 'first note'))->toBeTrue()
        ->and($T::finish($server, $next, true, 'Done', true))->toBeTrue()
        ->and($T::present($server))->toMatchArray(['state' => 'done', 'warning' => true, 'message' => 'Done', 'notes' => ['first note'], 'can_dismiss' => true])
        ->and($T::dismiss($server))->toBeTrue()
        ->and($T::get($server))->toBeNull();

    // A page opened while a task waits shows it (whatever its kind) and blocks installs.
    $T::start($server, 'modpack', 'Test Pack', $user->id);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(VersionsPage::class)->assertSet('task.state', 'queued')->assertSet('busy', true)
        ->assertSee('Test Pack')->assertSeeHtml('wire:poll.2s="pollTask"');
});

it('ignores a duplicate delivery of a running job but fails it on timeout or when it went silent', function () {
    [$user, $server] = mctServer(new FakeWings());
    $T = \McToolkit\Support\ServerTask::class;

    $token = $T::start($server, 'software', 'Paper 1.21.4', $user->id);
    $T::begin($server, $token);
    $job = new \McToolkit\Jobs\InstallSoftwareJob($server->id, $user->id, $token, 'PAPER', '1.21.4');

    expect($job->tries)->toBe(1)->and($job->timeout)->toBe(3600)->and($job->failOnTimeout)->toBeTrue();

    $job->failed(new \Illuminate\Queue\MaxAttemptsExceededException('second delivery'));
    expect($T::get($server)['state'])->toBe('running');

    $job->failed(new \Illuminate\Queue\TimeoutExceededException('timed out'));
    expect($T::get($server))->toMatchArray(['state' => 'failed'])
        ->and($T::get($server)['message'])->toContain('60 minutes')
        ->and(\Illuminate\Notifications\DatabaseNotification::query()->where('notifiable_id', $user->id)->count())->toBe(1);

    // A duplicate delivery after the run went silent marks it failed too.
    $token = $T::start($server, 'software', 'Paper 1.21.4', $user->id);
    $T::begin($server, $token);
    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(11));
    (new \McToolkit\Jobs\InstallSoftwareJob($server->id, $user->id, $token, 'PAPER', '1.21.4'))
        ->failed(new \Illuminate\Queue\MaxAttemptsExceededException('second delivery'));
    expect($T::get($server)['state'])->toBe('failed');
});

it('queues a Versions install, shows and polls the task, refuses a second one and cancels it', function () {
    config()->set('queue.default', 'database');
    \Illuminate\Support\Facades\Queue::fake();

    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(VersionsPage::class)->assertOk()->set('search', '1.21.4');
    $c->call('askInstall', '1.21.4')->assertSet('pending.version', '1.21.4')->call('runInstall');

    \Illuminate\Support\Facades\Queue::assertPushed(\McToolkit\Jobs\InstallSoftwareJob::class, fn ($job) => $job->type === 'PAPER'
        && $job->version === '1.21.4' && $job->java === 21 && $job->serverId === $server->id && $job->userId === $user->id);

    $c->assertSet('busy', true)->assertSet('task.state', 'queued')->assertSeeHtml('wire:poll.2s="pollTask"');

    // A second install while one is queued is refused.
    $c->call('askInstall', '1.21.4')->call('runInstall');
    \Illuminate\Support\Facades\Queue::assertPushed(\McToolkit\Jobs\InstallSoftwareJob::class, 1);

    // Stuck in the queue: the page hints at the missing worker.
    \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(3));
    $c->call('pollTask')->assertSet('task.queue_stalled', true)->assertSee('queue:work');

    $c->call('cancelTask')->assertSet('task', null)->assertSet('busy', false)->assertDontSeeHtml('wire:poll.2s');

    // The worker finally picks the cancelled job up: it does nothing.
    \Illuminate\Support\Facades\Queue::pushed(\McToolkit\Jobs\InstallSoftwareJob::class)->first()->handle();
    expect($wings->pulls)->toBe([])->and($wings->power)->toBe([]);
});

it('runs a queued software install like the worker would: actor, Java image, notification', function () {
    config()->set('queue.default', 'database');
    \Illuminate\Support\Facades\Queue::fake();

    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $server->forceFill(['image' => 'ghcr.io/pelican-eggs/yolks:java_17'])->save();
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(VersionsPage::class)->set('search', '1.21.4');
    $c->call('askInstall', '1.21.4')->set('startAfter', false)->call('runInstall');

    // Worker context: nobody is logged in.
    auth()->forgetUser();
    \Illuminate\Support\Facades\Exceptions::fake();
    \Illuminate\Support\Facades\Queue::pushed(\McToolkit\Jobs\InstallSoftwareJob::class)->first()->handle();
    \Illuminate\Support\Facades\Exceptions::assertNothingReported();

    $task = \McToolkit\Support\ServerTask::get($server);
    $server->refresh();

    expect($task['state'])->toBe('done')
        ->and($wings->power)->toBe(['stop'])
        ->and($wings->files)->toHaveKey('bungeecord.jar')
        ->and($wings->files)->not->toHaveKey('paper-1.21.4-232.jar')
        ->and(json_decode($wings->files['.mctoolkit-install.json'], true))->toMatchArray(['software' => 'PAPER', 'version' => '1.21.4'])
        ->and($server->image)->toBe('ghcr.io/pelican-eggs/yolks:java_21')
        ->and(mctqNotes($server))->toContain('Java 21')
        ->and(\App\Models\ActivityLog::query()->where('event', 'mc-toolkit:versions.install')->value('actor_id'))->toBe($user->id)
        ->and(\App\Models\ActivityLog::query()->where('event', 'server:startup.image')->value('actor_id'))->toBe($user->id);

    // Filament's database notification is itself queued (the queue is faked here).
    \Illuminate\Support\Facades\Queue::assertPushed(\Illuminate\Notifications\SendQueuedNotifications::class, fn ($job) => $job->notifiables->first()?->is($user)
        && str_contains((string) ($job->notification->data['title'] ?? ''), 'Paper 1.21.4'));

    // The page notices the end of the run on its next poll.
    $this->actingAs($user);
    $c->call('pollTask')->assertSet('task.state', 'done')->assertSet('busy', false)->call('dismissTask')->assertSet('task', null);
});

it('re-checks permissions when a queued install finally runs', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings, ['minecraft-toolkit.use']);
    $T = \McToolkit\Support\ServerTask::class;

    $token = $T::start($server, 'software', 'Paper 1.21.4', $user->id);
    (new \McToolkit\Jobs\InstallSoftwareJob($server->id, $user->id, $token, 'PAPER', '1.21.4'))->handle();

    expect($T::get($server)['state'])->toBe('failed')
        ->and($T::get($server)['message'])->toBe(trans('minecraft-toolkit::errors.statuses.permission_denied'))
        ->and($wings->pulls)->toBe([]);
});

it('installs an archive build: launcher renamed to SERVER_JARFILE, unix_args.txt gone, image and startup aligned', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->state = 'offline';
    $wings->put('unix_args.txt', '-cp libraries/old.jar');
    $wings->put('libraries/old/old.jar', 'old');
    $wings->archives['#^https://files\.mcjars\.app/forge/#'] = [
        'server.jar' => 'FORGE LAUNCHER',
        'libraries/net/minecraftforge/forge/forge.jar' => 'lib',
    ];
    [$user, $server] = mctServer($wings);

    $server->egg->forceFill(['startup_commands' => ['Forge args' => 'java @unix_args.txt', 'Default' => 'java -Xms128M -jar {{SERVER_JARFILE}}']])->save();
    $server->forceFill(['startup' => 'java @unix_args.txt', 'image' => 'ghcr.io/pelican-eggs/yolks:java_8'])->save();
    $server = $server->fresh(['egg', 'node']);

    $result = app(\McToolkit\Services\Catalog\SoftwareInstallerService::class)->install($server, $user, 'FORGE', '1.20.1', ['build' => '47.2.0']);
    $server->refresh();

    expect($result->buildName)->toBe('47.2.0')
        ->and($result->java)->toBe(17)
        ->and($wings->files)->not->toHaveKey('unix_args.txt')
        ->and($wings->files)->not->toHaveKey('server.jar')
        ->and($wings->files)->not->toHaveKey('mcvapi.server.jar.zip')
        ->and($wings->files)->not->toHaveKey('libraries/old/old.jar')
        ->and($wings->files['bungeecord.jar'])->toBe('FORGE LAUNCHER')
        ->and($wings->files)->toHaveKey('libraries/net/minecraftforge/forge/forge.jar')
        ->and(json_decode($wings->files['.mctoolkit-install.json'], true)['files'])->toBe(['bungeecord.jar' => strlen('FORGE LAUNCHER')])
        ->and($server->image)->toBe('ghcr.io/pelican-eggs/yolks:java_17')
        ->and($server->startup)->toBe('java -Xms128M -jar {{SERVER_JARFILE}}')
        ->and(implode("\n", $result->notes))->toContain('bungeecord.jar')->toContain('unix_args.txt')->toContain('"Default"')
        ->and($result->attention)->toBeFalse();

    $detected = app(\McToolkit\Services\DetectionService::class)->detect($server, true);
    expect($detected['software'])->toBe('forge')->and($detected['version'])->toBe('1.20.1');
});

it('leaves image and startup alone without Startup permissions and notes it', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->state = 'offline';
    [$user, $server] = mctServer($wings, ['minecraft-toolkit.use', 'minecraft-toolkit.versions', 'minecraft-toolkit.manage']);
    $server->forceFill(['image' => 'ghcr.io/pelican-eggs/yolks:java_17'])->save();

    $result = app(\McToolkit\Services\Catalog\SoftwareInstallerService::class)
        ->install($server->fresh(['egg', 'node']), $user, 'FABRIC', '1.20.1', ['build' => '9.9.9-not-a-build']);

    expect($server->fresh()->image)->toBe('ghcr.io/pelican-eggs/yolks:java_17') // 1.20.1 needs 17: nothing to do
        ->and($result->buildName)->not->toBe('9.9.9-not-a-build')
        ->and(implode("\n", $result->notes))->toContain('9.9.9-not-a-build')->toContain('startup command')
        ->and($result->attention)->toBeTrue();

    $result = app(\McToolkit\Services\Catalog\SoftwareInstallerService::class)
        ->install($server->fresh(['egg', 'node']), $user, 'PAPER', '1.21.4');

    expect($server->fresh()->image)->toBe('ghcr.io/pelican-eggs/yolks:java_17')
        ->and(implode("\n", $result->notes))->toContain('Java 21')->toContain('Startup page');
});

it('maps Minecraft versions and egg images to Java majors', function () {
    $S = \McToolkit\Services\Catalog\SoftwareInstallerService::class;

    expect($S::fallbackJava('1.16.5'))->toBe(8)
        ->and($S::fallbackJava('1.17.1'))->toBe(16)
        ->and($S::fallbackJava('1.18.2'))->toBe(17)
        ->and($S::fallbackJava('1.20.4'))->toBe(17)
        ->and($S::fallbackJava('1.20.5'))->toBe(21)
        ->and($S::fallbackJava('1.21.11'))->toBe(21)
        ->and($S::fallbackJava('26.3'))->toBe(25)
        ->and($S::fallbackJava('3.4.0'))->toBeNull()
        ->and($S::javaMajorOf('ghcr.io/pelican-eggs/yolks:java_21'))->toBe(21)
        ->and($S::javaMajorOf('Java 8'))->toBe(8)
        ->and($S::javaMajorOf('eclipse-temurin JRE 17'))->toBe(17)
        ->and($S::javaMajorOf('ghcr.io/x/y:latest'))->toBeNull()
        ->and($S::pickImage(['Java 8' => 'a', 'Java 21' => 'c', 'Java 17' => 'b'], 16)['image'])->toBe('b')
        ->and($S::pickImage(['Java 8' => 'a', 'Java 17' => 'b'], 21))->toBeNull()
        ->and($S::launchesJar('java -jar {{SERVER_JARFILE}}'))->toBeTrue()
        ->and($S::launchesJar('java @unix_args.txt'))->toBeFalse();
});

it('installs a modpack in the background together with its loader and keeps the server stopped', function () {
    $wings = new FakeWings();
    mctWorld($wings); // a Paper server with plugins
    $wings->state = 'offline';
    [$user, $server] = mctServer($wings);
    $id = mctqPack($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(ModpacksPage::class)->assertOk()->assertDontSee(trans('minecraft-toolkit::pages.mods.blocker.bukkit_message'));
    expect($c->instance()->blockedNotice())->toBeNull();

    $c->call('install', 'modrinth', $id)
        ->assertSet('pendingPack.title', 'Test Pack')
        ->assertSet('pendingPack.loader', 'fabric')
        ->assertSet('pendingPack.game_version', '1.20.1')
        ->assertSet('pendingPack.switches', true)
        ->assertSet('packInstallLoader', true)
        ->assertSet('packStartAfter', false)
        ->assertSee('Also install Fabric for Minecraft 1.20.1');

    \Illuminate\Support\Facades\Exceptions::fake();
    $c->call('confirmPack'); // sync driver: the job runs once the request terminated
    \Illuminate\Support\Facades\Exceptions::assertNothingReported();

    $task = \McToolkit\Support\ServerTask::get($server);
    $marker = json_decode($wings->files['.mctoolkit-install.json'] ?? 'null', true);

    expect($task['state'])->toBe('done')
        ->and($task['kind'])->toBe('modpack')
        ->and($wings->files)->toHaveKey('mods/sodium.jar')
        ->and($wings->files)->not->toHaveKey('mods/client-only.jar')
        ->and($wings->files)->toHaveKey('config/test.toml')
        ->and($wings->files)->toHaveKey('modrinth.index.json')
        ->and($wings->files['bungeecord.jar'] ?? '')->toContain('fabric/1.20.1/0.16.10')
        ->and($marker)->toMatchArray(['software' => 'FABRIC', 'version' => '1.20.1', 'build' => '0.16.10'])
        ->and($wings->power)->not->toContain('start')
        ->and(mctqNotes($server))->toContain('Fabric 0.16.10 for Minecraft 1.20.1')->toContain('plugins')
        ->and(collect(array_keys($wings->files))->filter(fn ($f) => str_starts_with($f, '.mctoolkit-staging'))->all())->toBe([])
        ->and(\App\Models\ActivityLog::query()->where('event', 'mc-toolkit:content.install')->value('actor_id'))->toBe($user->id)
        ->and(\Illuminate\Notifications\DatabaseNotification::query()->where('notifiable_id', $user->id)->count())->toBe(1);

    $row = \McToolkit\Models\InstalledContent::query()->where('server_id', $server->id)->where('kind', 'modpack')->first();
    expect($row->dependencies['summary']['requires']['matches_server'])->toBeTrue();

    // The next poll notices the end of the run and shows the installed list.
    $c->call('pollTask')->assertSet('task.state', 'done')->assertSet('tab', 'installed');
});

it('skips the loader without the Versions permission and points to the Versions page', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->state = 'offline';
    [$user, $server] = mctServer($wings, ['minecraft-toolkit.use', 'minecraft-toolkit.content', 'minecraft-toolkit.manage']);
    $id = mctqPack($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $c = livewire(ModpacksPage::class)->call('install', 'modrinth', $id);
    expect($c->instance()->canInstallLoader())->toBeFalse();
    $c->set('packStartAfter', true)->call('confirmPack');

    $task = \McToolkit\Support\ServerTask::get($server);

    expect($task['state'])->toBe('done')
        ->and($task['warning'])->toBeTrue()
        ->and($wings->files)->toHaveKey('mods/sodium.jar')
        ->and(collect($wings->pulls)->contains(fn ($p) => str_contains($p['url'], 'mcjars')))->toBeFalse()
        ->and($wings->power)->toBe([]) // never started on the wrong software
        ->and(mctqNotes($server))->toContain('Versions page')->toContain('not started');
});

it('aborts a modpack cleanly when the server was started while its files downloaded', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->state = 'offline';
    $wings->onPull = function (FakeWings $w, string $url): void {
        if (str_contains($url, 'sodium.jar')) {
            $w->state = 'running'; // somebody pressed Start meanwhile
        }
    };
    [$user, $server] = mctServer($wings);
    $id = mctqPack($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(ModpacksPage::class)->call('install', 'modrinth', $id)->call('confirmPack');

    $task = \McToolkit\Support\ServerTask::get($server);

    expect($task['state'])->toBe('failed')
        ->and($task['message'])->toContain(trans('minecraft-toolkit::errors.statuses.stopped_required'))
        ->and($wings->files)->not->toHaveKey('mods/sodium.jar')
        ->and($wings->files)->not->toHaveKey('config/test.toml')
        ->and($wings->files)->not->toHaveKey('modrinth.index.json')
        ->and(collect(array_keys($wings->files))->filter(fn ($f) => str_starts_with($f, '.mctoolkit-staging'))->all())->toBe([])
        ->and(\McToolkit\Models\InstalledContent::query()->where('server_id', $server->id)->count())->toBe(0);
});

it('refuses to queue a modpack while the server runs', function () {
    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $id = mctqPack($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(ModpacksPage::class)->call('install', 'modrinth', $id)->call('confirmPack')->assertSet('task', null);

    expect(\McToolkit\Support\ServerTask::get($server))->toBeNull()->and($wings->pulls)->toBe([]);
});

it('mints a short world download token and schedules the archive clean-up', function () {
    config()->set('queue.default', 'database');
    \Illuminate\Support\Facades\Queue::fake();

    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);

    $q = \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, 'world');
    $location = $this->get("/server/{$server->uuid_short}/mc-toolkit/download/world?".http_build_query(['world' => 'world'] + $q))
        ->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $claims = json_decode(base64_decode(strtr(explode('.', $query['token'])[1], '-_', '+/')), true);
    expect((int) $claims['exp'] - now()->getTimestamp())->toBeGreaterThanOrEqual(175)->toBeLessThanOrEqual(185);

    $archive = collect(array_keys($wings->files))->first(fn ($f) => str_starts_with($f, 'mctk-download-'));
    expect($archive)->not->toBeNull();

    \Illuminate\Support\Facades\Queue::assertPushed(\McToolkit\Jobs\DiscardWorldArchiveJob::class, fn ($job) => $job->archive === $archive
        && $job->delay->getTimestamp() === now()->addSeconds(240)->getTimestamp());

    \Illuminate\Support\Facades\Queue::pushed(\McToolkit\Jobs\DiscardWorldArchiveJob::class)->first()->handle(app(\McToolkit\Services\Worlds\WorldsService::class));
    expect($wings->files)->not->toHaveKey($archive);
});

it('does not schedule a delayed archive clean-up on the sync driver', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);

    $q = \McToolkit\Http\Controllers\WorldDownloadController::makeQuery($server, 'world');
    $this->get("/server/{$server->uuid_short}/mc-toolkit/download/world?".http_build_query(['world' => 'world'] + $q))->assertRedirect();

    \Illuminate\Support\Facades\Queue::assertNotPushed(\McToolkit\Jobs\DiscardWorldArchiveJob::class);
});

/* ================================================================== */
/*  Background installs (queued jobs) — END                            */
/* ================================================================== */

/* ================================================================== */
/*  Companion bridge (one-click install + live data) — START           */
/* ================================================================== */

function mctBridgeBundle(): string
{
    $dir = sys_get_temp_dir().'/mct-bridge-'.bin2hex(random_bytes(4));
    @mkdir($dir);
    file_put_contents("$dir/MCToolkitBridge.jar", "PK\x03\x04\x00\xffbukkit-jar");
    file_put_contents("$dir/mctoolkit-bridge.jar", "PK\x03\x04\x00\xffmod-jar");
    file_put_contents("$dir/manifest.json", json_encode(['version' => '1.2.0', 'files' => [
        'bukkit' => ['file' => 'MCToolkitBridge.jar', 'sha256' => hash_file('sha256', "$dir/MCToolkitBridge.jar")],
        'mod' => ['file' => 'mctoolkit-bridge.jar', 'sha256' => hash_file('sha256', "$dir/mctoolkit-bridge.jar")],
    ]]));
    config()->set('minecraft-toolkit.bridge.bundle_dir', $dir);

    return $dir;
}

function mctBridgeMeta(int $age = 0, bool $running = true, string $version = '1.2.0'): array
{
    return ['protocol' => 1, 'version' => $version, 'platform' => 'paper', 'software' => 'Paper', 'minecraft' => '1.21.4',
        'updated_at' => time() - $age, 'interval' => 5, 'running' => $running];
}

function mctBridgeState(FakeWings $wings, int $age = 0, string $version = '1.2.0'): void
{
    $wings->put('.toolkit/state.json', json_encode(['bridge' => mctBridgeMeta($age, true, $version), 'players' => [
        '0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10' => [
            'name' => 'Steve', 'mode' => 'survival', 'world' => 'world', 'dimension' => 'overworld',
            'x' => 156.5, 'y' => 70.0, 'z' => -307.25, 'health' => 18.5, 'max_health' => 20.0,
            'food' => 17, 'saturation' => 3.2, 'level' => 30, 'exp' => 0.42, 'ping' => 42, 'op' => false, 'joined_at' => time() - 600,
        ],
    ]]));
    $wings->put('.toolkit/players/0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10.json', json_encode([
        'bridge' => mctBridgeMeta($age, true, $version), 'uuid' => '0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10', 'name' => 'Steve',
        'inventory' => [
            ['slot' => 0, 'id' => 'minecraft:diamond_sword', 'count' => 1, 'name' => 'Excalibur', 'enchanted' => true, 'damage' => 12, 'max_damage' => 1561],
            ['slot' => 9, 'id' => 'minecraft:oak_log', 'count' => 64, 'name' => null, 'enchanted' => false, 'damage' => null, 'max_damage' => null],
            ['slot' => 99, 'id' => 'minecraft:stone', 'count' => 1],          // out of range: dropped
            ['slot' => 1, 'id' => '<script>', 'count' => 1],                  // not an id: dropped
        ],
        'armor' => ['head' => null, 'chest' => ['id' => 'minecraft:netherite_chestplate', 'count' => 1, 'name' => null, 'enchanted' => false, 'damage' => 0, 'max_damage' => 592], 'legs' => null, 'feet' => null],
        'offhand' => ['id' => 'minecraft:shield', 'count' => 1, 'name' => null, 'enchanted' => false, 'damage' => 0, 'max_damage' => 336],
        'ender_chest' => [['slot' => 3, 'id' => 'minecraft:ender_pearl', 'count' => 16, 'name' => null, 'enchanted' => false, 'damage' => null, 'max_damage' => null]],
        'effects' => [['id' => 'minecraft:speed', 'amplifier' => 1, 'duration' => 600]],
    ]));
}

it('treats bridge files as fresh only while the bridge keeps writing', function () {
    expect(McToolkit\Services\Bridge\BridgeService::isFresh(['bridge' => mctBridgeMeta(0)]))->toBeTrue()
        ->and(McToolkit\Services\Bridge\BridgeService::isFresh(['bridge' => mctBridgeMeta(120)]))->toBeFalse()
        ->and(McToolkit\Services\Bridge\BridgeService::isFresh(['bridge' => mctBridgeMeta(0, running: false)]))->toBeFalse()
        // files without the meta block (hand-written / third-party) keep working as before
        ->and(McToolkit\Services\Bridge\BridgeService::isFresh(['tps' => 20]))->toBeTrue();
});

it('installs the bundled bridge into plugins/ on a Paper server and replaces older copies', function () {
    mctBridgeBundle();
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->put('plugins/MCToolkitBridge-0.9.0.jar', 'old');
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);

    $service = app(McToolkit\Services\Bridge\BridgeService::class);
    $before = $service->status($server, true);

    expect($before['supported'])->toBeTrue()->and($before['family'])->toBe('bukkit')->and($before['running'])->toBeFalse();

    $result = $service->install($server, $user);

    expect($result['path'])->toBe('plugins/MCToolkitBridge.jar')
        ->and($wings->files['plugins/MCToolkitBridge.jar'] ?? null)->toBe("PK\x03\x04\x00\xffbukkit-jar")
        ->and(isset($wings->files['plugins/MCToolkitBridge-0.9.0.jar']))->toBeFalse()
        ->and(isset($wings->files['plugins/EssentialsX-2.21.0.jar']))->toBeTrue();

    $after = $service->status($server, true);
    expect($after['installed'])->toBeTrue()->and($after['running'])->toBeFalse()->and($after['bundled_version'])->toBe('1.2.0');

    $this->assertDatabaseHas('activity_logs', ['event' => 'mc-toolkit:bridge.install']);
});

it('installs the mod build into mods/ on a Fabric server and refuses proxies', function () {
    mctBridgeBundle();

    $fabric = new FakeWings();
    $fabric->put('server.properties', "level-name=world\n");
    $fabric->put('fabric-server-mc.1.21.1-loader.0.16.10-launcher.1.0.1.jar', str_repeat('x', 1000));
    $fabric->put('.fabric/server/1.21.1-server.jar', 'x');
    $fabric->mkdir('mods');
    [$user, $server] = mctServer($fabric);
    $this->actingAs($user);

    expect(app(McToolkit\Services\Bridge\BridgeService::class)->install($server, $user)['path'])->toBe('mods/mctoolkit-bridge.jar')
        ->and($fabric->files['mods/mctoolkit-bridge.jar'] ?? null)->toBe("PK\x03\x04\x00\xffmod-jar");

    $proxy = new FakeWings();
    $proxy->put('velocity.toml', "bind = \"0.0.0.0:25577\"\n");
    $proxy->put('velocity-3.4.0-SNAPSHOT-500.jar', str_repeat('x', 1000));
    [$user2, $server2] = mctServer($proxy);
    $this->actingAs($user2);

    $service = app(McToolkit\Services\Bridge\BridgeService::class);
    expect($service->status($server2, true)['supported'])->toBeFalse();
    expect(fn () => $service->install($server2, $user2))->toThrow(DomainException::class);
    expect(array_filter(array_keys($proxy->files), fn ($f) => str_contains(strtolower($f), 'bridge')))->toBe([]);
});

it('refuses to install a bundle whose checksum does not match the manifest', function () {
    $dir = mctBridgeBundle();
    file_put_contents("$dir/MCToolkitBridge.jar", 'tampered');

    $wings = new FakeWings();
    mctWorld($wings);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);

    expect(fn () => app(McToolkit\Services\Bridge\BridgeService::class)->install($server, $user))->toThrow(DomainException::class);
    expect(isset($wings->files['plugins/MCToolkitBridge.jar']))->toBeFalse();
});

it('reports a running, a stale and an outdated bridge', function () {
    mctBridgeBundle();
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->put('plugins/MCToolkitBridge.jar', 'jar');
    [$user, $server] = mctServer($wings);
    $service = app(McToolkit\Services\Bridge\BridgeService::class);

    mctBridgeState($wings, 0, '1.2.0');
    $running = $service->status($server, true);
    expect($running['running'])->toBeTrue()->and($running['version'])->toBe('1.2.0')->and($running['update_available'])->toBeFalse();

    mctBridgeState($wings, 300, '1.2.0');
    $stale = $service->status($server, true);
    expect($stale['running'])->toBeFalse()->and($stale['stale'])->toBeTrue()->and($stale['installed'])->toBeTrue();

    mctBridgeState($wings, 0, '1.0.0');
    expect($service->status($server, true)['update_available'])->toBeTrue();
});

it('shows live player stats and the inventory from a running bridge', function () {
    mctBridgeBundle();
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->put('usercache.json', json_encode([['name' => 'Steve', 'uuid' => '0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10', 'expiresOn' => '2030-01-01 00:00:00 +0000']]));
    $wings->put('ops.json', '[]');
    mctBridgeState($wings, 0);
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    $detail = app(McToolkit\Services\Servers\PlayerRegistry::class)->bridgePlayerDetail($server, '0c9a1c4e5d0a4b9e9a516b1f3c2a7d10');
    expect(array_column($detail['inventory'], 'slot'))->toBe([0, 9])
        ->and($detail['armor']['chest']['id'])->toBe('minecraft:netherite_chestplate')
        ->and($detail['offhand']['id'])->toBe('minecraft:shield')
        ->and($detail['ender_chest'][0]['count'])->toBe(16);

    $component = livewire(PlayersPage::class)->assertOk()->set('selected', '0c9a1c4e-5d0a-4b9e-9a51-6b1f3c2a7d10');
    $html = $component->html();

    expect($html)->toContain('18.5 / 20')
        ->and($html)->toContain('17 / 20')
        ->and($html)->toContain('42 ms')
        ->and($html)->toContain('Excalibur (Diamond Sword)')
        ->and($html)->toContain('Live data from the companion bridge 1.2.0');
});

it('keeps the bridge-fed elements visible with an install button when the bridge is missing or stale', function () {
    mctBridgeBundle();
    $wings = new FakeWings();
    mctWorld($wings);
    $wings->put('.toolkit/performance.json', json_encode(['bridge' => mctBridgeMeta(600), 'tps' => 19.5, 'mspt' => 20.1, 'worlds' => []]));
    [$user, $server] = mctServer($wings);
    $this->actingAs($user);
    Filament::setTenant($server);

    // stale performance file: no numbers from it, charts still rendered with the hint
    $page = livewire(PerformancePage::class)->assertOk();
    expect($page->instance()->isBridged())->toBeFalse();
    $html = $page->html();
    expect($html)->toContain('Ticks per second')
        ->and($html)->toContain('Needs the companion bridge')
        ->and($html)->toContain('Install bridge');

    // one click installs it
    $page->call('installBridge')->assertNotified();
    expect($wings->files['plugins/MCToolkitBridge.jar'] ?? null)->toBe("PK\x03\x04\x00\xffbukkit-jar");
    expect($page->html())->toContain('restart to activate');

    // the Players page keeps its inventory grid and stat rows, with em-dashes
    $players = livewire(PlayersPage::class)->assertOk()->html();
    expect($players)->toContain('Companion bridge installed');
});

/* ================================================================== */
/*  Companion bridge — END                                             */
/* ================================================================== */
