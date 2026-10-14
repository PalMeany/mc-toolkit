<?php

declare(strict_types=1);

namespace McToolkit\Providers;

use App\Models\Role;
use App\Models\Subuser;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the static permission/model surfaces only.
 *
 * Runs during the panel's register phase (plugin providers are registered inside
 * AppServiceProvider::register() flow), so Subuser/Role traits receive our keys
 * before any Filament component is built. Nothing else belongs here in v1 —
 * no console widget hooks, no render hooks, no view registration.
 */
class McToolkitServiceProvider extends ServiceProvider
{
    /** Fixed per-server subuser abilities, in contract order. */
    private const PERMISSIONS = [
        'use',
        'manage',
        'administer',
        'players',
        'content',
        'versions',
        'worlds',
        'properties',
        'map',
    ];

    public function register(): void
    {
        // ---- Per-server subuser permissions ------------------------------------
        // Signature verified against app/Models/Subuser.php:52:
        // registerCustomPermissions(string $name, array $permissions,
        //     ?string $translationPrefix = null, null|string|BackedEnum $icon = null, ?bool $hidden = null)
        if (method_exists(Subuser::class, 'registerCustomPermissions')) {
            Subuser::registerCustomPermissions(
                'minecraft-toolkit',
                self::PERMISSIONS,
                'minecraft-toolkit::permissions', // resolves trans('<prefix>.{group}_{perm}') labels
                \App\Enums\TablerIcon::BrandMinecraft, // TablerIcon.php:991 — verified case
                false,
            );
        }

        // ---- Admin role surface (Roles UI) --------------------------------------
        // Verified against app/Models/Role.php:91/99/115:
        //   registerCustomModelIcon(string $model, string|BackedEnum $icon)
        //   registerCustomDefaultPermissions(string $model) -> adds viewList/view/create/update/delete
        if (method_exists(Role::class, 'registerCustomModelIcon') && method_exists(Role::class, 'registerCustomDefaultPermissions')) {
            Role::registerCustomModelIcon('mcToolSetting', 'tabler-brand-minecraft');
            Role::registerCustomDefaultPermissions('mcToolSetting');
        }
    }

    public function boot(): void
    {
        // ActivityLog::getLabel() resolves trans_choice('activity.mc-toolkit.*')
        // against the DEFAULT translation group, but Pelican's PluginService only
        // registers the plugin lang directory under the 'minecraft-toolkit'
        // namespace. Appending the same path to the default group makes
        // lang/en/activity.php's ['mc-toolkit' => ...] tree resolve without touching
        // existing minecraft-toolkit::* namespace usage (FileLoader merges appends).
        // callAfterResolving() also covers the usual case where another provider's boot()
        // already resolved the translator: a plain afterResolving() hook never fires for
        // an instance that exists before it is registered.
        $this->callAfterResolving('translator', function ($translator): void {
            if (method_exists($translator, 'addPath')) {
                $translator->addPath(plugin_path('minecraft-toolkit', 'lang'));
            }
        });
    }
}
