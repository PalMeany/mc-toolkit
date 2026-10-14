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
            'MC_TOOLKIT_HANGAR_KEY' => (string) (env('MC_TOOLKIT_HANGAR_KEY') ?? env('HANGAR_KEY', '')),
            'MC_TOOLKIT_ENABLED_PAGES' => $this->enabledPages(),
        ];
    }

    /** @return Component[] */
    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('minecraft-toolkit::permissions.minecraft-toolkit_title'))
                ->description('Optional catalog keys. Sources without a key are skipped gracefully.')
                ->columns(2)
                ->schema([
                    TextInput::make('MC_TOOLKIT_CURSEFORGE_KEY')
                        ->label('CurseForge API key')
                        ->password()
                        ->revealable()
                        ->columnSpan(1)
                        ->default((string) (env('MC_TOOLKIT_CURSEFORGE_KEY') ?? env('CURSEFORGE_KEY', '')))
                        ->helperText('Unlocks the CurseForge source on the Plugins and Mods pages.'),

                    TextInput::make('MC_TOOLKIT_HANGAR_KEY')
                        ->label('Hangar API token')
                        ->password()
                        ->revealable()
                        ->columnSpan(1)
                        ->default((string) (env('MC_TOOLKIT_HANGAR_KEY') ?? env('HANGAR_KEY', '')))
                        ->helperText('Personal access token for hangar.papermc.io.'),
                ]),

            Section::make('Enabled pages')
                ->description('Pick which toolkit pages show up in the server sidebar.')
                ->schema([
                    CheckboxList::make('MC_TOOLKIT_ENABLED_PAGES')
                        ->hiddenLabel()
                        ->bulkToggleable()
                        ->columns(2)
                        ->options(array_map(
                            fn (string $slug): string => trans("minecraft-toolkit::pages.{$slug}.title"),
                            self::PAGES,
                        ))
                        ->descriptions(array_map(
                            fn (string $slug): string => trans("minecraft-toolkit::pages.{$slug}.subheading"),
                            self::PAGES,
                        )),
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

        $hangarKey = trim(Arr::get($data, 'MC_TOOLKIT_HANGAR_KEY', '') ?? '');
        if ($hangarKey !== '') {
            $values['MC_TOOLKIT_HANGAR_KEY'] = $hangarKey;
        }

        // Always write the explicit enabled-list so config('...pages.enabled') has a
        // single unambiguous source once this form has been used at least once.
        $selected = Arr::wrap(Arr::get($data, 'MC_TOOLKIT_ENABLED_PAGES', []));
        $ordered = array_values(array_intersect(self::PAGES, $selected));

        $values['MC_TOOLKIT_ENABLED_PAGES'] = implode(',', $ordered);

        $this->writeToEnvironment($values);
    }

    /** @return string[] slugs currently enabled per MC_TOOLKIT_ENABLED_PAGES (blank/unset/all = every slug) */
    private function enabledPages(): array
    {
        $raw = trim((string) env('MC_TOOLKIT_ENABLED_PAGES', ''));

        if ($raw === '' || str_contains($raw, 'all')) {
            return self::PAGES;
        }

        $listed = array_map(
            fn (string $slug): string => mb_strtolower(trim($slug)),
            explode(',', $raw),
        );

        return array_values(array_intersect(self::PAGES, $listed));
    }
}
