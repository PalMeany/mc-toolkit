<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages\Concerns;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use McToolkit\Filament\Server\Pages\VersionsPage;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\ContentInstallerService;
use McToolkit\Services\Catalog\ContentSearchService;
use McToolkit\Services\Content\UpdateScanner;
use McToolkit\Services\DetectionService;
use McToolkit\Support\Activity;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Shared engine behind the Plugins / Mods / Modpacks catalog pages.
 *
 * Everything interactive lives here so all three pages stay behaviourally identical
 * (demo parity: segmented tabs, debounced search, source chips, compat filter,
 * result grid, tracked-vs-foreign installed split, quiet-update mechanic).
 *
 * Subclasses MUST define:
 *   - contentKind():        'plugins'|'mods'|'modpacks'  (ContentSearchService kind id)
 *   - installerKind():      'plugin'|'mod'|'modpack'     (InstalledContent / installer kind id)
 *   - langSection():        'plugins'|'mods'|'modpacks'  (pages.* namespace slice)
 *   - blockedNotice():      ?array{message:string, versionsUrl:?string|null} | null
 *
 * Sorting honesty (documented limitation): every registry adapter fetches its own
 * default index upstream (Modrinth relevance, CurseForge popularity, Hangar stars,
 * Spiget update-date on empty queries) and its frozen signature exposes no sort
 * parameter, so "Newest" and "Downloads" RE-SORT the merged multi-source result set
 * locally instead of remapping upstream indexes. The SpigotMC slice is closest to a
 * true date order because its empty-query listing arrives sorted by updateDate.
 *
 * Authorization (contract matrix): this whole domain reads through
 * 'minecraft-toolkit.content' (authorizeAccess) on top of page 'use'
 * (canAccess, defined per page class); install/update additionally require
 * 'minecraft-toolkit.manage'. No content mutation is destructive ⇒ 'administer'
 * is never demanded here (worlds ops own that rule).
 */
trait InteractsWithContent
{
    /* ------------------------------------------------------------------ */
    /*  Livewire state                                                     */
    /* ------------------------------------------------------------------ */

    /** Segmented control: browse | installed (demo tabs). */
    public string $tab = 'browse';

    /** Debounced search box contents. */
    public string $query = '';

    /** Current catalog page (merged multi-source pagination is approximate). */
    public int $browsePage = 1;

    /** Restrict results to one registry chip (null = all enabled sources). */
    public ?string $source = null;

    /** Relevance | newest | downloads — see class docblock sorting honesty. */
    public string $sortKey = 'relevance';

    /** "Compatible with my server" filter flag. */
    public bool $compatOnly = false;

    /** Busy markers (hex keys) rendered into per-card :disabled bindings. */
    public array $installingIds = [];

    /** Tracked file names currently running an update. */
    public array $updatingNames = [];

    /** Per-request memo flags; invalidated whenever an input or data layer changes. */
    private bool $browseDirty = true;

    private bool $installedDirty = true;

    /* ------------------------------------------------------------------ */
    /*  Per-page identity                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * ContentSearchService kind id: plugins | mods | modpacks.
     */
    abstract public function contentKind(): string;

    /**
     * Installer/InstalledContent kind id: plugin | mod | modpack.
     */
    abstract protected function installerKind(): string;

    /**
     * pages.<section>.* translation slice.
     */
    abstract protected function langSection(): string;

    /**
     * Loader-mismatch / no-toolkit parity callout, or null when the page runs free.
     *
     * PUBLIC because the shared blade body reads it during render ($this is the
     * hosting component there; PHP visibility would otherwise throw).
     *
     * @return array{title:?string, message:string, versions_url:?string}|null
     */
    abstract public function blockedNotice(): ?array;

    /* ------------------------------------------------------------------ */
    /*  Server + permission plumbing                                       */
    /* ------------------------------------------------------------------ */

    protected function contentServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /**
     * Whether the current user may mutate content on this server
     * (domain perm AND manage, per the contract matrix).
     *
     * PUBLIC — read directly by the shared blade body while gating CTAs.
     */
    public function userCanMutate(): bool
    {
        $server = $this->contentServer();
        $user = user();

        return filled($user)
            && (bool) $user->can('minecraft-toolkit.content', $server)
            && (bool) $user->can('minecraft-toolkit.manage', $server);
    }

    /* ------------------------------------------------------------------ */
    /*  Detection-driven helpers                                           */
    /* ------------------------------------------------------------------ */

