<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use McToolkit\Services\DetectionService;
use McToolkit\Services\Properties\PropertiesParser;
use McToolkit\Support\Activity;
use Throwable;

/**
 * Properties — parity port of the demo's server.properties editor:
 * one big InteractsWithForms form split into Section groups (General / Players /
 * World / Network & RCON / Advanced), per-group ghost save buttons validating only
 * their own subset, an Alpine chip filter row, a debounced live search box, and a
 * MOTD editor whose B/I/U/S/Reset toolbar wraps legacy codes around the selection
 * while a multiplayer-list preview mirrors the parsed result.
 *
 * Panel-owned keys (server-port / server-ip) render as disabled inputs labeled
 * "Set by the panel"; {@see PropertiesParser} never rewrites them.
 *
 * State notes: Livewire only keeps PUBLIC properties between requests, so everything a
 * later request needs (the baseline the form was filled from, the load outcome) is a
 * public #[Locked] property — never protected.
 */
final class PropertiesPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::AdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 24;

    protected string $view = 'minecraft-toolkit::server.properties';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/properties (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/properties';

    /** Form state container (statePath 'data' mirrors core ServerFormPage conventions). */
    public ?array $data = [];

    /** Debounced search box state; filters rows live across every group. */
    public ?string $search = null;

    /** Coarse per-group dirty flags set through field afterStateUpdated hooks. */
    #[Locked]
    public array $dirty = [
        'general' => false,
        'players' => false,
        'world' => false,
        'network' => false,
        'advanced' => false,
    ];

    /** DetectionService payload powering the pinned detection banner. */
    #[Locked]
    public ?array $detection = null;

    /** Callout shown under the banner (proxy / unknown software / node down), null = none. */
    #[Locked]
    public ?string $loaderWarning = null;

    /** Rendered when the live search matches nothing anywhere on the page. */
    #[Locked]
    public bool $noResults = false;

    /** Parsed HTML preview of the current MOTD (internally escaped; rendered raw, hence locked). */
    #[Locked]
    public string $motdHtml = '';

    /** Character counter of the stripped MOTD text (0/N against the 50 soft limit). */
    #[Locked]
    public int $motdCount = 0;

    /** True when the stripped MOTD length exceeds the 50-character soft limit. */
    #[Locked]
    public bool $overLimit = false;

    /**
     * Effective baseline the form was filled from (canonical strings); dirty-diffing
     * relies on it so unchanged keys are never written back to disk.
     *
     * @var array<string,string>
     */
    #[Locked]
    public array $baseline = [];

    /** server.properties could not be read: saving stays disabled until a refresh succeeds. */
    #[Locked]
    public bool $loadFailed = false;

    // ---------------------------------------------------------------------
    // Spec table — group => ordered property metadata entries
    // ---------------------------------------------------------------------

    private const GROUP_LABEL_KEYS = [
        'general' => 'group.general',
        'players' => 'group.players',
        'world' => 'group.world',
        'network' => 'group.network',
        'advanced' => 'group.advanced',
    ];

    private const GROUP_ORDER = ['general', 'players', 'world', 'network', 'advanced'];

    private const SPECS = [
        'general' => [
            ['key' => 'motd', 'type' => 'textarea', 'label' => 'MOTD', 'help' => 'Shown in the multiplayer server list.'],
            ['key' => 'max-players', 'type' => 'number', 'label' => 'Max players', 'help' => 'How many players can join at once.', 'min' => 1, 'max' => 2147483647],
            ['key' => 'difficulty', 'type' => 'select', 'label' => 'Difficulty', 'help' => 'Base difficulty of the world.', 'options' => ['normal' => 'normal', 'easy' => 'easy', 'hard' => 'hard', 'peaceful' => 'peaceful']],
            ['key' => 'gamemode', 'type' => 'select', 'label' => 'Game mode', 'help' => 'Default game mode for new players.', 'options' => ['survival' => 'survival', 'creative' => 'creative', 'adventure' => 'adventure', 'spectator' => 'spectator']],
            ['key' => 'hardcore', 'type' => 'toggle', 'label' => 'Hardcore', 'help' => 'On death players become spectators forever.'],
            ['key' => 'pvp', 'type' => 'toggle', 'label' => 'PvP', 'help' => 'Player versus player combat.'],
            ['key' => 'allow-flight', 'type' => 'toggle', 'label' => 'Allow flight', 'help' => 'Tolerate flying clients without kicking them.'],
            ['key' => 'enable-command-block', 'type' => 'toggle', 'label' => 'Command blocks', 'help' => 'Enable command blocks on this server.'],
            ['key' => 'spawn-protection', 'type' => 'number', 'label' => 'Spawn protection', 'help' => 'Protected radius around spawn.', 'min' => 0, 'max' => 2147483647],
        ],
        'players' => [
            ['key' => 'white-list', 'type' => 'toggle', 'label' => 'Whitelist', 'help' => 'Only listed players can join.'],
            ['key' => 'enforce-whitelist', 'type' => 'toggle', 'label' => 'Enforce whitelist', 'help' => 'Re-apply the whitelist after every change.'],
            ['key' => 'online-mode', 'type' => 'toggle', 'label' => 'Online mode', 'help' => 'Verify accounts against Mojang.'],
        ],
        'world' => [
            ['key' => 'level-name', 'type' => 'text', 'label' => 'Level name', 'help' => 'Folder name of the default world.', 'required' => true],
            ['key' => 'level-seed', 'type' => 'text', 'label' => 'Level seed', 'help' => 'Seed used when a new world is generated.'],
            ['key' => 'allow-nether', 'type' => 'toggle', 'label' => 'Allow nether', 'help' => 'Let players travel to the Nether.'],
            ['key' => 'view-distance', 'type' => 'number', 'label' => 'View distance', 'help' => 'Chunks sent around each player.', 'min' => 3, 'max' => 32],
            ['key' => 'simulation-distance', 'type' => 'number', 'label' => 'Simulation distance', 'help' => 'Radius in which entities tick.', 'min' => 3, 'max' => 32],
        ],
        'network' => [
            ['key' => 'accepts-transfers', 'type' => 'toggle', 'label' => 'Accepts transfers', 'help' => 'Accept players transferred from another server.'],
            ['key' => 'enable-query', 'type' => 'toggle', 'label' => 'Query protocol', 'help' => 'Answer the GameSpy4 query protocol.'],
            ['key' => 'enable-rcon', 'type' => 'toggle', 'label' => 'RCON', 'help' => 'Expose the remote console.'],
            ['key' => 'server-port', 'type' => 'managed', 'label' => 'Server port', 'help' => 'Owned by the allocation.'],
            ['key' => 'server-ip', 'type' => 'managed', 'label' => 'Server IP', 'help' => 'Owned by the allocation.'],
        ],
        'advanced' => [
            ['key' => 'broadcast-console-to-ops', 'type' => 'toggle', 'label' => 'Broadcast console to ops', 'help' => 'Echo console commands to operators.'],
        ],
    ];

    /** Legacy numeric values still accepted when reading old files or typed eggs. */
    private const LEGACY_SELECT_ALIASES = [
        'difficulty' => ['0' => 'peaceful', '1' => 'easy', '2' => 'normal', '3' => 'hard'],
        'gamemode' => ['0' => 'survival', '1' => 'creative', '2' => 'adventure', '3' => 'spectator'],
    ];

    private const BOOLEAN_KEYS = [
        'hardcore', 'pvp', 'allow-flight', 'enable-command-block',
        'white-list', 'enforce-whitelist', 'online-mode',
        'allow-nether', 'accepts-transfers', 'enable-query', 'enable-rcon',
        'broadcast-console-to-ops',
    ];

    private const NUMBER_KEYS = ['max-players', 'spawn-protection', 'view-distance', 'simulation-distance'];

    /** Managed keys that have an (always disabled) input on the page. */
    private const MANAGED_FIELDS = ['server-port', 'server-ip'];

    /** Vanilla defaults filling absent keys so the draft stays complete before first write. */
    private const DEFAULTS = [
        'motd' => 'A Minecraft Server',
        'max-players' => '20',
        'difficulty' => 'easy',
        'gamemode' => 'survival',
        'hardcore' => 'false',
        'pvp' => 'true',
        'allow-flight' => 'false',
        'enable-command-block' => 'false',
        'spawn-protection' => '16',
        'white-list' => 'false',
        'enforce-whitelist' => 'false',
        'online-mode' => 'true',
        'level-name' => 'world',
        'level-seed' => '',
        'allow-nether' => 'true',
        'view-distance' => '10',
        'simulation-distance' => '10',
        'accepts-transfers' => 'false',
        'enable-query' => 'false',
        'enable-rcon' => 'false',
        'broadcast-console-to-ops' => 'true',
    ];

    /** MC legacy color palette (§0–§f), modern RGB equivalents. */
    private const MOTD_PALETTE = [
        '0' => '#000000', '1' => '#0000AA', '2' => '#00AA00', '3' => '#00AAAA',
        '4' => '#AA0000', '5' => '#AA00AA', '6' => '#FFAA00', '7' => '#AAAAAA',
        '8' => '#555555', '9' => '#5555FF', 'a' => '#55FF55', 'b' => '#55FFFF',
        'c' => '#FF5555', 'd' => '#FF55FF', 'e' => '#FFFF55', 'f' => '#FFFFFF',
    ];

    private const MOTD_SOFT_LIMIT = 50;

    /** DOM id of the MOTD textarea the toolbar's Alpine handlers target. */
    private const MOTD_FIELD_ID = 'mct-motd';

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function mount(): void
    {
        $this->loadProperties();
    }

    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /**
     * Checked on mount AND on every later Livewire request (CanAuthorizeAccess hydrate
     * hook), so a page opened before a suspension or a permission change stops working.
     */
    public static function canAccess(): bool
    {
        if (! parent::canAccess()) {
            return false;
        }

        // Admin "Enabled pages" toggle (same guard every other toolkit page applies).
        if (! (bool) config('minecraft-toolkit.pages.enabled.properties', true)) {
            return false;
        }

        $server = Filament::getTenant();

        if (! $server instanceof Server || $server->isInConflictState()) {
            return false;
        }

        $user = user();

        return $user !== null
            && $user->can('minecraft-toolkit.use', $server)
            && $user->can('minecraft-toolkit.properties', $server);
    }

    /** Whether the current user may write server.properties right now. */
    private function canManage(): bool
    {
        $server = $this->getRecord();
        $user = user();

        return $user !== null
            && ! $server->isInConflictState()
            && $user->can('minecraft-toolkit.properties', $server)
            && $user->can('minecraft-toolkit.manage', $server);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.properties.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.properties.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.properties.title');
    }

    // ---------------------------------------------------------------------
    // Header actions
    // ---------------------------------------------------------------------

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->color('gray')
                ->icon(\App\Enums\TablerIcon::Refresh)
                ->action(fn () => $this->refresh()),
        ];
    }

    public function refresh(): void
    {
        $this->loadProperties();
    }

    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        $server = $this->getRecord();

        try {
            $detected = app(DetectionService::class)->detect($server, force: true);
        } catch (Throwable) {
            $detected = null;
        }

        $this->applyDetection($detected);

        if (! is_array($detected) || ($detected['reachable'] ?? true) === false) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('mc-toolkit:detection.refresh')
            ->log();

        $software = ucfirst((string) ($detected['software'] ?? 'unknown'));

        Notification::make()
            ->title(trans('minecraft-toolkit::banner.detection.title_note'))
            ->body(filled($detected['version'] ?? null) ? $software.' — '.$detected['version'] : $software)
            ->success()
            ->send();
    }

    // ---------------------------------------------------------------------
    // Form schema — ONE big form divided into Sections per group
    // ---------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        $needle = mb_strtolower(trim((string) ($this->search ?? '')));
        $sections = [];

        foreach (self::GROUP_ORDER as $groupKey) {
            [$fields, $matchInGroup] = $this->buildFields($groupKey, $needle);

            if ($needle !== '' && ! $matchInGroup) {
                continue;
            }

            $sections[] = Section::make(trans('minecraft-toolkit::pages.properties.' . self::GROUP_LABEL_KEYS[$groupKey]))
                ->columnSpanFull()
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                    'md' => 3,
                ])
                ->footerActions([$this->saveGroupAction($groupKey)])
                ->footerActionsAlignment(\Filament\Support\Enums\Alignment::Right)
                ->extraAttributes([
                    'x-show' => "visibleGroup === 'all' || visibleGroup === '{$groupKey}'",
                ])
                ->schema($fields);
        }

        $this->noResults = $needle !== '' && $sections === [];

        // The record makes `Server $server` closures resolvable for every component and
        // action of this schema (same as core's ServerFormPage::form()).
        return $schema
            ->statePath('data')
            ->model($this->getRecord())
            ->components($sections);
    }

    /**
     * Assembles the schema components of one group, honouring the live search filter.
     *
     * @return array{0: list<mixed>, 1: bool} [components, anyComponentMatchesSearch]
     */
    private function buildFields(string $groupKey, string $needle): array
    {
        $fields = [];
        $matched = $needle === '';

        if ($groupKey === 'general') {
            $motdMatches = $needle === ''
                || str_contains(mb_strtolower('motd shown in the multiplayer server list.'), $needle);

            if ($motdMatches) {
                $matched = true;
                // The formatting buttons sit on the field's label row (hint actions); as a
                // separate grid item the toolbar landed in its own column, far from the field.
                $fields[] = $this->motdField();
            }
        }

        foreach (self::SPECS[$groupKey] ?? [] as $spec) {
            // The MOTD has its dedicated toolbar + textarea above; a second generic input
            // bound to the same state path would duplicate it.
            if (strval($spec['type']) === 'textarea') {
                continue;
            }

            $haystack = mb_strtolower(
                strval($spec['key']) . ' ' . strval($spec['label']) . ' ' . strval($spec['help']),
            );

            $rowMatches = $needle === '' || str_contains($haystack, $needle);

            if (! $rowMatches) {
                continue;
            }

            $matched = true;

            if (strval($spec['type']) === 'managed' && $this->managedHidden($needle)) {
                continue;
            }

            $fields[] = $this->fieldFor($spec, $groupKey);
        }

        return [$fields, $matched];
    }

    /** Managed inputs never hide behind the chip navigation; only search may drop them. */
    private function managedHidden(string $needle): bool
    {
        return $needle !== '';
    }

    private function fieldFor(array $spec, string $groupKey): mixed
    {
        $key = strval($spec['key']);
        $label = strval($spec['label']);
        $helperText = strval($spec['help']);

        switch (strval($spec['type'])) {
            case 'toggle':
                return Toggle::make($key)
                    ->label($label)
                    ->helperText($helperText)
                    ->live()
                    ->afterStateUpdated(function () use ($groupKey): void {
                        $this->markDirty($groupKey);
                    });

            case 'select':
                return Select::make($key)
                    ->label($label)
                    ->options(is_array($spec['options'] ?? null) ? $spec['options'] : [])
                    ->helperText($helperText)
                    ->live()
                    ->afterStateUpdated(function () use ($groupKey): void {
                        $this->markDirty($groupKey);
                    });

            case 'number':
                $field = TextInput::make($key)
                    ->label($label)
                    ->numeric();

                if (isset($spec['min']) && $spec['min'] !== null) {
                    $field->minValue(intval($spec['min']));
                }

                if (isset($spec['max']) && $spec['max'] !== null) {
                    $field->maxValue(intval($spec['max']));
                }

                return $field
                    ->helperText($helperText)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () use ($groupKey): void {
                        $this->markDirty($groupKey);
                    });

            case 'text':
                return TextInput::make($key)
                    ->label($label)
                    ->helperText($helperText)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function () use ($groupKey): void {
                        $this->markDirty($groupKey);
                    });

            case 'managed':
                return TextInput::make($key)
                    ->label($label)
                    ->helperText($helperText)
                    ->disabled()
                    ->hint(trans('minecraft-toolkit::pages.properties.managed_by_panel'));

            default:
                return TextInput::make($key)->label($label);
        }
    }

    /**
     * MOTD formatting buttons (§l §o §n §m, reset), shown on the MOTD label row.
     *
     * @return list<Action>
     */
    private function motdToolbar(): array
    {
        return [
            $this->motdWrapAction('l'),
            $this->motdWrapAction('o'),
            $this->motdWrapAction('n'),
            $this->motdWrapAction('m'),
            Action::make('motd_reset')
                ->label(trans('minecraft-toolkit::common.buttons.reset'))
                ->link()
                ->color('gray')
                ->alpineClickHandler(self::motdJs('r')),
        ];
    }

    private function motdField(): Textarea
    {
        return Textarea::make('motd')
            ->id(self::MOTD_FIELD_ID)
            ->label('MOTD')
            ->hintActions($this->motdToolbar())
            ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 2])
            ->autosize()
            ->rows(3)
            ->live()
            ->afterStateUpdated(function ($state): void {
                $this->applyMotdPreview(is_string($state) ? $state : '');
                $this->markDirty('general');
            });
    }

    private function motdWrapAction(string $code): Action
    {
        return Action::make('motd_' . $code)
            ->iconButton()
            ->tooltip(match ($code) {
                'l' => 'Bold',
                'o' => 'Italic',
                'n' => 'Underline',
                'm' => 'Strikethrough',
                default => 'Reset',
            })
            ->icon(match ($code) {
                'l' => \App\Enums\TablerIcon::Bold,
                'o' => \App\Enums\TablerIcon::Italic,
                'n' => \App\Enums\TablerIcon::Underline,
                'm' => \App\Enums\TablerIcon::Strikethrough,
                default => \App\Enums\TablerIcon::ArrowBackUp,
            })
            ->alpineClickHandler(self::motdJs($code));
    }

    /**
     * One-liner Alpine handler: wraps the current selection of the MOTD textarea (found
     * by its fixed DOM id — the live binding renders `wire:model.live`, so attribute
     * matching is brittle) with §<code> … §r, then replays the input event so Alpine's
     * entangled state pushes the change to Livewire immediately.
     */
    private static function motdJs(string $code): string
    {
        $openCode = "'\\u00a7" . $code . "'";
        $closeCode = "'\\u00a7r'";

        return "(() => { var t=document.getElementById('" . self::MOTD_FIELD_ID . "');"
            . ' if(!t){return;}'
            . ' var s=t.selectionStart||0,e=t.selectionEnd||0,v=t.value,c=' . $openCode . ';'
            . ' v=(s===e)?(v.slice(0,s)+c+v.slice(s)):(v.slice(0,s)+c+v.slice(s,e)+' . $closeCode . '+v.slice(e));'
            . ' t.value=v;t.dispatchEvent(new Event(\'input\',{bubbles:true}));t.focus();'
            . ' requestAnimationFrame(function(){try{t.setSelectionRange(Math.min(v.length,s+c.length),Math.min(v.length,s+c.length));}catch(err){}});'
            . ' })()';
    }

    private function saveGroupAction(string $groupKey): Action
    {
        return Action::make('save_' . $groupKey)
            ->label(trans('minecraft-toolkit::common.buttons.save'))
            ->color('primary')
            ->icon(\App\Enums\TablerIcon::DeviceFloppy)
            // Filament re-evaluates this when the action is mounted, so it gates the
            // request server-side too; saveGroup() checks again for direct calls.
            ->disabled(fn (): bool => $this->loadFailed || ! $this->canManage())
            ->action(fn () => $this->saveGroup($groupKey));
    }

    private function markDirty(string $groupKey): void
    {
        if (array_key_exists($groupKey, $this->dirty)) {
            $this->dirty[$groupKey] = true;
        }
    }

    // ---------------------------------------------------------------------
    // Loading & filling
    // ---------------------------------------------------------------------

    private function loadProperties(): void
    {
        $server = $this->getRecord();

        try {
            $detected = app(DetectionService::class)->detect($server);
        } catch (Throwable) {
            $detected = null;
        }

        $this->applyDetection($detected);

        $this->loadFailed = false;

        try {
            $parsed = app(PropertiesParser::class)->read($server);
        } catch (Throwable $e) {
            if (! $e instanceof ConnectionException) {
                report($e);
            }

            Notification::make()
                ->title(trans('minecraft-toolkit::pages.properties.load_failed'))
                ->danger()
                ->send();

            $parsed = ['exists' => false, 'values' => [], 'comments_preserved' => []];
            $this->loadFailed = true;
        }

        $effective = self::DEFAULTS;

        foreach ($parsed['values'] as $key => $value) {
            if (array_key_exists($key, $effective)) {
                $effective[$key] = strval($value);
            }
        }

        $canonical = $this->canonicalize($effective);

        // Panel-owned keys are display-only but still hydrate the disabled inputs.
        foreach (PropertiesParser::MANAGED_KEYS as $managed) {
            $canonical[$managed] = strval($parsed['values'][$managed] ?? '');
        }

        $this->baseline = $canonical;

        $this->form->fill($this->formState($canonical));

        $this->resetDirtyFlags();

        $this->applyMotdPreview(strval($canonical['motd'] ?? ''));
    }

    /**
     * Banner payload + the callout under it. server.properties applies to every game
     * server (vanilla, Paper, Fabric, Forge…); only proxies ignore it, and an
     * unrecognised or unreachable server gets its own message.
     */
    private function applyDetection(?array $detected): void
    {
        $this->detection = $detected;

        $this->loaderWarning = match (true) {
            ! is_array($detected), ($detected['reachable'] ?? true) === false => trans('minecraft-toolkit::banner.failures.gateway_down'),
            ($detected['loader'] ?? null) === 'velocity' => trans('minecraft-toolkit::banner.failures.proxy_no_properties'),
            ($detected['software'] ?? 'unknown') === 'unknown' => trans('minecraft-toolkit::banner.failures.not_minecraft'),
            default => null,
        };
    }

    private function resetDirtyFlags(): void
    {
        $this->dirty = [
            'general' => false,
            'players' => false,
            'world' => false,
            'network' => false,
            'advanced' => false,
        ];
    }

    /**
     * Canonical draft coercion: booleans collapse to lowercase strings, numbers to
     * plain integers, select values accept legacy numeric aliases; the MOTD keeps its
     * leading spaces (used for centering), everything else trims into plain strings.
     *
     * @param  array<string,mixed>  $incoming
     * @return array<string,string>
     */
    private function canonicalize(array $incoming): array
    {
        $out = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $raw = array_key_exists($key, $incoming)
                ? $incoming[$key]
                : (self::DEFAULTS[$key] ?? '');

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                $out[$key] = self::toBool($raw) ? 'true' : 'false';

                continue;
            }

            $text = is_scalar($raw) ? strval($raw) : '';

            if (in_array($key, self::NUMBER_KEYS, true)) {
                $out[$key] = strval(intval(trim($text)));

                continue;
            }

            if ($key === 'motd') {
                $out[$key] = rtrim(str_replace("\r", '', $text));

                continue;
            }

            $text = trim($text);

            if (isset(self::LEGACY_SELECT_ALIASES[$key])) {
                $text = strval(self::LEGACY_SELECT_ALIASES[$key][$text] ?? $text);
            }

            $out[$key] = $text;
        }

        return $out;
    }

    /**
     * Livewire/Filament form state for a canonical value set: toggles need REAL
     * booleans — Filament's BooleanStateCast turns the string "false" into true.
     *
     * @param  array<string,string>  $canonical
     * @return array<string,mixed>
     */
    private function formState(array $canonical): array
    {
        $state = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = $canonical[$key] ?? self::DEFAULTS[$key];

            $state[$key] = in_array($key, self::BOOLEAN_KEYS, true) ? $value === 'true' : $value;
        }

        foreach (self::MANAGED_FIELDS as $managed) {
            $state[$managed] = $canonical[$managed] ?? '';
        }

        return $state;
    }

    private static function toBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            return $raw === 1;
        }

        return is_string($raw)
            && in_array(mb_strtolower(trim($raw)), ['true', '1', 'yes', 'on'], true);
    }

    // ---------------------------------------------------------------------
    // Saving — per-group subset validation, minimal diff write-back
    // ---------------------------------------------------------------------

    public function saveGroup(string $groupKey): void
    {
        if (! $this->canManage()) {
            Notification::make()
                ->title(trans($this->getRecord()->isInConflictState()
                    ? 'minecraft-toolkit::errors.statuses.server_unavailable'
                    : 'minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        if (! array_key_exists($groupKey, self::SPECS)) {
            return;
        }

        if ($this->loadFailed) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.properties.load_failed'))
                ->danger()
                ->send();

            return;
        }

        $server = $this->getRecord();
        $state = is_array($this->data) ? $this->data : [];

        $subset = [];
        $rules = [];
        $attributes = [];

        foreach (self::SPECS[$groupKey] as $spec) {
            $key = strval($spec['key']);

            if (in_array($key, PropertiesParser::MANAGED_KEYS, true)) {
                continue;
            }

            // Livewire's `data` still holds every key, also the ones the live search or
            // the chip filter currently hides — validate and save exactly those.
            $value = array_key_exists($key, $state) ? $state[$key] : ($this->baseline[$key] ?? null);

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                // Native booleans so the `boolean` Validator rule applies deterministically.
                $value = self::toBool($value);
            } elseif (! is_scalar($value)) {
                $value = null;
            }

            $subset[$key] = $value;
            $rules[$key] = $this->rulesFor($key);
            $attributes[$key] = strval($spec['label']);
        }

        // Only this group is validated: invalid fields elsewhere never block it.
        $validator = Validator::make($subset, $rules, [], $attributes);

        if ($validator->fails()) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.properties.group.' . $groupKey))
                ->body(implode(' ', $validator->errors()->all()))
                ->danger()
                ->send();

            // Field-level errors under the matching inputs (Livewire fills the error bag).
            $messages = [];

            foreach ($validator->errors()->messages() as $key => $keyMessages) {
                $messages['data.' . $key] = $keyMessages;
            }

            throw ValidationException::withMessages($messages);
        }

        $canonical = $this->canonicalize(array_merge($this->baseline, $subset));

        $changed = [];
        foreach (array_keys($subset) as $key) {
            $candidate = strval($canonical[$key] ?? '');
            $original = strval($this->baseline[$key] ?? '');

            if ($candidate !== $original) {
                $changed[$key] = $candidate;
            }
        }

        if ($changed === []) {
            $this->dirty[$groupKey] = false;

            return;
        }

        try {
            app(PropertiesParser::class)->write($server, $changed);
        } catch (ConnectionException) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            return;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(trans('minecraft-toolkit::pages.properties.save_failed'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('mc-toolkit:properties.save')
            ->property(['changed' => implode(', ', array_keys($changed))])
            ->log();

        $this->baseline = array_merge($this->baseline, $changed);

        // Re-sync ONLY this group's inputs to the canonical values just written; unsaved
        // edits in other groups stay untouched.
        $formState = $this->formState($this->baseline);

        if (! is_array($this->data)) {
            $this->data = [];
        }

        foreach (array_keys($subset) as $key) {
            $this->data[$key] = $formState[$key];
        }

        $this->resetErrorBag(array_map(static fn (string $key): string => 'data.' . $key, array_keys($subset)));

        $this->dirty[$groupKey] = false;

        if (array_key_exists('motd', $changed)) {
            $this->applyMotdPreview($this->baseline['motd']);
        }

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.properties.group.' . $groupKey))
            ->body(trans('minecraft-toolkit::activity.mc-toolkit.properties.save', [
                'changed' => implode(', ', array_keys($changed)),
            ]))
            ->success()
            ->send();
    }

    /**
     * Validation fragment for ONE property, applied under its state key.
     *
     * @return list<mixed>
     */
    private function rulesFor(string $key): array
    {
        $spec = $this->specOf($key);

        return match (strval($spec['type'])) {
            // `required` matters: an emptied number would otherwise slip past `integer`
            // and be written as intval('') = 0.
            'number' => [
                'required',
                'integer',
                sprintf('between:%s,%s', strval($spec['min'] ?? -2147483648), strval($spec['max'] ?? 2147483647)),
            ],
            'select' => ['required', 'in:' . implode(',', array_keys(is_array($spec['options'] ?? null) ? $spec['options'] : []))],
            'toggle' => ['boolean'],
            'text' => [($spec['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:255'],
            'textarea' => ['nullable', 'string', 'max:1024'],
            'managed' => [],
            default => [],
        };
    }

    private function specOf(string $key): array
    {
        foreach (self::SPECS as $specs) {
            foreach ($specs as $spec) {
                if (strval($spec['key']) === $key) {
                    return $spec;
                }
            }
        }

        return ['key' => $key, 'type' => 'text', 'label' => $key, 'help' => '', 'options' => []];
    }

    // ---------------------------------------------------------------------
    // MOTD preview pipeline
    // ---------------------------------------------------------------------

    private function applyMotdPreview(string $motd): void
    {
        $stripped = preg_replace('/\x{00A7}./u', '', $motd) ?? $motd;

        $this->motdCount = mb_strlen($stripped);
        $this->overLimit = $this->motdCount > self::MOTD_SOFT_LIMIT;
        $this->motdHtml = $this->renderMotd($motd);
    }

    /**
     * Legacy-code parser producing safe inline-styled spans. Every text run escapes
     * through htmlspecialchars first, so the returned markup is XSS-safe raw HTML.
     *
     * Like the game: codes are case-insensitive, a color code clears bold/italic/
     * underline/strikethrough, §r resets everything, §k (obfuscated) is ignored.
     */
    private function renderMotd(string $motd): string
    {
        if ($motd === '') {
            return '';
        }

        $html = '';
        $color = null;
        $styles = [];

        // Split runs at every §; the character right after it is the effect code.
        $runs = preg_split('/(\x{00A7}.)/u', $motd, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($runs as $run) {
            $chunk = $run;

            if (mb_strlen($chunk) >= 2 && mb_substr($chunk, 0, 1) === '§') {
                $code = mb_strtolower(mb_substr($chunk, 1, 1));
                $chunk = mb_substr($chunk, 2);

                if (isset(self::MOTD_PALETTE[$code])) {
                    $color = self::MOTD_PALETTE[$code];
                    $styles = [];
                } elseif ($code === 'r') {
                    $color = null;
                    $styles = [];
                } else {
                    match ($code) {
                        'l' => $styles['font-weight'] = '700',
                        'o' => $styles['font-style'] = 'italic',
                        'n' => $styles['text-decoration']['underline'] = 'underline',
                        'm' => $styles['text-decoration']['line-through'] = 'line-through',
                        default => null,
                    };
                }
            }

            if ($chunk === '') {
                continue;
            }

            $escaped = htmlspecialchars($chunk, ENT_QUOTES, 'UTF-8');
            $escaped = str_replace(["\r\n", "\n"], '<br>', $escaped);

            $styleBits = [];

            if ($color !== null) {
                $styleBits[] = 'color:' . $color;
            }

            foreach ($styles as $property => $value) {
                $styleBits[] = is_array($value)
                    ? $property . ':' . implode(' ', $value)
                    : $property . ':' . $value;
            }

            $attribute = $styleBits === [] ? '' : ' style="' . implode(';', $styleBits) . '"';

            $html .= '<span' . $attribute . '>' . $escaped . '</span>';
        }

        return $html;
    }
}
