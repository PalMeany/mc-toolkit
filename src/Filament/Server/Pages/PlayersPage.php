<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Attributes\On;
use McToolkit\Services\CommandBus;
use McToolkit\Services\DetectionService;
use McToolkit\Services\Servers\PlayerRegistry;
use McToolkit\Support\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Players manager — parity clone of the original addon's "Players" surface,
 * rebuilt on Pelican's design system (see .research/demo-findings.md §Players).
 *
 * Capability tiers realized here:
 *   Tier 1 (day-one, vanilla everywhere): registry merge + card grid + all command
 *       controls (op/deop/whitelist/ban/kick/gamemode/xp/heal/feed/starve/kill/give)
 *       routed through {@see CommandBus}.
 *   Tier 2 (companion bridge present at `.toolkit/state.json`): Mode / Dimension /
 *       Position info grid values + the "Instant" chip; without the bridge those
 *       fields render em-dash placeholders exactly like the original demo does.
 *
 * COMMAND MAP — every mutation funnels through CommandBus->send(), gated by
 * `minecraft-toolkit.players` + `minecraft-toolkit.manage`:
 *
 *      op            -> op <name>
 *      deop          -> deop <name>
 *      whitelisted   -> whitelist add|remove <name>
 *      banned        -> ban <name>          | pardon <name>  (unban)
 *      kick          -> kick <name>
 *      gamemode      -> gamemode <mode> <name>
 *      give          -> give <name> <item> <count>
 *      xp_set        -> xp set <name> <levels> levels     (see caveat below)
 *      heal          -> effect give <name> minecraft:instant_health 1 1
 *      feed          -> effect give <name> minecraft:saturation 1 3
 *      starve        -> effect give <name> minecraft:hunger 1 3
 *      kill          -> kill <name>
 *
 * XP legacy caveat: modern vanilla syntax is `/xp set <targets> <amount> levels`
 * (since 1.13). Older builds accepted the operands in a different order; we emit the
 * modern order deliberately because every version this panel realistically serves
 * understands it — if an ancient jar is detected the row simply reports the server's
 * own error text back through the danger toast.
 *
 * @property \Filament\Schemas\Schema $form
 */
final class PlayersPage extends Page
{
    use InteractsWithForms;

    /**
     * Form state container backing every Controls / Give input
     * (schema names mirror the historic public props: gamemodeChoice,
     * xpLevels, giveItem, giveCount, addWhitelistName).
     */
    public ?array $data = [];

    /** Canonical blank-slate values used on mount and whenever a detail row opens. */
    private function controlDefaults(): array
    {
        return [
            'addWhitelistName' => '',
            'gamemodeChoice' => 'survival',
            'xpLevels' => 0,
            'giveItem' => 'diamond',
            'giveCount' => 1,
        ];
    }
    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 25;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::UsersGroup;

    protected string $view = 'minecraft-toolkit::server.players';

    /* ------------------------------------------------------------------ */
    /*  Livewire state                                                     */
    /* ------------------------------------------------------------------ */

    /** Current list filter: 'online' | 'all' | 'ops' | 'banned'. */
    public string $filter = 'online';

    public string $search = '';

    /** Dashed-lowercase uuid of the opened detail panel; null hides it. */
    public ?string $selected = null;

    /** Opt-in live polling (15 s) — only honored while the Online tab is active. */
    public bool $polling = false;

    /* ------------------------------------------------------------------ */
    /*  Frozen command definitions                                         */
    /* ------------------------------------------------------------------ */

    private const GAMEMODES = ['survival', 'creative', 'adventure', 'spectator'];

    /**
     * Static shortlist powering the Give combobox suggestions (demo parity:
     * "diamond, golden_apple..."). Free-text input stays allowed so any modded
     * id (stone:variant style) still works after client-side charset validation.
     *
     * PUBLIC deliberately — the players.blade.php datalist reads it directly.
     */
    public const GIVE_SUGGESTIONS = [
        'diamond', 'diamond_block', 'golden_apple', 'apple', 'bread', 'cooked_beef',
        'iron_ingot', 'gold_ingot', 'netherite_ingot', 'oak_log', 'cobblestone',
        'stone', 'dirt', 'torch', 'arrow', 'tnt', 'ender_pearl', 'experience_bottle',
    ];

