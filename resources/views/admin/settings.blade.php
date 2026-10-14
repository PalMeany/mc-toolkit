{{--
    Minecraft Toolkit — Admin panel settings page (global plugin config).

    Pairs with McToolkit\Filament\Admin\Pages\McAdminSettingsPage:
      - three compact chips (top): global tracked-content counts grouped by kind
        (plugin | mod | modpack), one indexed GROUP BY — no heavy queries,
      - info callout: what each catalog serves and which sources need a key
        (parity with README §External services; key-gated chips hide themselves),
      - {{ $this->form }}: catalog key inputs (password/revealable) + nine page
        Toggles composing MC_TOOLKIT_ENABLED_PAGES,
      - static requirements list echoing README §Requirements.

    Detection banner is intentionally NOT rendered here: the admin panel has no
    tenant Server context, so DetectionService::detect(App\Models\Server) has no
    subject to probe. The banner stays pinned on every SERVER-panel toolkit page.
--}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
    wire:submit="save"
>
    {{-- --------------------------------------------------------- --}}
    {{-- Global tracked-content counts — three small chips only   --}}
    {{-- --------------------------------------------------------- --}}
    @php
        $chip = 'fi-small-stat-block w-full p-3 rounded-lg bg-white shadow-sm '
            .'ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10';
    @endphp

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="{{ $chip }}">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('minecraft-toolkit::pages.plugins.title') }}
            </span>
            <span class="ms-2 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">
                {{ number_format((int) ($kindCounts['plugin'] ?? 0)) }}
            </span>
        </div>

        <div class="{{ $chip }}">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('minecraft-toolkit::pages.mods.title') }}
            </span>
            <span class="ms-2 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">
                {{ number_format((int) ($kindCounts['mod'] ?? 0)) }}
            </span>
        </div>

        <div class="{{ $chip }}">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ __('minecraft-toolkit::pages.modpacks.title') }}
            </span>
            <span class="ms-2 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">
                {{ number_format((int) ($kindCounts['modpack'] ?? 0)) }}
            </span>
        </div>
    </div>

    {{-- --------------------------------------------------------- --}}
    {{-- What catalogs appear where (README §External services)    --}}
    {{-- --------------------------------------------------------- --}}
    <x-filament::callout color="info" :icon="'tabler-info-circle'" class="mt-4">
        <x-slot name="heading">
            {{ 'Catalog sources' }}
        </x-slot>

        <x-slot name="description">
            <span class="block space-y-1 text-sm leading-6 text-info-700 dark:text-info-300">
                <span class="block"><b>Versions</b> — mcjars.app catalog, no key needed.</span>
                <span class="block"><b>{{ __('minecraft-toolkit::pages.plugins.title') }}</b> — Modrinth · Hangar · SpigotMC always on; CurseForge appears once its key is set here.</span>
                <span class="block"><b>{{ __('minecraft-toolkit::pages.mods.title') }}</b> — Modrinth always on; CurseForge appears once its key is set.</span>
                <span class="block"><b>{{ __('minecraft-toolkit::pages.modpacks.title') }}</b> — Modrinth only, no key needed.</span>
                <span class="block text-xs">Sources without a configured key are skipped gracefully — same behavior as the original addon hiding its source chip.</span>
            </span>
        </x-slot>
    </x-filament::callout>

    {{-- Form: catalog keys + nine page toggles --}}
    <div class="mt-4">
        {{ $this->form }}
    </div>

    {{-- --------------------------------------------------------- --}}
    {{-- Requirements (echoes README §Requirements)                --}}
    {{-- --------------------------------------------------------- --}}
    <div class="mt-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            {{ 'Requirements' }}
        </p>
        <ul class="mt-2 list-disc ps-6 text-sm leading-6 text-gray-600 dark:text-gray-400">
            <li>Pelican Panel <code class="text-xs">^1.0.0</code> — tested against 1.0.0-rc.1</li>
            <li>PHP <code class="text-xs">^8.4</code> / <code class="text-xs">^8.5</code> — matches the panel's own requirement</li>
            <li>A working queue worker — installs, updates and uninstalls run as queued jobs</li>
        </ul>
        <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">
            Blank key fields never wipe existing .env values — to remove a key entirely, edit
            <code>.env</code> directly. Values are stored as
            <code>MC_TOOLKIT_CURSEFORGE_KEY</code> and
            <code>MC_TOOLKIT_ENABLED_PAGES</code>.
        </p>
    </div>
</x-filament-panels::page>
