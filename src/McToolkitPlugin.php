<?php

declare(strict_types=1);

namespace McToolkit;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Arr;

/**
 * Main plugin class discovered via the manifest keys `namespace` + `class`
 * (App\Models\Plugin::fullClass() -> "\McToolkit\McToolkitPlugin").
 *
 * The model instantiates it with zero constructor arguments
 * (PluginService::loadPanelPlugins() does `new $pluginClass()`), so everything
 * here must be resolvable without request context — settings read straight from env/config.
 */
final class McToolkitPlugin implements Plugin, HasPluginSettings
{
    use EnvironmentWriterTrait;

    /**
     * Canonical page slugs, in navigation order. Mirrors the frozen v1 page list in
     * the build contracts and drives both the "enabled pages" checkbox and the
     * pages.enabled map inside config/minecraft-toolkit.php.
     */
    public const PAGES = [
        'versions',
        'plugins',
        'mods',
        'modpacks',
        'properties',
        'players',
        'worlds',
        'map',
        'performance',
    ];

    /** Prebuilt stylesheet (scripts/build-css.sh) holding every utility class the views use. */
    private const STYLESHEET = 'resources/dist/toolkit.css';

    /**
     * Pages that render this plugin's views — the only ones that get the stylesheet.
     *
     * @var list<class-string>
     */
    private const STYLED_PAGES = [
        \McToolkit\Filament\Server\Pages\VersionsPage::class,
        \McToolkit\Filament\Server\Pages\PluginsPage::class,
        \McToolkit\Filament\Server\Pages\ModsPage::class,
        \McToolkit\Filament\Server\Pages\ModpacksPage::class,
        \McToolkit\Filament\Server\Pages\PropertiesPage::class,
        \McToolkit\Filament\Server\Pages\PlayersPage::class,
        \McToolkit\Filament\Server\Pages\WorldsPage::class,
        \McToolkit\Filament\Server\Pages\MapPage::class,
        \McToolkit\Filament\Server\Pages\PerformancePage::class,
        \McToolkit\Filament\Admin\Pages\McAdminSettingsPage::class,
    ];

    public function getId(): string
    {
        return 'minecraft-toolkit';
    }

