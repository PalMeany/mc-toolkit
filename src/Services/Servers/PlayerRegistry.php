<?php

declare(strict_types=1);

namespace McToolkit\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Arr;
use McToolkit\Services\FileBridge;

/**
 * Merges the vanilla identity files on the server filesystem into ONE deterministic
 * player registry, then intersects it with a live {@see ServerListPing} sample so the
 * Players page can show who is online right now and keep op/whitelist/ban state.
 *
 * Expected source files (vanilla naming, all tolerated as missing):
 *
 * | file               | entry shape (fields used below)                                        |
 * |--------------------|------------------------------------------------------------------------|
 * | whitelist.json     | [{uuid, name}]                                                          |
 * | ops.json           | [{uuid, name, level}]                                                   |
 * | banned-players.json| [{uuid, name, created, source, reason, expires}]                        |
 * | banned-ips.json    | [{ip, created, source, reason, expires}]                                |
 * | usercache.json     | [{uuid, name, type, expiresOn}]                                         |
 *
 * Merge semantics:
 * - players are keyed by lowercase dashed uuid; the same account discovered in
 *   several files collapses into one row with boolean flags set per file hit.
 * - `ban_reason` is null when there is no ban or when the server wrote an empty reason.
 * - `last_seen` HEURISTIC: vanilla exposes no activity feed, so the only durable
 *   timestamp for offline discovery is the ban record's `created` field. When a
 *   player has no ban row the value stays null (documented parity decision).
 * - ping sample ids arrive dash-less; they are normalized before intersection.
 *   Sample-only players (offline-mode setups where no registry row exists) are
 *   appended with false flags so counters and card grids stay consistent.
 * - counters prefer the live ping (`online`/`max`) because the sample list is capped
 *   at 12 entries; when the ping failed we fall back to counting intersected rows and
 *   a config-less default max of 20 is reported via `max_is_estimate`.
 *
 * Companion-bridge file `.toolkit/state.json` (tier 2): consumed ONLY through
 * {@see self::bridgeActive()} and {@see self::bridgePlayers()} — the minimal
 * schema this plugin defines is
 *
 *     {"players": {"<dashed-lowercase-uuid>": {
 *         "name": "...", "mode": "survival", "dimension": "overworld",
 *         "x": 156, "y": 70, "z": 307 }}}
 *
 * Unknown fields are ignored deliberately so older bridge builds stay compatible.
 *
 * Return shape (frozen):
 *
 *     [
 *         'players' => list<array{
 *             uuid:string, name:string, op:bool, whitelisted:bool, banned:bool,
 *             ban_reason:?string, online:bool, last_seen:?string }>,
 *         'counters' => ['online'=>int, 'max'=>int, 'ping_ok'=>bool],
 *     ]
 */
final class PlayerRegistry
{
    /**
     * Short TTL: the page polls every 15 s but SHOULD NOT hammer wings more than
     * twice a minute (guide risk R18 node-throttling guidance). Manual refreshes
     * bypass the cache entirely with `$force = true`.
     */
    private const CACHE_TTL_SECONDS = 30;

    /** Fallback max-players when neither the ping nor properties could tell us. */
    private const FALLBACK_MAX_PLAYERS = 20;

    public function __construct(
        private readonly FileBridge $files,
        private readonly ServerListPing $ping,
    ) {}

    /**
     * Full registry snapshot for one server, cached briefly unless forced.
     *
     * @return array{players: list<array<string, mixed>>, counters: array{online: int, max: int, ping_ok: bool}}
     */
    public function snapshot(Server $server, bool $force = false): array
    {
        $key = 'mctoolkit.players.' . $server->getAttribute('uuid_short');

        if (! $force) {
            $cached = cache()->get($key);

            if (is_array($cached) && isset($cached['players'], $cached['counters'])) {
                return $cached;
            }
        }

        $rows = $this->registryRows($server);
        [$onlineRows, $counters] = $this->applyOnlineState($server, $rows);

        $snapshot = [
            // uuid_key is an internal merge key — the frozen return shape carries
            // exactly: uuid, name, op, whitelisted, banned, ban_reason, online, last_seen.
            'players' => array_map(
                static function (array $row): array {
                    unset($row['uuid_key']);

                    return $row;
                },
                $this->sorted($onlineRows),
            ),
            'counters' => $counters,
        ];

        // Only cache when at least one signal really came back — a fully unreachable
        // node must not pin an empty registry for the whole TTL window.
        if ($counters['ping_ok'] || $rows !== []) {
            cache()->put($key, $snapshot, now()->addSeconds(self::CACHE_TTL_SECONDS));
        }

        return $snapshot;
    }

    /** Drops any cached snapshot so the next poll rebuilds fresh registry flags. */
    public function invalidate(Server $server): void
    {
        cache()->forget('mctoolkit.players.' . $server->getAttribute('uuid_short'));
    }