    /**
     * DetectionService payload (cached upstream for 15 minutes; force=off here).
     *
     * @return array<string, mixed>
     */
    public function contentDetection(): array
    {
        try {
            return app(DetectionService::class)->detect($this->contentServer());
        } catch (Throwable) {
            // Node unreachable — banner maps to gateway_down copy itself via the
            // generic shape below (matches DetectionService's uncached failure shape).
            return [
                'software' => 'unknown',
                'loader' => 'vanilla',
                'version' => null,
                'java' => null,
                'detected_by' => 'files',
                'proxy' => false,
                'confidence' => 0.0,
            ];
        }
    }

    /**
     * Loader tokens shown inside the compat-filter label, derived from the
     * DetectionService compat families:
     *   bukkit={paper,spigot,bukkit,purpur,leaves,pufferfish,folia,mohist,arclight,sponge}
     *   fabric={fabric,quilt}   forge={forge,neoforge}   proxies={velocity,waterfall,bungee*}.
     *
     * @return list<string>
     */
    public function compatLoaderTokens(): array
    {
        $detected = $this->contentDetection();

        return match ((string) ($detected['loader'] ?? 'vanilla')) {
            'bukkit' => ['paper', 'spigot', 'bukkit', 'purpur', 'leaves', 'pufferfish', 'folia', 'mohist', 'arclight', 'sponge'],
            'fabric' => ['fabric', 'quilt'],
            'forge' => ['forge', 'neoforge'],
            'velocity' => ['velocity', 'waterfall', 'bungeecord'],
            default => [],
        };
    }

