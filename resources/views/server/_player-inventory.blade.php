{{--
    Minecraft Toolkit — selected player's inventory (Players page aside).

    $detail: PlayerRegistry::bridgePlayerDetail() or null; $bridgeActive: bridge running.
    Without data every slot stays visible and empty, with a hint why — nothing is hidden.
    Items show a two-letter monogram tinted by material, the stack size and a durability bar;
    the full name, id and durability are in the slot's tooltip.
--}}
@php
    $detail = is_array($detail ?? null) ? $detail : null;

    $bySlot = [];
    foreach ($detail['inventory'] ?? [] as $item) {
        $bySlot[(int) $item['slot']] = $item;
    }

    $enderBySlot = [];
    foreach ($detail['ender_chest'] ?? [] as $item) {
        $enderBySlot[(int) $item['slot']] = $item;
    }

    $pretty = static function (string $id): string {
        $path = str_contains($id, ':') ? substr($id, strpos($id, ':') + 1) : $id;

        return ucwords(str_replace(['_', '/', '.'], ' ', $path));
    };

    $monogram = static function (string $id): string {
        $path = str_contains($id, ':') ? substr($id, strpos($id, ':') + 1) : $id;
        $words = array_values(array_filter(explode('_', $path)));

        if (count($words) >= 2) {
            return strtoupper($words[0][0].$words[count($words) - 1][0]);
        }

        return ucfirst(substr($words[0] ?? '?', 0, 2));
    };

    // Material tint (first matching keyword wins); static strings so Tailwind sees them.
    $tints = [
        'netherite' => 'bg-gray-700 text-gray-100',
        'diamond' => 'bg-cyan-200 text-cyan-900 dark:bg-cyan-400/30 dark:text-cyan-100',
        'emerald' => 'bg-emerald-200 text-emerald-900 dark:bg-emerald-400/30 dark:text-emerald-100',
        'gold' => 'bg-amber-200 text-amber-900 dark:bg-amber-400/30 dark:text-amber-100',
        'iron' => 'bg-gray-300 text-gray-800 dark:bg-gray-400/30 dark:text-gray-100',
        'copper' => 'bg-orange-200 text-orange-900 dark:bg-orange-400/30 dark:text-orange-100',
        'redstone' => 'bg-red-200 text-red-900 dark:bg-red-400/30 dark:text-red-100',
        'lapis' => 'bg-blue-200 text-blue-900 dark:bg-blue-400/30 dark:text-blue-100',
        'leather' => 'bg-orange-300 text-orange-950 dark:bg-orange-700/40 dark:text-orange-100',
        'oak' => 'bg-yellow-700/30 text-yellow-900 dark:bg-yellow-700/40 dark:text-yellow-100',
        'spruce' => 'bg-yellow-800/30 text-yellow-950 dark:bg-yellow-800/50 dark:text-yellow-100',
        'birch' => 'bg-yellow-100 text-yellow-900 dark:bg-yellow-200/20 dark:text-yellow-100',
        'log' => 'bg-yellow-700/30 text-yellow-900 dark:bg-yellow-700/40 dark:text-yellow-100',
        'planks' => 'bg-yellow-600/30 text-yellow-900 dark:bg-yellow-600/40 dark:text-yellow-100',
        'stone' => 'bg-stone-300 text-stone-800 dark:bg-stone-400/30 dark:text-stone-100',
        'deepslate' => 'bg-stone-500 text-stone-50 dark:bg-stone-600/50 dark:text-stone-100',
        'dirt' => 'bg-amber-800/40 text-amber-950 dark:bg-amber-800/50 dark:text-amber-100',
        'grass' => 'bg-green-300 text-green-900 dark:bg-green-500/30 dark:text-green-100',
        'sand' => 'bg-yellow-200 text-yellow-900 dark:bg-yellow-300/30 dark:text-yellow-100',
        'coal' => 'bg-gray-800 text-gray-100',
        'obsidian' => 'bg-violet-900 text-violet-100',
        'ender' => 'bg-teal-700 text-teal-50',
        'tnt' => 'bg-red-500 text-white',
    ];

    $tintOf = static function (string $id) use ($tints): string {
        foreach ($tints as $needle => $class) {
            if (str_contains($id, $needle)) {
                return $class;
            }
        }

        return 'bg-gray-200 text-gray-700 dark:bg-white/15 dark:text-gray-100';
    };

    $tooltip = static function (array $item) use ($pretty): string {
        $parts = [($item['name'] ?? null) !== null ? $item['name'].' ('.$pretty($item['id']).')' : $pretty($item['id'])];

        if ($item['count'] > 1) {
            $parts[] = '×'.$item['count'];
        }

        if ($item['max_damage'] !== null && $item['damage'] !== null && $item['max_damage'] > 0) {
            $parts[] = trans('minecraft-toolkit::pages.players.inventory.durability', ['left' => $item['max_damage'] - $item['damage'], 'max' => $item['max_damage']]);
        }

        $parts[] = $item['id'];

        return implode(' · ', $parts);
    };

    $slotShell = 'relative aspect-square min-w-0 rounded-md bg-gray-100 ring-1 ring-inset ring-gray-950/5 dark:bg-white/5 dark:ring-white/10';
@endphp

