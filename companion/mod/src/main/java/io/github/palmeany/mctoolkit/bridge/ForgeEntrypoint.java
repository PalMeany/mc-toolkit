package io.github.palmeany.mctoolkit.bridge;

import net.minecraftforge.fml.common.Mod;

/** Forge (and NeoForge for 1.20.1, which kept Forge's packages), found through META-INF/mods.toml. */
@Mod(Bridge.MOD_ID)
public final class ForgeEntrypoint {

    public ForgeEntrypoint() {
        try {
            if (Platform.isDedicatedServer("net.minecraftforge.fml.loading.FMLEnvironment")) {
                Bridge.start(Platform.forge());
            }
        } catch (Throwable t) {
            Log.warn("MC Toolkit Bridge could not start", t);
        }
    }
}
