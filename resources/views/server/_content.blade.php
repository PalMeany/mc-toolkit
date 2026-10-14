{{--
    Minecraft Toolkit — SHARED BODY partial for Plugins / Mods / Modpacks pages.

    Consumes ONLY McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent public API;
    hosting page wrappers set one variable before including this file:

        ['kind' => 'plugins'|'mods'|'modpacks']

    Structure (demo parity v1.1.8):
      1. Detection banner pinned topmost (detection-banner component).
      2. Loader-mismatch / no-toolkit callout (per-page blockedNotice()) with
         an optional inline link toward the Versions page.
      3. Modpack prerequisite note (stopped container) when the box runs.
      4. Segmented tabs Browse | Installed(count badge).
      5. Toolbar: search · sort · source chips (used/skipped reason tooltips)
         · "Compatible with my server" filter.
      6. Result GRID of @include(content-card) with wire-driven Install CTAs.
      7. Pagination Previous/Next + page counter ("Page 1 / 1.7k").
      8. Installed tab: tracked rows (version chips + update dot/button) and the
         FOREIGN FILES section listing untouched directory jars with human sizes.

    Lang gaps accepted (documented in handoff notes, candidates for A1):
      sort option labels, prev/next aria-label texts, skipped-reason copy for
      no_key/request_error, pack-child tracker wording.
--}}
@php
    $kind = $kind ?? 'plugins';
    $section = $kind;

    $detected = $this->contentDetection();
    $compatLoaders = $this->compatLoaderTokens();
    $mcVersion = $this->compatMcVersion();

    /** Loader-mismatch / unsupported-server callout (null ⇒ free-running page). */
    $blocked = $this->blockedNotice();

    /**
     * Install-enablement matrix (parity intent, commented):
     *   - mods: disabled while a Bukkit-family server is detected (blocker present),
     *   - plugins: STAYS enabled even when detection is weak — pre-placing jars
     *     before a software swap is legitimate (documented decision),
     *   - modpacks: always offered; stop/safety failures return through friendly errors.
     */
    $installsEnabled = match ($kind) {
        'mods' => $blocked === null,
        'modpacks' => true,
        default => true,
    };

    $browse = $this->browsePayload();

    /** Upper bound honored from merged meta; loose when several registries contributed. */
    $pagesTotal = is_int($browse['pages']) && $browse['pages'] > 0 ? $browse['pages'] : null;
    $hasMorePages = $pagesTotal !== null ? $this->browsePage < $pagesTotal : count($browse['items']) >= 25;
    $canGoBack = $this->browsePage > 1;

    $allowedSourceKeys = $this->allowedSources();
    $sourcesUsed = array_values(array_intersect($allowedSourceKeys, $browse['sources_used']));
    $sourcesSkipped = [];

    foreach ($allowedSourceKeys as $sourceKey) {
        if (! in_array($sourceKey, $sourcesUsed, true)) {
            $sourcesSkipped[$sourceKey] = $browse['sources_skipped'][$sourceKey] ?? null;
        }
    }

    /** wire argument escaper — keeps hangar Owner/Slug ids quote-safe inside JS calls. */
    $jsArg = static fn (string $value): string => addcslashes($value, "\\'");

    /** Installation busy key mirrors InteractsWithContent::install(). */
    $busyKeyOf = static fn (array $item): string => hash('crc32b', ($item['source'] ?? '').'|'.($item['id'] ?? '').'|'.($item['title'] ?? ''));

    $chipBase = 'inline-flex items-center justify-center gap-x-1 rounded-md px-2 py-0.5 '
        .'text-xs font-medium ring-1 ring-inset';

    $chipNeutral = $chipBase.' bg-gray-50 text-gray-600 ring-gray-600/10 '
        .'dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';

    $segIdle = 'inline-flex items-center justify-center gap-x-1.5 rounded-lg px-3 py-2 text-sm font-medium '
        .'text-gray-500 ring-1 ring-transparent hover:bg-gray-100 hover:text-gray-700 '
        .'dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200';

    $segActive = 'inline-flex items-center justify-center gap-x-1.5 rounded-lg px-3 py-2 text-sm font-semibold '
        .'bg-white text-gray-950 shadow-sm ring-1 ring-gray-950/5 '
        .'dark:bg-gray-800 dark:text-white dark:ring-white/10';

    $updateDot = 'inline-block size-2 rounded-full bg-warning-600 ring-1 ring-inset ring-warning-500/30 '
        .'dark:bg-warning-400';
@endphp

{{-- --------------------------------------------------------------- --}}
{{-- 1. Detection banner — pinned top of every toolkit page          --}}
{{-- --------------------------------------------------------------- --}}
<x-dynamic-component :component="'minecraft-toolkit::components.detection-banner'" :software="$detected" />

