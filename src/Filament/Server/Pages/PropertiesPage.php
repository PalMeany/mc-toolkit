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
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
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
 */
final class PropertiesPage extends Page
{
    use InteractsWithFormActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::AdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 24;

    protected string $view = 'minecraft-toolkit::server.properties';

    /** Form state container (statePath 'data' mirrors core ServerFormPage conventions). */
    public ?array $data = [];

    /** Debounced search box state; filters rows live across every group. */
    public ?string $search = null;

    /** Coarse per-group dirty flags set through field afterStateUpdated hooks. */
    public array $dirty = [
        'general' => false,
        'players' => false,
        'world' => false,
        'network' => false,
        'advanced' => false,
    ];

    /** DetectionService payload powering the pinned detection banner + loader callout. */
    public ?array $detection = null;

    /** True when the detected loader is NOT bukkit-family (banner.failures.not_minecraft callout). */
    public bool $showLoaderWarning = false;

    /** Rendered when the live search matches nothing anywhere on the page. */
    public bool $noResults = false;

    /** Parsed HTML preview of the current MOTD (internally escaped). */
    public string $motdHtml = '';

    /** Character counter of the stripped MOTD text (0/N against the 50 soft limit). */
    public int $motdCount = 0;

    /** True when the stripped MOTD length exceeds the 50-character soft limit. */
    public bool $overLimit = false;

    /**
     * Effective baseline the form was filled from (canonical strings); dirty-diffing
     * relies on it so unchanged keys are never written back to disk.
     *
     * @var array<string,string>
     */
    protected array $baseline = [];

