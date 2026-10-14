{{--
    Minecraft Toolkit — modpack install/update confirmation sheet (Modpacks page).

    Fed by ModpacksPage::$pendingPack (id, title, is_update, version, loader,
    loader_label, game_version, matches, switches) and the two sheet options
    $packInstallLoader / $packStartAfter. Confirm queues an InstallModpackJob.
    Same sheet markup as the Versions page install sheet.
--}}
@if (! is_null($pendingPack))
    @php
        $packTitle = trim(($pendingPack['title'] ?? '').' '.($pendingPack['version'] ?? ''));
        $packLoader = $pendingPack['loader_label'] ?? null;
        $packGame = $pendingPack['game_version'] ?? null;
        $canLoader = $this->canInstallLoader();
    @endphp

    <div class="fixed inset-0 z-40 bg-gray-950/50 backdrop-blur-sm dark:bg-gray-950/75" wire:click="closePack()"></div>

    <div class="fixed inset-x-4 top-1/2 z-40 mx-auto -translate-y-1/2 sm:inset-x-0 sm:max-w-md" role="dialog" aria-modal="true">
        <div class="w-full rounded-xl bg-white p-6 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10">
            <h4 class="text-base leading-6 font-semibold text-gray-950 dark:text-white">
                {{ __($pendingPack['is_update'] ?? false ? 'minecraft-toolkit::pages.modpacks.confirm.update_title' : 'minecraft-toolkit::pages.modpacks.confirm.title', ['title' => $packTitle]) }}
            </h4>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('minecraft-toolkit::pages.modpacks.confirm.body') }}
            </p>

            <div class="mt-5 space-y-4">
                {{-- loader --}}
                @if ($packLoader === null || $packGame === null)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('minecraft-toolkit::pages.modpacks.confirm.loader_unknown') }}
                    </p>
                @elseif ($pendingPack['matches'] ?? false)
                    <p class="flex items-start gap-x-2 text-sm text-gray-950 dark:text-white">
                        <x-filament::icon icon="tabler-circle-check" class="mt-0.5 size-4 shrink-0 text-success-600 dark:text-success-400" />
                        <span>{{ __('minecraft-toolkit::pages.modpacks.confirm.loader_matches', ['loader' => $packLoader, 'version' => $packGame]) }}</span>
                    </p>
                @else
                    <label for="mct-pack-loader" class="block">
                        <span class="flex items-start gap-x-3">
                            <input id="mct-pack-loader" type="checkbox"
                                   wire:model="packInstallLoader"
                                   @unless ($canLoader) disabled @endunless
                                   class="mt-0.5 size-4 rounded ring-1 ring-gray-950/10 accent-primary-600 dark:ring-white/20" />
                            <span class="text-sm text-gray-950 dark:text-white">
                                {{ __('minecraft-toolkit::pages.modpacks.confirm.install_loader', ['loader' => $packLoader, 'version' => $packGame]) }}
                            </span>
                        </span>
                        <span class="ml-7 mt-1 block text-xs text-gray-400 dark:text-gray-500">
                            {{ $canLoader
                                ? __('minecraft-toolkit::pages.modpacks.confirm.install_loader_hint')
                                : __('minecraft-toolkit::pages.modpacks.confirm.loader_needs_versions', ['loader' => $packLoader, 'version' => $packGame]) }}
                        </span>
                        @if (($pendingPack['switches'] ?? false) && $canLoader)
                            <span class="ml-7 mt-1 block text-xs text-warning-600 dark:text-warning-400">
                                {{ __('minecraft-toolkit::pages.modpacks.confirm.switches_loader', ['loader' => $packLoader]) }}
                            </span>
                        @endif
                    </label>
                @endif

                {{-- start after --}}
                <label for="mct-pack-start" class="block">
                    <span class="flex items-start gap-x-3">
                        <input id="mct-pack-start" type="checkbox"
                               wire:model="packStartAfter"
                               class="mt-0.5 size-4 rounded ring-1 ring-gray-950/10 accent-primary-600 dark:ring-white/20" />
                        <span class="text-sm text-gray-950 dark:text-white">
                            {{ __('minecraft-toolkit::pages.modpacks.confirm.start_after') }}
                        </span>
                    </span>
                    <span class="ml-7 mt-1 block text-xs text-gray-400 dark:text-gray-500">
                        {{ __('minecraft-toolkit::pages.versions.install.stopped_note') }}
                    </span>
                </label>
            </div>

            <div class="mt-6 flex items-center justify-end gap-x-3">
                <x-filament::button :color="'gray'" :outlined="true" size="sm" wire:click="closePack()">
                    {{ __('minecraft-toolkit::common.buttons.cancel') }}
                </x-filament::button>

                <x-filament::button :color="'primary'" size="sm"
                                    wire:click="confirmPack()" wire:loading.attr="disabled" wire:target="confirmPack">
                    {{ __($pendingPack['is_update'] ?? false ? 'minecraft-toolkit::common.buttons.update' : 'minecraft-toolkit::common.buttons.install') }}
                </x-filament::button>
            </div>
        </div>
    </div>
@endif