    /**
     * Detected MC version feeding the compat chip + install versionMeta hints.
     */
    public function compatMcVersion(): ?string
    {
        $version = $this->contentDetection()['version'] ?? null;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    /**
     * Which registry chips the current kind may show, in fixed order.
     *
     * @return list<string> source keys identical to ContentSearchService SOURCES lists
     */
    public function allowedSources(): array
    {
        return match ($this->contentKind()) {
            'mods' => ['modrinth', 'curseforge'],
            'modpacks' => ['modrinth'],
            default => ['modrinth', 'hangar', 'spigot', 'curseforge'],
        };
    }

    /**
     * Localized chip label for a source key (falls back to the raw key for gaps).
     */
    public function sourceLabel(string $source): string
    {
        $key = trans("minecraft-toolkit::pages.{$this->langSection()}.sources.{$source}");
        $fallback = trans("minecraft-toolkit::pages.plugins.sources.{$source}");

        return is_string($key) && str_starts_with($key, 'minecraft-toolkit::')
            ? (is_string($fallback) && ! str_starts_with($fallback, 'minecraft-toolkit::')
                ? $fallback
                : ucfirst($source))
            : $key;
    }

    /**
     * Human text for one skip reason coming back from ContentSearchService.
     *
     * Mapping contract: rate_limited has a real errors.* entry; no_key/request_error
     * have NO exact translation in the frozen lang tree yet — they render constant
     * English and are listed in handoff notes as lang-gaps
     * (candidates: pages.content.skip.no_key / pages.content.skip.request_error).
     */
    public function skipReasonText(string $reason): string
    {
        return match ($reason) {
            'rate_limited' => trans('minecraft-toolkit::errors.statuses.rate_limited'),
            'no_key' => 'API key not configured',
            default => 'Request failed',
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Sorting                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Sort dropdown rows. Labels are constant English because the frozen lang tree
     * carries no sort words yet (lang-gap note; candidates: pages.content.sort.*).
     *
     * @return array<array{value: string, label: string}>
     */
    public function sortOptions(): array
    {
        return [
            ['value' => 'relevance', 'label' => 'Relevance'],
            ['value' => 'newest', 'label' => 'Newest'],
            ['value' => 'downloads', 'label' => 'Downloads'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Browse payload                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * One memoized merged-search pass powering the Browse grid.
     *
     * Every item gains: 'installed' (tracker presence hybrid),
     * 'key' (morph-stable hash), and stays untouched otherwise.
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pages: ?int,
     *     sources_used: list<string>,
     *     sources_skipped: array<string, string>,
     * }
     */
    public function browsePayload(): array
    {
        if (! $this->browseDirty && is_array($this->memoizedBrowse)) {
            return $this->memoizedBrowse;
        }

        $this->memoizedBrowse = $this->runBrowseQuery();
        $this->browseDirty = false;

        return $this->memoizedBrowse;
    }

    private ?array $memoizedBrowse = null;

    /**
     * @return array{items: list<array<string, mixed>>, pages: ?int, sources_used: list<string>, sources_skipped: array<string, string>}
     */
    private function runBrowseQuery(): array
    {
        $knownFiles = $this->trackedFileIndex();
        $items = [];

        $search = app(ContentSearchService::class);

        $compat = null;

        if ($this->compatOnly) {
            $loader = (string) ($this->contentDetection()['loader'] ?? '');
            $token = match (true) {
                $loader === 'bukkit' => 'paper',
                $loader === 'fabric' => 'fabric',
                $loader === 'forge' => 'forge',
                $loader === 'velocity' => 'velocity',
                default => '',
            };

            $compat = [
                'loader' => $token,
                'mc_version' => $this->compatMcVersion() ?? '',
            ];
        }

        try {
            $payload = $search->search($this->contentKind(), $this->source, $this->query, max(1, $this->browsePage), $compat);
        } catch (Throwable) {
            // Adapters already isolate their own transport failures; reaching here means
            // an unexpected shape — degrade into the empty state rather than break render.
            return ['items' => [], 'pages' => null, 'sources_used' => [], 'sources_skipped' => []];
        }

        foreach ($payload['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            // "Compatible with my server" checkbox: sources whose facets already
            // narrowed upstream only mark items explicitly (SpigotMC has no facet
            // support, so its rows arrive untagged) — drop hard-incompatible items
            // here so the filter holds true on every registry.
            if ($this->compatOnly && ($item['compatible'] ?? true) === false) {
                continue;
            }

            $item['key'] = hash('crc32b', ($item['source'] ?? '').'|'.($item['id'] ?? '').'|'.($item['title'] ?? ''));

            // Installed-flag HYBRID (contract wording): presence decided from tracker rows
            // OR from real directory naming overlap. Exact basename equality OR substring
            // containment of the project slug/id inside any tracked artifact name.
            $needle = mb_strtolower(trim((string) (($item['slug'] ?? '') !== '' ? $item['slug'] : ($item['id'] ?? ''))));
            $isInstalled = false;

            if ($needle !== '' && mb_strlen($needle) >= 3) {
                foreach (array_keys($knownFiles) as $fileName) {
                    if ($fileName === $needle || str_contains($fileName, $needle)) {
                        $isInstalled = true;

                        break;
                    }
                }
            }

            $item['installed'] = $isInstalled;

            $items[] = $item;
        }

        $sorted = match ($this->sortKey) {
            // Upstream feeds arrive relevance/popularity-ish ordered; re-sorts are LOCAL
            // (see trait docblock honesty note). Prefix-sort hack ties downloads and keeps
            // blended stability via array index stamp before the comparator.
            'downloads' => $this->stableSort($items, fn (array $a, array $b): int => ((int) ($b['downloads'] ?? 0)) <=> ((int) ($a['downloads'] ?? 0))),
            'newest' => $this->stableSort($items, function (array $a, array $b): int {
                $ta = $this->timestampOf($a['updated'] ?? null);
                $tb = $this->timestampOf($b['updated'] ?? null);

                return $tb <=> $ta; // desc; undated items sink to the bottom
            }),
            default => $items,
        };

        return [
            'items' => $sorted,
            'pages' => is_int($payload['pages']) ? $payload['pages'] : null,
            'sources_used' => $payload['sources_used'],
            'sources_skipped' => $payload['sources_skipped'],
        ];
    }

    /**
     * Downloads/newest ordering stamps every element with its blend position so the
     * comparator never flips equal keys between renders (Livewire morphing loves us for it).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function stableSort(array $items, callable $comparator): array
    {
        $stamp = [];

        foreach ($items as $i => $item) {
            $stamp[] = ['item' => $item, 'i' => $i];
        }

        usort($stamp, fn (array $a, array $b): int => $comparator($a['item'], $b['item']) ?: $a['i'] <=> $b['i']);

        return array_map(fn (array $pair) => $pair['item'], $stamp);
    }

    /** Flexible ISO/unix timestamp extractor ('updated' fields differ per adapter). */
    private function timestampOf(mixed $raw): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            return (int) $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $ts = strtotime(trim($raw));

        return $ts === false ? null : $ts;
    }

    /**
     * Lowercase basenames of every tracked row for this kind (hybrid presence oracle).
     *
     * @return array<string, true>
     */
    private function trackedFileIndex(): array
    {
        $out = [];

        foreach ($this->installer()->installed($this->contentServer(), $this->installerKind())['tracked'] as $row) {
            $base = mb_strtolower(basename(str_replace('\\', '/', (string) ($row->file_name ?? ''))));

            // The hybrid token-match needs the extension-free stem too ("EssentialsX-2.2.jar"
            // vs slug "essentialsx"), so stems ride alongside full names.
            $stem = mb_strtolower(preg_replace('/\.(jar|zip|mrpack)$/i', '', $base) ?? $base);

            if ($base !== '' && $base !== '.') {
                $out[$base] = true;
                $out[$stem] = true;
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /*  Installed payload                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * How many tracked rows this page's kind holds — drives the Installed tab badge.
     */
    public function installedCount(): int
    {
        $payload = $this->installedPayload();

        return count($payload['primary']);
    }

    /**
     * Split installed listing: toolkit-tracked primary rows (decorated with the
     * update-scan map) plus genuinely foreign directory files (name + human size).
     *
     * @return array{
     *     primary: list<array<string, mixed>>,
     *     foreign: list<array{name: string, human_size: string}>,
     * }
     */
    public function installedPayload(): array
    {
        if (! $this->installedDirty && is_array($this->memoizedInstalled)) {
            return $this->memoizedInstalled;
        }

        $this->memoizedInstalled = $this->buildInstalledPayload();
        $this->installedDirty = false;

        return $this->memoizedInstalled;
    }

    private ?array $memoizedInstalled = null;

    /**
     * @return array{primary: list<array<string, mixed>>, foreign: list<array{name: string, human_size: string}>}
     */
    private function buildInstalledPayload(): array
    {
        try {
            $listing = $this->installer()->installed($this->contentServer(), $this->installerKind());
        } catch (Throwable) {
            return ['primary' => [], 'foreign' => []];
        }

        $scanMap = $this->updatesScanMap();
        $seenKeys = [];
        $primary = [];

        foreach ($listing['tracked'] as $row) {
            if (! $row instanceof InstalledContent) {
                continue;
            }

            $scan = $scanMap[strtolower(($row->registry ?? '').'|'.($row->project_id ?? ''))] ?? null;
            $latestTs = is_array($scan) ? ($scan['latest_ts'] ?? null) : null;
            $baselineTs = $row->updated_at instanceof Carbon ? $row->updated_at->getTimestamp() : null;

            $available = is_int($latestTs) && is_int($baselineTs) && $latestTs > $baselineTs;

            $deps = is_array($row->dependencies) ? $row->dependencies : [];
            $title = is_string($deps['title'] ?? null) ? $deps['title'] : (is_string($deps['pack'] ?? null) ? $deps['pack'] : '');

            $isPackChild = is_string($deps['pack'] ?? null);

            $entry = [
                'row' => $row,
                'file_name' => (string) $row->file_name,
                'display_name' => $title !== '' ? $title : basename(str_replace('\\', '/', (string) $row->file_name)),
                'version' => $row->version,
                'registry' => (string) ($row->registry ?? ''),
                'registry_label' => $this->sourceLabel((string) ($row->registry ?? '')),
                'updated_at_human' => $row->updated_at instanceof Carbon ? $row->updated_at->translatedFormat('M j, Y') : null,
                'update_available' => $available && (bool) $row->tracked_by_toolkit,
                'update_time' => $available ? Carbon::createFromTimestampUTC($latestTs)->translatedFormat('M j, Y H:i') : null,
                'is_pack_child' => $isPackChild,
                'installable' => ! $isPackChild, // child rows reinstall WITH their pack umbrella only
                'busy' => in_array(md5((string) $row->file_name), $this->updatingNames, true),
            ];

            $dedupeKey = strtolower($entry['registry'].'|'.basename(strtolower($entry['file_name'])).'|'.(int) $isPackChild);

            if (isset($seenKeys[$dedupeKey])) {
                continue;
            }

            $seenKeys[$dedupeKey] = true;

            $primary[] = $entry;
        }

        $foreign = [];

        foreach ($listing['foreign'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $foreign[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'human_size' => $this->formatBytes(isset($entry['size']) && is_numeric($entry['size']) ? (int) $entry['size'] : null),
            ];
        }

        return ['primary' => $primary, 'foreign' => $foreign];
    }

    /**
     * Project-level update scan map (transient adapter probes, cached by UpdateScanner):
     * registry|project_id → ['latest_ts' => ?int, 'latest_version' => ?string].
     *
     * @return array<string, array{latest_ts: ?int, latest_version: ?string}>
     */
    private function updatesScanMap(): array
    {
        try {
            return app(UpdateScanner::class)->scan($this->contentServer());
        } catch (Throwable) {
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Actions                                                            */
    /* ------------------------------------------------------------------ */

    /** Livewire event caught by the shared detection-banner component. */
    public function redetect(): void
    {
        $server = $this->contentServer();

        try {
            app(DetectionService::class)->detect($server, true);

            Activity::event('mc-toolkit:detection.refresh')->log();
        } catch (Throwable) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            $this->bustCaches();

            return;
        }

        $this->bustCaches();

        Notification::make()
            ->title(trans('minecraft-toolkit::banner.detection.title_note'))
            ->body(sprintf('%s %s', ucfirst($this->contentDetection()['software'] ?? ''), $this->compatMcVersion() ?? ''))
            ->success()
            ->send();
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab === 'installed' ? 'installed' : 'browse';

        if ($this->tab === 'installed') {
            $this->installedDirty = true;
        }
    }

    public function runSearch(): void
    {
        $this->browsePage = 1;
        $this->browseDirty = true;
    }

    public function previousPage(): void
    {
        $this->browsePage = max(1, $this->browsePage - 1);
        $this->browseDirty = true;
    }

    public function nextPage(): void
    {
        $maxPage = $this->browsePayload()['pages'];

        $this->browsePage = $maxPage !== null ? min(max(1, $maxPage), $this->browsePage + 1) : $this->browsePage + 1;
        $this->browseDirty = true;
    }

    public function setSource(?string $source): void
    {
        $candidate = $source === null || trim($source) === '' ? null : strtolower(trim($source));
        $this->source = in_array($candidate, $this->allowedSources(), true) ? $candidate : null;

        $this->browsePage = 1;
        $this->browseDirty = true;
    }

    public function toggleCompat(): void
    {
        $this->compatOnly = ! $this->compatOnly;

        $this->browsePage = 1;
        $this->browseDirty = true;
    }

    /** Header Refresh: reconcile stale tracked rows, then recompute everything silently. */
    public function refresh(): void
    {
        $server = $this->contentServer();

        try {
            app(ContentInstallerService::class)->forgetMissing($server);
        } catch (Throwable) {
            // Node hiccup must never turn Refresh into a toast storm — silent per R18 discipline.
        }

        $this->bustCaches(true);
    }

    /**
     * Install one Browse-grid card ("Install" CTA on un-tracked projects).
     */
    public function install(string $source, string $projectId, ?string $title = null): void
    {
        $server = $this->contentServer();

        if (! $this->userCanMutate()) {
            $this->denyNotification();

            return;
        }

        $key = hash('crc32b', $source.'|'.$projectId.'|'.($title ?? ''));

        $this->installingIds[] = $key;

        try {
            $row = app(ContentInstallerService::class)->installProject($server, $this->installerKind(), [
                'source' => $source,
                'id' => $projectId,
                'title' => (string) ($title ?? ''),
            ], $this->versionMeta());

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.install'))
                ->body($row->file_name)
                ->success()
                ->send();

            $this->switchTab('installed');
        } catch (Throwable $exception) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.download_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->installingIds = array_values(array_diff($this->installingIds, [$key]));
            $this->bustCaches(true);
        }
    }

    /**
     * Quietly reinstall the SAME project backing one tracked row — overwrites the
     * same-named artifact in place (contract: update = re-run installer). Emits the
     * contract-defined mc-toolkit:content.update activity event.
     */
    public function updateTracked(string $fileName, string $registry, string $projectId, ?string $title = null): void
    {
        $server = $this->contentServer();

        if (! $this->userCanMutate()) {
            $this->denyNotification();

            return;
        }

        $nameKey = md5($fileName);

        $this->updatingNames[] = $nameKey;

        $safeRegistry = strtolower(trim($registry)) === 'spigot' ? 'spigotmc' : $registry;

        try {
            app(ContentInstallerService::class)->installProject($server, $this->installerKind(), [
                'source' => $safeRegistry,
                'id' => $projectId,
                'title' => (string) ($title ?? basename($fileName)),
            ], $this->versionMeta());

            Activity::event('mc-toolkit:content.update')
                ->property('title', (string) ($title ?? basename($fileName)))
                ->property('source', $safeRegistry)
                ->subject($server)
                ->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.update'))
                ->body($title ?? $fileName)
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.update'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->updatingNames = array_values(array_diff($this->updatingNames, [$nameKey]));
            $this->bustCaches(true);
        }
    }

    /**
     * Loader/game-version hints for installer resolution.
     * software tokens ARE valid registry category tags (paper/spigot/fabric/quilt/
     * forge/neoforge/folia/purpur/velocity…); vanilla/unknown/foreign shapes fall back to ''.
     *
     * @return array{loader: string, mc_version: string}
     */
    private function versionMeta(): array
    {
        $detected = $this->contentDetection();
        $software = strtolower((string) ($detected['software'] ?? 'unknown'));
        $loaderFamily = (string) ($detected['loader'] ?? 'vanilla');

        $registryUsable = match ($loaderFamily) {
            'bukkit' => $software !== 'vanilla' && $software !== 'unknown' ? $software : 'paper',
            'fabric' => $software !== 'vanilla' && $software !== 'unknown' ? $software : 'fabric',
            'forge' => $software !== 'vanilla' && $software !== 'unknown' ? $software : 'forge',
            'velocity' => $software !== 'vanilla' && $software !== 'unknown' ? $software : 'velocity',
            default => '',
        };

        return [
            'loader' => $registryUsable,
            'mc_version' => $this->compatMcVersion() ?? '',
        ];
    }

    /** Friendly perm-denied toast keeping abort() out of action paths. */
    private function denyNotification(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
            ->danger()
            ->send();
    }

    private function installer(): ContentInstallerService
    {
        return app(ContentInstallerService::class);
    }

    /** Clear both render memos (optionally with staleness recompute downstream). */
    protected function bustCaches(bool $rerun = false): void
    {
        $this->browseDirty = true;
        $this->installedDirty = true;
        $this->memoizedBrowse = null;
        $this->memoizedInstalled = null;

        if ($rerun) {
            $this->browsePayload();
            $this->installedPayload();
        }
    }

    /** Reflect livewire property edits back into the dirty flags + bounds. */
    public function updated(string $property): void
    {
        if (in_array($property, ['query', 'source', 'sortKey', 'compatOnly', 'tab'], true)) {
            $this->browsePage = max(1, $this->browsePage);
            $this->browseDirty = true;
        }

        if ($property === 'tab' || $property === 'query' || $property === 'source') {
            $this->installedDirty = true;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Presentational helpers (view-facing)                               */
    /* ------------------------------------------------------------------ */

    /** Cross-link to Versions for blocked-state guidance (guarded, our own class space). */
    public function versionsUrl(): ?string
    {
        if (! class_exists(VersionsPage::class)) {
            return null;
        }

        try {
            return VersionsPage::getUrl(panel: 'server', tenant: $this->contentServer());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * KB/MB/GB rounding formatter for the foreign-files listing
     * (demo shows human sizes next to the orphan jars).
     */
    public function formatBytes(?int $bytes): string
    {
        if ($bytes === null || $bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = max(0, min(count($units) - 1, $power));

        $scaled = $bytes / (1024 ** $power);

        return rtrim(rtrim(number_format($scaled, $scaled >= 10 || $power === 0 ? 0 : 1), '0'), '.').' '.$units[$power];
    }

    /** Compact locale-safe date chip ("Updated Oct 14") for cards; null-safe. */
    public function shortDate(mixed $raw): ?string
    {
        $ts = $this->timestampOf($raw);

        return $ts === null ? null : Carbon::createFromTimestampUTC($ts)->translatedFormat('M j, Y');
    }

    /** Detection source-of-truth count that feeds the Installed badge consistently. */
    public function trackedTotalAllKinds(): int
    {
        $server = $this->contentServer();

        try {
            /** @var Collection<int, InstalledContent> $rows */
            $rows = InstalledContent::query()->forServer($server)->get();
        } catch (Throwable) {
            return 0;
        }

        return $rows->count();
    }

    /** Status gate recheck for install-time modpack prerequisites preview callouts. */
    public function containerRequiresStop(): bool
    {
        try {
            $live = $this->contentServer()->retrieveStatus();

            return ! in_array($live, [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Created], true);
        } catch (Throwable) {
            return false;
        }
    }
}
