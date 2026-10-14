{{--
    Minecraft Toolkit — Versions page.
    Fed by McToolkit\Filament\Server\Pages\VersionsPage (public Livewire props only).
    Design tokens: .research/design-system.md — zinc grays, ring-gray-950/5 cards,
    semantic colors only, dark: variants everywhere.
--}}
<x-filament-panels::page>
    @php
        $chip = 'inline-flex items-center justify-center gap-x-1 rounded-md bg-gray-50 px-2 py-0.5 '
            .'text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-600/10 '
            .'dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';
        $chipSuccess = 'inline-flex items-center justify-center gap-x-1 rounded-md bg-success-50 px-2 py-0.5 '
            .'text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 '
            .'dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30';
        $tabBtn = 'rounded-lg px-3 py-1.5 text-sm font-medium transition duration-75';
        $buckets = ['recommended','established','experimental','miscellaneous','limbo'];
        $tabs = ['releases' => 'minecraft-toolkit::pages.versions.tab.releases',
                 'snapshots' => 'minecraft-toolkit::pages.versions.tab.snapshots',
                 'all' => 'minecraft-toolkit::pages.versions.tab.all'];
    @endphp

    {{-- ------------------------------------------------------- detection banner --}}
    <x-dynamic-component
        :component="'minecraft-toolkit::components.detection-banner'"
        :software="$profile ?? []"
    />

    {{-- ------------------------------------------------------- profile cluster --}}
    <div class="flex flex-wrap items-center justify-end gap-x-2 gap-y-1">
        <span class="{{ $chip }}">
            <x-filament::icon :icon="'tabler-server'" class="size-3.5 shrink-0" />
            {{ ucfirst((string) ($profile['software'] ?? 'unknown')) }}
            @if (filled($profile['version'] ?? null)) {{ $profile['version'] }} @endif
        </span>
        @if ($profile['proxy'] ?? false)
            <span class="inline-flex items-center justify-center rounded-md bg-info-50 px-2 py-0.5 text-xs font-medium text-info-700 ring-1 ring-inset ring-info-600/20 dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/30">
                Proxy
            </span>
        @endif
    </div>

    {{-- ------------------------------------------------------- bucket tabs --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
        @foreach ($buckets as $b)
            <button type="button"
                    wire:click="$set('bucket', '{{ $b }}')"
                    class="{{ $tabBtn }} @if (($bucket ?? 'recommended') === $b)
                               bg-primary-600 text-white shadow-sm hover:bg-primary-500
                           @else
                               bg-white text-gray-950 ring-1 ring-inset ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10
                           @endif">
                {{ __("minecraft-toolkit::pages.versions.filter.$b") }}
            </button>
        @endforeach
    </div>

    {{-- ------------------------------------------------------- type chips --}}
    <div class="-mx-1 flex flex-wrap gap-x-2 gap-y-2 px-1">
        @forelse ($this->typeChips as $t)
            <button type="button"
                    wire:click="$set('type', '{{ $t['key'] }}')"
                    class="inline-flex items-center gap-x-1.5 rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset transition duration-75
                           @if (($type ?? '') === $t['key'])
                               bg-primary-50 text-primary-700 ring-primary-600/25 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30
                           @else
                               bg-white text-gray-950 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10
                           @endif">
                {{ $t['label'] }}
                @if ($t['installed'])
                    <span class="{{ $chipSuccess }}">{{ __('minecraft-toolkit::common.badges.installed') }}</span>
                @endif
            </button>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('minecraft-toolkit::common.empty.nothing_here') }}</p>
        @endforelse
    </div>

    {{-- ------------------------------------------------------- selected type hero --}}
    @if (filled(($sel = collect($this->typeChips)->firstWhere('key', $type))['label'] ?? null))
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $sel['label'] }}</h3>
            @if (filled($sel['desc']))
                <p class="mt-1 max-w-2xl text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $sel['desc'] }}</p>
            @endif
        </div>
    @endif

    {{-- ------------------------------------------------------- filter row --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-2" role="group" aria-label="{{ __('minecraft-toolkit::pages.versions.title') }}">
            @foreach ($tabs as $key => $nsKey)
                <button type="button"
                        wire:click="setTab('{{ $key }}')"
                        class="{{ $tabBtn }} @if (($tab ?? 'releases') === $key)
                                   bg-primary-600 text-white shadow-sm hover:bg-primary-500
                               @else
                                   bg-white text-gray-950 ring-1 ring-inset ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:hover:bg-white/10
                               @endif">
                    {{ __($nsKey) }}
                </button>
            @endforeach
        </div>

        <div class="w-full sm:w-64">
            <div class="fi-input-wrp flex rounded-lg bg-white shadow-sm ring-1 ring-gray-950/10 focus-within:ring-2 focus-within:ring-primary-600 dark:bg-white/5 dark:ring-white/20 dark:focus-within:ring-primary-500">
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="{{ __('minecraft-toolkit::common.buttons.search') }}"
                    aria-label="{{ __('minecraft-toolkit::common.buttons.search') }}"
                    class="fi-input w-full border-none bg-transparent px-3 py-1.5 text-sm leading-6 text-gray-950 outline-none placeholder:text-gray-400 dark:text-white"
                />
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------- version list --}}
    <div class="relative flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($this->versionProps as $row)
                <li class="flex items-center justify-between gap-3 px-4 py-3 transition duration-75 hover:bg-gray-50 sm:px-6 dark:hover:bg-white/5">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $row['id'] }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            @if (! is_null($row['java']))
                                <span class="{{ $chip }}" title="Java {{ $row['java'] }}">
                                    <x-filament::icon :icon="'tabler-coffee'" class="size-3.5 shrink-0" />
                                    Java {{ $row['java'] }}
                                </span>
                            @endif
                            @if (! is_null($row['builds']))
                                <span class="{{ $chip }}" title="{{ $row['builds'] }}">
                                    <x-filament::icon :icon="'tabler-stack'" class="size-3.5 shrink-0" />
                                    {{ number_format((int) $row['builds']) }}
                                </span>
                            @endif
                            @if ($row['installed'])
                                <span class="{{ $chipSuccess }}">{{ __('minecraft-toolkit::common.badges.installed') }}</span>
                            @endif
                        </div>
                    </div>

                    <x-filament::button
                        :color="'primary'"
                        :size="'sm'"
                        {{-- $this->type = selected catalog type id (PAPER…); the row's own
                             `type` field is McJars' RELEASE|SNAPSHOT|BETA release kind and
                             would build an invalid /types/{release-kind}/… URL --}}
                        wire:click="askInstall('{{ $this->type }}', '{{ $row['id'] }}')"
                        wire:loading.attr="disabled"
                        wire:target="runInstall(), askInstall()"
                        :disabled="$busy"
                    >
                        {{ __('minecraft-toolkit::common.buttons.install') }}
                    </x-filament::button>
                </li>
            @empty
                <li class="px-4 py-10 text-center sm:px-6">
                    <x-filament::icon :icon="'tabler-search'" class="mx-auto size-8 text-gray-400 dark:text-gray-500" />
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('minecraft-toolkit::common.buttons.no_results') }}</p>
                </li>
            @endforelse
        </ul>

        @if ((count($this->versions) < $total) && count($this->versions) > 0)
            <div class="border-t border-gray-200 p-4 dark:border-white/10">
                <x-filament::button
                    :color="'gray'"
                    :outlined="true"
                    size="md"
                    class="w-full"
                    wire:click="showMore"
                    wire:loading.attr="disabled"
                    wire:target="showMore"
                >
                    @php($showMoreKey = 'minecraft-toolkit::pages.versions.show_more')
                    @php($showMore = __($showMoreKey))
                    {{ $showMore === $showMoreKey ? 'Show more' : $showMore }}
                </x-filament::button>
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------- install sheet --}}
    @if (! is_null($pending))
        <div class="fixed inset-0 z-40 bg-gray-900/50 dark:bg-gray-100/20" wire:click="closeAsk()"></div>

        <div class="fixed inset-x-4 top-1/2 z-40 mx-auto -translate-y-1/2 sm:inset-x-0 sm:max-w-sm" role="dialog" aria-modal="true">
            <div class="w-full rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
                <h4 class="text-base leading-6 font-semibold text-gray-950 dark:text-white">
                    {{ __('minecraft-toolkit::pages.versions.install.title', [
                        'software' => ucfirst(mb_strtolower((string) $pending['type'])),
                        'version' => $pending['version'],
                    ]) }}
                </h4>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('minecraft-toolkit::pages.versions.install.body') }}
                </p>

                <div class="mt-5 space-y-4">
                    {{-- backup first --}}
                    <label for="mct-backup-first" class="block">
                        <span class="flex items-start gap-x-3">
                            <input id="mct-backup-first" type="checkbox"
                                   wire:model="backupFirst"
                                   @unless (canBackup()) disabled @endunless
                                   class="mt-0.5 size-4 rounded ring-1 ring-gray-950/10 accent-primary-600 dark:ring-white/20" />
                            <span class="text-sm text-gray-950 dark:text-white">
                                {{ __('minecraft-toolkit::pages.versions.install.backup_first') }}
                            </span>
                        </span>
                        @unless (canBackup())
                            <span class="ml-7 mt-1 block text-xs text-gray-400 dark:text-gray-500">
                                {{ __('minecraft-toolkit::pages.versions.backup_unavailable') }}
                            </span>
                        @endunless
                    </label>

                    {{-- start after --}}
                    <label for="mct-start-after" class="block">
                        <span class="flex items-start gap-x-3">
                            <input id="mct-start-after" type="checkbox"
                                   wire:model="startAfter"
                                   class="mt-0.5 size-4 rounded ring-1 ring-gray-950/10 accent-primary-600 dark:ring-white/20" />
                            <span class="text-sm text-gray-950 dark:text-white">
                                {{ __('minecraft-toolkit::pages.versions.install.start_after') }}
                            </span>
                        </span>
                        <span class="ml-7 mt-1 block text-xs text-gray-400 dark:text-gray-500">
                            {{ __('minecraft-toolkit::pages.versions.install.stopped_note') }}
                        </span>
                    </label>
                </div>

                <div class="mt-6 flex items-center justify-end gap-x-3">
                    <x-filament::button
                        :color="'gray'"
                        :outlined="true"
                        size="sm"
                        wire:click="closeAsk()"
                    >
                        {{ __('minecraft-toolkit::common.buttons.cancel') }}
                    </x-filament::button>

                    <x-filament::button
                        :color="'primary'"
                        size="sm"
                        wire:click="runInstall()"
                        wire:loading.attr="disabled"
                        wire:target="runInstall()"
                        :disabled="$busy"
                    >
                        {{ __('minecraft-toolkit::common.buttons.install') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- busy overlay spinner --}}
    <div wire:loading wire:target="runInstall()" class="fixed inset-x-0 bottom-6 z-50 flex justify-center">
        <span class="{{ $chip }} shadow-lg">…</span>
    </div>
</x-filament-panels::page>
