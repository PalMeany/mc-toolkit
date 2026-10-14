{{--
    Minecraft Toolkit — companion-bridge state + one-click install (Concerns\ManagesBridge).

    Props:
        bridge      BridgeService::status() snapshot (null renders nothing)
        canInstall  the viewer may install/update the bridge
        canRestart  the viewer may restart the server
        context     one line about what THIS page shows once the bridge runs (optional)

    The elements that need the bridge stay visible on every page; this callout only explains
    why they are empty and offers the fix.
--}}
@props([
    'bridge' => null,
    'canInstall' => false,
    'canRestart' => false,
    'context' => null,
])

@if (is_array($bridge))
    @php
        $supported = (bool) ($bridge['supported'] ?? false);
        $installed = (bool) ($bridge['installed'] ?? false);
        $running = (bool) ($bridge['running'] ?? false);
        $updatedAt = is_int($bridge['updated_at'] ?? null) ? $bridge['updated_at'] : null;
        $update = (bool) ($bridge['update_available'] ?? false);
        $ago = $updatedAt !== null ? \Illuminate\Support\Carbon::createFromTimestamp($updatedAt)->diffForHumans() : null;
    @endphp

    @if ($running && ! $update)
        <div class="flex items-center gap-x-2 text-xs font-medium text-success-700 dark:text-success-400">
            <span class="relative flex size-2">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-success-500 opacity-60"></span>
                <span class="relative inline-flex size-2 rounded-full bg-success-500"></span>
            </span>
            {{ trans('minecraft-toolkit::pages.bridge.running', ['version' => $bridge['version'] ?? '']) }}
        </div>
    @else
        @php
            [$color, $icon, $title, $body] = match (true) {
                ! $supported => ['gray', 'tabler-plug-connected-x', trans('minecraft-toolkit::pages.bridge.unsupported_title'), trans('minecraft-toolkit::pages.bridge.unsupported', ['software' => $bridge['reason'] ?? '?'])],
                $running && $update => ['info', 'tabler-refresh', trans('minecraft-toolkit::pages.bridge.update_title'), trans('minecraft-toolkit::pages.bridge.update_body', ['current' => $bridge['version'] ?? '?', 'bundled' => $bridge['bundled_version'] ?? '?'])],
                $installed && $updatedAt !== null => ['warning', 'tabler-plug-connected-x', trans('minecraft-toolkit::pages.bridge.stale_title'), trans('minecraft-toolkit::pages.bridge.stale_body', ['ago' => $ago])],
                $installed => ['info', 'tabler-player-play', trans('minecraft-toolkit::pages.bridge.installed_title'), trans('minecraft-toolkit::pages.bridge.installed_body', ['file' => $bridge['target'] ?? 'plugins/'])],
                default => ['warning', 'tabler-plug-connected', trans('minecraft-toolkit::pages.bridge.missing_title'), trans('minecraft-toolkit::pages.bridge.what')],
            };

            $showInstall = $supported && $canInstall && (! $installed || $update);
            $showRestart = $supported && $canRestart && $installed && ! $running;
        @endphp

        <x-filament::callout :color="$color" :icon="$icon">
            <x-slot name="heading">{{ $title }}</x-slot>

            <x-slot name="description">
                <span class="flex flex-col gap-y-1">
                    <span>{{ $body }}</span>

                    @if (filled($context) && $supported && ! $running)
                        <span class="text-xs opacity-80">{{ $context }}</span>
                    @endif

                    @if ($supported && ! $canInstall && (! $installed || $update))
                        <span class="text-xs opacity-80">{{ trans('minecraft-toolkit::pages.bridge.needs_manage') }}</span>
                    @endif
                </span>

                @if ($showInstall || $showRestart)
                    <span class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($showInstall)
                            <x-filament::button
                                size="sm"
                                :icon="$update ? 'tabler-refresh' : 'tabler-download'"
                                wire:click="installBridge"
                                wire:loading.attr="disabled"
                                wire:target="installBridge"
                            >
                                {{ trans($update ? 'minecraft-toolkit::pages.bridge.update' : 'minecraft-toolkit::pages.bridge.install') }}
                            </x-filament::button>
                        @endif

                        @if ($showRestart)
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="tabler-reload"
                                wire:click="restartForBridge"
                                wire:loading.attr="disabled"
                                wire:target="restartForBridge"
                            >
                                {{ trans('minecraft-toolkit::pages.bridge.restart') }}
                            </x-filament::button>
                        @endif
                    </span>
                @endif
            </x-slot>
        </x-filament::callout>
    @endif
@endif
