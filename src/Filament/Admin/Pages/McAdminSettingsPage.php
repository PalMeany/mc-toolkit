<?php

declare(strict_types=1);

namespace McToolkit\Filament\Admin\Pages;

use App\Traits\EnvironmentWriterTrait;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\StateCasts\BooleanStateCast;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use McToolkit\McToolkitPlugin;
use McToolkit\Models\InstalledContent;

/**
 * @property \Filament\Schemas\Schema $form
 *
 * Admin panel page "Minecraft Toolkit" — global plugin configuration.
 *
 * Functional parity with the original addon's admin area, rebuilt on Pelican's
 * design system (core admin Settings.php patterns):
 *   - catalog API keys persisted to .env (diff-only writes; blank key leaves the
 *     existing entry untouched — same contract as HasPluginSettings::saveSettings),
 *   - one Toggle per toolkit page slug composing MC_TOOLKIT_ENABLED_PAGES,
 *   - three compact chips with global tracked-content counts grouped by kind,
 *   - info callout (what each catalog serves) + static requirements list.
 *
 * Persistence goes through App\Traits\EnvironmentWriterTrait::writeToEnvironment()
 * (verified v1.0.0-rc.1: app/Traits/EnvironmentWriterTrait.php — per-key line
 * surgery via Illuminate\Support\Env::writeVariables($values, envPath, overwrite=true);
 * only keys passed in $values are touched, unrelated .env entries are never clobbered).
 */
final class McAdminSettingsPage extends Page
{
    use EnvironmentWriterTrait;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::Adjustments;

    protected static ?string $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 95;

    protected string $view = 'minecraft-toolkit::admin.settings';

    /** Form state container (statePath 'data' mirrors core Settings.php conventions). */
    public ?array $data = [];

