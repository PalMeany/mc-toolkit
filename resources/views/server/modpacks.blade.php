{{--
    Minecraft Toolkit — Modpacks page (server panel).

    Same shared body; adds the stopped-container prerequisite note for `.mrpack`
    installs when the container is running (ContentInstallerService requirement),
    the background task card and the install confirmation sheet (installs are queued).
--}}
<x-filament-panels::page>
    @include('minecraft-toolkit::server._content', ['kind' => 'modpacks'])

    @include('minecraft-toolkit::server._pack-confirm')
</x-filament-panels::page>
