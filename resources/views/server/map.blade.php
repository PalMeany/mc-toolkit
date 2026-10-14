{{-- Minecraft Toolkit — server Map page (parity build; see demo-findings.md §Map) --}}
<x-filament-panels::page>
    {{-- Detection summary pinned to the top of EVERY toolkit page ------------------- --}}
    <x-dynamic-component
        :component="'minecraft-toolkit::detection-banner'"
        :software="$software"
    />

    <x-minecraft-toolkit::bridge-callout
        :bridge="$this->bridgeStatus"
        :can-install="$this->canInstallBridge()"
        :can-restart="$this->canRestartForBridge()"
        :context="__('minecraft-toolkit::pages.map.bridge_context')"
    />

    @if (filled($this->selectedLabel()))
        <p class="text-sm leading-6 text-gray-500 dark:text-gray-400">
            {{ __('minecraft-toolkit::pages.map.render_note', ['world' => $this->selectedLabel()]) }}
        </p>
    @endif

    <div class="mt-4 grid items-start gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
        {{-- =========================================================== --}}
        {{-- LEFT CONTROL STACK                                          --}}
        {{-- =========================================================== --}}
        <div class="flex flex-col gap-4">

            {{-- Map targets (world folder × dimension) -------------------- --}}
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <p class="flex items-center justify-between text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('minecraft-toolkit::pages.worlds.title') }}
                        <span class="{{ 'inline-flex items-center rounded-md bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20' }}">
                            {{ count($worlds) }}
                        </span>
                    </p>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($worlds as $world)
                        <button
                            type="button"
                            wire:key="map-target-{{ md5($world['key']) }}"
                            wire:click="selectWorld(@js($world['key']))"
                            @class([
                                'flex w-full items-center justify-between gap-2 px-4 py-2.5 text-start text-sm transition',
                                'bg-gray-50 dark:bg-white/5' => $selectedWorld === $world['key'],
                                'hover:bg-gray-50 dark:hover:bg-white/5' => $selectedWorld !== $world['key'],
                            ])
                        >
                            <span class="inline-flex min-w-0 items-center gap-x-2">
                                <span
                                    class="size-3.5 shrink-0 rounded-full ring-2 ring-white/10"
                                    style="background: {{ ['overworld' => '#22c55e', 'nether' => '#ef4444', 'end' => '#a855f7'][$world['dimension']] ?? '#f59e0b' }}"
                                ></span>
                                <span class="truncate font-medium text-gray-950 dark:text-white">{{ $world['name'] }}</span>
                            </span>

                            <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ __('minecraft-toolkit::pages.map.dimensions.'.$world['dimension']) }}
                            </span>
                        </button>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('minecraft-toolkit::common.empty.nothing_here') }}
                        </p>
                    @endforelse
                </div>
            </div>

            {{-- Layer presets (switch rows; state rides showSpawn/showWorldBorder
                 through MapPage::togglePreset() so the mirror datasets + canvas stay wired) --}}
            <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('minecraft-toolkit::pages.map.layers.spawn') }} / {{ __('minecraft-toolkit::pages.map.layers.world_border') }}
                </p>

                <div class="mt-2 flex flex-col gap-1">
                    @foreach ([
                        ['spawn', $showSpawn, 'tabler-map-pin', __('minecraft-toolkit::pages.map.layers.spawn')],
                        ['border', $showWorldBorder, 'tabler-square-rounded', __('minecraft-toolkit::pages.map.layers.world_border')],
                    ] as [$layerKey, $layerOn, $layerIcon, $layerLabel])
                        <button type="button"
                                role="switch"
                                aria-checked="{{ $layerOn ? 'true' : 'false' }}"
                                wire:click="togglePreset('{{ $layerKey }}')"
                                class="flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-sm font-medium text-gray-700 transition duration-75 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5">
                            <span class="flex items-center gap-x-2">
                                <x-filament::icon :icon="$layerIcon" class="size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                                {{ $layerLabel }}
                            </span>

                            <span class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition duration-150 {{ $layerOn ? 'bg-primary-600 dark:bg-primary-500' : 'bg-gray-200 dark:bg-gray-700' }}">
                                <span class="absolute top-0.5 size-4 rounded-full bg-white shadow-sm transition-all duration-150 {{ $layerOn ? 'left-4.5' : 'left-0.5' }}"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Persisted markers ------------------------------------------ --}}
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('minecraft-toolkit::pages.map.layers.markers') }}
                        <span class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">({{ count($markers) }})</span>
                    </p>
                    @if ($this->canManageMarkers() && $selectedWorld !== '')
                        {{-- opens the mounted `add_marker` Filament Action modal (schema fields) --}}
                        <x-filament::button
                            size="xs"
                            color="gray"
                            icon="tabler-plus"
                            wire:click="mountAction('add_marker')"
                        />
                    @endif
                </div>

                <ul class="divide-y divide-gray-200 dark:divide-white/10" wire:key="marker-list-{{ md5($selectedWorld) }}-{{ count($markers) }}">
                    @forelse ($markers as $marker)
                        <li class="group flex items-center justify-between gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-white/5">
                            <span class="inline-flex min-w-0 items-center gap-x-2">
                                <span
                                    class="size-3 shrink-0 rounded-full"
                                    style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', strval($marker['color'] ?? '')) === 1 ? $marker['color'] : '#fbbf24' }}"
                                    aria-hidden="true"
                                ></span>
                                <span class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $marker['label'] }}</span>
                                <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $marker['x'] }}, {{ $marker['z'] }}</span>
                            </span>

                            @if ($this->canManageMarkers())
                                <button
                                    type="button"
                                    class="{{ 'text-danger-600 opacity-0 transition group-hover:opacity-100 hover:opacity-100 focus:opacity-100' }}"
                                    wire:click="deleteMarker({{ (int) $marker['id'] }})"
                                    aria-label="{{ __('minecraft-toolkit::pages.map.delete_marker') }} — {{ $marker['label'] }}"
                                    title="{{ __('minecraft-toolkit::pages.map.delete_marker') }} — {{ $marker['label'] }}"
                                >
                                    <x-filament::icon icon="tabler-trash" class="h-4 w-4" />
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-5 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('minecraft-toolkit::common.empty.nothing_here') }}
                        </li>
                    @endforelse
                </ul>

                @if ($this->canManageMarkers() && $selectedWorld !== '')
                    {{-- marker creation lives in the mounted `add_marker` Action modal --}}
                    <div class="border-t border-gray-200 p-4 dark:border-white/10">
                        <x-filament::button
                            size="sm"
                            color="primary"
                            icon="tabler-plus"
                            wire:click="mountAction('add_marker')"
                        >
                            {{ __('minecraft-toolkit::pages.map.layers.markers') }}
                        </x-filament::button>
                    </div>
                @endif
            </div>

            {{-- Players (bridge-fed) --------------------------------------- --}}
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <p class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ __('minecraft-toolkit::pages.map.layers.players') }}
                        <span class="ml-1 text-xs font-normal text-gray-500 dark:text-gray-400">({{ count($pins) }})</span>
                    </p>
                </div>

                @if (count($pins) > 0)
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($pins as $pin)
                            <li class="flex items-center justify-between gap-2 px-4 py-2">
                                <span class="inline-flex min-w-0 items-center gap-x-2">
                                    <img
                                        src="https://mc-heads.net/avatar/{{ rawurlencode($pin['name']) }}/24"
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        width="24"
                                        height="24"
                                        class="size-6 shrink-0 rounded-md ring-success-600/40 ring-2"
                                    />
                                    <span class="truncate text-sm text-gray-800 dark:text-gray-200">{{ $pin['name'] }}</span>
                                </span>
                                <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                    Y {{ $pin['y'] ?? '~' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    {{-- explanatory dashed placeholder chip (bridge absent parity state) --}}
                    <div class="p-4">
                        <span
                            class="flex items-center gap-2 rounded-md border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-500 dark:border-white/20 dark:text-gray-400"
                            title="{{ __('minecraft-toolkit::pages.players.states.tier2_missing') }}"
                        >
                            <x-filament::icon icon="tabler-eye-off" class="h-4 w-4 shrink-0" />
                            {{ __('minecraft-toolkit::pages.players.states.tier2_missing') }}
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- ============================================================== --}}
        {{-- CANVAS COLUMN                                                  --}}
        {{-- ============================================================== --}}
        <div class="flex min-w-0 flex-col gap-3">

            {{-- live-state MIRRORS outside the wire:ignore stage (Livewire-safe);
                 the 12s ping refreshes companion-bridge pins/time per parity spec --}}
            <div
                id="mct-mirror"
                hidden
                wire:poll.12s="refreshBridge"
                data-target="{{ $selectedWorld }}"
                data-world="{{ $this->selectedFolder() }}"
                data-dim="{{ $this->currentDimension() }}"
                data-epoch="{{ $tileEpoch }}"
                data-follow="{{ $follow ? '1' : '0' }}"
                data-show-spawn="{{ $showSpawn ? '1' : '0' }}"
                data-show-border="{{ $showWorldBorder ? '1' : '0' }}"
                data-spawn="{{ json_encode($this->spawnAnchor()) }}"
                data-tile-base="{{ $this->tileBaseUrl() }}"
                data-time="{{ $gameTimeTicks ?? '' }}"
                data-regions="{{ json_encode($regionMeta['regions'] ?? []) }}"
            ></div>

            {{-- Server-rendered pin feeds read by the canvas script (placed BEFORE it so the
                 observer can attach on first load) ------------------------------------ --}}
            <div id="mct-feeds" class="hidden" aria-hidden="true">
                <div id="mct-player-feed">{{ json_encode(collect($pins)->map(fn ($pin) => [
                    'kind' => 'player',
                    'name' => $pin['name'],
                    'wx' => $pin['x'],
                    'wz' => $pin['z'],
                ])->values()) }}</div>
                <div id="mct-marker-feed">{{ json_encode(collect($markers)->map(fn ($marker) => [
                    'kind' => 'marker',
                    'name' => $marker['label'],
                    'wx' => $marker['x'],
                    'wz' => $marker['z'],
                    'color' => $marker['color'] ?: '#fbbf24',
                ])->values()) }}</div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-filament::button
                    size="sm"
                    :color="$follow ? 'primary' : 'gray'"
                    icon="tabler-current-location"
                    wire:click="toggleFollow"
                >
                    {{ $follow
                        ? __('minecraft-toolkit::pages.map.follow_off')
                        : __('minecraft-toolkit::pages.map.follow_on') }}
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="tabler-refresh"
                    wire:click="redraw"
                    wire:loading.attr="disabled"
                >
                    {{ __('minecraft-toolkit::pages.map.redraw') }}
                </x-filament::button>
            </div>

            {{-- Stage: the element itself and every script-owned child are wire:ignore'd so
                 Livewire morphs (12 s poll, clicks) never reset the canvas or drop pins --}}
            <div
                id="mct-map-stage"
                wire:ignore.self
                class="{{ 'relative overflow-hidden rounded-xl bg-[#0b0e14] ring-1 ring-gray-950/5 dark:ring-white/10' }} aspect-[4/3] sm:aspect-auto sm:h-[560px]"
                tabindex="0"
                role="application"
                aria-label="{{ __('minecraft-toolkit::pages.map.title') }}"
            >
                <canvas id="mct-canvas" wire:ignore wire:key="mct-canvas" class="absolute inset-0 h-full w-full touch-none select-none"></canvas>

                {{-- player / marker overlay pins are injected here (absolute positioning) --}}
                <div id="mct-pins-layer" wire:ignore wire:key="mct-pins-layer" class="pointer-events-none absolute inset-0"></div>

                {{-- TIMECLOCK top-right ----------------------------------------- --}}
                <div
                    id="mct-clock"
                    wire:ignore
                    wire:key="mct-clock"
                    class="{{ 'absolute end-3 top-3 rounded-md bg-gray-900/80 px-2 py-1 font-medium text-xs text-gray-100 backdrop-blur' }} dark:bg-gray-950/70"
                    aria-live="off"
                >--:--</div>

                {{-- LOCATION bottom-left chip (aria-live polite parity) ---------- --}}
                <div
                    id="mct-location"
                    wire:ignore
                    wire:key="mct-location"
                    class="{{ 'absolute bottom-3 start-3 rounded-md bg-gray-900/80 px-2 py-1 font-medium text-xs tabular-nums text-gray-100 backdrop-blur' }} dark:bg-gray-950/70"
                    aria-live="polite"
                >LOCATION</div>

                {{-- busy indicator ---------------------------------------------- --}}
                <div
                    id="mct-busy"
                    wire:ignore
                    wire:key="mct-busy"
                    class="pointer-events-none absolute end-3 bottom-3 hidden items-center gap-1 rounded-md bg-gray-900/80 px-2 py-1 text-xs text-gray-100 backdrop-blur dark:bg-gray-950/70"
                >
                    <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10" opacity="0.25"/><path d="M22 12a10 10 0 0 0-10-10"/></svg>
                </div>

                {{-- Under construction / zero regions explainer -------------- --}}
                @if (count(data_get($regionMeta, 'regions', [])) === 0)
                    <div class="absolute inset-0 grid place-items-center p-6" wire:key="mct-empty-{{ md5($selectedWorld) }}">
                        <div class="max-w-sm rounded-xl bg-white p-6 text-center shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                            <p class="font-semibold text-gray-950 dark:text-white">
                                {{ __('minecraft-toolkit::common.empty.nothing_here') }}
                            </p>
                            <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">
                                {{ filled($this->selectedLabel())
                                    ? __('minecraft-toolkit::pages.map.no_regions', ['world' => $this->selectedLabel()])
                                    : __('minecraft-toolkit::pages.map.no_world') }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>

            @if (data_get($regionMeta, 'truncated', false))
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('minecraft-toolkit::pages.map.regions_truncated', ['count' => \McToolkit\Services\Maps\MapTileService::MAX_REGIONS_PER_SCAN]) }}
                </p>
            @endif
        </div>
    </div>

    {{-- ================================================================= --}}

    <script>
        (() => {
            'use strict';

            const BOOT_KEY = '__MCT_MAP__';
            const ROOT_ID = 'mct-map-stage';

            // Idempotent SPA guard: a second render of this page never stacks loops.
            if (window[BOOT_KEY]) {
                try { window[BOOT_KEY].teardown(); } catch (_) {}
            }

            /* ------------------------------------------------------------
             * Documented geometry constants (server side mirrors in
             * McToolkit\Services\Maps\*):
             *   blocksPerChunk   = 16
             *   chunks per region axis = 32  -> region covers 512 blocks
             * ------------------------------------------------------------ */
            const REGION_BLOCKS_AXIS = 512;

            const MIN_ZOOM = 0.25;          // wheel zoom clamp (task contract)
            const MAX_ZOOM = 4;
            const ZOOM_STEP = 1.15;         // wheel delta factor base
            const INITIAL_VISIBLE_SPAN_BLOCKS = 1024; // spawn-centered first fit
            const TILE_CONCURRENCY = 3;     // center-out worker pool budget
            const NETWORK_RETRY_MS = 15000; // back-off step after a transient network failure
            const MAX_NETWORK_ATTEMPTS = 3; // then the tile stays fog until Redraw
            const DEFAULT_RETRY_AFTER_S = 30; // 429 without a Retry-After header
            const WORLD_BORDER_HALF_BLOCKS = 30_000_000; // vanilla-border guess (documented)
            const MARKER_COLORS_DEFAULT = '#fbbf24';
            const FOG_HEX = '#0b0e14';
            const PIN_BUBBLE_CLASS = [
                'absolute','left-0','top-0','-translate-x-1/2','-translate-y-full',
                'whitespace-nowrap','rounded-md','bg-gray-900/80','backdrop-blur',
                'px-1','text-xs','text-gray-100','dark:bg-gray-950/80',
            ].join(' ');

            const stage = document.getElementById(ROOT_ID);
            if (! stage) return;

            const canvas = stage.querySelector('#mct-canvas');
            const pinsLayer = stage.querySelector('#mct-pins-layer');
            const clockEl = stage.querySelector('#mct-clock');
            const locationEl = stage.querySelector('#mct-location');
            const busyEl = stage.querySelector('#mct-busy');
            const mirror = document.getElementById('mct-mirror');

            if (! canvas || ! pinsLayer) return;

            const dpr = () => Math.max(1, Math.min(window.devicePixelRatio || 1, 2));

            let ctx = null;
            let rafHandle = 0;
            let running = document.visibilityState !== 'hidden'; // RAF only while visible
            let destroyed = false;                                // set once by teardown
            let resizeObserver = null;
            let mirrorObserver = null;
            let feedsObserver = null;
            let resumeTimer = 0;

            /* ------------------------------------------------------------
             * View transform: pixels-per-block plus focus block coordinates.
             * ------------------------------------------------------------ */
            const view = {
                ppb: 0.25,
                centerX: 0,
                centerZ: 0,
                fitReady: false,
            };

            const pointer = { down: false, moved: false, lastX: 0, lastY: 0, lastClientX: 0, lastClientY: 0 };

            /* ------------------------------------------------------------
             * Tiles + scheduler. Keys embed the map target so tiles of two
             * worlds/dimensions can never be mixed up. Every fetch carries the
             * scheduler generation; results of an older generation are ignored.
             * ------------------------------------------------------------ */
            const tileCache = new Map();    // "target|rx_rz" -> HTMLCanvasElement
            const failedTiles = new Map();  // key -> { until: epoch ms, attempts }
            const queue = [];               // [{rx, rz, v, dist}]
            const inflight = new Set();
            let aborter = new AbortController();
            let schedulerGen = 0;
            let pausedUntil = 0;            // global pause after HTTP 429

            const parsedJson = (raw, fallback) => {
                try { return JSON.parse(raw) ?? fallback; } catch (_) { return fallback; }
            };

            let currentTarget = mirror?.dataset.target || '';
            let currentWorld = mirror?.dataset.world || '';
            let currentDim = mirror?.dataset.dim || 'overworld';
            let currentEpoch = mirror?.dataset.epoch || '1';
            let regionsSignature = mirror?.dataset.regions || '[]';
            let regionsList = (() => {
                const parsed = parsedJson(regionsSignature, []);
                return Array.isArray(parsed) ? parsed : [];
            })();

            const tileKey = (rx, rz) => currentTarget + '|' + rx + '_' + rz;

            const focusRegion = () => ({
                rx: Math.floor(view.centerX / REGION_BLOCKS_AXIS),
                rz: Math.floor(view.centerZ / REGION_BLOCKS_AXIS),
            });

            const updateBusy = () => {
                if (! busyEl) return;
                busyEl.classList.toggle('hidden', ! inflight.size);
                busyEl.classList.toggle('inline-flex', !! inflight.size);
            };

            const rebuildQueue = () => {
                queue.length = 0;

                if (! regionsList.length) return;

                const focus = focusRegion();
                const now = Date.now();

                for (const r of regionsList) {
                    const key = tileKey(r.rx, r.rz);

                    if (tileCache.has(key) || inflight.has(key)) continue;

                    const failure = failedTiles.get(key);
                    if (failure && failure.until > now) continue;

                    const dx = r.rx - focus.rx;
                    const dz = r.rz - focus.rz;
                    queue.push({ rx: r.rx, rz: r.rz, v: Number(r.mtime) || 0, dist: dx * dx + dz * dz });
                }

                queue.sort((a, b) => a.dist - b.dist);
            };

            const tileUrl = (job) => {
                const url = new URL(mirror?.dataset.tileBase || '', window.location.href);
                url.searchParams.set('world', currentWorld);
                url.searchParams.set('dim', currentDim);
                url.searchParams.set('rx', String(job.rx));
                url.searchParams.set('rz', String(job.rz));
                url.searchParams.set('v', String(job.v));
                url.searchParams.set('epoch', String(currentEpoch)); // browser cache-bust only
                return url.toString();
            };

            const makeTileFromTile = (payload, key) => {
                const width = Number(payload.width) || 0;
                const height = Number(payload.height) || 0;
                const bin = atob(payload.pixels);

                if (! width || ! height || bin.length !== width * height * 4) return false;

                const bytes = new Uint8ClampedArray(bin.length);
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);

                const off = document.createElement('canvas');
                off.width = width;
                off.height = height;

                const octx = off.getContext('2d');
                if (! octx) return false;

                octx.putImageData(new ImageData(bytes, width, height), 0, 0);
                tileCache.set(key, off);

                return true;
            };

            const scheduleResume = (delayMs) => {
                clearTimeout(resumeTimer);
                resumeTimer = setTimeout(() => {
                    if (destroyed) return;
                    rebuildQueue();
                    pumpQueue();
                }, Math.max(250, delayMs));
            };

            const pumpQueue = () => {
                if (destroyed) return;

                const now = Date.now();

                if (pausedUntil > now) {
                    scheduleResume(pausedUntil - now);
                    updateBusy();
                    return;
                }

                while (inflight.size < TILE_CONCURRENCY && queue.length) {
                    const job = queue.shift();
                    const key = tileKey(job.rx, job.rz);

                    if (tileCache.has(key) || inflight.has(key)) continue;

                    inflight.add(key);
                    const gen = schedulerGen;

                    fetch(tileUrl(job), {
                        signal: aborter.signal,
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    })
                        .then(async (res) => {
                            if (gen !== schedulerGen) return;

                            if (res.status === 429) {
                                // Throttled: pause the whole scheduler, the tile stays eligible.
                                const retryAfter = Number(res.headers.get('Retry-After'));
                                const seconds = Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : DEFAULT_RETRY_AFTER_S;
                                pausedUntil = Date.now() + seconds * 1000;
                                return;
                            }

                            if (! res.ok) {
                                // Permission, validation or server error: final until Redraw.
                                failedTiles.set(key, { until: Infinity, attempts: MAX_NETWORK_ATTEMPTS });
                                return;
                            }

                            const body = await res.json();
                            if (gen !== schedulerGen) return;

                            const tile = body && body.tile;
                            if (! (tile && tile.pixels && makeTileFromTile(tile, key))) {
                                failedTiles.set(key, { until: Infinity, attempts: MAX_NETWORK_ATTEMPTS }); // graceful fog
                            }
                        })
                        .catch((error) => {
                            if (gen !== schedulerGen || destroyed || (error && error.name === 'AbortError')) return;

                            const previous = failedTiles.get(key);
                            const attempts = (previous ? previous.attempts : 0) + 1;
                            const final = attempts >= MAX_NETWORK_ATTEMPTS;

                            failedTiles.set(key, { until: final ? Infinity : Date.now() + NETWORK_RETRY_MS * attempts, attempts });

                            if (! final) scheduleResume(NETWORK_RETRY_MS * attempts);
                        })
                        .finally(() => {
                            // Stale generation (hard reset / teardown): state was already reset.
                            if (gen !== schedulerGen) return;

                            inflight.delete(key);

                            if (destroyed) return;

                            rebuildQueue();
                            pumpQueue();
                        });
                }

                updateBusy();
            };

            const resetScheduler = (hard) => {
                if (destroyed) return;

                if (hard) {
                    schedulerGen++;
                    aborter.abort();
                    aborter = new AbortController();
                    inflight.clear();
                    tileCache.clear();
                    failedTiles.clear();
                    pausedUntil = 0;
                    clearTimeout(resumeTimer);
                }

                rebuildQueue();
                pumpQueue();
            };

            /* ------------------------------------------------------------
             * Overlay pins (players + markers) kept as raw DOM nodes inside the
             * wire:ignore'd #mct-pins-layer.
             * ------------------------------------------------------------ */
            let pinNodes = [];
            let pinsSignature = '';

            const pinFeed = () => {
                const playerHost = document.getElementById('mct-player-feed');
                const markerHost = document.getElementById('mct-marker-feed');

                const players = playerHost ? parsedJson(playerHost.textContent || '[]', []) : [];
                const markers = markerHost ? parsedJson(markerHost.textContent || '[]', []) : [];

                return [...(Array.isArray(players) ? players : []), ...(Array.isArray(markers) ? markers : [])];
            };

            const syncPins = () => {
                const feed = pinFeed();
                const signature = JSON.stringify(feed);

                if (signature === pinsSignature && pinsLayer.childElementCount === pinNodes.length) return;
                pinsSignature = signature;

                pinsLayer.replaceChildren();
                pinNodes = [];

                for (const pin of feed) {
                    if (typeof pin.wx !== 'number' || typeof pin.wz !== 'number') continue;

                    const el = document.createElement('div');
                    el.className = 'absolute will-change-transform';
                    el.style.transformOrigin = 'center';

                    const avatarWrap = document.createElement('span');
                    avatarWrap.className = 'relative inline-flex';

                    if (pin.kind === 'player') {
                        const img = document.createElement('img');
                        img.src = 'https://mc-heads.net/avatar/' + encodeURIComponent(pin.name || '?') + '/24';
                        img.alt = '';
                        img.decoding = 'async';
                        img.loading = 'lazy';
                        img.width = 24; img.height = 24;
                        img.className = 'size-6 rounded-md shadow';
                        img.style.boxShadow = '0 0 0 2px rgb(74 222 128 / 0.7)';
                        avatarWrap.appendChild(img);
                    } else {
                        const dot = document.createElement('span');
                        dot.className = 'block size-2.5 rounded-full ring-1 ring-black/20';

                        // Client-side hex sanity belt (server already validates the same shape).
                        dot.style.background = /^#([0-9a-fA-F]{6})$/.test(String(pin.color || '')) ? pin.color : MARKER_COLORS_DEFAULT;

                        avatarWrap.appendChild(dot);
                    }

                    const bubble = document.createElement('span');
                    bubble.className = PIN_BUBBLE_CLASS;
                    bubble.textContent = pin.name || '';
                    bubble.style.top = '-4px';
                    avatarWrap.appendChild(bubble);

                    el.appendChild(avatarWrap);
                    pinsLayer.appendChild(el);

                    pinNodes.push({
                        el,
                        wx: pin.wx,
                        wz: pin.wz,
                        isPlayer: pin.kind === 'player',
                    });
                }
            };

            /* ------------------------------------------------------------
             * Time clock conversion (documented):
             * 24000 ticks per day; 0 ticks == 06:00 world time.
             * ------------------------------------------------------------ */
            const renderClock = () => {
                if (! clockEl) return;

                const ticksRaw = mirror?.dataset.time || '';
                const ticks = Number(ticksRaw);

                if (ticksRaw === '' || ! Number.isFinite(ticks)) {
                    clockEl.textContent = '--:--';
                    return;
                }

                const dayTicks = ((ticks % 24000) + 24000) % 24000;
                const hourTotal = (dayTicks / 1000 + 6) % 24;
                const hh = String(Math.floor(hourTotal)).padStart(2, '0');
                const mm = String(Math.floor((hourTotal % 1) * 60)).padStart(2, '0');
                clockEl.textContent = hh + ':' + mm;
            };

            /* ------------------------------------------------------------
             * Spawn / border annotation sync (layer preset switches)
             * ------------------------------------------------------------ */
            let showSpawn = true;
            let showBorder = false;
            let spawnAnchor = { x: 0, z: 0 };

            const syncAnnotations = () => {
                if (! mirror) return;
                showSpawn = (mirror.dataset.showSpawn ?? '1') === '1';
                showBorder = (mirror.dataset.showBorder ?? '0') === '1';
                spawnAnchor = parsedJson(mirror.dataset.spawn || '{"x":0,"z":0}', { x: 0, z: 0 });
            };

            /* ------------------------------------------------------------
             * Mirror sync: a new target or Redraw epoch is a HARD reset (abort,
             * drop tiles, refit on the new spawn); a new region list is soft.
             * ------------------------------------------------------------ */
            const syncMirrorState = () => {
                if (! mirror || destroyed) return;

                let hard = false;
                let soft = false;

                if ((mirror.dataset.target || '') !== currentTarget) {
                    currentTarget = mirror.dataset.target || '';
                    currentWorld = mirror.dataset.world || '';
                    currentDim = mirror.dataset.dim || 'overworld';
                    view.fitReady = false;
                    hard = true;
                }

                if ((mirror.dataset.epoch || '1') !== currentEpoch) {
                    currentEpoch = mirror.dataset.epoch || '1';
                    hard = true;
                }

                const regionsRaw = mirror.dataset.regions || '[]';

                if (regionsRaw !== regionsSignature) {
                    regionsSignature = regionsRaw;
                    const parsed = parsedJson(regionsRaw, []);
                    regionsList = Array.isArray(parsed) ? parsed : [];
                    soft = true;
                }

                syncAnnotations();

                if (hard || soft) {
                    resetScheduler(hard);
                }
            };

            /* ------------------------------------------------------------
             * Fit / transforms
             * zoom clamp contract: the wheel factor multiplier stays within
             * [MIN_ZOOM .. MAX_ZOOM] relative to the initial spawn fit.
             * ------------------------------------------------------------ */
            let fitPpb = 1; // captured once fitted

            const ensureFit = () => {
                if (view.fitReady) return;
                const rect = stage.getBoundingClientRect();
                if (rect.width <= 0 || rect.height <= 0) return;

                fitPpb = Math.min(rect.width, rect.height) / INITIAL_VISIBLE_SPAN_BLOCKS;
                view.ppb = fitPpb;
                view.centerX = Number(spawnAnchor.x ?? 0) || 0;
                view.centerZ = Number(spawnAnchor.z ?? 0) || 0;
                view.fitReady = true;
            };

            const zoomAt = (clientX, clientY, deltaY) => {
                ensureFit();
                if (! view.fitReady || fitPpb <= 0) return;

                const currentFactor = view.ppb / fitPpb;
                const nextFactor = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, currentFactor * Math.pow(ZOOM_STEP, -deltaY / 100)));
                const next = nextFactor * fitPpb;

                if (Math.abs(next - view.ppb) < 1e-9) return;

                const rect = stage.getBoundingClientRect();

                // Anchor: keep the world point under the cursor stationary.
                const offsetX = (clientX - rect.left) - rect.width / 2;
                const offsetY = (clientY - rect.top) - rect.height / 2;
                const wxBefore = offsetX / view.ppb + view.centerX;
                const wzBefore = offsetY / view.ppb + view.centerZ;

                view.ppb = next;
                view.centerX = wxBefore - offsetX / view.ppb;
                view.centerZ = wzBefore - offsetY / view.ppb;

                rebuildQueue();
                pumpQueue();
            };

            const blockAtPointer = (clientX, clientY) => {
                const rect = stage.getBoundingClientRect();

                return {
                    x: Math.round(((clientX - rect.left) - rect.width / 2) / view.ppb + view.centerX),
                    z: Math.round(((clientY - rect.top) - rect.height / 2) / view.ppb + view.centerZ),
                };
            };

            let lastLocationKey = '';

            const updateLocationChip = () => {
                if (! locationEl || ! pointer.lastClientX) return;

                const point = blockAtPointer(pointer.lastClientX, pointer.lastClientY);
                const key = point.x + ',' + point.z;

                if (key === lastLocationKey) return;
                lastLocationKey = key;

                // DOCUMENTED inverse-transform readout; Y intentionally shown as "~".
                locationEl.textContent = 'LOCATION ' + point.x + ', ~, ' + point.z;
            };

            // Queue refresh when the focus region changes so pans fetch nearby tiles first.
            let queuedFocus = '';
            const rebuildQueueWhenScrolledFar = () => {
                const focus = focusRegion();
                const key = focus.rx + '_' + focus.rz;

                if (key === queuedFocus) return;
                queuedFocus = key;
                rebuildQueue();
                pumpQueue();
            };

            /* ------------------------------------------------------------ */
            /* Render loop                                                  */
            /* ------------------------------------------------------------ */
            const drawFrame = () => {
                if (destroyed || ! running || ! ctx) {
                    rafHandle = 0; // lets onVisibility restart the loop later
                    return;
                }

                syncMirrorState();
                ensureFit();
                syncPins();
                renderClock();

                // Follow mode: recenters onto the LEAD player pin each frame.
                if ((mirror?.dataset.follow ?? '0') === '1') {
                    const lead = pinNodes.find((node) => node.isPlayer) || null;
                    if (lead) {
                        view.centerX = lead.wx;
                        view.centerZ = lead.wz;
                    }
                }

                const rect = stage.getBoundingClientRect();
                const ratio = dpr();

                if (canvas.width !== Math.floor(rect.width * ratio) || canvas.height !== Math.floor(rect.height * ratio)) {
                    canvas.width = Math.floor(rect.width * ratio);
                    canvas.height = Math.floor(rect.height * ratio);
                }

                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.imageSmoothingEnabled = false;

                ctx.fillStyle = FOG_HEX;               // unpainted region background: seamless fog
                ctx.fillRect(0, 0, rect.width, rect.height);

                const halfW = rect.width / 2;
                const halfH = rect.height / 2;

                const startRX = Math.floor((view.centerX - halfW / view.ppb) / REGION_BLOCKS_AXIS) - 1;
                const endRX = Math.ceil((view.centerX + halfW / view.ppb) / REGION_BLOCKS_AXIS) + 1;
                const startRZ = Math.floor((view.centerZ - halfH / view.ppb) / REGION_BLOCKS_AXIS) - 1;
                const endRZ = Math.ceil((view.centerZ + halfH / view.ppb) / REGION_BLOCKS_AXIS) + 1;

                const toScreenX = (wx) => halfW + (wx - view.centerX) * view.ppb;
                const toScreenY = (wz) => halfH + (wz - view.centerZ) * view.ppb;
                const sizePx = REGION_BLOCKS_AXIS * view.ppb;

                for (let rx = startRX; rx < endRX; rx++) {
                    for (let rz = startRZ; rz < endRZ; rz++) {
                        const tile = tileCache.get(tileKey(rx, rz));

                        if (! tile) continue;             // fog shows through until fetched

                        ctx.drawImage(tile, toScreenX(rx * REGION_BLOCKS_AXIS), toScreenY(rz * REGION_BLOCKS_AXIS), sizePx, sizePx);
                    }
                }

                // ---- annotation layer: spawn + guessed vanilla border ------
                if (showSpawn) {
                    const sx = toScreenX(Number(spawnAnchor.x ?? 0) || 0);
                    const sy = toScreenY(Number(spawnAnchor.z ?? 0) || 0);

                    ctx.strokeStyle = 'rgba(245,158,11,0.9)';
                    ctx.lineWidth = 2;
                    ctx.beginPath();
                    ctx.arc(sx, sy, 7, 0, Math.PI * 2);
                    ctx.stroke();

                    ctx.beginPath();
                    ctx.moveTo(sx - 11, sy);
                    ctx.lineTo(sx + 11, sy);
                    ctx.moveTo(sx, sy - 11);
                    ctx.lineTo(sx, sy + 11);
                    ctx.stroke();
                }

                if (showBorder) {
                    const half = WORLD_BORDER_HALF_BLOCKS;
                    const sxa = toScreenX(-half), sxb = toScreenX(half);
                    const sya = toScreenY(-half), syb = toScreenY(half);

                    ctx.save();
                    ctx.setLineDash([8, 6]);
                    ctx.strokeStyle = 'rgba(226,232,240,0.65)';
                    ctx.lineWidth = 2;
                    ctx.strokeRect(sxa, sya, sxb - sxa, syb - sya);
                    ctx.restore();
                }

                // ---- overlay pin placement (documented inverse transform) --
                for (const node of pinNodes) {
                    node.el.style.transform =
                        'translate(' + toScreenX(node.wx) + 'px,' + toScreenY(node.wz) + 'px)';
                }

                updateLocationChip();
                rebuildQueueWhenScrolledFar();

                rafHandle = requestAnimationFrame(drawFrame);
            };

            /* ------------------------------------------------------------ */
            /* Wiring                                                       */
            /* ------------------------------------------------------------ */
            const onKeyDown = (event) => {
                const step = 64;

                if (event.key === 'ArrowUp') view.centerZ -= step;
                else if (event.key === 'ArrowDown') view.centerZ += step;
                else if (event.key === 'ArrowLeft') view.centerX -= step;
                else if (event.key === 'ArrowRight') view.centerX += step;
                else return;

                event.preventDefault();
                rebuildQueue();
                pumpQueue();
            };

            const onPointerDown = (event) => {
                if (event.button !== 0 && event.pointerType === 'mouse') return;
                pointer.down = true;
                pointer.moved = false;
                pointer.lastX = event.clientX;
                pointer.lastY = event.clientY;
                pointer.lastClientX = event.clientX;
                pointer.lastClientY = event.clientY;
                stage.setPointerCapture?.(event.pointerId);
            };

            const onPointerMove = (event) => {
                pointer.lastClientX = event.clientX;
                pointer.lastClientY = event.clientY;

                if (! pointer.down) return;

                const dx = event.clientX - pointer.lastX;
                const dy = event.clientY - pointer.lastY;

                if (dx || dy) {
                    pointer.moved = true;
                    view.centerX -= dx / view.ppb;
                    view.centerZ -= dy / view.ppb;
                    pointer.lastX = event.clientX;
                    pointer.lastY = event.clientY;
                }
            };

            const onPointerUp = () => {
                pointer.down = false;
            };

            const onWheel = (event) => {
                event.preventDefault();
                zoomAt(event.clientX, event.clientY, event.deltaY);
            };

            const onVisibility = () => {
                running = document.visibilityState !== 'hidden';

                if (running && ! rafHandle && ! destroyed) {
                    rafHandle = requestAnimationFrame(drawFrame);
                }
            };

            // Mirror mutations arrive through Livewire DOM updates (data-* attributes).
            mirrorObserver = new MutationObserver(syncMirrorState);
            if (mirror) mirrorObserver.observe(mirror, { attributes: true });

            // Annotation feeds (server-rendered JSON payloads above the stage).
            const feedsRoot = document.getElementById('mct-feeds');

            if (feedsRoot) {
                feedsObserver = new MutationObserver(syncPins);
                feedsObserver.observe(feedsRoot, { childList: true, subtree: true, characterData: true });
            }

            stage.addEventListener('pointerdown', onPointerDown);
            stage.addEventListener('pointermove', onPointerMove);
            stage.addEventListener('pointerup', onPointerUp);
            stage.addEventListener('pointercancel', onPointerUp);
            stage.addEventListener('wheel', onWheel, { passive: false });
            stage.addEventListener('keydown', onKeyDown);

            // The DPR-aware draw loop resizes the canvas itself; the observer only wakes a
            // stopped loop when the stage changes size.
            resizeObserver = new ResizeObserver(() => onVisibility());
            resizeObserver.observe(stage);

            document.addEventListener('visibilitychange', onVisibility);

            // Lifecycle: drop RAF/timers/fetches before the SPA swaps away from this page.
            // Bumping the scheduler generation first makes every aborted fetch a no-op, so
            // nothing re-queues work after teardown.
            const teardown = () => {
                if (destroyed) return;

                destroyed = true;
                running = false;
                schedulerGen++;

                cancelAnimationFrame(rafHandle);
                rafHandle = 0;

                aborter.abort();
                clearTimeout(resumeTimer);

                queue.length = 0;
                inflight.clear();

                mirrorObserver?.disconnect();
                feedsObserver?.disconnect();
                resizeObserver?.disconnect();

                stage.removeEventListener('pointerdown', onPointerDown);
                stage.removeEventListener('pointermove', onPointerMove);
                stage.removeEventListener('pointerup', onPointerUp);
                stage.removeEventListener('pointercancel', onPointerUp);
                stage.removeEventListener('wheel', onWheel);
                stage.removeEventListener('keydown', onKeyDown);
                document.removeEventListener('visibilitychange', onVisibility);
                document.removeEventListener('livewire:navigating', teardown);

                tileCache.clear();
                failedTiles.clear();
                pinNodes = [];

                if (window[BOOT_KEY] && window[BOOT_KEY].teardown === teardown) {
                    delete window[BOOT_KEY];
                }
            };

            document.addEventListener('livewire:navigating', teardown, { once: true });

            // Debug handle ONLY when the app admits debug mode (mirrors core blade usage).
            @if (app()->hasDebugModeEnabled())
                window[BOOT_KEY] = { teardown, tileCache, view, get regions() { return regionsList; } };
            @else
                window[BOOT_KEY] = { teardown };
            @endif

            // Kick-off
            ctx = canvas.getContext('2d');

            syncAnnotations();
            ensureFit();
            rebuildQueue();
            pumpQueue();

            if (running) {
                rafHandle = requestAnimationFrame(drawFrame);
            }
        })();
    </script>

    {{-- Tile endpoint base is mirrored on #mct-mirror (kept outside wire:ignore) ----- --}}
</x-filament-panels::page>
