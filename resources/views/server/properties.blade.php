{{--
    Minecraft Toolkit — Properties page (server panel).

    Pairs with McToolkit\Filament\Server\Pages\PropertiesPage:
      - detection banner pinned top, proxy / unknown-software / node-down callout beneath it,
      - Alpine chip filter row (visibleGroup drives per-section x-show attrs),
      - debounced live search bound to $search,
      - ONE big InteractsWithForms form split into Sections per group,
      - MOTD multiplayer-list preview card fed by $motdHtml / $motdCount / $overLimit.
--}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
>
    {{-- ------------------------------------------------------------------}}
    {{-- Detection banner (parity item on every toolkit page)             --}}
    {{-- ------------------------------------------------------------------}}
    <x-dynamic-component
        :component="'minecraft-toolkit::detection-banner'"
        :software="$this->detection ?? []"
    />

    @if (filled($this->loaderWarning))
        <x-filament::callout color="warning" :icon="'tabler-alert-triangle'">
            <x-slot name="description">
                {{ $this->loaderWarning }}
            </x-slot>
        </x-filament::callout>
    @endif

    {{-- ------------------------------------------------------------------}}
    {{-- Chip navigation + live search                                     --}}
    {{-- ------------------------------------------------------------------}}
    <div
        x-data="{ visibleGroup: 'all' }"
        class="fi-page-content flex flex-col gap-y-8"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="{{ __('minecraft-toolkit::pages.properties.title') }}">
                @php
                    $chips = [
                        'all' => __('minecraft-toolkit::pages.versions.tab.all'),
                        'general' => __('minecraft-toolkit::pages.properties.group.general'),
                        'players' => __('minecraft-toolkit::pages.properties.group.players'),
                        'world' => __('minecraft-toolkit::pages.properties.group.world'),
                        'network' => __('minecraft-toolkit::pages.properties.group.network'),
                        'advanced' => __('minecraft-toolkit::pages.properties.group.advanced'),
                    ];

                    $chipBase = 'inline-flex items-center justify-center rounded-lg px-3 py-1.5 '
                        .'text-sm font-medium transition duration-75 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2';
                    $chipIdle = 'bg-white text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 '
                        .'dark:bg-white/5 dark:text-gray-300 dark:ring-white/20 dark:hover:bg-white/10';
                    $chipActive = 'bg-primary-600 text-white ring-1 ring-inset ring-primary-600 '
                        .'dark:bg-primary-500 dark:ring-primary-500';
                @endphp

                @foreach ($chips as $chipKey => $chipLabel)
                    <button
                        type="button"
                        wire:ignore
                        class="{{ $chipBase }}"
                        :class="visibleGroup === '{{ $chipKey }}' ? '{{ $chipActive }}' : '{{ $chipIdle }}'"
                        @click="visibleGroup = '{{ $chipKey }}'"
                    >
                        {{ $chipLabel }}
                    </button>
                @endforeach
            </div>

            <div class="w-full sm:w-64">
                <x-filament::input.wrapper prefix-icon="tabler-search">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        :placeholder="__('minecraft-toolkit::common.buttons.search')"
                        :aria-label="__('minecraft-toolkit::common.buttons.search')"
                    />
                </x-filament::input.wrapper>
            </div>
        </div>

        {{-- ------------------------------------------------------------}}
        {{-- MOTD multiplayer-list preview                              --}}
        {{-- ------------------------------------------------------------}}
        <div
            x-show="visibleGroup === 'all' || visibleGroup === 'general'"
            class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10"
        >
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                    {{ __('minecraft-toolkit::pages.properties.group.general') }} · MOTD
                </p>

                <p class="@if ($this->overLimit) text-danger-600 dark:text-danger-400 font-semibold @else text-gray-400 dark:text-gray-500 @endif text-xs tabular-nums">
                    {{ $this->motdCount }}/{{ 50 }}
                </p>
            </div>

            {{-- Multiplayer server-list row simulation --}}
            <div class="mt-3 flex items-start gap-3 rounded-lg bg-gray-950 px-4 py-3 ring-1 ring-gray-950/5 dark:bg-black/40 dark:ring-white/10">
                <span class="grid size-8 shrink-0 place-items-center rounded-md bg-white/5 text-success-500 ring-1 ring-white/10">
                    <x-filament::icon :icon="'tabler-brand-minecraft'" class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $this->getRecord()->name }}
                    </p>

                    <div class="mt-0.5 break-words text-sm leading-relaxed text-gray-100 dark:text-gray-200 [overflow-wrap:anywhere]">
                        @if (filled($this->motdHtml))
                            {!! $this->motdHtml !!}
                        @else
                            <span class="text-gray-500 dark:text-gray-500">{{ __('minecraft-toolkit::common.empty.nothing_here') }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        {{-- --------------------------------------------------------------}}
        {{-- THE big form: Sections carry their own group footer actions   --}}
        {{-- --------------------------------------------------------------}}
        {{ $this->form }}

        @if ($this->noResults)
            <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('minecraft-toolkit::common.buttons.no_results') }}
            </p>
        @endif

    </div>
</x-filament-panels::page>