@php
    $renderSlot = static function (?array $item) use ($slotShell, $monogram, $tintOf, $tooltip): string {
        if ($item === null) {
            return '<div class="'.$slotShell.'"></div>';
        }

        $durability = '';

        if ($item['max_damage'] !== null && $item['damage'] !== null && $item['max_damage'] > 0 && $item['damage'] > 0) {
            $left = max(0.0, min(1.0, 1 - $item['damage'] / $item['max_damage']));
            $bar = $left > 0.5 ? 'bg-success-500' : ($left > 0.2 ? 'bg-warning-500' : 'bg-danger-500');
            $durability = '<span class="absolute inset-x-1 bottom-0.5 h-0.5 rounded-full bg-gray-950/20"><span class="block h-full rounded-full '.$bar.'" style="width: '.round($left * 100).'%"></span></span>';
        }

        $glint = $item['enchanted'] ? ' ring-2 ring-violet-400/70' : '';
        $count = $item['count'] > 1 ? '<span class="absolute bottom-0 right-0.5 text-[10px] font-bold leading-none text-gray-950 drop-shadow-sm dark:text-white">'.e((string) $item['count']).'</span>' : '';

        return '<div class="'.$slotShell.'" title="'.e($tooltip($item)).'">'
            .'<span class="absolute inset-1 grid place-items-center rounded text-[10px] font-semibold '.$tintOf($item['id']).$glint.'">'.e($monogram($item['id'])).'</span>'
            .$count.$durability.'</div>';
    };
@endphp

<div class="{{ $cardShell }} flex flex-col gap-4 p-4 sm:p-5">
    <div class="flex items-center justify-between gap-2">
        <p class="flex items-center gap-x-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            <x-filament::icon :icon="'tabler-backpack'" class="size-4 shrink-0" />
            {{ trans('minecraft-toolkit::pages.players.inventory.title') }}
        </p>

        @if (! $bridgeActive)
            <span class="inline-flex items-center gap-x-1 rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30">
                <x-filament::icon :icon="'tabler-plug-connected-x'" class="size-3.5 shrink-0" />
                {{ trans('minecraft-toolkit::pages.bridge.needed') }}
            </span>
        @elseif ($detail === null)
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans('minecraft-toolkit::pages.players.inventory.offline') }}</span>
        @endif
    </div>

    {{-- armor (4) + off hand on the same slot grid as the inventory --}}
    <div>
        <div class="mb-1 flex justify-between text-[11px] font-medium text-gray-500 dark:text-gray-400">
            <span>{{ trans('minecraft-toolkit::pages.players.inventory.armor') }}</span>
            <span>{{ trans('minecraft-toolkit::pages.players.inventory.offhand') }}</span>
        </div>
        <div class="grid grid-cols-9 gap-1">
            @foreach (['head', 'chest', 'legs', 'feet'] as $piece)
                {!! $renderSlot($detail['armor'][$piece] ?? null) !!}
            @endforeach
            <div class="col-span-4"></div>
            {!! $renderSlot($detail['offhand'] ?? null) !!}
        </div>
    </div>

    {{-- storage 9–35, then the hotbar 0–8 like in game --}}
    <div class="flex flex-col gap-2">
        <div class="grid grid-cols-9 gap-1">
            @for ($slot = 9; $slot <= 35; $slot++)
                {!! $renderSlot($bySlot[$slot] ?? null) !!}
            @endfor
        </div>
        <div class="grid grid-cols-9 gap-1">
            @for ($slot = 0; $slot <= 8; $slot++)
                {!! $renderSlot($bySlot[$slot] ?? null) !!}
            @endfor
        </div>
    </div>

    {{-- ender chest --}}
    <details class="group">
        <summary class="flex cursor-pointer list-none items-center gap-x-1 text-[11px] font-medium text-gray-500 dark:text-gray-400">
            <x-filament::icon :icon="'tabler-chevron-right'" class="size-3.5 shrink-0 transition group-open:rotate-90" />
            {{ trans('minecraft-toolkit::pages.players.inventory.ender_chest') }}
            @if ($enderBySlot !== [])
                <span class="tabular-nums">({{ count($enderBySlot) }})</span>
            @endif
        </summary>
        <div class="mt-2 grid grid-cols-9 gap-1">
            @for ($slot = 0; $slot <= 26; $slot++)
                {!! $renderSlot($enderBySlot[$slot] ?? null) !!}
            @endfor
        </div>
    </details>

    {{-- effects --}}
    <div>
        <p class="mb-1 text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ trans('minecraft-toolkit::pages.players.inventory.effects') }}</p>
        @if (($detail['effects'] ?? []) === [])
            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $detail === null ? '—' : trans('minecraft-toolkit::pages.players.inventory.no_effects') }}</p>
        @else
            <div class="flex flex-wrap gap-1">
                @foreach ($detail['effects'] as $effect)
                    <span class="inline-flex items-center gap-x-1 rounded-md bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20">
                        {{ $pretty($effect['id']) }}@if ($effect['amplifier'] > 0) {{ ' '.($effect['amplifier'] + 1) }}@endif
                        @if (is_int($effect['duration']) && $effect['duration'] > 0)
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ gmdate($effect['duration'] >= 72000 ? 'H:i:s' : 'i:s', intdiv($effect['duration'], 20)) }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</div>
