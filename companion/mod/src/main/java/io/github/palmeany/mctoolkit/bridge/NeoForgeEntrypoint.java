package io.github.palmeany.mctoolkit.bridge;

import net.neoforged.fml.common.Mod;

/** NeoForge 1.20.2+ (found through META-INF/neoforge.mods.toml, or mods.toml before 1.20.5). */
@Mod(Bridge.MOD_ID)
public final class NeoForgeEntrypoint {

    public NeoForgeEntrypoint() {
        try {
            if (Platform.isDedicatedServer("net.neoforged.fml.loading.FMLEnvironment")) {
                Bridge.start(Platform.neoforge());
            }
        } catch (Throwable t) {
            Log.warn("MC Toolkit Bridge could not start", t);
        }
    }
}
