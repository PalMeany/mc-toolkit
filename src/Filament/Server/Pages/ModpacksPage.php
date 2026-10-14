<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent;

/**
 * Modpacks — one-shot Modrinth `.mrpack` installer (loader + mods + configs).
 *
 * Icon: TablerIcon::Package ('tabler-package', verified app/Enums/TablerIcon.php:4324).
 *
 * Prerequisites (ContentInstallerService real contract):
 *  - The container MUST be fully stopped during a modpack install (Offline/Exited/
 *    Created). The page surfaces this up-front as an info note plus per-CTA hint text
 *    reusing pages.versions.install.stopped_note — the installer keeps its own hard
 *    gate, so the notice is guidance, not enforcement duplicated.
 *  - No loader-mismatch BLOCKER here by design: installing a modpack onto a vanilla
 *    box is the legitimate conversion flow. When the detection disagrees with what
 *    the user intends to do the install simply proceeds; failures come back through
 *    friendly DomainException texts and guide users toward Versions in words
 *    (loader-mismatch redirect notice rides the shared callout below when the box is
 *    bukkit-family, quoting the modpacks subtitle tail semantics).
 */
final class ModpacksPage extends Page
{
    use InteractsWithContent;

    /** Verified case: app/Enums/TablerIcon.php:4324 = 'tabler-package'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Package;

    protected static ?string $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 23;

    protected string $view = 'minecraft-toolkit::server.modpacks';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.modpacks', true)
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.content', Filament::getTenant()), 403);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(TablerIcon::Refresh)
                ->action(fn () => $this->refresh()),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Concern identity                                                   */
    /* ------------------------------------------------------------------ */

    public function contentKind(): string
    {
        return 'modpacks';
    }

    protected function installerKind(): string
    {
        return 'modpack';
    }

    protected function langSection(): string
    {
        return 'modpacks';
    }

    /**
     * Bukkit-family boxes CAN technically take a pack, but demo parity pushes the
     * "change software first" hint with the modpacks-subtitle tail pointing at Versions.
     */
    public function blockedNotice(): ?array
    {
        if ((string) ($this->contentDetection()['loader'] ?? '') !== 'bukkit') {
            return null;
        }

        // Lang gap (documented): no dedicated modpacks.blocker key exists in the frozen
        // tree; reuse the mods blocker copy which already steers to the Versions page.
        return [
            'title' => null,
            'message' => trans('minecraft-toolkit::pages.mods.blocker.bukkit_message'),
            'versions_url' => $this->versionsUrl(),
        ];
    }
}
