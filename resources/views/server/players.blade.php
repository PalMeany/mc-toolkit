{{--
    Minecraft Toolkit — Players page (parity with the original addon demo, Pelican design system).

    Served by McToolkit\Filament\Server\Pages\PlayersPage; every interactive piece is a
    Livewire public prop / action on that class ($this). Detection banner stays pinned at
    the very top per the wave contract.
--}}

<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
>
    @php
        // ---- single fetch pass per request ------------------------------------
        $detection = $this->software();
        $snapshot = $this->playersSnapshot();
        $allPlayers = collect($snapshot['players'] ?? []);
        $counters = $snapshot['counters'] ?? ['online' => 0, 'max' => 0, 'ping_ok' => false];
        $canSend = $this->canSendCommands();
        $bridgeActive = $this->bridgeActive();
        $bridgePlayers = app(\McToolkit\Services\Servers\PlayerRegistry::class)->bridgePlayers($this->getRecord());
        $ipBans = $this->ipBans();

        $selectedUuid = filled($this->selected) ? strval($this->selected) : null;
        $selectedRow = $selectedUuid !== null ? $allPlayers->firstWhere('uuid', $selectedUuid) : null;
        $bridgeEntry = $selectedUuid !== null
            ? ($bridgePlayers[str_replace('-', '', $selectedUuid)] ?? [])
            : [];

        // ---- filtering (Online | All | Ops | Banned + search) ------------------
        $query = trim(strval($this->search));
        $filter = in_array($this->filter, ['online', 'all', 'ops', 'banned'], true) ? $this->filter : 'online';

        $visible = $allPlayers
            ->filter(function (array $row) use ($filter, $query): bool {
                if ($filter === 'online' && ! $row['online']) {
                    return false;
                }

                if ($filter === 'ops' && ! $row['op']) {
                    return false;
                }

                if ($filter === 'banned' && ! $row['banned']) {
                    return false;
                }

                if ($query !== '') {
                    $haystack = mb_strtolower(strval($row['name'])) . mb_strtolower(strval($row['uuid']));

                    return str_contains($haystack, mb_strtolower($query));
                }

                return true;
            })
            ->values();

        // ---- shared chrome recipes (design-system §2.2/§2.6/§8) ---------------
        $cardShell = 'rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
        $chip = 'inline-flex items-center justify-center gap-x-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset';
        $chipGray = $chip.' bg-gray-50 text-gray-600 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';
        $chipSuccess = $chip.' bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30';
        $chipOffline = $chip.' bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/30';
        $chipWarning = $chip.' bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30';

        $btnBase = 'inline-flex items-center justify-center gap-x-2 rounded-lg px-3 py-2 text-sm font-medium transition duration-75 ';
        $btnSecondary = $btnBase.'bg-white text-gray-950 hover:bg-gray-50 ring-1 ring-gray-300 dark:bg-white/5 dark:text-white dark:ring-gray-700 dark:hover:bg-white/10';
        $btnPrimary = $btnBase.'bg-primary-600 text-white hover:bg-primary-500';
        $btnDanger = $btnBase.'bg-danger-600 text-white hover:bg-danger-500';
        $btnDisabled = 'cursor-default opacity-70 pointer-events-none';

        $buttonTag = static fn (string $classes, bool $enabled): string =>
            $classes . ($enabled ? '' : ' '.$btnDisabled);
    @endphp

    {{-- ---------------------------------------------------------------
         Pinned detection banner — shared across every toolkit page.
      ---------------------------------------------------------------- }}
    <x-dynamic-component
        :component="'minecraft-toolkit::components.detection-banner'"
        :software="$detection"
    />

    {{-- ---------------------------------------------------------------
         Companion bridge notice (tier 2) — parity with the original's
         PrestonSync capability hints; instant controls remain hidden here.
      ---------------------------------------------------------------- --}}
    @if (! $bridgeActive)
        <x-filament::callout color="warning" icon="tabler-bolt">
            <x-slot name="heading">
                {{ trans('minecraft-toolkit::pages.players.states.tier2_missing') }}
            </x-slot>
            <x-slot name="description">
                <span class="text-xs font-medium text-warning-700 dark:text-warning-300">
                    {{ trans('minecraft-toolkit::pages.players.toolbar.instant_tooltip') }}
                </span>
            </x-slot>
        </x-filament::callout>
    @endif

    {{-- ---------------------------------------------------------------
         Toolbar — counter "x of y online", Instant chip, polling toggle.
      ---------------------------------------------------------------- }}
    <div class="{{ $cardShell }} flex flex-wrap items-center justify-between gap-x-4 gap-y-3 p-4 sm:p-5">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                {{ trans('minecraft-toolkit::pages.players.toolbar.online_of', [
                    'online' => number_format(intval($counters['online'] ?? 0)),
                    'max' => number_format(intval($counters['max'] ?? 0)),
                ]) }}
            </span>

            @if ($bridgeActive)
                <span class="{{ $chipSuccess }}" title="{{ trans('minecraft-toolkit::pages.players.toolbar.instant_tooltip') }}">
                    <x-filament::icon :icon="'tabler-bolt'" class="size-3.5" />
                    {{ trans('minecraft-toolkit::common.badges.instant') }}
                </span>
            @endif

            <span
                class="{{ $counters['ping_ok'] ?? false ? $chipSuccess : $chipWarning }}"
                title="{{ trans('minecraft-toolkit::common.buttons.refresh') }}"
            >
                {{ $counters['ping_ok'] ?? false
                    ? trans('minecraft-toolkit::common.badges.online')
                    : trans('minecraft-toolkit::common.badges.offline') }}
            </span>

            {{-- sample limitation disclosure (PlayerRegistry caps ping-name matches at 12) --}}
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ trans('minecraft-toolkit::pages.players.toolbar.sample_note') }}
            </span>
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
            {{-- auto-refresh opt-in (Online tab only drives the 15s tick) --}}
            <button type="button"
                    wire:click="togglePolling"
                    class="{{ $buttonTag($this->polling ? $btnPrimary : $btnSecondary, true) }}"
                    title="Auto-refresh every 15 s" {{-- lang-gap literal tooltip --}}>
                <x-filament::icon :icon="$this->polling ? 'tabler-bolt' : 'tabler-refresh'" class="size-4 shrink-0" />
                Auto-refresh {{-- lang-gap literal --}}
            </button>

            <button type="button"
                    wire:click="refreshRegistry"
                    class="{{ $btnSecondary }}">
                <x-filament::icon :icon="'tabler-refresh'" class="size-4 shrink-0" />
                {{ trans('minecraft-toolkit::common.buttons.refresh') }}
            </button>
        </div>
    </div>

    {{-- Live-poll anchor: a 15 s round-trip only while opted-in on the Online tab.
         Hidden by design — the refresh itself re-renders the whole component. --}}
    <div @if ($this->polling && $filter === 'online') wire:poll.15s="pollTick" @endif class="hidden"></div>

    {{-- ---------------------------------------------------------------
         Segmented filters + search + quick whitelist add row.
      ---------------------------------------------------------------- }}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
        <div class="flex flex-1 flex-col gap-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-3">
                <div class="flex flex-wrap items-center gap-x-1 rounded-lg bg-gray-50 p-1 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/20">
                    @foreach ([
                        'online' => trans('minecraft-toolkit::common.badges.online'),
                        'all' => trans('minecraft-toolkit::pages.versions.tab.all'),
                        'ops' => trans('minecraft-toolkit::pages.players.controls.operator'),
                        'banned' => trans('minecraft-toolkit::pages.players.controls.banned'),
                    ] as $key => $label)
                        <button type="button"
                                wire:click="setFilter('{{ $key }}')"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition duration-75 {{
                                    $filter === $key
                                        ? 'bg-primary-600 text-white shadow-sm'
                                        : 'text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-white/10'
                                }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <label class="flex min-w-[14rem] flex-1 items-center gap-x-2 rounded-lg bg-white px-3 py-2 shadow-sm ring-1 ring-gray-950/10 focus-within:ring-2 focus-within:ring-primary-500 dark:bg-white/5 dark:ring-white/20 dark:focus-within:ring-primary-400">
                    <x-filament::icon :icon="'tabler-search'" class="size-4 shrink-0 text-gray-400" />
                    <input type="search"
                           wire:model.live.debounce.300ms="search"
                           placeholder="{{ trans('minecraft-toolkit::common.buttons.search') }}"
                           class="w-full border-none bg-transparent text-sm leading-6 outline-none placeholder:text-gray-400" />
                </label>
            </div>

            {{-- quick add-to-whitelist input moved onto the page form schema
                 (renders inside the Controls card, triggers via its Add button there) --}}

            {{-- ----------------------------------------------
                 Player cards grid
              ---------------------------------------------- --}}
            @if ($visible->isEmpty())
                <div class="{{ $cardShell }} flex flex-col items-center justify-center gap-2 p-10 text-center">
                    <x-filament::icon :icon="'tabler-users-group'" class="size-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ trans('minecraft-toolkit::common.empty.nothing_here') }}
                    </p>
                </div>
            @else
                <div class="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(15rem,1fr))]">
                    @foreach ($visible as $row)
                        @php
                            $isSelected = $selectedUuid !== null && strtolower(strval($row['uuid'])) === $selectedUuid;
                            $isOnline = (bool) $row['online'];
                        @endphp

                        <button type="button"
                                wire:key="player-{{ md5(strval($row['uuid'].$row['name'])) }}"
                                wire:click="selectPlayer('{{ strtolower(strval($row['uuid'])) }}')"
                                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                class="{{ $cardShell }} group flex h-full w-full flex-col items-start gap-3 p-4 text-start transition duration-75 hover:bg-gray-50 dark:hover:bg-white/5 {{
                                    $isSelected ? 'ring-2 ring-primary-500' : ''
                                }}">
                            <div class="flex w-full items-start gap-3">
                                <img src="https://mc-heads.net/avatar/{{ urlencode(strval($row['name'])) }}/40"
                                     alt=""
                                     loading="lazy"
                                     decoding="async"
                                     class="size-10 shrink-0 rounded-md object-cover ring-1 ring-gray-950/5 dark:ring-white/10" />

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $row['name'] }}">
                                        {{ $row['name'] }}
                                    </p>

                                    <span class="mt-1 inline-flex items-center gap-x-1 text-xs font-medium {{ $isOnline ? 'text-success-700 dark:text-success-300' : 'text-gray-500 dark:text-gray-400' }}">
                                        <span class="size-1.5 rounded-full {{ $isOnline ? 'bg-success-500' : 'bg-gray-400' }}"></span>
                                        {{ $isOnline ? trans('minecraft-toolkit::common.badges.online') : trans('minecraft-toolkit::common.badges.offline') }}
                                    </span>
                                </div>

                                @if ((bool) $row['op'])
                                    <span class="{{ $chipWarning }}" title="Operator">
                                        <x-filament::icon :icon="'tabler-crown'" class="size-3.5" />
                                        {{ trans('minecraft-toolkit::pages.players.controls.operator') }}
                                    </span>
                                @endif
                            </div>

                            @if (filled($row['ban_reason']))
                                <span class="line-clamp-2 w-full text-xs text-gray-500 dark:text-gray-400">
                                    {{ $row['ban_reason'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Banned-tab extra surface: parsed address bans that carry no player row --}}
            @if ($filter === 'banned' && count($ipBans) > 0)
                <div class="{{ $cardShell }} p-4 sm:p-5">
                    <p class="flex items-center gap-x-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <x-filament::icon :icon="'tabler-shield-half'" class="size-4 shrink-0" />
                        IP bans {{-- lang-gap literal --}}
                    </p>

                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($ipBans as $ban)
                            <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2 text-sm">
                                <code class="font-mono text-xs text-gray-950 dark:text-white">{{ $ban['ip'] }}</code>

                                <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $ban['reason'] ?? trans('minecraft-toolkit::common.empty.nothing_here') }}
                                </span>

                                <span class="{{ $chipGray }}">{{ $ban['expires'] ?? '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- -------------------------------------------------------
             Detail panel — selected player identity + info + controls.
          -------------------------------------------------------- --}}
        <aside class="w-full shrink-0 lg:w-96">
            @php
                $placeholder = '—'; // em-dash when tier-2 bridge data is absent

                // Registry bridge keys are dashed-less lowercase uuids.
                $formatAxis = static function ($value) use ($placeholder): string {
                    return $value === null
                        ? $placeholder
                        : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
                };

                $positionText = isset($bridgeEntry['x'], $bridgeEntry['y'], $bridgeEntry['z'])
                    ? implode(' ', [$formatAxis($bridgeEntry['x']), $formatAxis($bridgeEntry['y']), $formatAxis($bridgeEntry['z'])])
                    : $placeholder;
                $modeText = filled($bridgeEntry['mode'] ?? null) ? strval($bridgeEntry['mode']) : $placeholder;
                $dimensionText = filled($bridgeEntry['dimension'] ?? null) ? strval($bridgeEntry['dimension']) : $placeholder;
            @endphp

            @if ($selectedRow === null)
                <div class="{{ $cardShell }} flex h-full min-h-40 flex-col items-center justify-center gap-2 p-8 text-center">
                    <x-filament::icon :icon="'tabler-target'" class="size-8 text-gray-300 dark:text-gray-600" />

                    {{-- lang-gap literal: no dedicated "pick a player" key ships in v1 lang set --}}
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pick a player from the list.</p>
                </div>
            @else
                <div class="flex h-full flex-col gap-4">
                    {{-- identity card --}}
                    <div class="{{ $cardShell }} p-4 sm:p-5">
                        <div class="flex items-start gap-3">
                            <img src="https://mc-heads.net/avatar/{{ urlencode(strval($selectedRow['name'])) }}/40"
                                 alt=""
                                 loading="lazy"
                                 decoding="async"
                                 class="size-10 shrink-0 rounded-md object-cover ring-1 ring-gray-950/5 dark:ring-white/10" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-semibold text-gray-950 dark:text-white" title="{{ $selectedRow['name'] }}">
                                    {{ $selectedRow['name'] }}
                                </p>

                                <span class="mt-1 inline-flex items-center gap-x-1 text-xs font-medium {{ (bool) $selectedRow['online'] ? 'text-success-700 dark:text-success-300' : 'text-gray-500 dark:text-gray-400' }}">
                                    <span class="size-1.5 rounded-full {{ (bool) $selectedRow['online'] ? 'bg-success-500' : 'bg-gray-400' }}"></span>
                                    {{ (bool) $selectedRow['online'] ? trans('minecraft-toolkit::common.badges.online') : trans('minecraft-toolkit::common.badges.offline') }}
                                </span>
                            </div>

                            @if ((bool) $selectedRow['op'])
                                <span class="{{ $chipWarning }}">
                                    <x-filament::icon :icon="'tabler-crown'" class="size-3.5" />
                                    OP
                                </span>
                            @endif
                        </div>

                        {{-- uuid, mono muted --}}
                        <p class="mt-3 break-all font-mono text-xs leading-5 tracking-tight text-gray-500 dark:text-gray-400">
                            {{ $selectedRow['uuid'] ?: '—' }}
                        </p>

                        {{-- info grid: Mode / Dimension / Position (tier-2 aware) --}}
                        <dl class="mt-4 grid grid-cols-3 gap-3 border-t border-gray-100 pt-4 dark:border-white/10">
                            <div>
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Mode</dt>
                                <dd class="mt-0.5 truncate text-sm text-gray-950 dark:text-white">{{ $modeText }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Dimension</dt>
                                <dd class="mt-0.5 truncate text-sm text-gray-950 dark:text-white">{{ $dimensionText }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Position</dt>
                                <dd class="mt-0.5 truncate text-sm tabular-nums text-gray-950 dark:text-white">{{ $positionText }}</dd>
                            </div>
                        </dl>

                        @if (filled($selectedRow['last_seen']))
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                Last seen heuristics: {{ $selectedRow['last_seen'] }} {{-- lang-gap literal --}}
                            </p>
                        @endif
                    </div>

                    {{-- CONTROLS card --}}
                    <div class="{{ $cardShell }} flex flex-col gap-5 p-4 sm:p-5">
                        <p class="flex items-center gap-x-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <x-filament::icon :icon="'tabler-users-group'" class="size-4 shrink-0" />
                            Controls {{-- lang-gap literal --}}
                        </p>

                        {{-- Suggestion datalist consumed by the giveItem schema field --}}
                        <datalist id="mc-give-items">
                            @foreach (\McToolkit\Filament\Server\Pages\PlayersPage::GIVE_SUGGESTIONS as $suggestion)
                                <option value="{{ $suggestion }}"></option>
                            @endforeach
                        </datalist>

                        {{-- THE Controls/Give schema: whitelist name, gamemode toggle buttons,
                             XP levels, give item + count — Filament form components only --}}
                        {{ $this->form }}

                        {{-- whitelist add trigger (field lives in the schema above) --}}
                        <x-filament::button
                            :color="'secondary'"
                            size="sm"
                            icon="tabler-user-check"
                            wire:click="addToWhitelist"
                            :disabled="! $canSend"
                            wire:loading.attr="disabled"
                            wire:target="addToWhitelist()"
                        >
                            {{ trans('minecraft-toolkit::pages.players.controls.whitelisted') }}
                        </x-filament::button>

                        {{-- XP stepper actions: − Set + (value itself lives in the schema) --}}
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
                            <x-filament::button
                                :color="'secondary'"
                                size="sm"
                                wire:click="bumpXp(-1)"
                                :disabled="! $canSend"
                                aria-label="-1"
                            >&minus;</x-filament::button>

                            <x-filament::button
                                color="primary"
                                size="sm"
                                wire:click="setXpLevels"
                                :disabled="! $canSend"
                                wire:loading.attr="disabled"
                                wire:target="setXpLevels()"
                            >
                                {{ trans('minecraft-toolkit::common.buttons.set') }}
                            </x-filament::button>

                            <x-filament::button
                                :color="'secondary'"
                                size="sm"
                                wire:click="bumpXp(1)"
                                :disabled="! $canSend"
                                aria-label="+1"
                            >+</x-filament::button>
                        </div>

                        {{-- effect buttons row --}}
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <button type="button" wire:click="healPlayer" class="{{ $buttonTag($btnSecondary, $canSend) }}">
                                {{ trans('minecraft-toolkit::common.buttons.heal') }}
                            </button>
                            <button type="button" wire:click="killPlayer" class="{{ $buttonTag($btnDanger, $canSend) }}">
                                {{ trans('minecraft-toolkit::common.buttons.kill') }}
                            </button>
                            <button type="button" wire:click="feedPlayer" class="{{ $buttonTag($btnSecondary, $canSend) }}">
                                {{ trans('minecraft-toolkit::common.buttons.feed') }}
                            </button>
                            <button type="button" wire:click="starvePlayer" class="{{ $buttonTag($btnSecondary, $canSend) }}">
                                {{ trans('minecraft-toolkit::common.buttons.starve') }}
                            </button>
                        </div>

                        {{-- state toggles: Operator / Whitelisted / Banned --}}
                        <div class="grid gap-2 sm:grid-cols-3">
                            @foreach ([
                                'operator' => ['on' => $selectedRow['op'], 'wire' => 'toggleOperator(\''.strtolower(strval($selectedRow['uuid'])).'\')', 'lang' => trans('minecraft-toolkit::pages.players.controls.operator')],
                                'whitelisted' => ['on' => $selectedRow['whitelisted'], 'wire' => 'toggleWhitelisted(\''.strtolower(strval($selectedRow['uuid'])).'\')', 'lang' => trans('minecraft-toolkit::pages.players.controls.whitelisted')],
                                'banned' => ['on' => $selectedRow['banned'], 'wire' => 'toggleBanned(\''.strtolower(strval($selectedRow['uuid'])).'\')', 'lang' => trans('minecraft-toolkit::pages.players.controls.banned')],
                            ] as $key => $toggle)
                                <button type="button"
                                        wire:key="toggle-{{ $key }}-{{ md5(strtolower(strval($selectedRow['uuid']))) }}"
                                        wire:click="{{ $toggle['wire'] }}"
                                        class="flex w-full items-center justify-between gap-x-2 rounded-lg bg-white px-3 py-2 text-sm font-medium shadow-sm ring-1 transition duration-75 {{
                                            $toggle['on']
                                                ? 'ring-success-600/30 text-success-700 dark:ring-success-400/40 dark:text-success-300'
                                                : 'ring-gray-300 text-gray-600 dark:ring-gray-700 dark:text-gray-300'
                                        }} {{ $canSend ? 'hover:bg-gray-50 dark:hover:bg-white/5' : 'opacity-70 pointer-events-none' }}">
                                    {{ $toggle['lang'] }}

                                    <span class="size-2 rounded-full {{ $toggle['on'] ? 'bg-success-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                                </button>
                            @endforeach
                        </div>

                        {{-- Kick button --}}
                        <button type="button"
                                wire:click="kickPlayer"
                                class="{{ $buttonTag($btnDanger, $canSend) }} w-full">
                            {{ trans('minecraft-toolkit::common.buttons.kick') }}
                        </button>

                        {{-- give submit trigger (fields live in the shared schema above) --}}
                        <x-filament::button
                            color="primary"
                            size="md"
                            class="w-full"
                            icon="tabler-target"
                            wire:click="giveItem"
                            :disabled="! $canSend"
                            wire:loading.attr="disabled"
                            wire:target="giveItem()"
                        >
                            {{ trans('minecraft-toolkit::common.buttons.give') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        </aside>
    </div>
</x-filament-panels::page>
