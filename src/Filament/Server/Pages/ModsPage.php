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
 * Mods — Modrinth + CurseForge catalog "with their dependencies" (demo parity),
 * blocked-state UX pointing at Versions on Bukkit-family servers.
 *
 * Icon: TablerIcon::PuzzleFilled ('tabler-puzzle-filled', verified
 * app/Enums/TablerIcon.php:4663) — deliberately distinct from PluginsPage's Puzzle.
 *
 * Blocker rule (demo blocker.bukkit_message parity): the callout renders when the
 * DETECTED loader is bukkit-family, and installs are refused (UI and server-side) —
 * proxy-only (velocity/waterfall/bungee) boxes keep browsing but show no callout
 * because their jar semantics differ from either plugins or mods.
 */
final class ModsPage extends Page
{
    use InteractsWithContent;

    /** Verified case: app/Enums/TablerIcon.php:4663 = 'tabler-puzzle-filled'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::PuzzleFilled;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 22;

    protected string $view = 'minecraft-toolkit::server.mods';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/mods (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/mods';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    /**
     * 'use' + 'content' on a server outside any conflict state. Filament re-runs this on
     * every Livewire request of the page (CanAuthorizeAccess::hydrateCanAuthorizeAccess).
     */
    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.mods', true)
            && static::canAccessContentCatalog();
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.mods.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.mods.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.mods.subheading');
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
        return 'mods';
    }

    protected function installerKind(): string
    {
        return 'mod';
    }

    protected function langSection(): string
    {
        return 'mods';
    }

    /**
     * Bukkit-family servers cannot load mods at all — full blocker callout linking
     * to the Versions page, installs disabled per demo parity.
     */
    public function blockedNotice(): ?array
    {
        $detected = $this->contentDetection();

        if ((string) ($detected['loader'] ?? '') !== 'bukkit') {
            return null;
        }

        return [
            'title' => null,
            'message' => trans('minecraft-toolkit::pages.mods.blocker.bukkit_message'),
            'versions_url' => $this->versionsUrl(),
        ];
    }
}