{{-- --------------------------------------------------------------- --}}
{{-- 2. Loader-mismatch / unsupported callout                        --}}
{{-- --------------------------------------------------------------- --}}
@if ($blocked !== null)
    <x-filament::callout color="warning" icon="tabler-alert-triangle">
        <x-slot name="heading">
            {{ $blocked['title'] ?? trans("minecraft-toolkit::pages.{$section}.title") }}
        </x-slot>

        <x-slot name="description">
            <span class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm leading-6">
                <span>{{ $blocked['message'] }}</span>

                @if (! empty($blocked['versions_url']))
                    {{-- Inline guidance link — the callout copy itself steers to Versions;
                          this makes that jump one click. --}}
                    <a href="{{ $blocked['versions_url'] }}"
                       class="inline-flex items-center gap-x-1 font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">
                        Versions
                        <x-filament::icon :icon="'tabler-arrow-right'" class="size-3.5 shrink-0" />
                    </a>
                @endif
            </span>
        </x-slot>
    </x-filament::callout>
@endif

{{-- --------------------------------------------------------------- --}}
{{-- 3. Modpack prerequisite: stopped-container reminder             --}}
{{-- --------------------------------------------------------------- --}}
@if ($kind === 'modpacks' && $this->containerRequiresStop())
    <x-filament::callout color="info" icon="tabler-player-stop">
        <x-slot name="description">
            <span class="text-sm leading-6">
                {{ trans('minecraft-toolkit::pages.versions.install.stopped_note') }}
                {{ trans('minecraft-toolkit::errors.statuses.stopped_required') }}
            </span>
        </x-slot>
    </x-filament::callout>
@endif

{{-- --------------------------------------------------------------- --}}
{{-- 4. Segmented tabs                                               --}}
{{-- --------------------------------------------------------------- --}}
<div class="inline-flex w-fit items-center gap-1 rounded-xl p-1 bg-gray-100 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
    <button type="button"
            wire:click="switchTab('browse')"
            class="{{ $tab === 'browse' ? $segActive : $segIdle }}">
        {{ trans('minecraft-toolkit::common.buttons.browse') }}
    </button>

    <button type="button"
            wire:click="switchTab('installed')"
            class="{{ $tab === 'installed' ? $segActive : $segIdle }}">
        {{ trans('minecraft-toolkit::common.buttons.installed') }}

        <span class="{{ $chipNeutral }}">{{ $this->installedCount() }}</span>
    </button>
</div>

