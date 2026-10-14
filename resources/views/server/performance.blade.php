{{--
    Minecraft Toolkit — Performance page body.

    Contract pins:
      - Root MUST be <x-filament-panels::page> (page-agent rule).
      - Detection banner pinned top of EVERY toolkit page via x-dynamic-component;
        its control dispatches 'mc-toolkit.redetect', caught by PerformancePage::redetect().
      - Polling cadence: wire:poll.visible.20s on a single wrapper ("visible only").
      - Bridge-absent truthfulness: wing-level CPU/memory/uptime stay live; every
        game-side figure degrades to an em-dash placeholder — nothing invented.
      - Sparklines ride <x-minecraft-toolkit::sparkline> (plugin-namespaced
        blade component, same resolution family as the detection-banner tag form).

    Lang gaps accepted (A1 may fold these into lang/en/pages.php later — tracked in handoff):
      performance.charts.{tps,mspt,cpu,memory}, tile sub-captions, bridge overlay chips,
      worlds Players label, top-entities caption.
--}}
@php
    // ---- numeric extraction ---------------------------------------------------
    $snapshot = $snapshot ?? [];
    $bridgePresent = (bool) ($snapshot['bridge_present'] ?? false);

    $tps = isset($snapshot['tps']) && is_numeric($snapshot['tps']) ? floatval($snapshot['tps']) : null;
    $mspt = isset($snapshot['mspt']) && is_numeric($snapshot['mspt']) ? floatval($snapshot['mspt']) : null;
    $avg = is_array($snapshot['tps_avg'] ?? null) ? $snapshot['tps_avg'] : [];

    $avgOne = isset($avg['one']) && is_numeric($avg['one']) ? floatval($avg['one']) : null;
    $avgFive = isset($avg['five']) && is_numeric($avg['five']) ? floatval($avg['five']) : null;
    $avgFifteen = isset($avg['fifteen']) && is_numeric($avg['fifteen']) ? floatval($avg['fifteen']) : null;

    $cpu = isset($snapshot['cpu_percent']) && is_numeric($snapshot['cpu_percent']) ? floatval($snapshot['cpu_percent']) : null;
    $memoryBytes = isset($snapshot['memory_bytes']) && is_numeric($snapshot['memory_bytes']) ? intval($snapshot['memory_bytes']) : null;
    $memoryLimit = isset($snapshot['memory_limit_bytes']) && is_numeric($snapshot['memory_limit_bytes']) ? intval($snapshot['memory_limit_bytes']) : null;
    $uptimeMs = isset($snapshot['uptime_ms']) && is_numeric($snapshot['uptime_ms']) ? intval($snapshot['uptime_ms']) : null;

    // Binary divisor matches wings' byte payloads and the panel's GiB convention.
    $gib = static function (?int $bytes): ?string {
        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format($bytes / 1073741824, 2), '0'), '.') . ' GiB';
    };

    $memValue = ($gib)($memoryBytes);
    $memCap = ($gib)($memoryLimit);

    $stateKey = mb_strtolower(strval($snapshot['state'] ?? ''));

    // ---- design tokens (IMPLEMENTATION-GUIDE §2 verbatim recipes) -------------
    $statCard = 'relative block h-full rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $statLabel = 'text-sm font-medium text-gray-500 dark:text-gray-400';
    $statValue = 'mt-1 block truncate text-3xl font-semibold tracking-tight text-gray-950 tabular-nums dark:text-white';
    $statSub = 'mt-1 block text-xs font-normal text-gray-400 dark:text-gray-500';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $smallCard = 'p-3 rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    $chip = 'inline-flex items-center justify-center rounded-md bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';

    // Semantic badge accents mirror the detection-banner chipAccent recipe (§2.6).
    $stateAccent = match ($this->stateColor()) {
        'success' => 'bg-success-50 text-success-700 ring-inset ring-success-600/20 dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30',
        'primary' => 'bg-primary-50 text-primary-700 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30',
        'danger' => 'bg-danger-50 text-danger-700 ring-inset ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/30',
        default => '',
    };

    // "Needs the bridge" veil over charts that stay visible without data.
    $needsBridgeVeil = 'absolute inset-0 grid place-items-center rounded-md bg-white/60 dark:bg-gray-900/60';
    $needsBridgeChip = 'inline-flex items-center gap-x-1 whitespace-nowrap rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30';

    $fmt = static fn (?float $v): ?string => $v === null ? null : number_format($v, 1);
@endphp

