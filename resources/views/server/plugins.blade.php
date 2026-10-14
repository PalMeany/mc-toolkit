{{--
    Minecraft Toolkit — Plugins page (server panel).

    Page-agent contract: root MUST be <x-filament-panels::page>; the whole body lives
    in the shared content partial so Plugins / Mods / Modpacks stay identical.
--}}
<x-filament-panels::page>
    @include('minecraft-toolkit::server._content', ['kind' => 'plugins'])
</x-filament-panels::page>
