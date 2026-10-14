<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages\Concerns;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use McToolkit\Filament\Server\Pages\VersionsPage;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\ContentInstallerService;
use McToolkit\Services\Catalog\ContentSearchService;
use McToolkit\Services\Content\UpdateScanner;
use McToolkit\Services\DetectionService;
use McToolkit\Support\Activity;
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
 *   - blockedNotice():      ?array{title:?string, message:string, versions_url:?string}
 *
 * Sorting: every registry answers in its own relevance order and ContentSearchService
 * merges them round-robin, so "Relevance" keeps that blend while "Newest" and
 * "Downloads" re-sort the merged page locally (upstream indexes are not remapped).
 *
 * Authorization (contract matrix): page access needs 'minecraft-toolkit.use' AND
 * 'minecraft-toolkit.content' on a server that is not in a conflict state
 * ({@see self::canAccessContentCatalog()}, re-checked by Filament on every Livewire
 * request); install/update/forget additionally require 'minecraft-toolkit.manage'.
 * No content mutation is destructive ⇒ 'administer' is never demanded here.
 *
 * Wire safety: actions are called with ids only (Js::from-encoded in the views) and
 * every title, registry or project id is resolved server-side again.
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

    /** Relevance | newest | downloads — see class docblock. */
    public string $sortKey = 'relevance';

    /** "Compatible with my server" filter flag. */
    public bool $compatOnly = false;

    /** Per-request memos; Livewire never persists private state between requests. */
    private ?array $memoizedBrowse = null;

    private ?array $memoizedInstalled = null;

    private ?array $memoizedDetection = null;

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

    /**
     * Shared canAccess() body of the three catalog pages (the page adds its own
     * parent::canAccess() + enabled-page switch). Doubles as the per-request gate:
     * Filament re-runs canAccess() while hydrating every Livewire call.
     */
    protected static function canAccessContentCatalog(): bool
    {
        $tenant = Filament::getTenant();
        $user = user();

        return $tenant instanceof Server
            && $user !== null
            && !$tenant->isInConflictState()
            && $user->can('minecraft-toolkit.use', $tenant)
            && $user->can('minecraft-toolkit.content', $tenant);
    }

    protected function contentServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /** Read access to the catalog (page-level 'use' + 'content'). */
    public function userCanBrowse(): bool
    {
        $user = user();
        $server = $this->contentServer();

        return $user !== null
            && $user->can('minecraft-toolkit.use', $server)
            && $user->can('minecraft-toolkit.content', $server);
    }

    /**
     * Whether the current user may mutate content on this server
     * (domain perm AND manage, per the contract matrix).
     *
     * PUBLIC — read directly by the shared blade body while gating CTAs.
     */
    public function userCanMutate(): bool
    {
        $user = user();

        return $this->userCanBrowse()
            && $user !== null
            && $user->can('minecraft-toolkit.manage', $this->contentServer());
    }

    /**
     * Whether Install CTAs make sense here: the Mods page refuses while a Bukkit-family
     * server is detected (its blocker callout explains why). Enforced server-side too.
     */
    public function installsAllowed(): bool
    {
        return $this->contentKind() !== 'mods' || $this->blockedNotice() === null;
    }

    /* ------------------------------------------------------------------ */
    /*  Detection-driven helpers                                           */
    /* ------------------------------------------------------------------ */

    /**
     * DetectionService payload (cached upstream for 15 minutes; memoized per request).
     *
     * @return array<string, mixed>
     */
    public function contentDetection(): array
    {
        if ($this->memoizedDetection !== null) {
            return $this->memoizedDetection;
        }

        try {
            return $this->memoizedDetection = app(DetectionService::class)->detect($this->contentServer());
        } catch (Throwable) {
            // Node unreachable — same generic shape as DetectionService's uncached failure.
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
     * DetectionService compat families.
     *
     * @return list<string>
     */
    public function compatLoaderTokens(): array
    {
        $detected = $this->contentDetection();
        $loader = (string) ($detected['loader'] ?? 'vanilla');

        // A modpack brings (and now installs) its own loader, so on a plugin or vanilla server
        // the compat filter only narrows by Minecraft version — plugin loaders match no pack.
        if ($this->contentKind() === 'modpacks' && ! in_array($loader, ['fabric', 'forge'], true)) {
            return [];
        }

        return match ($loader) {
            'bukkit' => ['paper', 'spigot', 'bukkit', 'purpur', 'folia'],
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
        // Spiget rows are tracked as 'spigotmc'; the label lives under the chip key.
        $source = strtolower(trim($source)) === 'spigotmc' ? 'spigot' : strtolower(trim($source));

        foreach (["minecraft-toolkit::pages.{$this->langSection()}.sources.{$source}", "minecraft-toolkit::pages.plugins.sources.{$source}"] as $key) {
            $label = trans($key);

            if (is_string($label) && $label !== $key) {
                return $label;
            }
        }

        return ucfirst($source);
    }

    /**
     * Human text for one skip reason coming back from ContentSearchService.
     *
     * Mapping contract: rate_limited has a real errors.* entry; no_key/request_error
     * have NO exact translation in the frozen lang tree yet — they render constant English.
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
     * carries no sort words yet (lang-gap note).
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
     * Every item gains: 'installed' (exact registry|project_id match against the
     * tracker), 'key' (morph-stable hash) and 'updated_human'.
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
        return $this->memoizedBrowse ??= $this->runBrowseQuery();
    }

    /**
     * @return array{items: list<array<string, mixed>>, pages: ?int, sources_used: list<string>, sources_skipped: array<string, string>}
     */
    private function runBrowseQuery(): array
    {
        $trackedProjects = $this->trackedProjectIndex();
        $items = [];

        $compat = null;

        if ($this->compatOnly) {
            $loader = (string) ($this->contentDetection()['loader'] ?? '');

            $compat = [
                'loader' => match ($loader) {
                    'bukkit' => 'paper',
                    'fabric' => 'fabric',
                    'forge' => 'forge',
                    'velocity' => 'velocity',
                    default => '',
                },
                'mc_version' => $this->compatMcVersion() ?? '',
            ];
        }

        $source = $this->source !== null && in_array($this->source, $this->allowedSources(), true) ? $this->source : null;

        try {
            $payload = app(ContentSearchService::class)->search(
                $this->contentKind(),
                $source,
                mb_substr(trim($this->query), 0, 100),
                max(1, $this->browsePage),
                $compat,
            );
        } catch (Throwable) {
            // Adapters already isolate their own transport failures; reaching here means
            // an unexpected shape — degrade into the empty state rather than break render.
            return ['items' => [], 'pages' => null, 'sources_used' => [], 'sources_skipped' => []];
        }

        foreach ($payload['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            // "Compatible with my server": sources whose facets already narrowed upstream
            // only mark items explicitly — drop hard-incompatible items so the filter holds
            // on every registry.
            if ($this->compatOnly && ($item['compatible'] ?? true) === false) {
                continue;
            }

            $projectKey = UpdateScanner::keyFor((string) ($item['source'] ?? ''), (string) ($item['id'] ?? ''));

            $item['key'] = hash('crc32b', $projectKey);
            $item['installed'] = isset($trackedProjects[$projectKey]);
            $item['updated_human'] = $this->shortDate($item['updated'] ?? null);

            $items[] = $item;
        }

        $sorted = match ($this->sortKey) {
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
     * Stamps every element with its blend position so the comparator never flips equal
     * keys between renders (Livewire morphing loves us for it).
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

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $ts = strtotime(trim($raw));

        return $ts === false ? null : $ts;
    }

    /**
     * `registry|project_id` keys of every standalone project tracked for this kind
     * (pack files carry the PACK's id and are left out).
     *
     * @return array<string, true>
     */
    private function trackedProjectIndex(): array
    {
        $out = [];

        try {
            /** @var Collection<int, InstalledContent> $rows */
            $rows = InstalledContent::query()
                ->forServer($this->contentServer())
                ->where('kind', $this->installerKind())
                ->get();
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            if (!$row->isPackChild()) {
                $out[UpdateScanner::keyFor($row->registry, (string) $row->project_id)] = true;
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
        return count($this->installedPayload()['primary']);
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
        return $this->memoizedInstalled ??= $this->buildInstalledPayload();
    }

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
            if (!$row instanceof InstalledContent) {
                continue;
            }

            $isPackChild = $row->isPackChild();

            $scan = $scanMap[UpdateScanner::keyFor($row->registry, (string) $row->project_id)] ?? null;
            $latestTs = is_array($scan) ? ($scan['latest_ts'] ?? null) : null;
            $baselineTs = $row->updated_at instanceof Carbon ? $row->updated_at->getTimestamp() : null;

            // Pack files share the pack's probe; only their umbrella row offers the update.
            $available = !$isPackChild
                && (bool) $row->tracked_by_toolkit
                && is_int($latestTs) && is_int($baselineTs) && $latestTs > $baselineTs;

            // Pack files are listed by their own jar name (the "+pack" chip names the origin).
            $title = $isPackChild ? '' : $row->displayTitle();

            $entry = [
                'row_id' => (int) $row->getKey(),
                'file_name' => (string) $row->file_name,
                'display_name' => $title !== '' ? $title : basename(str_replace('\\', '/', (string) $row->file_name)),
                'version' => $row->version,
                'registry' => (string) ($row->registry ?? ''),
                'registry_label' => $this->sourceLabel((string) ($row->registry ?? '')),
                'updated_at_human' => $row->updated_at instanceof Carbon ? $row->updated_at->translatedFormat('M j, Y') : null,
                'update_available' => $available,
                'update_time' => $available ? Carbon::createFromTimestampUTC($latestTs)->translatedFormat('M j, Y H:i') : null,
                'is_pack_child' => $isPackChild,
                'installable' => !$isPackChild, // child rows reinstall WITH their pack umbrella only
                'requires' => $this->packRequirementNote($row),
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
            if (!is_array($entry)) {
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
     * UpdateScanner::keyFor() → ['latest_ts' => ?int, 'latest_version' => ?string].
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

    /**
     * "Needs Fabric 0.15.11 for Minecraft 1.20.1" style note for a modpack umbrella row whose
     * requirements do not match the detected software; null otherwise.
     */
    private function packRequirementNote(InstalledContent $row): ?string
    {
        if ($row->kind !== InstalledContent::KIND_MODPACK) {
            return null;
        }

        $deps = is_array($row->dependencies) ? $row->dependencies : [];
        $requires = $deps['summary']['requires'] ?? null;

        if (!is_array($requires) || ($requires['matches_server'] ?? true) !== false) {
            return null;
        }

        $loader = is_string($requires['loader'] ?? null) ? ucfirst($requires['loader']) : 'Vanilla';

        if (is_string($requires['loader_version'] ?? null) && $requires['loader_version'] !== '') {
            $loader .= ' '.$requires['loader_version'];
        }

        $note = trans('minecraft-toolkit::pages.modpacks.requires', [
            'loader' => $loader,
            'version' => is_string($requires['minecraft'] ?? null) ? $requires['minecraft'] : '?',
        ]);

        return is_string($note) ? $note : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Actions                                                            */
    /* ------------------------------------------------------------------ */

    /** Livewire event caught from the shared detection-banner component. */
    public function redetect(): void
    {
        if (!$this->userCanBrowse()) {
            $this->denyNotification();

            return;
        }

        $server = $this->contentServer();

        try {
            app(DetectionService::class)->detect($server, true);

            Activity::event('mc-toolkit:detection.refresh')->subject($server)->log();
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
            ->body(trim(sprintf('%s %s', ucfirst((string) ($this->contentDetection()['software'] ?? '')), $this->compatMcVersion() ?? '')))
            ->success()
            ->send();
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab === 'installed' ? 'installed' : 'browse';
        $this->bustCaches();
    }

    public function runSearch(): void
    {
        $this->browsePage = 1;
        $this->bustCaches();
    }

    public function previousPage(): void
    {
        $this->browsePage = max(1, $this->browsePage - 1);
        $this->bustCaches();
    }

    public function nextPage(): void
    {
        $maxPage = $this->browsePayload()['pages'];

        $this->browsePage = $maxPage !== null ? min(max(1, $maxPage), $this->browsePage + 1) : $this->browsePage + 1;
        $this->bustCaches();
    }

    public function setSource(?string $source): void
    {
        $candidate = $source === null || trim($source) === '' ? null : strtolower(trim($source));
        $this->source = in_array($candidate, $this->allowedSources(), true) ? $candidate : null;

        $this->browsePage = 1;
        $this->bustCaches();
    }

    public function toggleCompat(): void
    {
        $this->compatOnly = !$this->compatOnly;

        $this->browsePage = 1;
        $this->bustCaches();
    }

    /**
     * Header Refresh: re-probe updates and recompute; managers also reconcile tracked
     * rows whose files vanished (that prunes database rows, hence the manage gate).
     *
     * Named refreshContent() so Filament's own BasePage::refresh() (#[On('refresh-page')])
     * keeps its meaning.
     */
    public function refreshContent(): void
    {
        if (!$this->userCanBrowse()) {
            $this->denyNotification();

            return;
        }

        $server = $this->contentServer();

        if ($this->userCanMutate()) {
            try {
                $this->installer()->forgetMissing($server);
            } catch (Throwable) {
                // Node hiccup must never turn Refresh into a toast storm — silent per R18 discipline.
            }
        }

        $this->forgetUpdateScan();
        $this->bustCaches();
    }

    /**
     * Install one Browse-grid card. Only ids travel over the wire; the title is looked up
     * again from the (cached) catalog page, never trusted from the client.
     */
    public function install(string $source, string $projectId): void
    {
        if (!$this->userCanMutate() || !$this->installsAllowed()) {
            $this->denyNotification();

            return;
        }

        $source = strtolower(trim($source));
        $projectId = trim($projectId);
        $chip = $source === 'spigotmc' ? 'spigot' : $source;

        if ($projectId === '' || !in_array($chip, $this->allowedSources(), true)) {
            $this->denyNotification();

            return;
        }

        $server = $this->contentServer();

        try {
            $row = $this->installer()->installProject($server, $this->installerKind(), [
                'source' => $source,
                'id' => $projectId,
                'title' => $this->catalogTitle($source, $projectId),
            ], $this->versionMeta());

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.install'))
                ->body($row->displayTitle() !== '' ? $row->displayTitle() : $row->file_name)
                ->success()
                ->send();

            $this->warnAboutPackRequirements($row);

            $this->tab = 'installed';
        } catch (Throwable $exception) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.download_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->forgetUpdateScan();
            $this->bustCaches();
        }
    }

    /**
     * Reinstall the newest matching build of the project behind one tracked row. The row
     * is looked up by id on THIS server and kind; registry, project id and title come from
     * the database, never from the client. A changed file name retires the old file.
     */
    public function updateTracked(int $rowId): void
    {
        if (!$this->userCanMutate()) {
            $this->denyNotification();

            return;
        }

        $server = $this->contentServer();

        $row = InstalledContent::query()
            ->forServer($server)
            ->where('kind', $this->installerKind())
            ->whereKey($rowId)
            ->first();

        if (!$row instanceof InstalledContent || $row->isPackChild() || !$row->tracked_by_toolkit) {
            $this->denyNotification();

            return;
        }

        $title = $row->displayTitle() !== '' ? $row->displayTitle() : basename((string) $row->file_name);

        try {
            $updated = $this->installer()->installProject($server, $this->installerKind(), [
                'source' => (string) $row->registry,
                'id' => (string) $row->project_id,
                'title' => $title,
            ], $this->versionMeta(), isUpdate: true);

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.update'))
                ->body($title.($updated->version !== null ? ' — '.$updated->version : ''))
                ->success()
                ->send();

            $this->warnAboutPackRequirements($updated);
        } catch (Throwable $exception) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.update'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->forgetUpdateScan();
            $this->bustCaches();
        }
    }

    /**
     * Loader/game-version hints for installer resolution: the detected software token
     * (purpur, quilt, velocity, …) plus its loader family; the installer maps those to
     * every loader tag that server can actually run.
     *
     * @return array{software: string, loader: string, mc_version: string}
     */
    private function versionMeta(): array
    {
        $detected = $this->contentDetection();

        return [
            'software' => strtolower((string) ($detected['software'] ?? 'unknown')),
            'loader' => strtolower((string) ($detected['loader'] ?? 'vanilla')),
            'mc_version' => $this->compatMcVersion() ?? '',
        ];
    }

    /** Title of a catalog item on the current browse page, '' when it is not there. */
    private function catalogTitle(string $source, string $projectId): string
    {
        $wanted = UpdateScanner::keyFor($source, $projectId);

        foreach ($this->browsePayload()['items'] as $item) {
            if (UpdateScanner::keyFor((string) ($item['source'] ?? ''), (string) ($item['id'] ?? '')) === $wanted) {
                return trim((string) ($item['title'] ?? ''));
            }
        }

        return '';
    }

    /** Modpack installs onto the wrong software still succeed — say what the pack needs. */
    private function warnAboutPackRequirements(InstalledContent $row): void
    {
        $note = $this->packRequirementNote($row);

        if ($note !== null) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.modpacks.title'))
                ->body($note)
                ->warning()
                ->persistent()
                ->send();
        }
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

    private function forgetUpdateScan(): void
    {
        try {
            app(UpdateScanner::class)->forget($this->contentServer());
        } catch (Throwable) {
            // Cache hiccup: the snapshot simply expires on its own.
        }
    }

    /** Clear the per-request memos so the next read recomputes. */
    protected function bustCaches(): void
    {
        $this->memoizedBrowse = null;
        $this->memoizedInstalled = null;
        $this->memoizedDetection = null;
    }

    /** Sanitize client-writable properties and restart paging when the result set changes. */
    public function updated(string $property): void
    {
        if ($property === 'tab') {
            $this->tab = $this->tab === 'installed' ? 'installed' : 'browse';
        }

        if ($property === 'sortKey' && !in_array($this->sortKey, array_column($this->sortOptions(), 'value'), true)) {
            $this->sortKey = 'relevance';
        }

        if ($property === 'source' && $this->source !== null && !in_array($this->source, $this->allowedSources(), true)) {
            $this->source = null;
        }

        if ($property === 'query') {
            $this->query = mb_substr($this->query, 0, 100);
        }

        // A new search, source or filter is a new result set: page 5 of the old one is meaningless.
        if (in_array($property, ['query', 'source', 'compatOnly'], true)) {
            $this->browsePage = 1;
        }

        $this->browsePage = max(1, $this->browsePage);

        $this->bustCaches();
    }

    /* ------------------------------------------------------------------ */
    /*  Presentational helpers (view-facing)                               */
    /* ------------------------------------------------------------------ */

    /** Cross-link to Versions for blocked-state guidance (guarded, our own class space). */
    public function versionsUrl(): ?string
    {
        if (!class_exists(VersionsPage::class)) {
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
        $decimals = $scaled >= 10 || $power === 0 ? 0 : 1;
        $formatted = number_format($scaled, $decimals);

        // Only a fractional part may lose trailing zeros ("2.0" → "2"); "10" or "250" must stay.
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted.' '.$units[$power];
    }

    /** Compact locale-safe date chip ("Oct 14, 2026") for cards; null-safe. */
    public function shortDate(mixed $raw): ?string
    {
        $ts = $this->timestampOf($raw);

        return $ts === null ? null : Carbon::createFromTimestampUTC($ts)->translatedFormat('M j, Y');
    }

    /** Detection source-of-truth count that feeds the Installed badge consistently. */
    public function trackedTotalAllKinds(): int
    {
        try {
            return InstalledContent::query()->forServer($this->contentServer())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /** Status gate recheck for install-time modpack prerequisites preview callouts. */
    public function containerRequiresStop(): bool
    {
        try {
            $live = $this->contentServer()->retrieveStatus();

            return !in_array($live, [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Created], true);
        } catch (Throwable) {
            return false;
        }
    }
}
