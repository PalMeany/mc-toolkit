{{--
    Minecraft Toolkit — Mods page (server panel).

    Same shared body as Plugins; the bukkit-family blocker callout and disabled
    install CTAs come from ModsPage::blockedNotice().
--}}
<x-filament-panels::page>
    @include('minecraft-toolkit::server._content', ['kind' => 'mods'])
</x-filament-panels::page>
