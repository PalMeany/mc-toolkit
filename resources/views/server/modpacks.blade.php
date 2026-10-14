{{--
    Minecraft Toolkit — Modpacks page (server panel).

    Same shared body; adds the stopped-container prerequisite note for `.mrpack`
    installs when the container is running (ContentInstallerService requirement).
--}}
<x-filament-panels::page>
    @include('minecraft-toolkit::server._content', ['kind' => 'modpacks'])
</x-filament-panels::page>
