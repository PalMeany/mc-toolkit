<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent;
use Throwable;

/**
 * Mods — Modrinth + CurseForge catalog "with their dependencies" (demo parity),
 * blocked-state UX pointing at Versions on Bukkit-family servers.
 *
 * Icon: TablerIcon::PuzzleFilled ('tabler-puzzle-filled', verified
 * app/Enums/TablerIcon.php:4663) — deliberately distinct from PluginsPage's Puzzle.
 *
 * Blocker rule (demo blocker.bukkit_message parity): the callout renders when the
 * DETECTED loader is bukkit-family — proxy-only (velocity/waterfall/bungee) boxes
 * keep browsing but show no callout because their jar semantics differ from either
 * plugins or mods (documented deviation, honest copy concerns).
 */
final class ModsPage extends Page
{
    use InteractsWithContent;

    /** Verified case: app/Enums/TablerIcon.php:4663 = 'tabler-puzzle-filled'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::PuzzleFilled;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 22;

    protected string $view = 'minecraft-toolkit::server.mods';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.mods', true)
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.content', Filament::getTenant()), 403);
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
                ->action(fn () => $this->safeRefresh()),
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

    /* ------------------------------------------------------------------ */
    /*  Error-shielded refresh                                             */
    /* ------------------------------------------------------------------ */

    private function safeRefresh(): void
    {
        try {
            $this->refresh();
        } catch (Throwable) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();
        }
    }
}
