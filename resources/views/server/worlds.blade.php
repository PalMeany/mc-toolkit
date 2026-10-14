{{--
    Minecraft Toolkit — Worlds page body.

    Backed by McToolkit\Filament\Server\Pages\WorldsPage (Livewire state):
        $worlds        list<row> per WorldsService::listWorlds()
        $detection     DetectionService::detect() payload
        $pendingReset  ?array{name,paths} confirmation sheet model
                       (import runs through the mounted `import` Action modal)
        $pendingRestart ?array{level} restart-suggestion callout
        $restartArmed  bool two-click confirm guard for the power action

    Demo parity anchors: Worlds counter, Default/Overworld/Nether/End chips,
    one middot-separated tertiary meta line (approx size · Minecraft ver · mode ·
    Played date · seed mono-clipped), Download/Reset (+ Play-on) row actions and
    the informative empty-state fallback.

    NOTE (lang gaps #worlds.*): chips/labels below reuse ONLY existing plugin keys;
    everything else renders exact demo-recon English literals — each flagged in the
    agent handoff notes as a lang-gap list entry.
--}}

<x-filament-panels::page>
    @php
        // No `use` imports here: this block compiles inside the page component's
        // `if (...):` wrapper, where PHP only accepts fully-qualified names.
        $canManage = $this->allows('manage');
        $canAdminister = $this->allows('administer');

        $chipNeutral = 'inline-flex items-center justify-center rounded-md bg-gray-50 px-2 py-0.5 '
            .'text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-600/10 '
            .'dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';
        $chipAccent = 'inline-flex items-center justify-center rounded-md bg-info-50 px-2 py-0.5 '
            .'text-xs font-medium text-info-700 ring-1 ring-inset ring-info-600/20 '
            .'dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/30';
        $chipSuccess = 'inline-flex items-center justify-center rounded-md bg-success-50 px-2 py-0.5 '
            .'text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 '
            .'dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30';

        $dimensionLabels = [
            'overworld' => 'Overworld', // lang gap #worlds.chips.overworld
            'nether' => 'Nether',       // lang gap #worlds.chips.nether
            'end' => 'End',             // lang gap #worlds.chips.end
            'custom' => 'Custom',       // lang gap #worlds.chips.custom
        ];

        /** Middle-truncated mono seed, max 12 chars visible ("123…9042"). */
        $seedClip = static function (?string $seed): ?string {
            if ($seed === null || $seed === '') {
                return null;
            }

            return mb_strlen($seed) <= 12
                ? $seed
                : mb_substr($seed, 0, 5).'…'.mb_substr($seed, -5);
        };

        /** "Minecraft 1.21.3" when Version.Name exists. */
        $mcVersionLabel = static function (?string $version): ?string {
            return filled($version) ? 'Minecraft '.strval($version) : null; // lang gap #worlds.meta.minecraft
        };

        /** Playable-date chip label from level.dat LastPlayed ISO stamp. */
        $playedLabel = static function (?string $iso): ?string {
            if ($iso === null || $iso === '') {
                return null;
            }

            try {
                return 'Played '.\Illuminate\Support\Carbon::parse($iso)->translatedFormat('M j, Y'); // lang gap #worlds.meta.played
            } catch (\Throwable) {
                return null;
            }
        };
    @endphp

    {{-- Detection banner pinned top of every toolkit page --}}
    <x-dynamic-component
        :component="'minecraft-toolkit::detection-banner'"
        :software="$detection"
    />

    {{-- Suggested restart callout (Play-on / Reset follow-through) --}}
    @if ($pendingRestart)
        <div class="flex w-full flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-warning-600/20 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900 dark:ring-warning-400/30">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <span class="font-semibold">{{ __('minecraft-toolkit::pages.worlds.title') }}:</span>
                {{ $pendingRestart['level'] }}
                <span class="mx-1 text-gray-400 dark:text-gray-500">&middot;</span>
                Restart to apply the change. {{-- lang gap #worlds.restart_note --}}
            </p>

            <div class="flex items-center gap-2">
                @if (! $restartArmed)
                    <x-filament::button color="danger" size="sm" wire:click="armRestart">
                        {{ trans('server/console.power_actions.restart') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="dismissRestart">
                        {{ __('minecraft-toolkit::common.buttons.close') }}
                    </x-filament::button>
                @else
                    <x-filament::button color="danger" size="sm" icon="tabler-player-play-filled" wire:click="doRestart">
                        {{ __('minecraft-toolkit::common.buttons.confirm') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="dismissRestart">
                        {{ __('minecraft-toolkit::common.buttons.cancel') }}
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif

    {{-- Worlds card --}}
    <div class="relative flex flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-white/10">
            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ __('minecraft-toolkit::pages.worlds.title') }} {{ count($worlds) }} {{-- lang gap #worlds.counter --}}
            </span>
        </div>

        <div class="divide-y divide-gray-200 overflow-x-auto dark:divide-white/10">
            @forelse ($worlds as $world)
                @php
                    $isDefaultRow = (bool) ($world['default'] ?? false);

                    $metaBits = array_values(array_filter([
                        (($world['approximate'] ?? false) ? '~' : '').\convert_bytes_to_readable(max(0, (int) ($world['size'] ?? 0))),
                        $mcVersionLabel($world['version'] ?? null),
                        strval($world['game_mode'] ?? ''),
                        $playedLabel($world['last_played'] ?? null),
                    ], static fn ($bit): bool => filled($bit)));

                    $hardcoreBit = ($world['hardcore'] ?? false) ? 'Hardcore' : ''; // lang gap #worlds.chips.hardcore
                    $downloadEnabled = $canManage;
                @endphp

                <div class="flex flex-col gap-2 p-4 transition duration-75 hover:bg-gray-50 sm:flex-row sm:items-start sm:justify-between sm:p-6 dark:hover:bg-white/5">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <p class="truncate text-sm font-bold text-gray-950 dark:text-white" title="{{ $world['name'] }}">
                                {{ $world['name'] }}
                            </p>

                            @if ($isDefaultRow)
                                <span class="{{ $chipSuccess }}">Default</span> {{-- lang gap #worlds.chips.default --}}
                            @endif

                            <span class="{{ $chipNeutral }}">{{ $dimensionLabels[strval($world['dimension'] ?? 'custom')] ?? ucfirst(strval($world['dimension'] ?? 'custom')) }}</span>

                            @if (filled($hardcoreBit))
                                <span class="{{ $chipAccent }}">{{ $hardcoreBit }}</span>
                            @endif
                        </div>

                        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">
                            {!! implode('<span class="mx-1 text-gray-400 dark:text-gray-500">&middot;</span>', array_map('e', $metaBits)) !!}
                            @if (filled($world['seed'] ?? null))
                                <span class="mx-1 text-gray-400 dark:text-gray-500">&middot;</span>
                                Seed {{-- lang gap #worlds.meta.seed --}}
                                <span class="font-mono" title="{{ $world['seed'] }}">{{ $seedClip(strval($world['seed'])) }}</span>
                            @endif
                        </p>

                        @if ($world['linked'] ?? false)
                            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                {{ __('minecraft-toolkit::pages.worlds.rows.part_of_default') }}
                            </p>
                        @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 pt-2 sm:pt-0">
                        @unless ($downloadEnabled)
                            <x-filament::button color="primary" size="sm" :disabled="true" icon="tabler-download">
                                {{ __('minecraft-toolkit::common.buttons.download') }}
                            </x-filament::button>
                        @else
                            <x-filament::button color="primary" size="sm" icon="tabler-download" wire:click="downloadWorld({{ \Illuminate\Support\Js::from($world['name']) }})">
                                {{ __('minecraft-toolkit::common.buttons.download') }}
                            </x-filament::button>
                        @endunless

                        @if ($canAdminister)
                            <x-filament::button color="danger" size="sm" icon="tabler-trash" wire:click="confirmReset({{ \Illuminate\Support\Js::from($world['name']) }})">
                                {{ __('minecraft-toolkit::common.buttons.reset') }}
                            </x-filament::button>
                        @endif

                        @if ($canManage && ! $isDefaultRow && ($world['linked'] ?? false) === false)
                            <x-filament::button color="gray" size="sm" icon="tabler-player-play-filled" wire:click="playOn({{ \Illuminate\Support\Js::from($world['name']) }})">
                                Play on {{-- lang gap #worlds.actions.play_on --}}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @empty
                {{-- Informative fallback: loader mismatch or fresh install --}}
                <div class="flex flex-col items-center gap-3 p-8 text-center sm:p-12">
                    <span class="grid size-12 place-items-center rounded-xl bg-gray-50 text-gray-400 ring-1 ring-inset ring-gray-950/5 dark:bg-gray-400/10 dark:text-gray-500 dark:ring-white/10">
                        <x-filament::icon icon="tabler-world-search" />
                    </span>

                    <p class="max-w-md text-sm text-gray-500 dark:text-gray-400">
                        No world folders were detected yet. A fresh Minecraft server creates its first world at startup; loaders like Velocity keep worlds elsewhere and cannot host them here. {{-- lang gap #worlds.empty.title --}}
                    </p>

                    <p class="max-w-md text-xs text-gray-400 dark:text-gray-500">
                        Pulling an external archive in via “{{ __('minecraft-toolkit::pages.worlds.import_link') }}” drops a ready-to-run world straight onto this server. {{-- lang gap #worlds.empty.import_hint --}}
                    </p>

                    @if ($canAdminister)
                        <x-filament::button color="primary" size="sm" icon="tabler-cloud-upload" wire:click="mountAction('import')">
                            {{ __('minecraft-toolkit::pages.worlds.import_link') }}
                        </x-filament::button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

    {{-- Reset confirmation sheet --}}
    @if ($pendingReset)
        <div class="fixed inset-0 z-40 bg-gray-950/50 backdrop-blur-sm dark:bg-gray-950/75" wire:click="cancelReset"></div>

        <div class="fixed inset-0 z-40 overflow-y-auto p-6">
            <div class="relative mx-auto mt-24 flex w-full max-w-lg flex-col gap-4 rounded-xl bg-white p-6 shadow-xl outline-none ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start justify-between gap-4">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('minecraft-toolkit::common.buttons.reset') }}: {{ $pendingReset['name'] }}
                    </h3>

                    <button type="button" wire:click="cancelReset" class="-m-1 p-1 text-gray-400 hover:text-gray-950 dark:hover:text-white" aria-label="{{ __('minecraft-toolkit::common.buttons.close') }}">
                        ✕
                    </button>
                </div>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('minecraft-toolkit::pages.worlds.reset_note') }}
                </p>

                <ul class="max-h-48 space-y-1 overflow-y-auto font-mono text-xs text-gray-700 dark:text-gray-300">
                    @foreach ($pendingReset['paths'] as $path)
                        <li>/{{ $path }}</li>
                    @endforeach
                </ul>

                <div class="mt-2 flex items-center justify-end gap-2">
                    <x-filament::button color="gray" size="sm" wire:click="cancelReset">
                        {{ __('minecraft-toolkit::common.buttons.cancel') }}
                    </x-filament::button>
                    <x-filament::button color="danger" size="sm" icon="tabler-trash" wire:click="doReset">
                        {{ __('minecraft-toolkit::common.buttons.confirm') }}
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- Import now runs through the mounted `import` Filament Action modal
         (schema-backed URL + optional name fields on WorldsPage). --}}
</x-filament-panels::page>
