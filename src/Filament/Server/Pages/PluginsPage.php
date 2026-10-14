<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent;
use Throwable;

/**
 * Plugins — catalog from Modrinth / Hangar / SpigotMC (+ CurseForge with a key),
 * tracked installs and the foreign-jars awareness section (demo parity v1.1.8).
 *
 * Icon: TablerIcon::Puzzle ('tabler-puzzle', verified app/Enums/TablerIcon.php:4665).
 *
 * No-toolkit parity callout: pure vanilla / undetected servers get the translated
 * "This server does not load plugins" note, but INSTALL stays available — placing
 * plugins BEFORE swapping software on the Versions page is a real workflow
 * (decision documented in the wave handoff notes).
 */
final class PluginsPage extends Page
{
    use InteractsWithContent;

    /** Verified case: app/Enums/TablerIcon.php:4665 = 'tabler-puzzle'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Puzzle;

    protected static ?string $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 21;

    protected string $view = 'minecraft-toolkit::server.plugins';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.plugins', true)
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.content', Filament::getTenant()), 403);
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
                ->action(fn () => $this->safeRefresh()),
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

        if ($software === 'unknown' || in_array($loader, ['vanilla', 'velocity'], true)) {
            return [
                'title' => trans('minecraft-toolkit::pages.plugins.no_toolkit.title'),
                'message' => trans('minecraft-toolkit::pages.plugins.no_toolkit.desc'),
                'versions_url' => $this->versionsUrl(),
            ];
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Error-shielded refresh (header action wrapper)                     */
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