@if ($tab === 'installed')
    {{-- ============================================================ --}}
    {{-- INSTALLED TAB                                                --}}
    {{-- ============================================================ --}}

    @php
        $installedRows = $this->installedPayload()['primary'];
        $foreignFiles = $this->installedPayload()['foreign'];
    @endphp

    <div class="flex flex-col gap-6">
        {{-- Primary tracked list ---------------------------------------- --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 divide-y divide-gray-200 dark:bg-gray-900 dark:ring-white/10 dark:divide-white/10">
            @forelse ($installedRows as $rowRow)
                <div class="relative flex flex-wrap items-center gap-x-3 gap-y-1.5 p-4 first:rounded-t-xl last:rounded-b-xl"
                     wire:key="tracked-{{ md5($rowRow['file_name']) }}">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                           title="{{ $rowRow['display_name'] }}{{ $rowRow['update_available'] ? ' — '.($rowRow['update_time'] ?? '') : '' }}">
                            @if ($rowRow['update_available'])
                                <span class="{{ $updateDot }}"></span>
                            @endif

                            {{ $rowRow['display_name'] }}

                            @if ($rowRow['is_pack_child'])
                                <span class="{{ $chipNeutral }}">+pack</span>
                            @endif
                        </p>

                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            @if (! is_null($rowRow['version']))
                                <span class="{{ $chipNeutral }}">{{ $rowRow['version'] }}</span>
                            @endif

                            <span class="{{ $chipNeutral }}">{{ $rowRow['registry_label'] }}</span>

                            @if (! is_null($rowRow['updated_at_human']))
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $rowRow['updated_at_human'] }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($rowRow['installable'] && $this->userCanMutate())
                        @php
                            $updateTarget = sprintf(
                                "updateTracked('%s', '%s', '%s', '%s')",
                                $jsArg((string) $rowRow['file_name']),
                                $jsArg($rowRow['registry']),
                                $jsArg((string) ($rowRow['row']->project_id ?? '')),
                                $jsArg($rowRow['display_name']),
                            );
                        @endphp

                        <x-filament::button
                            :color="$rowRow['update_available'] ? 'primary' : 'gray'"
                            :size="'sm'"
                            wire:click="{{ $updateTarget }}"
                            wire:target="{{ $updateTarget }}"
                            wire:loading.attr="disabled">
                            {{ trans('minecraft-toolkit::common.buttons.update') }}

                            <x-filament::loading-indicator wire:loading wire:target="{{ $updateTarget }}" class="h-4 w-4 shrink-0" />
                        </x-filament::button>

                        {{-- Busy veil covering the whole row while its update runs -------- --}}
                        <span class="absolute inset-0 grid place-items-center rounded-lg bg-white/40 backdrop-blur-[1px] dark:bg-gray-900/40"
                              wire:loading
                              wire:target="{{ $updateTarget }}">
                            <x-filament::loading-indicator class="size-5 text-primary-600 dark:text-primary-400" />
                        </span>
                    @endif
                </div>
            @empty
                <div class="p-8 text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ trans('minecraft-toolkit::common.empty.nothing_here') }}
                    </p>
                </div>
            @endforelse
        </div>

        {{-- Foreign files section (demo-exact lines) ---------------------- --}}
        @if (count($foreignFiles) > 0)
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-4 sm:p-5 dark:bg-gray-900 dark:ring-white/10">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ trans('minecraft-toolkit::pages.plugins.foreign_section.title') }}
                </h3>

                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                    {{ trans('minecraft-toolkit::pages.plugins.foreign_section.desc') }}
                </p>

                <ul class="mt-3 divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($foreignFiles as $foreignEntry)
                        <li class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                            wire:key="foreign-{{ md5($foreignEntry['name']) }}">
                            <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-300">{{ $foreignEntry['name'] }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $foreignEntry['human_size'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@else
    {{-- ============================================================ --}}
    {{-- BROWSE TAB                                                   --}}
    {{-- ============================================================ --}}

    {{-- Toolbar ------------------------------------------------------ --}}
    <div class="flex flex-wrap items-end gap-x-3 gap-y-3">
        <label class="fi-input-wrp flex min-w-52 flex-1 items-center gap-x-2 px-3 py-2 sm:max-w-md"
               wire:target="runSearch">
            <x-filament::icon :icon="'tabler-search'" class="size-4 shrink-0 text-gray-400 dark:text-gray-500" />

            <input type="search"
                   name="query"
                   class="fi-input w-full border-0 bg-transparent px-0 py-0 text-sm leading-6 placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:bg-transparent"
                   placeholder="{{ trans('minecraft-toolkit::common.buttons.search') }}"
                   wire:model.live.debounce.400ms="query"
                   wire:keydown.enter="runSearch"
                   autocomplete="off" />
        </label>

        <select wire:model.live.debounce.300ms="sortKey"
                class="block rounded-lg border-0 bg-white py-2 pe-8 ps-3 text-sm leading-6 text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20"
                aria-label="Sort results">
            @foreach ($this->sortOptions() as $sortOption)
                <option value="{{ $sortOption['value'] }}" @selected($sortKey === $sortOption['value'])>{{ $sortOption['label'] }}</option>
            @endforeach
        </select>

        <button type="button"
                wire:click="runSearch"
                class="{{ $segIdle }} lg:hidden"
                wire:loading.attr="disabled"
                wire:target="runSearch">
            {{ trans('minecraft-toolkit::common.buttons.search') }}
        </button>
    </div>

    {{-- Source chips + compat filter ----------------------------------- --}}
    <div class="flex flex-col gap-y-2">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-2" role="group" aria-label="{{ __('Sources') }}">
            @foreach ($allowedSourceKeys as $sourceKey)
                @php
                    $isSelected = $source === $sourceKey;
                    $skipReason = $sourcesSkipped[$sourceKey] ?? null;

                    $chipClass = $isSelected
                        ? $chipBase.' bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30'
                        : ($skipReason !== null
                            ? $chipBase.' bg-gray-50 text-gray-400 ring-gray-600/10 opacity-70 cursor-not-allowed dark:bg-gray-400/5 dark:text-gray-500 dark:ring-gray-400/10'
                            : $chipBase.' bg-gray-50 text-gray-600 ring-gray-600/10 hover:bg-gray-100 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20 dark:hover:bg-gray-400/20');
                @endphp

                <button type="button"
                        class="{{ $chipClass }}"
                        title="{{ $skipReason !== null ? $this->skipReasonText($skipReason) : $this->sourceLabel($sourceKey) }}"
                        @unless ($skipReason !== null)
                            wire:click="setSource({{ $isSelected ? 'null' : "'".$jsArg($sourceKey)."'" }})"
                        @endunless>
                    {{ $this->sourceLabel($sourceKey) }}
                </button>
            @endforeach

            {{-- Demo-parity compat checkbox: dynamic parenthesized loader tokens · MC version --}}
            <label class="ms-auto flex cursor-pointer items-center gap-x-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                <input type="checkbox"
                       value="1"
                       class="size-4 rounded border-0 text-primary-600 shadow-sm ring-1 ring-gray-950/10 checked:bg-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:ring-offset-gray-100 dark:focus:ring-offset-gray-900"
                       @checked($compatOnly)
                       wire:click="toggleCompat" />

                <span>{{ trans('minecraft-toolkit::pages.plugins.compat_filter_label', [
                    'loaders' => count($compatLoaders) > 0 ? implode(', ', array_slice($compatLoaders, 0, 3)).(count($compatLoaders) > 3 ? ', …' : '') : '—',
                    'version' => $mcVersion ?? '—',
                ]) }}</span>
            </label>
        </div>

        {{-- Full token list visible under the condensed checkbox label -------------------- --}}
        @if ($compatLoaders !== [] || ! is_null($mcVersion))
            <p class="w-full text-xs text-gray-500 dark:text-gray-400">
                {{ implode(', ', $compatLoaders) }}@if (! is_null($mcVersion)) &middot; {{ $mcVersion }}@endif
            </p>
        @endif
    </div>

    {{-- Results grid ---------------------------------------------------- --}}
    @if (count($browse['items']) > 0)
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($browse['items'] as $resultItem)
                @php
                    $cardKey = $resultItem['key'];
                    $cardInstalled = (bool) ($resultItem['installed'] ?? false);
                    $cardBusyHash = $busyKeyOf($resultItem);
                    $actionLabel = ($resultItem['title'] ?? '') !== '' ? (string) $resultItem['title'] : 'project';
                    $actionArgs = sprintf(
                        "install('%s', '%s', '%s')",
                        $jsArg((string) ($resultItem['source'] ?? '')),
                        $jsArg((string) ($resultItem['id'] ?? '')),
                        $jsArg($actionLabel),
                    );

                    // Attribute bag lands on the content-card DEFAULT CTA (its contract),
                    // carrying the Livewire directives + busy-key disabled binding.
                    $ctaBag = new \Illuminate\View\ComponentAttributeBag([
                        'wire:key' => 'mct-cta-'.$cardKey,
                        'wire:click' => $actionArgs,
                        'wire:target' => $actionArgs,
                        'wire:loading.attr' => 'disabled',
                        ':disabled' => "\$wire.installingIds.includes('{$cardBusyHash}')",
                    ]);
                @endphp

                <div class="relative h-full"
                     wire:key="card-{{ $cardKey }}">
                    @include('minecraft-toolkit::components.content-card', [
                        'item' => $resultItem,
                        'installed' => $cardInstalled,
                        'withAction' => $installsEnabled && $this->userCanMutate(),
                        'attributes' => $ctaBag,
                    ])

                    {{-- Busy overlay scoped to THIS card's action ------------------------- --}}
                    <span class="absolute end-3 top-3 grid size-5 place-items-center"
                          wire:loading
                          wire:target="{{ $actionArgs }}">
                        <x-filament::loading-indicator class="size-5 text-primary-600 dark:text-primary-400" />
                    </span>
                </div>
            @endforeach
        </div>
    @else
        {{-- Nothing found OR every upstream failed/skipped: honest empty surface ---------- --}}
        <div class="rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 p-10 text-center dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ trans('minecraft-toolkit::common.buttons.no_results') }}
            </p>

            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ trans('minecraft-toolkit::common.empty.nothing_here') }}
            </p>

            @if (count($sourcesUsed) === 0 && $sourcesSkipped !== [])
                <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Sources unavailable').': '.implode(', ', array_map(
                        fn ($key, $reason) => $this->sourceLabel($key).' ('.$this->skipReasonText((string) $reason).')',
                        array_keys($sourcesSkipped),
                        $sourcesSkipped,
                    )) }}
                </p>
            @endif
        </div>
    @endif

    {{-- Pagination --------------------------------------------------------- --}}
    <div class="flex items-center justify-between gap-3">
        <button type="button"
                wire:click="previousPage"
                @unless ($canGoBack) disabled @endunless
                class="{{ $segIdle }} {{ $canGoBack ? '' : 'pointer-events-none opacity-40' }}"
                wire:loading.attr="disabled"
                wire:target="previousPage"
                aria-label="Previous page">
            <x-filament::icon :icon="'tabler-chevron-left'" class="size-4 shrink-0" />

            Previous
        </button>

        <span class="text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400">
            {{ 'Page '.number_format(max(1, $this->browsePage)).' / '.number_format(max(1, $pagesTotal ?? $this->browsePage)) }}
        </span>

        <button type="button"
                wire:click="nextPage"
                @unless ($hasMorePages) disabled @endunless
                class="{{ $segIdle }} {{ $hasMorePages ? '' : 'pointer-events-none opacity-40' }}"
                wire:loading.attr="disabled"
                wire:target="nextPage"
                aria-label="Next page">
            Next

            <x-filament::icon :icon="'tabler-chevron-right'" class="size-4 shrink-0" />
        </button>
    </div>
@endif