<x-filament-panels::page>

    {{-- ---- Detection banner (pinned top, parity across all toolkit pages) ------- --}}
    <x-dynamic-component :component="'minecraft-toolkit::detection-banner'" :software="$detected"/>

    {{-- ---- Companion bridge state + one-click install --------------------------- --}}
    <x-minecraft-toolkit::bridge-callout
        :bridge="$this->bridgeStatus"
        :can-install="$this->canInstallBridge()"
        :can-restart="$this->canRestartForBridge()"
        :context="__('minecraft-toolkit::pages.performance.bridge.unavailable')"
    />

    {{-- ---- Live zone ------------------------------------------------------------ --}}
    <div wire:poll.visible.20s="tick" class="flex flex-col gap-6">

        {{-- ---- Wing-truth meta strip (state + uptime remain live regardless) ---- --}}
        <?php $uptimeText = $this->uptimeText(); ?>
        @if (! in_array($stateKey, ['', 'unknown'], true))
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="{{ $chip }} {{ $stateAccent }}">{{ ucfirst($stateKey) }}</span>

                @if ($uptimeMs !== null && $uptimeMs > 0 && filled($uptimeText))
                    <span>{{ $uptimeText }}</span>
                @endif
            </div>
        @endif

        {{-- ---- KPI stat tiles -------------------------------------------------- --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">

            {{-- TPS --}}
            <div class="{{ $statCard }}">
                <span class="{{ $statLabel }}">{{ __('minecraft-toolkit::pages.performance.tiles.tps') }}</span>
                <span class="{{ $statValue }}">
                    @if ($tps !== null)
                        {{ number_format($tps, 1) }}
                    @else
                        <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                    @endif
                </span>

                <span class="{{ $statSub }}">
                    {{-- demo copy: "1 min X · 5 min Y · 15 min Z" (lang gap, documented) --}}
                    1 min {!! (($fmt)($avgOne) ?? '&mdash;') !!}
                    <span aria-hidden="true">&middot;</span>
                    5 min {!! (($fmt)($avgFive) ?? '&mdash;') !!}
                    <span aria-hidden="true">&middot;</span>
                    15 min {!! (($fmt)($avgFifteen) ?? '&mdash;') !!}
                </span>

                <div class="relative mt-3">
                    <x-minecraft-toolkit::sparkline
                        :series="$this->series('tps')"
                        height-classes="h-9"
                        aria-label="TPS history"
                    />
                    @unless ($bridgePresent)
                        <div class="{{ $needsBridgeVeil }}">
                            <span class="{{ $needsBridgeChip }}">
                                <x-filament::icon :icon="'tabler-plug-connected-x'" class="size-3.5 shrink-0" />
                                {{ __('minecraft-toolkit::pages.bridge.needed') }}
                            </span>
                        </div>
                    @endunless
                </div>
            </div>

            {{-- MSPT --}}
            <div class="{{ $statCard }}">
                <span class="{{ $statLabel }}">{{ __('minecraft-toolkit::pages.performance.tiles.mspt') }}</span>
                <span class="{{ $statValue }}">
                    @if ($mspt !== null)
                        {{ number_format($mspt, 1) }}
                    @else
                        <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                    @endif
                </span>
                {{-- demo copy: "Milliseconds per tick, 50 at most" (lang gap, documented) --}}
                <span class="{{ $statSub }}">Milliseconds per tick, 50 at most</span>

                <div class="relative mt-3">
                    <x-minecraft-toolkit::sparkline
                        :series="$this->series('mspt')"
                        height-classes="h-9"
                        aria-label="MSPT history"
                    />
                    @unless ($bridgePresent)
                        <div class="{{ $needsBridgeVeil }}">
                            <span class="{{ $needsBridgeChip }}">
                                <x-filament::icon :icon="'tabler-plug-connected-x'" class="size-3.5 shrink-0" />
                                {{ __('minecraft-toolkit::pages.bridge.needed') }}
                            </span>
                        </div>
                    @endunless
                </div>
            </div>

            {{-- CPU (wing-level truth stays live without any bridge) --}}
            <div class="{{ $statCard }}">
                <span class="{{ $statLabel }}">{{ __('minecraft-toolkit::pages.performance.tiles.cpu') }}</span>
                <span class="{{ $statValue }}">
                    @if ($cpu !== null)
                        {{ intval(round($cpu)) }}%
                    @else
                        <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                    @endif
                </span>
                {{-- demo copy: "Of the whole server" (lang gap, documented) --}}
                <span class="{{ $statSub }}">Of the whole server</span>

                <div class="relative mt-3">
                    <x-minecraft-toolkit::sparkline
                        :series="$this->series('cpu')"
                        height-classes="h-9"
                        aria-label="CPU percent history"
                    />
                </div>
            </div>

            {{-- Memory (wing-level truth stays live without any bridge) --}}
            <div class="{{ $statCard }}">
                <span class="{{ $statLabel }}">{{ __('minecraft-toolkit::pages.performance.tiles.memory') }}</span>
                <span class="{{ $statValue }}">
                    @if ($memValue !== null)
                        {{ $memValue }}
                    @else
                        <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                    @endif
                </span>
                <span class="{{ $statSub }}">
                    {{-- demo copy: "Used by Java, {cap} at most" (lang gap, documented) --}}
                    @if (filled($memCap))
                        Used by Java, {{ $memCap }} at most
                    @else
                        Used by Java
                    @endif
                </span>

                <div class="relative mt-3">
                    <x-minecraft-toolkit::sparkline
                        :series="$this->series('memory')"
                        height-classes="h-9"
                        aria-label="Memory history"
                    />
                </div>
            </div>
        </div>

        {{-- ---- Charts (responsive 2-col grid) ----------------------------------- --}}
        <div class="grid gap-4 lg:grid-cols-2">

            <div class="{{ $card }}">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Ticks per second</h3>
                <div class="relative">
                    <x-minecraft-toolkit::sparkline :series="$this->series('tps')" height-classes="h-24" aria-label="Ticks per second"/>
                    @unless ($bridgePresent)
                        <div class="{{ $needsBridgeVeil }}">
                            <span class="{{ $needsBridgeChip }}">
                                <x-filament::icon :icon="'tabler-plug-connected-x'" class="size-3.5 shrink-0" />
                                {{ __('minecraft-toolkit::pages.bridge.needed') }}
                            </span>
                        </div>
                    @endunless
                </div>
            </div>

            <div class="{{ $card }}">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Milliseconds per tick</h3>
                <div class="relative">
                    <x-minecraft-toolkit::sparkline :series="$this->series('mspt')" height-classes="h-24" aria-label="Milliseconds per tick"/>
                    @unless ($bridgePresent)
                        <div class="{{ $needsBridgeVeil }}">
                            <span class="{{ $needsBridgeChip }}">
                                <x-filament::icon :icon="'tabler-plug-connected-x'" class="size-3.5 shrink-0" />
                                {{ __('minecraft-toolkit::pages.bridge.needed') }}
                            </span>
                        </div>
                    @endunless
                </div>
            </div>

            <div class="{{ $card }}">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">CPU %</h3>
                <x-minecraft-toolkit::sparkline :series="$this->series('cpu')" height-classes="h-24" aria-label="CPU percent"/>
            </div>

            <div class="{{ $card }}">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">Memory of the server</h3>
                <x-minecraft-toolkit::sparkline :series="$this->series('memory')" height-classes="h-24" aria-label="Memory of the server"/>
            </div>
        </div>

        {{-- ---- Worlds breakdown --------------------------------------------------- --}}
        <div class="{{ $card }}">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                {{ __('minecraft-toolkit::pages.performance.worlds_header') }}
            </h2>

            @if (!empty($worlds))
                <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($worlds as $world)
                        @continue(! is_array($world) || empty($world['name']))
                        <div class="{{ $smallCard }}">
                            <div class="flex items-center justify-between gap-x-2">
                                <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $world['name'] }}</span>
                                @if (filled($world['badge'] ?? null))
                                    <span class="{{ $chip }}">{{ $world['badge'] }}</span>
                                @endif
                            </div>

                            <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                                <div>
                                    <dt class="{{ $statLabel }} text-[11px]">Players</dt>
                                    <dd class="tabular-nums {{ is_int($world['players'] ?? null) ? '' : 'text-gray-400 dark:text-gray-500' }}">
                                        {!! is_int($world['players'] ?? null) ? e(strval($world['players'])) : '&mdash;' !!}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="{{ $statLabel }} text-[11px]">{{ __('minecraft-toolkit::pages.performance.tiles.entities') }}</dt>
                                    <dd class="tabular-nums {{ is_int($world['entities'] ?? null) ? '' : 'text-gray-400 dark:text-gray-500' }}">
                                        {!! is_int($world['entities'] ?? null) ? e(number_format($world['entities'], 0, '.', ',')) : '&mdash;' !!}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="{{ $statLabel }} text-[11px]">{{ __('minecraft-toolkit::pages.performance.tiles.chunks') }}</dt>
                                    <dd class="tabular-nums {{ is_int($world['chunks'] ?? null) ? '' : 'text-gray-400 dark:text-gray-500' }}">
                                        {!! is_int($world['chunks'] ?? null) ? e(number_format($world['chunks'], 0, '.', ',')) : '&mdash;' !!}
                                    </dd>
                                </div>
                            </dl>

                            @if (!empty($world['top']))
                                <div class="mt-3 border-t border-gray-100 pt-2 dark:border-white/10">
                                    <span class="{{ $statLabel }} text-[11px]">Top entities</span>
                                    <div class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($world['top'] as $entity)
                                            <span class="{{ $chip }}">
                                                {{ $entity['type'] }} {{ number_format(intval($entity['count']), 0, '.', ',') }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-sm text-gray-400 dark:text-gray-500">{{ __('minecraft-toolkit::common.empty.nothing_here') }}</p>
            @endif
        </div>

    </div>

</x-filament-panels::page>
