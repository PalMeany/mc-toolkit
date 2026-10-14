<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent;

/**
 * Plugins — catalog from Modrinth / Hangar / SpigotMC (+ CurseForge with a key),
 * tracked installs and the foreign-jars awareness section (demo parity v1.1.8).
 *
 * Icon: TablerIcon::Puzzle ('tabler-puzzle', verified app/Enums/TablerIcon.php:4665).
 *
 * No-toolkit parity callout: pure vanilla / undetected servers get the translated
 * "This server does not load plugins" note, but INSTALL stays available — placing
 * plugins BEFORE swapping software on the Versions page is a real workflow
 * (decision documented in the wave handoff notes). Proxies (Velocity / Waterfall /
 * BungeeCord) DO load plugins and get no callout.
 */
final class PluginsPage extends Page
{
    use InteractsWithContent;

    /** Verified case: app/Enums/TablerIcon.php:4665 = 'tabler-puzzle'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Puzzle;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 21;

    protected string $view = 'minecraft-toolkit::server.plugins';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/plugins (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/plugins';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    /**
     * 'use' + 'content' on a server outside any conflict state. Filament re-runs this on
     * every Livewire request of the page (CanAuthorizeAccess::hydrateCanAuthorizeAccess).
     */
    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.plugins', true)
            && static::canAccessContentCatalog();
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.plugins.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.plugins.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.plugins.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(TablerIcon::Refresh)
                ->action(fn () => $this->refreshContent()),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Concern identity                                                   */
    /* ------------------------------------------------------------------ */

    public function contentKind(): string
    {
        return 'plugins';
    }

    protected function installerKind(): string
    {
        return 'plugin';
    }

    protected function langSection(): string
    {
        return 'plugins';
    }

    public function blockedNotice(): ?array
    {
        $detected = $this->contentDetection();
        $software = strtolower((string) ($detected['software'] ?? 'unknown'));
        $loader = strtolower((string) ($detected['loader'] ?? 'vanilla'));

        if ($software === 'unknown' || $loader === 'vanilla') {
            return [
                'title' => trans('minecraft-toolkit::pages.plugins.no_toolkit.title'),
                'message' => trans('minecraft-toolkit::pages.plugins.no_toolkit.desc'),
                'versions_url' => $this->versionsUrl(),
            ];
        }

        return null;
    }
}