    protected bool $propertiesExist = false;

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
            ['key' => 'level-name', 'type' => 'text', 'label' => 'Level name', 'help' => 'Folder name of the default world.'],
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

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->loadProperties();
    }

    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public static function canAccess(): bool
    {
        // Admin "Enabled pages" toggle (same guard every other toolkit page applies).
        if (! (bool) config('minecraft-toolkit.pages.enabled.properties', true)) {
            return false;
        }

        return parent::canAccess()
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.properties', Filament::getTenant()), 403);
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
            app(DetectionService::class)->detect($server, force: true);
        } catch (Throwable) {
            // detect() already falls back to its unknown shape without caching failures.
        }

        $detected = app(DetectionService::class)->detect($server);

        $this->detection = $detected;
        $this->showLoaderWarning = strval($detected['loader'] ?? 'vanilla') !== 'bukkit';

        Activity::event('mc-toolkit:detection.refresh')
            ->log();

        Notification::make()
            ->title(trans('minecraft-toolkit::banner.detection.title_note'))
            ->body(trans('minecraft-toolkit::activity.mc-toolkit.detection.refresh'))
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

        return $schema
            ->statePath('data')
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
            }

            if ($needle === '' || $motdMatches) {
                $fields[] = $this->motdToolbar();
                $fields[] = $this->motdField();
            }
        }

        foreach (self::SPECS[$groupKey] ?? [] as $spec) {
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

    /** MOTD toolbar rendered as a compact icon-button row above the textarea. */
    private function motdToolbar(): Actions
    {
        return Actions::make([
            $this->motdWrapAction('l'),
            $this->motdWrapAction('o'),
            $this->motdWrapAction('n'),
            $this->motdWrapAction('m'),
            Action::make('motd_reset')
                ->label(trans('minecraft-toolkit::common.buttons.reset'))
                ->color('gray')
                ->alpineClickHandler(self::motdJs('r')),
        ]);
    }

    private function motdField(): Textarea
    {
        return Textarea::make('motd')
            ->label('MOTD')
            ->autosize()
            ->rows(3)
            ->live()
            ->afterStateUpdated(function ($state): void {
                $this->applyMotdPreview((string) ($state ?? ''));
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
     * One-liner Alpine handler: wraps the current selection of the big-form MOTD
     * textarea (`wire:model="data.motd"`) with §<code> … §r, then replays the input
     * event so Livewire syncs immediately. No global helpers required.
     */
    private static function motdJs(string $code): string
    {
        $openCode = "'\\u00a7" . $code . "'";
        $closeCode = "'\\u00a7r'";

        return "(() => { var t=[].slice.call(document.querySelectorAll('textarea')).filter(function(el){return String(el.getAttribute('wire:model')||'').endsWith('.motd');})[0];"
            . ' if(!t){return;}'
            . ' var s=t.selectionStart||0,e=t.selectionEnd||0,v=t.value,c=' . $openCode . ';'
            . ' v=(s===e)?(v.slice(0,s)+c+v.slice(s)):(v.slice(0,s)+c+v.slice(s,e)+' . $closeCode . '+v.slice(e));'
            . ' t.value=v;t.dispatchEvent(new Event("input",{bubbles:true}));t.focus();'
            . ' requestAnimationFrame(function(){try{t.setSelectionRange(Math.min(v.length,s+c.length),Math.min(v.length,s+c.length));}catch(err){}});'
            . ' })()';
    }

    private function saveGroupAction(string $groupKey): Action
    {
        return Action::make('save_' . $groupKey)
            ->label(trans('minecraft-toolkit::common.buttons.save'))
            ->color('gray')
            ->icon(\App\Enums\TablerIcon::DeviceFloppy)
            ->disabled(fn (Server $server) => ! user()?->can('minecraft-toolkit.properties', $server) || ! user()?->can('minecraft-toolkit.manage', $server))
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
            $this->detection = app(DetectionService::class)->detect($server);
        } catch (Throwable) {
            $this->detection = null;
        }

        $loaderFamily = is_array($this->detection)
            ? strval($this->detection['loader'] ?? 'vanilla')
            : 'vanilla';

        $this->showLoaderWarning = $loaderFamily !== 'bukkit';

        try {
            $parsed = app(PropertiesParser::class)->read($server);
        } catch (ConnectionException) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            $parsed = ['exists' => false, 'values' => [], 'comments_preserved' => []];
        }

        $this->propertiesExist = (bool) $parsed['exists'];

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

        $this->form->fill($canonical);

        $this->resetDirtyFlags();

        $this->applyMotdPreview(strval($canonical['motd'] ?? ''));
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
     * plain integers, select values accept legacy numeric aliases; everything else
     * trims into plain strings.
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
                $flag = is_bool($raw)
                    ? $raw
                    : in_array(mb_strtolower(trim((string) $raw)), ['true', '1', 'yes', 'on'], true);

                $out[$key] = $flag ? 'true' : 'false';

                continue;
            }

            if (in_array($key, self::NUMBER_KEYS, true)) {
                $out[$key] = strval(intval($raw));

                continue;
            }

            $text = trim(strval($raw));

            if (isset(self::LEGACY_SELECT_ALIASES[$key])) {
                $text = strval(self::LEGACY_SELECT_ALIASES[$key][$text] ?? $text);
            }

            $out[$key] = $text;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Saving — per-group subset validation, minimal diff write-back
    // ---------------------------------------------------------------------

    public function saveGroup(string $groupKey): void
    {
        $server = $this->getRecord();

        if (
            ! user()?->can('minecraft-toolkit.properties', $server)
            || ! user()?->can('minecraft-toolkit.manage', $server)
        ) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        $keys = array_map(
            static fn (array $spec): string => strval($spec['key']),
            self::SPECS[$groupKey] ?? [],
        );

        try {
            $state = $this->form->getState();
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $subset = [];
        $rules = [];

        foreach ($keys as $key) {
            if (in_array($key, PropertiesParser::MANAGED_KEYS, true)) {
                continue;
            }

            // Keys hidden by the live search are absent from Schema::getState()
            // (only rendered components aggregate state); fall back to the baseline
            // value instead of an empty string so untouched keys never diff as a
            // coerced 0 / false write into server.properties.
            $subset[$key] = array_key_exists($key, $state)
                ? $state[$key]
                : strval($this->baseline[$key] ?? '');

            if (in_array($key, self::BOOLEAN_KEYS, true)) {
                // Native booleans so the `boolean` Validator rule applies deterministically.
                $subset[$key] = is_bool($subset[$key])
                    ? $subset[$key]
                    : in_array(mb_strtolower(trim((string) $subset[$key])), ['true', '1', 'yes', 'on'], true);
            }

            $rules[$key] = $this->rulesFor($key);
        }

        $validator = Validator::make($subset, $rules);

        if ($validator->fails()) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.properties.group.' . $groupKey))
                ->body(implode(', ', $validator->errors()->all()))
                ->danger()
                ->send();

            return;
        }

        $canonical = $this->canonicalize(array_merge($this->baseline, $subset));

        $changed = [];
        foreach ($subset as $key => $ignoredValue) {
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
        } catch (\RuntimeException $e) {
            report($e);

            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        } catch (\LogicException|Throwable $e) {
            report($e);

            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('mc-toolkit:properties.save')
            ->property(['changed' => implode(', ', array_keys($changed))])
            ->log();

        $this->baseline = array_merge($this->baseline, $changed);

        $this->form->fill($this->baseline);

        $this->resetDirtyFlags();

        $this->applyMotdPreview(strval($this->baseline['motd'] ?? ''));

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
            'number' => [
                'integer',
                sprintf('between:%s,%s', strval($spec['min'] ?? -2147483648), strval($spec['max'] ?? 2147483647)),
            ],
            'select' => ['in:' . implode(',', array_keys(is_array($spec['options'] ?? null) ? $spec['options'] : []))],
            'toggle' => ['boolean'],
            'text' => ['string', 'max:255'],
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
     */
    private function renderMotd(string $motd): string
    {
        if ($motd === '') {
            return '';
        }

        $html = '';
        $color = null;
        $styles = [];

        // Split runs at every §; the byte right after it is the effect code.
        $runs = preg_split('/(\x{00A7}.)/u', $motd, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($runs as $run) {
            $chunk = $run;

            if (mb_strlen($chunk) >= 2 && mb_substr($chunk, 0, 1) === '§') {
                $code = mb_substr($chunk, 1, 1);
                $chunk = mb_substr($chunk, 2);

                $color = self::MOTD_PALETTE[$code] ?? $color;

                if ($code === 'r') {
                    $color = null;
                    $styles = [];
                } else {
                    match ($code) {
                        'l' => $styles['font-weight'] = '700',
                        'o' => $styles['font-style'] = 'italic',
                        'n' => $styles['text-decoration'][] = 'underline',
                        'm' => $styles['text-decoration'][] = 'line-through',
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
