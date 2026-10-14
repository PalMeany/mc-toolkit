{{--
    Minecraft Toolkit — background task card (queued software / modpack installs).

    Payload: McToolkit\Support\ServerTask::present() (null renders nothing):
        kind, label, state (queued|running|done|failed), step, step_params, done, total,
        percent, message, warning, notes, queued_at/started_at/finished_at (unix),
        active, finished, queue_stalled, dead, can_cancel, can_dismiss, ref

    Usage on a page using Concerns\TracksServerTask:
        <x-minecraft-toolkit::task-status :task="$task" :can-control="..." />

    While the task is active the card polls the hosting component (`pollTask`) every two
    seconds; once it finished the attribute is gone and polling stops.
--}}
@props([
    'task' => null,
    'canControl' => true,
    'poll' => 'pollTask',
    'cancel' => 'cancelTask',
    'dismiss' => 'dismissTask',
])

@if (is_array($task))
    @php
        $state = (string) ($task['state'] ?? '');
        $dead = (bool) ($task['dead'] ?? false);
        $active = (bool) ($task['active'] ?? false);
        $warning = (bool) ($task['warning'] ?? false);
        $notes = array_values(array_filter((array) ($task['notes'] ?? []), 'is_string'));
        $total = (int) ($task['total'] ?? 0);
        $done = (int) ($task['done'] ?? 0);
        $percent = $task['percent'] ?? null;

        $stateKey = $dead ? 'dead' : $state;
        $tone = match (true) {
            $dead, $state === 'failed' => 'danger',
            $state === 'done' && $warning => 'warning',
            $state === 'done' => 'success',
            $state === 'running' => 'primary',
            default => 'gray',
        };

        $icon = match (true) {
            $dead => 'tabler-alert-triangle',
            $state === 'failed' => 'tabler-circle-x',
            $state === 'done' && $warning => 'tabler-alert-circle',
            $state === 'done' => 'tabler-circle-check',
            $state === 'queued' => 'tabler-clock',
            default => null, // running: spinner
        };

        $badge = [
            'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-300 dark:ring-danger-400/30',
            'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30',
            'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-300 dark:ring-success-400/30',
            'primary' => 'bg-primary-50 text-primary-700 ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30',
            'gray' => 'bg-gray-50 text-gray-600 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20',
        ][$tone];

        $iconColor = [
            'danger' => 'text-danger-600 dark:text-danger-400',
            'warning' => 'text-warning-600 dark:text-warning-400',
            'success' => 'text-success-600 dark:text-success-400',
            'primary' => 'text-primary-600 dark:text-primary-400',
            'gray' => 'text-gray-400 dark:text-gray-500',
        ][$tone];

        $messageColor = match (true) {
            $state === 'failed' => 'text-danger-600 dark:text-danger-400',
            $warning => 'text-warning-600 dark:text-warning-400',
            default => 'text-gray-700 dark:text-gray-300',
        };

        $step = is_string($task['step'] ?? null) ? $task['step'] : null;
        $stepLabel = null;

        if ($step !== null && ! in_array($state, ['done', 'failed'], true)) {
            $stepKey = 'minecraft-toolkit::pages.tasks.steps.'.$step;
            $stepLabel = trans($stepKey, (array) ($task['step_params'] ?? []));
            $stepLabel = $stepLabel === $stepKey ? $step : $stepLabel;
        }

        $kindKey = 'minecraft-toolkit::pages.tasks.kinds.'.($task['kind'] ?? '');
        $kindLabel = trans($kindKey);
        $kindLabel = $kindLabel === $kindKey ? trans('minecraft-toolkit::pages.tasks.heading') : $kindLabel;

        $when = static fn ($ts): ?string => is_int($ts) ? \Illuminate\Support\Carbon::createFromTimestamp($ts)->diffForHumans() : null;
        $timeLine = match (true) {
            $when($task['finished_at'] ?? null) !== null => trans('minecraft-toolkit::pages.tasks.finished_at', ['time' => $when($task['finished_at'])]),
            $when($task['started_at'] ?? null) !== null => trans('minecraft-toolkit::pages.tasks.started_at', ['time' => $when($task['started_at'])]),
            $when($task['queued_at'] ?? null) !== null => trans('minecraft-toolkit::pages.tasks.queued_at', ['time' => $when($task['queued_at'])]),
            default => null,
        };
    @endphp

    <section
        wire:key="mct-task-{{ $task['ref'] ?? 'none' }}"
        @if ($active) wire:poll.2s="{{ $poll }}" @endif
        aria-live="polite"
        class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 sm:p-5 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
            <span class="mt-0.5 shrink-0 {{ $iconColor }}">
                @if ($icon === null)
                    <x-filament::loading-indicator class="size-5" />
                @else
                    <x-filament::icon :icon="$icon" class="size-5" />
                @endif
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $kindLabel }}</p>
                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $task['label'] ?? '' }}</span>
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge }}">
                        {{ trans('minecraft-toolkit::pages.tasks.states.'.$stateKey) }}
                    </span>
                </p>
            </div>

            @if ($canControl && (($task['can_cancel'] ?? false) || ($task['can_dismiss'] ?? false)))
                <div class="flex shrink-0 items-center gap-x-2">
                    @if ($task['can_cancel'] ?? false)
                        <x-filament::button color="gray" size="sm" :outlined="true"
                                            wire:click="{{ $cancel }}" wire:loading.attr="disabled" wire:target="{{ $cancel }}">
                            {{ trans('minecraft-toolkit::common.buttons.cancel') }}
                        </x-filament::button>
                    @endif

                    @if ($task['can_dismiss'] ?? false)
                        <x-filament::button color="gray" size="sm" :outlined="true"
                                            wire:click="{{ $dismiss }}" wire:loading.attr="disabled" wire:target="{{ $dismiss }}">
                            {{ trans('minecraft-toolkit::pages.tasks.dismiss') }}
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>

        @if ($stepLabel !== null)
            <div class="mt-3 space-y-1.5">
                <div class="flex items-center justify-between gap-3 text-xs text-gray-600 dark:text-gray-300">
                    <span class="truncate">{{ $stepLabel }}</span>
                    @if ($total > 0)
                        <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                            {{ trans('minecraft-toolkit::pages.tasks.progress', ['done' => number_format($done), 'total' => number_format($total)]) }}
                        </span>
                    @endif
                </div>

                @if ($state === 'running' && ! $dead)
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                         role="progressbar"
                         @if (is_int($percent)) aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" @endif>
                        @if (is_int($percent))
                            <div class="h-full rounded-full bg-primary-600 transition-all duration-500 dark:bg-primary-500" style="width: {{ max(2, $percent) }}%"></div>
                        @else
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-primary-600 dark:bg-primary-500"></div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        @if (filled($task['message'] ?? null))
            <p class="mt-3 text-sm {{ $messageColor }}">{{ $task['message'] }}</p>
        @endif

        @if ($task['queue_stalled'] ?? false)
            <p class="mt-3 flex items-start gap-x-1.5 text-xs text-warning-600 dark:text-warning-400">
                <x-filament::icon icon="tabler-alert-triangle" class="mt-px size-4 shrink-0" />
                <span>{{ trans('minecraft-toolkit::pages.tasks.queue_hint') }}</span>
            </p>
        @endif

        @if ($dead)
            <p class="mt-3 flex items-start gap-x-1.5 text-xs text-danger-600 dark:text-danger-400">
                <x-filament::icon icon="tabler-alert-triangle" class="mt-px size-4 shrink-0" />
                <span>{{ trans('minecraft-toolkit::pages.tasks.dead_hint') }}</span>
            </p>
        @endif

        @if ($notes !== [])
            <ul class="mt-3 list-disc space-y-1 ps-5 text-xs leading-5 text-gray-600 marker:text-gray-400 dark:text-gray-300 dark:marker:text-gray-500">
                @foreach ($notes as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif

        @if ($timeLine !== null)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">{{ $timeLine }}</p>
        @endif
    </section>
@endif