    /** Whether the companion bridge published its state file (the "Instant" chip signal). */
    public function bridgeActive(Server $server): bool
    {
        $path = trim(strval(config('minecraft-toolkit.bridge.path', '.toolkit')), "/\\ ");

        if ($path === '') {
            return false;
        }

        try {
            return $this->files->exists($server, '/' . $path . '/state.json');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Live bridge data keyed by dashed-lowercase uuid. Returns [] when the bridge
     * is absent or its JSON does not follow our minimal contract — callers render
     * em-dash placeholders in that case.
     *
     * @return array<string, array{mode: ?string, dimension: ?string, x: ?float, y: ?float, z: ?float}>
     */
    public function bridgePlayers(Server $server): array
    {
        if (! $this->bridgeActive($server)) {
            return [];
        }

        $path = '/' . trim(strval(config('minecraft-toolkit.bridge.path', '.toolkit')), "/\\ ") . '/state.json';

        try {
            $payload = $this->files->readJson($server, $path);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($payload) || ! is_array($payload['players'] ?? null)) {
            return [];
        }

        $mapped = [];

        foreach (Arr::wrap($payload['players']) as $uuid => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $position = ['x', 'y', 'z'];

            $numbers = [];

            foreach ($position as $axis) {
                $raw = $entry[$axis] ?? $entry['position'][$axis] ?? null;
                $numbers[$axis] = is_numeric($raw) ? floatval($raw) : null;
            }

            $mapped[strtolower(str_replace('-', '', strval($uuid)))] = [
                'mode' => is_string($entry['mode'] ?? null) ? strval($entry['mode']) : null,
                'dimension' => is_string($entry['dimension'] ?? null) ? strval($entry['dimension']) : null,
                'x' => $numbers['x'],
                'y' => $numbers['y'],
                'z' => $numbers['z'],
            ];
        }

        return $mapped;
    }

    /* ---------------------------------------------------------------------
    |  Internals
    --------------------------------------------------------------------- */

    /**
     * Vanilla ban-file parser exposed separately so the Banned tab can also show
     * address bans that have no player row.
     *
     * @return list<array{ip:string, created:?string, source:?string, reason:?string, expires:?string}>
     */
    public function ipBans(Server $server): array
    {
        $entries = $this->readEntryList($server, 'banned-ips.json');

        $bans = [];

        foreach ($entries as $entry) {
            $ip = strval($entry['ip'] ?? '');

            if ($ip === '') {
                continue;
            }

            $bans[] = [
                'ip' => $ip,
                'created' => $this->nullableString($entry['created'] ?? null),
                'source' => $this->nullableString($entry['source'] ?? null),
                'reason' => $this->nullableString($entry['reason'] ?? null),
                'expires' => $this->nullableString($entry['expires'] ?? null),
            ];
        }

        return $bans;
    }

    /**
     * Registry rows BEFORE the online pass — still the full merge of all five files.
     *
     * @return array<string, array<string, mixed>> keyed by dashed-lowercase uuid.
     */
    private function registryRows(Server $server): array
    {
        $merged = []; // uuid => row

        $flagMap = [
            'ops.json' => ['op' => true],
            'whitelist.json' => ['whitelisted' => true],
            'banned-players.json' => ['banned' => true],
            'usercache.json' => [],
        ];

        foreach ($flagMap as $file => $flags) {
            foreach ($this->readEntryList($server, $file) as $entry) {
                $name = strval($entry['name'] ?? '');
                $uuidKey = $this->canonicalUuid($entry['uuid'] ?? '');

                if ($uuidKey === '' || $name === '') {
                    continue;
                }

                $row = $merged[$uuidKey] ?? $this->freshRow($uuidKey, $name);

                foreach ($flags as $flag => $value) {
                    $row[$flag] = $value;
                }

                // Prefer the most recently read display name for rows merged from
                // several files (ops -> whitelist -> bans -> usercache order wins).
                $row['name'] = $name;

                if ($flags !== []) {
                    $ban = $this->banFacts($entry);
                    $row['ban_reason'] = $ban['reason'];
                    $row['last_seen'] = $ban['last_seen'];
                }

                $merged[$uuidKey] = $row;
            }
        }

        return $merged;
    }

    /**
     * Live-online projection over the merged rows plus counters.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array{online: int, max: int, ping_ok: bool}}
     */
    private function applyOnlineState(Server $server, array $rows): array
    {
        // No primary allocation => the ping call itself returns null immediately.
        $status = $this->ping->ping(
            $server->allocation ?? '',
            2500,
        );

        $byId = [];
        $byName = [];

        foreach ($status['players']['sample'] ?? [] as $sample) {
            $id = strval($sample['id'] ?? '');

            if ($id !== '') {
                $byId[$id] = strval($sample['name']);
            }

            $byName[mb_strtolower(strval($sample['name']))] = $id;
        }

        $output = [];

        foreach ($rows as $uuid => $row) {
            $dashless = str_replace('-', '', $uuid);

            $isSampled = isset($byId[$dashless]);

            if (! $isSampled) {
                // Offline-mode registries sometimes hold numeric/nil ids — fall back
                // to matching by lowercase name against the live sample.
                $isSampled = isset($byName[mb_strtolower(strval($row['name']))]);
            }

            $row['online'] = $isSampled;

            $output[] = $row;
        }

        $matchedCount = count(array_filter($output, fn (array $r): bool => (bool) $r['online']));

        // Sample-only rows appended LAST so registered identities always win the grid.
        foreach ($byId as $id => $sampledName) {
            $already = false;

            foreach ($output as $row) {
                if (str_replace('-', '', $row['uuid']) === $id
                    || mb_strtolower(strval($row['name'])) === mb_strtolower($sampledName)) {
                    $already = true;

                    break;
                }
            }

            if ($already) {
                continue;
            }

            $dashed = $this->dashOut($id);

            $row = $this->freshRow($dashed !== '' ? $dashed : $id, $sampledName);
            $row['online'] = true;

            $output[] = $row;
        }

        $pingOk = $status !== null;

        $max = $pingOk
            ? intval($status['players']['max'] ?? self::FALLBACK_MAX_PLAYERS)
            : $this->guessMaxFromProperties($server) ?? self::FALLBACK_MAX_PLAYERS;

        $online = $pingOk
            ? max(intval($status['players']['online'] ?? 0), $matchedCount)
            : $matchedCount;

        return [$output, ['online' => $online, 'max' => $max, 'ping_ok' => $pingOk]];
    }

    /**
     * Last-resort max-player estimate reading server.properties directly — guarded
     * per guide §7 because PropertiesParser may land in a parallel build wave.
     */
    private function guessMaxFromProperties(Server $server): ?int
    {
        if (! class_exists(\McToolkit\Services\Properties\PropertiesParser::class)) {
            return null;
        }

        try {
            $properties = app(\McToolkit\Services\Properties\PropertiesParser::class)->read($server);

            $raw = $properties['values']['max-players'] ?? null;

            return is_numeric($raw) ? intval($raw) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Ban heuristics: reason + approximated last-seen stamp from ban metadata. */
    private function banFacts(array $entry): array
    {
        $reason = $this->nullableString($entry['reason'] ?? null);
        $created = $this->nullableString($entry['created'] ?? null);

        // Only genuine ban entries carry BOTH created + expires stamps — applying the
        // heuristic to whitelist/ops/usercache rows would fabricate a fake activity date.
        // `expires: "forever"` still counts as observed activity evidence.
        $isBanEntry = isset($entry['created'], $entry['expires']);

        return [
            'reason' => $reason,
            'last_seen' => ($isBanEntry && $created !== null) ? $created : null,
        ];
    }

    /** Tolerant reader: missing / unparsable / wrong-shaped files become empty lists. */
    private function readEntryList(Server $server, string $file): array
    {
        try {
            $decoded = $this->files->readJson($server, '/' . $file);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? array_values(Arr::wrap($decoded)) : [];
    }

    private function freshRow(string $uuid, string $name): array
    {
        return [
            'uuid' => $uuid,
            'uuid_key' => strtolower(str_replace('-', '', $uuid)),
            'name' => $name,
            'op' => false,
            'whitelisted' => false,
            'banned' => false,
            'ban_reason' => null,
            'last_seen' => null,
            'online' => false,
        ];
    }

    /** Online-first then name sort, mirroring how the demo lays out its cards. */
    private function sorted(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $onlineDelta = intval($b['online']) <=> intval($a['online']);

            if ($onlineDelta !== 0) {
                return $onlineDelta;
            }

            return strcasecmp(strval($a['name']), strval($b['name']));
        });

        return $rows;
    }

    private function canonicalUuid(mixed $uuid): string
    {
        $clean = strtolower(str_replace('-', '', strval($uuid)));

        if ($clean === '') {
            return '';
        }

        return $this->dashOut($clean);
    }

    /** Re-dashes a compressed uuid ("8667ba71b85a...") into display form. */
    private function dashOut(string $dashless): string
    {
        if (strlen($dashless) !== 32 || preg_match('/^[0-9a-f]{32}$/', $dashless) !== 1) {
            return $dashless; // non-canonical ids are passed through untouched
        }

        return substr($dashless, 0, 8) . '-'
            . substr($dashless, 8, 4) . '-'
            . substr($dashless, 12, 4) . '-'
            . substr($dashless, 16, 4) . '-'
            . substr($dashless, 20);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