    /** Item charset guard for free-text Give entries ("lowercase underscore colon"). */
    private const ITEM_PATTERN = '/^[a-z0-9_:]+$/';

    private const PLAYER_NAME_PATTERN = '/^[A-Za-z0-9_]{3,16}$/';

    private const MAX_GIVE_COUNT = 576; // 36 stacks — comfortably below the wire limit

    /* ------------------------------------------------------------------ */
    /*  Authorization                                                      */
    /* ------------------------------------------------------------------ */

    public static function canAccess(): bool
    {
        // Page toggle from the admin settings slide-over rides alongside 'use'
        // (guide §8b decision #4: no core per-page switch exists, config map instead).
        if (! (bool) config('minecraft-toolkit.pages.enabled.players', true)) {
            return false;
        }

        return parent::canAccess()
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.players', Filament::getTenant()), 403);
    }

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->form->fill($this->controlDefaults());
    }

    /**
     * Schema powering every Controls / Give input on players.blade.php
     * (IMPLEMENTATION-GUIDE §2.4: no raw HTML form controls on panel pages).
     *
     * The Gamemode field fires its command immediately through afterStateUpdated
     * and snaps back to `survival` so re-picking the same option re-fires; XP and
     * Give submit via their wire:click action buttons next to the schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('addWhitelistName')
                    ->label(trans('minecraft-toolkit::pages.players.controls.whitelisted'))
                    ->required()
                    ->maxLength(16),

                ToggleButtons::make('gamemodeChoice')
                    ->label(trans('minecraft-toolkit::pages.players.controls.gamemode'))
                    ->inline()
                    ->options(array_combine(self::GAMEMODES, self::GAMEMODES))
                    ->live()
                    ->afterStateUpdated(fn () => $this->applyGamemodeSelection()),

                TextInput::make('xpLevels')
                    ->label(trans('minecraft-toolkit::pages.players.controls.xp_levels'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(32767)
                    ->default(0)
                    ->live(onBlur: true),

                TextInput::make('giveItem')
                    ->regex(self::ITEM_PATTERN)
                    ->maxLength(48)
                    ->default('diamond')
                    // keeps the existing suggestion datalist wired (id mc-give-items)
                    ->extraInputAttributes(['list' => 'mc-give-items']),

                TextInput::make('giveCount')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(self::MAX_GIVE_COUNT)
                    ->default(1),
            ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Presentation metadata                                              */
    /* ------------------------------------------------------------------ */

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.players.title');
    }

    public function getSubheading(): ?string
    {
        // Exact demo line: "The inventory, health and permissions of your players,
        // changed live in the game."
        return trans('minecraft-toolkit::pages.players.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.players.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(TablerIcon::Refresh)
                ->color('gray')
                ->action(fn () => $this->refreshRegistry()),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Data accessors consumed by the blade view                          */
    /* ------------------------------------------------------------------ */

    public function getRecord(): Server
    {
        $tenant = Filament::getTenant();

        throw_unless($tenant instanceof Server, new \LogicException('PlayersPage requires the server tenant panel.'));

        return $tenant;
    }

    /** DetectionService result feeding the pinned banner component. */
    public function software(): array
    {
        try {
            return app(DetectionService::class)->detect($this->getRecord());
        } catch (\Throwable) {
            return ['software' => 'unknown', 'version' => null, 'java' => null, 'proxy' => false];
        }
    }

    /** Full registry snapshot (cached ~30 s server-side unless forced). */
    public function playersSnapshot(): array
    {
        try {
            return app(PlayerRegistry::class)->snapshot($this->getRecord());
        } catch (\Throwable) {
            return ['players' => [], 'counters' => ['online' => 0, 'max' => 0, 'ping_ok' => false]];
        }
    }

    /** Whether the companion bridge published its state file (Instant chip signal). */
    public function bridgeActive(): bool
    {
        return app(PlayerRegistry::class)->bridgeActive($this->getRecord());
    }

    /** IP bans for the Banned tab — address-level entries have no player row. */
    public function ipBans(): array
    {
        try {
            return app(PlayerRegistry::class)->ipBans($this->getRecord());
        } catch (\Throwable) {
            return [];
        }
    }

    /** Cheap status probe used to disable command buttons while offline. */
    public function canSendCommands(): bool
    {
        try {
            return app(CommandBus::class)->canSend($this->getRecord());
        } catch (\Throwable) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Navigation / polling interactions                                  */
    /* ------------------------------------------------------------------ */

    public function selectPlayer(string $uuid): void
    {
        $clean = trim($uuid);

        $this->selected = $clean === '' ? null : strtolower($clean);

        if ($this->selected === null) {
            return;
        }

        $row = collect($this->playersSnapshot()['players'])->firstWhere('uuid', $this->selected);

        if ($row === null) {
            return;
        }

        // Reset the editable controls to sane defaults each time the panel opens.
        $this->data['gamemodeChoice'] = 'survival';
        $this->data['xpLevels'] = 0;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['online', 'all', 'ops', 'banned'], true) ? $filter : 'online';
    }

    public function refreshRegistry(): void
    {
        try {
            app(PlayerRegistry::class)->snapshot($this->getRecord(), force: true);
        } catch (\Throwable) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.refresh'))
                ->body(trans('minecraft-toolkit::errors.statuses.wings_502'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.refresh'))
            ->success()
            ->send();
    }

    /** Detection-banner listener — forces the filesystem re-probe (demo parity). */
    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        $detected = app(DetectionService::class)->detect($this->getRecord(), force: true);

        Activity::event('mc-toolkit:detection.refresh')->log();

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.detect_again'))
            ->body(trans('minecraft-toolkit::banner.detection.title_note')
                . ' · ' . ucfirst(strval($detected['software']))
                . ($detected['version'] !== null ? ' ' . strval($detected['version']) : ''))
            ->success()
            ->send();
    }

    /**
     * wire:poll.15s handler — Online tab only; manual Refresh covers other tabs.
     * Forced snapshots deliberately bypass the 30 s registry cache so the counter
     * moves once per tick while never faster than the opt-in cadence itself
     * (node-throttling guidance from guide risk R18).
     */
    public function pollTick(): void
    {
        if (! $this->polling || $this->filter !== 'online') {
            return;
        }

        try {
            app(PlayerRegistry::class)->snapshot($this->getRecord(), force: true);
        } catch (\Throwable) {
            // Silent tick failures keep polling alive; the danger toast path stays
            // reserved for explicit user actions like the header Refresh.
        }
    }

    public function togglePolling(): void
    {
        $this->polling = ! $this->polling;
    }

    /**
     * afterStateUpdated hook of the Gamemode ToggleButtons: applies the command
     * immediately, then snaps back to the vanilla default so re-picking the same
     * option next time still fires the change event.
     */
    private function applyGamemodeSelection(): void
    {
        $mode = mb_strtolower(trim(strval($this->data['gamemodeChoice'] ?? '')));

        if ($mode !== '') {
            $this->applyGamemode($mode);
        }

        $this->data['gamemodeChoice'] = 'survival';
    }

    /* ------------------------------------------------------------------ */
    /*  Mutations                                                          */
    /* ------------------------------------------------------------------ */

    public function applyGamemode(string $mode): void
    {
        $player = $this->requireSelectedRow();

        if ($player === null || ! in_array($mode, self::GAMEMODES, true)) {
            return;
        }

        $this->dispatchCommand(
            template: 'gamemode {mode} {name}',
            vars: ['{mode}' => $mode, '{name}' => $player['name']],
            actionKey: 'gamemode',
            player: $player,
        );
    }

    /** Applies the numeric stepper value via `xp set` (value rides $data.xpLevels). */
    public function setXpLevels(): void
    {
        $player = $this->requireSelectedRow();

        if ($player === null) {
            return;
        }

        $levels = max(0, min(32767, intval($this->data['xpLevels'] ?? 0)));

        $this->dispatchCommand(
            // Modern vanilla order (XP legacy caveat documented in the class docblock):
            // `/xp set <target> <amount> levels`.
            template: 'xp set {name} {levels} levels',
            vars: ['{name}' => $player['name'], '{levels}' => strval($levels)],
            actionKey: 'xp.set',
            player: $player,
        );
    }

    public function bumpXp(int $delta): void
    {
        $current = max(0, min(32767, intval($this->data['xpLevels'] ?? 0)));

        $this->data['xpLevels'] = max(0, min(32767, $current + $delta));
    }

    public function healPlayer(): void
    {
        $this->effectOnSelected('heal', 'heal');
    }

    public function feedPlayer(): void
    {
        $this->effectOnSelected('feed', 'feed');
    }

    public function starvePlayer(): void
    {
        $this->effectOnSelected('starve', 'starve');
    }

    public function killPlayer(): void
    {
        $this->effectOnSelected('kill', 'kill');
    }

    public function kickPlayer(): void
    {
        $this->effectOnSelected('kick', 'kick');
    }

    /** Operator toggle — sends op or deop depending on the current flag. */
    public function toggleOperator(string $uuid): void
    {
        $player = $this->findRow($uuid);

        if ($player === null) {
            return;
        }

        $turningOn = ! (bool) $player['op'];

        $this->dispatchCommand(
            template: $turningOn ? 'op {name}' : 'deop {name}',
            vars: ['{name}' => $player['name']],
            actionKey: $turningOn ? 'op' : 'deop',
            player: $player,
        );
    }

    public function toggleWhitelisted(string $uuid): void
    {
        $player = $this->findRow($uuid);

        if ($player === null) {
            return;
        }

        $turningOn = ! (bool) $player['whitelisted'];

        $this->dispatchCommand(
            template: $turningOn ? 'whitelist add {name}' : 'whitelist remove {name}',
            vars: ['{name}' => $player['name']],
            actionKey: $turningOn ? 'whitelist.add' : 'whitelist.remove',
            player: $player,
        );
    }

    public function toggleBanned(string $uuid): void
    {
        $player = $this->findRow($uuid);

        if ($player === null) {
            return;
        }

        $banning = ! (bool) $player['banned'];

        $this->dispatchCommand(
            template: $banning ? 'ban {name}' : 'pardon {name}',
            vars: ['{name}' => $player['name']],
            actionKey: $banning ? 'ban' : 'pardon',
            player: $player,
        );
    }

    public function giveItem(): void
    {
        $player = $this->requireSelectedRow();

        if ($player === null) {
            return;
        }

        $item = mb_strtolower(trim(strval($this->data['giveItem'] ?? '')));
        $count = max(1, min(self::MAX_GIVE_COUNT, intval($this->data['giveCount'] ?? 1)));

        if (! preg_match(self::ITEM_PATTERN, $item)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.give'))
                ->body('Use lowercase letters, digits, underscores or colons for the item id.') // lang gap (EN literal)
                ->danger()
                ->send();

            return;
        }

        $this->dispatchCommand(
            template: 'give {name} {item} {count}',
            vars: [
                '{name}' => $player['name'],
                '{item}' => $item,
                '{count}' => strval($count),
            ],
            actionKey: 'give',
            player: $player,
        );
    }

    /** Quick add-player-to-whitelist (name rides $data.addWhitelistName, demo parity). */
    public function addToWhitelist(): void
    {
        $name = trim(strval($this->data['addWhitelistName'] ?? ''));

        if (! preg_match(self::PLAYER_NAME_PATTERN, $name)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.search')) // lang gap placeholder
                ->body('Player names are 3-16 letters, digits or underscores.') // lang gap (EN literal)
                ->danger()
                ->send();

            return;
        }

        // Unknown until the server joins them into its own registry files —
        // a pseudo-row feeds only the audit properties of the shared funnel.
        $player = $this->freshPlaceholderPlayer('', $name);

        $this->dispatchCommand(
            template: 'whitelist add {name}',
            vars: ['{name}' => $name],
            actionKey: 'whitelist.add',
            player: $player,
        );

        $this->data['addWhitelistName'] = '';
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    private function effectOnSelected(string $actionKey, string $templateKey): void
    {
        $player = $this->requireSelectedRow();

        if ($player === null) {
            return;
        }

        $templates = [
            'heal' => 'effect give {name} minecraft:instant_health 1 1',
            'feed' => 'effect give {name} minecraft:saturation 1 3',
            'starve' => 'effect give {name} minecraft:hunger 1 3',
            'kill' => 'kill {name}',
            'kick' => 'kick {name}',
        ];

        $this->dispatchCommand(
            template: $templates[$templateKey],
            vars: ['{name}' => $player['name']],
            actionKey: $templateKey,
            player: $player,
        );
    }

    private function requireSelectedRow(): ?array
    {
        return $this->selected === null ? null : $this->findRow($this->selected);
    }

    private function findRow(string $uuid): ?array
    {
        $row = collect(app(PlayerRegistry::class)->snapshot($this->getRecord())['players'])
            ->firstWhere('uuid', strtolower(trim($uuid)));

        if ($row === null) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.no_results'))
                ->danger()
                ->send();
        }

        return $row;
    }

    /**
     * Shared funnel: permission double-gate -> CommandBus send -> activity log ->
     * targeted registry invalidation -> toast feedback (no abort() outside
     * authorizeAccess per the wave task contract).
     *
     * @param  array<string, mixed>  $player
     */
    private function dispatchCommand(string $template, array $vars, string $actionKey, array $player): void
    {
        $server = $this->getRecord();

        if (! $this->ensureCanMutate($server)) {
            return;
        }

        $command = strtr($template, $vars);

        try {
            app(CommandBus::class)->send($server, $command);
        } catch (ConnectionException) {
            Notification::make()
                ->title($command)
                ->body(trans('minecraft-toolkit::errors.statuses.wings_502'))
                ->danger()
                ->send();

            return;
        } catch (HttpException $exception) {
            Notification::make()
                ->title($command)
                ->body(filled($exception->getMessage())
                    ? strval($exception->getMessage())
                    : trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        // Domain-specific audit trail; CommandBus already logs mc-toolkit:console.command.
        Activity::event('mc-toolkit:players.action')
            ->subject($server)
            ->property([
                'action' => $actionKey,
                'player' => $player['name'],
            ])
            ->log();

        // Targeted registry cache drop — next poll/re-render re-reads the vanilla files.
        app(PlayerRegistry::class)->invalidate($server);

        Notification::make()
            ->title($command)
            ->body(
                str(trans('minecraft-toolkit::activity.mc-toolkit.players.action', [
                    'action' => $actionKey,
                    'player' => $player['name'],
                ]))->stripTags(),
            )
            ->success()
            ->send();
    }

    private function ensureCanMutate(Server $server): bool
    {
        $user = user();

        if ($user?->can('minecraft-toolkit.players', $server) && $user->can('minecraft-toolkit.manage', $server)) {
            return true;
        }

        Notification::make()
            ->title(trans('minecraft-toolkit::common.badges.unsupported')) // closest existing key; lang gap noted
            ->body(trans('minecraft-toolkit::errors.statuses.permission_denied'))
            ->danger()
            ->send();

        return false;
    }

    private function freshPlaceholderPlayer(string $pseudoUuid, string $name): array
    {
        return [
            'uuid' => $pseudoUuid,
            'name' => $name,
            'op' => false,
            'whitelisted' => false,
            'banned' => false,
            'ban_reason' => null,
            'online' => false,
            'last_seen' => null,
        ];
    }
}