    /**
     * Canonical page slugs in navigation order — same source of truth as
     * config/minecraft-toolkit.php and HasPluginSettings (A1-owned).
     *
     * @return string[]
     */
    private function pageSlugs(): array
    {
        return McToolkitPlugin::PAGES;
    }

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->form->fill();
    }

    // ---------------------------------------------------------------------
    // Access — grounded on the mcToolSetting role surface registered by
    // McToolkitServiceProvider (Role::registerCustomDefaultPermissions ->
    // "view|update mcToolSetting"); root admins bypass via Gate::before.
    // ---------------------------------------------------------------------

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && user()?->can('view mcToolSetting');
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('view mcToolSetting'), 403);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::permissions.minecraft-toolkit_title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::permissions.minecraft-toolkit_title');
    }

    /**
     * Lang-gap note: pages.admin.* does not exist yet in lang/en/pages.php
     * (A1-owned file) — subheading is a hardcoded literal, mirroring how
     * McToolkitPlugin::getSettingsForm() already uses inline English copy.
     */
    public function getSubheading(): string
    {
        return 'Global settings: catalog API keys and which toolkit pages appear in the server sidebar.';
    }

    // ---------------------------------------------------------------------
    // Form schema
    // ---------------------------------------------------------------------

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(trans('minecraft-toolkit::permissions.minecraft-toolkit_title'))
                    ->description('Optional catalog credentials. Sources without a key are skipped gracefully.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('MC_TOOLKIT_CURSEFORGE_KEY')
                            ->label('CurseForge API key')
                            ->password()
                            ->revealable()
                            ->helperText('Unlocks the CurseForge source on the Plugins and Mods pages.'),
                        TextInput::make('MC_TOOLKIT_HANGAR_KEY')
                            ->label('Hangar API token')
                            ->password()
                            ->revealable()
                            ->helperText('Personal access token for hangar.papermc.io.'),
                    ]),

                Section::make('Enabled pages')
                    ->description('Pick which toolkit pages show up in every server sidebar under Minecraft.')
                    ->columns(3)
                    ->schema($this->pageToggles()),
            ]);
    }

    /**
     * One Toggle per toolkit page slug. Defaults come from the resolved
     * config('minecraft-toolkit.pages.enabled') map so what you see is exactly
     * what the panel currently does.
     *
     * @return array<Toggle>
     */
    private function pageToggles(): array
    {
        $enabled = $this->enabledPagesMap();

        $toggles = [];
        foreach ($this->pageSlugs() as $slug) {
            $toggles[] = Toggle::make("pages.{$slug}")
                ->label(trans("minecraft-toolkit::pages.{$slug}.title"))
                ->helperText(trans("minecraft-toolkit::pages.{$slug}.subheading"))
                ->inline(false)
                ->onIcon(\App\Enums\TablerIcon::Check)
                ->offIcon(\App\Enums\TablerIcon::X)
                ->onColor('success')
                ->offColor('danger')
                ->stateCast(new BooleanStateCast(false))
                ->default((bool) ($enabled[$slug] ?? true));
        }

        return $toggles;
    }

    /**
     * Resolved enabled-page map using the plugin's own semantics:
     * blank / missing / literal "all" = every page enabled.
     *
     * Mirrors McToolkitPlugin::enabledPages() + config/minecraft-toolkit.php
     * (exact comma-token split; stray tokens from other tooling are ignored).
     *
     * @return array<string, bool>
     */
    private function enabledPagesMap(): array
    {
        $raw = mb_strtolower(trim((string) env('MC_TOOLKIT_ENABLED_PAGES', '')));
        $all = [];

        if ($raw === '' || str_contains($raw, 'all')) {
            foreach ($this->pageSlugs() as $slug) {
                $all[$slug] = true;
            }

            return $all;
        }

        $listed = array_map(
            fn (string $token): string => mb_strtolower(trim($token)),
            explode(',', $raw),
        );

        foreach ($this->pageSlugs() as $slug) {
            $all[$slug] = in_array($slug, $listed, true);
        }

        return $all;
    }

    // ---------------------------------------------------------------------
    // Header actions
    // ---------------------------------------------------------------------

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('minecraft-toolkit::common.buttons.save'))
                ->color('primary')
                ->icon(\App\Enums\TablerIcon::DeviceFloppy)
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }

    // ---------------------------------------------------------------------
    // Save — diff-only .env writes through EnvironmentWriterTrait
    // ---------------------------------------------------------------------

    /**
     * Persists changed values into .env via EnvironmentWriterTrait::writeToEnvironment().
     *
     * Verified writer behavior (app/Traits/EnvironmentWriterTrait.php →
     * Illuminate\Support\Env::writeVariables(v5.13-laravel 13.35)): per-key line
     * surgery only — existing lines for OTHER keys are untouched; our keys replace
     * their own KEY=... line or insert after the last same-prefix line; empty string
     * writes a bare `KEY=` line. Only changed values are submitted (diff-only).
     */
    public function save(): void
    {
        abort_unless(user()?->can('update mcToolSetting'), 403);

        try {
            $data = $this->form->getState();

            $values = $this->diffedEnvValues($data);

            if ($values !== []) {
                $this->writeToEnvironment($values);
            }

            $this->redirect($this->getUrl());

            Notification::make()
                ->title('Settings saved')
                ->body($this->savedSummary($values))
                ->success()
                ->send();
        } catch (Exception $exception) {
            Notification::make()
                ->title('Saving failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Builds the diff-only set of env entries that actually changed.
     *
     * Key inputs: an empty field NEVER wipes an existing .env value — clearing a
     * key is done by editing .env directly (same contract as
     * McToolkitPlugin::saveSettings(), A1).
     *
     * Page toggles: composed into MC_TOOLKIT_ENABLED_PAGES (comma-separated
     * enabled slugs, canonical order). Blank also means "all nine enabled"
     * (README §Settings), so when everything is checked the written value is an
     * explicit full list — unambiguous either way.
     *
     * @param  array<mixed>  $data
     * @return array<string, string>
     */
    private function diffedEnvValues(array $data): array
    {
        $values = [];

        // ---- Catalog keys ----------------------------------------------------
        $keyFields = [
            'MC_TOOLKIT_CURSEFORGE_KEY' => 'CURSEFORGE_KEY',
            'MC_TOOLKIT_HANGAR_KEY' => 'HANGAR_KEY',
        ];

        foreach ($keyFields as $field => $bare) {
            $submitted = trim((string) ($data[$field] ?? ''));

            if ($submitted === '') {
                continue; // blank input never wipes an existing entry (A1 contract)
            }

            $current = trim((string) (env($field) ?? env($bare, '')));

            if ($submitted !== $current) {
                $values[$field] = $submitted;
            }
        }

        // ---- Enabled pages ---------------------------------------------------
        $checked = [];
        foreach ($this->pageSlugs() as $slug) {
            if (!empty($data['pages'][$slug])) {
                $checked[] = $slug;
            }
        }
        $desired = implode(',', $checked);
        $raw = trim((string) env('MC_TOOLKIT_ENABLED_PAGES', ''));

        if (!$this->sameEnabledSet($desired, $raw)) {
            $values['MC_TOOLKIT_ENABLED_PAGES'] = $desired;
        }

        return $values;
    }

    /**
     * Compare desired vs current enabled-slug sets by exact tokens (blank /
     * literal "all" on the stored side count as "every page").
     */
    private function sameEnabledSet(string $desired, string $raw): bool
    {
        $tokens = static function (string $s): array {
            $parts = array_map(
                fn (string $t): string => mb_strtolower(trim($t)),
                explode(',', $s),
            );

            return array_values(array_unique(array_filter($parts, fn (string $t): bool => $t !== '')));
        };

        $want = $tokens($desired);

        if ($raw === '' || str_contains(mb_strtolower($raw), 'all')) {
            return count($want) === count($this->pageSlugs());
        }

        $have = array_values(array_intersect($tokens($raw), $this->pageSlugs()));

        return $want === $have;
    }

    /** Short success summary listing which env entries were rewritten. */
    private function savedSummary(array $values): string
    {
        if ($values === []) {
            return 'No changes detected — nothing was written.';
        }

        return 'Updated: ' . implode(', ', array_keys($values));
    }

    // ---------------------------------------------------------------------
    // Global content counts — three small chips, no heavy queries
    // ---------------------------------------------------------------------

    /**
     * Global tracked-content totals grouped by kind (plugin | mod | modpack),
     * one indexed GROUP BY over mc_installed_content. Guarded best-effort:
     * before migrations run (or DB hiccup) all counts stay at 0.
     *
     * @return array<string, int>
     */
    private function kindCounts(): array
    {
        $counts = array_fill_keys(InstalledContent::KINDS, 0);

        try {
            if (DatabaseSchema::hasTable('mc_installed_content')) {
                $rows = InstalledContent::query()
                    ->selectRaw('kind, COUNT(*) as cnt')
                    ->groupBy('kind')
                    ->get();

                foreach ($rows as $row) {
                    if (in_array($row->kind, InstalledContent::KINDS, true)) {
                        $counts[$row->kind] = (int) $row->cnt;
                    }
                }
            }
        } catch (\Throwable) {
            // Table not migrated yet (plugin just imported) — keep zeros.
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'kindCounts' => $this->kindCounts(),
        ];
    }
}
