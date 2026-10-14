{{--
    Minecraft Toolkit — filesystem detection summary banner.

    Expected payload mirrors DetectionService::detect():
        software(array{software:string|null, loader:string|null, version:?string,
                       java:?int, detected_by:'files', proxy:bool, ...})
        canRedetect(bool) — optional, defaults true; gates the re-detect control.

    Usage (core-compatible paths):
        <x-minecraft-toolkit::components.detection-banner :software="$software"/>
        {{-- or --}} {{ view('minecraft-toolkit::components.detection-banner', ['software' => $software])->render() }}

    Emits the Livewire event 'mc-toolkit.redetect' for hosting pages to catch:
        #[On('mc-toolkit.redetect')] public function redetect(): void { /* refresh detection cache */ }
--}}
@php
    $software ??= [];
    $canRedetect ??= true;

    $label = ucfirst((string) ($software['software'] ?? 'unknown'));
    $version = filled($software['version'] ?? null) ? (string) $software['version'] : null;
    $java = isset($software['java']) && $software['java'] !== null ? (int) $software['java'] : null;
    $proxy = filled($software['proxy'] ?? false);

    $chip = 'inline-flex items-center justify-center rounded-md bg-gray-50 px-2 py-0.5 '
        .'text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-600/10 '
        .'dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20';

    $chipAccent = 'inline-flex items-center justify-center rounded-md bg-info-50 px-2 py-0.5 '
        .'text-xs font-medium text-info-700 ring-1 ring-inset ring-info-600/20 '
        .'dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/30';
@endphp

<x-filament::callout color="info" icon="tabler-cpu">
    <x-slot name="heading">
        <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $label }}</span>

            @if (filled($version))
                <span class="{{ $chip }}">{{ $version }}</span>
            @endif

            @if (! is_null($java))
                <span class="text-xs text-gray-400 dark:text-gray-500" aria-hidden="true">&middot;</span>
                <span class="{{ $chip }}">Java {{ $java }}</span>
            @endif

            @if ($proxy)
                <span class="text-xs text-gray-400 dark:text-gray-500" aria-hidden="true">&middot;</span>
                <span class="{{ $chipAccent }}">Proxy</span>
            @endif
        </span>
    </x-slot>

    <x-slot name="description">
        <span class="inline-flex flex-wrap items-center gap-x-1.5 text-xs font-medium text-info-700 dark:text-info-300">
            {{ __('minecraft-toolkit::banner.detection.title_note') }}
        </span>
    </x-slot>

    @if ($canRedetect)
        <x-slot name="controls">
            <x-filament::icon-button
                :icon="'tabler-refresh'"
                :color="'gray'"
                :label="__('minecraft-toolkit::common.buttons.detect_again')"
                wire:click="$wire.dispatch('mc-toolkit.redetect')"
            />
        </x-slot>
    @endif
</x-filament::callout>
