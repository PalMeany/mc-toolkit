{{--
    Minecraft Toolkit — registry browse card (plugins / mods / modpacks search results).

    Props:
        item              — contract shape: ['source','id','slug','title','author','description',
                            'downloads','likes','updated','icon_url','page_url',
                            'loaders','game_versions','project_type']
        installed(bool)   — optional, false. Shows the "Installed" success badge.
        pendingActionName(string|null) — optional label override for the CTA.
                            Defaults to common.buttons.update when installed, else common.buttons.install.
        withAction(bool)  — optional, true. When false the default CTA row is skipped entirely
                            (e.g. blocked-state pages that render their own notice).

    Extension points:
        slot          — replaces the whole footer area (custom page-owned action button).
        $attributes   — extra attributes land on the DEFAULT CTA only (wire:click="install(..)",
                        class="..." etc.), so pages can wire actions without a slot. A bag
                        built in PHP is printed RAW: callers must encode every value they
                        put in it (Js::from for wire-call arguments).
                        The outer card intentionally stays attribute-free; wrap it in your own
                        layout container instead.

    Emits nothing itself.
--}}
@php
    $item ??= [];
    $installed ??= false;
    $withAction ??= true;
    if (! isset($attributes)) {
        $attributes = new \Illuminate\View\ComponentAttributeBag();
    }

    $title = (string) ($item['title'] ?? '');
    $author = filled($item['author'] ?? null) ? (string) $item['author'] : null;
    $description = filled($item['description'] ?? null) ? (string) $item['description'] : null;
    $icon = filled($item['icon_url'] ?? null) ? (string) $item['icon_url'] : null;
    // Pages pass a formatted 'updated_human' ("Oct 14, 2026"); the raw ISO stamp is the fallback.
    $updated = filled($item['updated_human'] ?? null)
        ? (string) $item['updated_human']
        : (filled($item['updated'] ?? null) ? (string) $item['updated'] : null);

    $actionLabel = filled($pendingActionName ?? null)
        ? (string) $pendingActionName
        : __('minecraft-toolkit::common.buttons.'.($installed ? 'update' : 'install'));

    /** Counts rendered like the demo: 94M / 6.6k / 420 */
    $fmtCount = static function (mixed $n): string {
        if (! is_numeric($n)) {
            return '';
        }

        $n = max(0.0, (float) $n);

        return match (true) {
            $n >= 1_000_000_000 => str_replace('.0', '', number_format($n / 1_000_000_000, 1)).'B',
            $n >= 1_000_000 => str_replace('.0', '', number_format($n / 1_000_000, 1)).'M',
            $n >= 1_000 => str_replace('.0', '', number_format($n / 1_000, 1)).'k',
            default => (string) (int) $n,
        };
    };

    $downloads = $fmtCount($item['downloads'] ?? null);
    $likes = $fmtCount($item['likes'] ?? null);

    /** True when the caller replaced the footer area through <x-slot> content. */
    $hasCustomAction = isset($slot) && method_exists($slot, 'hasContents') && $slot->hasContents();

    $badgeInstalled = 'inline-flex items-center justify-center gap-x-1 rounded-md bg-success-50 px-2 py-0.5 '
        .'text-xs font-medium text-success-700 ring-1 ring-inset ring-success-600/20 '
        .'dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30';
@endphp

<div class="flex h-full flex-col gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10">
    <div class="flex items-start gap-3">
        @if (! is_null($icon))
            <img src="{{ $icon }}" alt="" loading="lazy" decoding="async"
                 class="size-10 shrink-0 rounded-md object-cover ring-1 ring-gray-950/5 dark:ring-white/10" />
        @else
            <span class="grid size-10 shrink-0 place-items-center rounded-md bg-gray-50 text-gray-400 ring-1 ring-inset ring-gray-950/5 dark:bg-gray-400/10 dark:text-gray-500 dark:ring-white/10">
                <x-filament::icon :icon="'tabler-puzzle'" class="size-6" />
            </span>
        @endif

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $title }}">
                {{ $title }}
            </p>
            @if (! is_null($author))
                <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $author }}</p>
            @endif
        </div>

        @if ($installed)
            <span class="{{ $badgeInstalled }}">
                {{ __('minecraft-toolkit::common.badges.installed') }}
            </span>
        @endif
    </div>

    @if (! is_null($description))
        <p class="line-clamp-3 text-sm leading-6 text-pretty break-words text-gray-500 dark:text-gray-400">
            {{ $description }}
        </p>
    @endif

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium text-gray-500 dark:text-gray-400">
        @if ($downloads !== '')
            <span class="inline-flex items-center gap-x-1"
                  title="{{ number_format((float) ($item['downloads'] ?? 0)) }}">
                <x-filament::icon :icon="'tabler-download'" class="size-4 shrink-0" />
                {{ $downloads }}
            </span>
        @endif

        @if ($likes !== '')
            <span class="inline-flex items-center gap-x-1"
                  title="{{ number_format((float) ($item['likes'] ?? 0)) }}">
                <x-filament::icon :icon="'tabler-star'" class="size-4 shrink-0" />
                {{ $likes }}
            </span>
        @endif

        @if (! is_null($updated))
            <span class="truncate">{{ $updated }}</span>
        @endif
    </div>

    <div class="mt-auto border-t border-gray-100 pt-3 dark:border-white/10">
        @if ($hasCustomAction)
            {!! $slot !!}
        @elseif ($withAction)
            <x-filament::button :color="'primary'" :size="'sm'" class="w-full" {{ $attributes }}>
                {{ $actionLabel }}
            </x-filament::button>
        @endif
    </div>
</div>