    public function register(Panel $panel): void
    {
        // Admin | Server | App — same discovery shape as the first-party Announcements plugin.
        // Guarded with is_dir() so a side without discovered classes simply skips silently.
        $id = str($panel->getId())->title();

        $pagesDirectory = plugin_path($this->getId(), "src/Filament/{$id}/Pages");

        if (is_dir($pagesDirectory)) {
            $panel->discoverPages($pagesDirectory, "McToolkit\\Filament\\{$id}\\Pages");
        }

        $panel->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => self::stylesheetTag(), scopes: self::STYLED_PAGES);
    }

    /**
     * Inline <style> with the plugin's prebuilt utility classes.
     *
     * The panel's own stylesheet only holds the Tailwind classes found in the panel's sources
     * (Tailwind skips git-ignored paths and plugins/ is ignored), so the toolkit views need
     * their own build. It ships prebuilt instead of going through the panel's Vite: the panel
     * runs `yarn build` on plugin install, but most production panels have no Node/yarn, the
     * build then fails silently and the pages lose every grid, gap, colour and badge style.
     * Inlined (about 40 kB, toolkit pages only) it needs no route, manifest or asset publish.
     */
    private static function stylesheetTag(): string
    {
        static $css = null;

        if ($css === null) {
            $path = plugin_path('minecraft-toolkit', self::STYLESHEET);
            $css = is_file($path) ? (string) file_get_contents($path) : '';
            // A stylesheet can never legitimately contain this; keep the element closed.
            $css = str_ireplace('</style', '<\\/style', $css);
        }

        return $css === '' ? '' : '<style id="mc-toolkit-styles">'.$css.'</style>';
    }

    public function boot(Panel $panel): void
    {
        // Nothing yet: panel-specific wiring lives in McToolkitServiceProvider::register()
        // so it lands before any request code runs.
    }

    /* ------------------------------------------------------------------ */
    /*  HasPluginSettings — powers Admin → Plugins → Settings slide-over  */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return [
            'MC_TOOLKIT_CURSEFORGE_KEY' => (string) (env('MC_TOOLKIT_CURSEFORGE_KEY') ?? env('CURSEFORGE_KEY', '')),
            'MC_TOOLKIT_ENABLED_PAGES' => $this->enabledPages(),
        ];
    }

    /** @return Component[] */
    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('minecraft-toolkit::permissions.minecraft-toolkit_title'))
                ->description('Optional catalog keys. Sources without a key are skipped gracefully; Hangar needs none.')
                ->schema([
                    TextInput::make('MC_TOOLKIT_CURSEFORGE_KEY')
                        ->label('CurseForge API key')
                        ->password()
                        ->revealable()
                        // One visible-ASCII token: keeps line breaks out of the .env writer.
                        ->regex('/^[\x21-\x7E]+$/D')
                        ->default((string) (env('MC_TOOLKIT_CURSEFORGE_KEY') ?? env('CURSEFORGE_KEY', '')))
                        ->helperText('Unlocks the CurseForge source on the Plugins and Mods pages.'),
                ]),

            Section::make('Enabled pages')
                ->description('Pick which toolkit pages show up in the server sidebar.')
                ->schema([
                    CheckboxList::make('MC_TOOLKIT_ENABLED_PAGES')
                        // Options MUST be keyed by slug ("versions" => "Versions").
                        // A list-shaped options array makes Filament render checkbox
                        // values as 0..n indexes while the form is filled with slugs
                        // from getSettingsFormData() — the two shapes then fail the
                        // built-in Rule::in(...) validation on every save attempt
                        // ("The selected mc_toolkit_enabled_pages is invalid.").
                        ->hiddenLabel()
                        ->bulkToggleable()
                        ->columns(2)
                        ->options(collect(self::PAGES)->mapWithKeys(
                            fn (string $slug): array => [$slug => trans("minecraft-toolkit::pages.{$slug}.title")],
                        )->all())
                        ->descriptions(collect(self::PAGES)->mapWithKeys(
                            fn (string $slug): array => [$slug => trans("minecraft-toolkit::pages.{$slug}.subheading")],
                        )->all()),
                ]),
        ];
    }

    /**
     * Persists into .env through the same writer the core Settings page uses.
     *
     * Blank key fields leave existing entries untouched on purpose — an admin that
     * wants to remove a key edits .env directly.
     *
     * @param  array<mixed, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        $values = [];

        $curseforgeKey = trim(Arr::get($data, 'MC_TOOLKIT_CURSEFORGE_KEY', '') ?? '');
        if ($curseforgeKey !== '') {
            $values['MC_TOOLKIT_CURSEFORGE_KEY'] = $curseforgeKey;
        }

        // Always write the explicit enabled-list so config('...pages.enabled') has a
        // single unambiguous source once this form has been used at least once.
        $selected = Arr::wrap(Arr::get($data, 'MC_TOOLKIT_ENABLED_PAGES', []));

        // Defense-in-depth: tolerate option indexes (0..n) from any earlier build's
        // list-shaped options by mapping them back through the canonical page order.
        $selected = array_map(
            function (string | int $value): ?string {
                $value = mb_strtolower(trim((string) $value));

                return ctype_digit($value)
                    ? self::PAGES[(int) $value] ?? null // index submission from older UIs
                    : $value;
            },
            $selected,
        );

        $ordered = array_values(array_intersect(self::PAGES, $selected));

        // A blank value means "every page" to the config reader, so an empty selection
        // needs its own explicit word or unticking everything would switch all pages on.
        $values['MC_TOOLKIT_ENABLED_PAGES'] = $ordered === [] ? 'none' : implode(',', $ordered);

        $this->writeToEnvironment($values);
    }

    /** @return string[] slugs currently enabled per MC_TOOLKIT_ENABLED_PAGES (blank/unset/all = every slug, none = no slug) */
    private function enabledPages(): array
    {
        $raw = trim((string) env('MC_TOOLKIT_ENABLED_PAGES', ''));

        $listed = array_map(
            fn (string $slug): string => mb_strtolower(trim($slug)),
            explode(',', $raw),
        );

        if ($raw === '' || in_array('all', $listed, true)) {
            return self::PAGES;
        }

        return array_values(array_intersect(self::PAGES, $listed));
    }
}
