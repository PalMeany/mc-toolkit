package io.github.palmeany.mctoolkit.bridge;

import net.fabricmc.api.DedicatedServerModInitializer;

/**
 * Fabric and Quilt: the {@code server} entrypoint in fabric.mod.json, which the loader only calls on
 * a dedicated server (a client never runs it).
 */
public final class FabricEntrypoint implements DedicatedServerModInitializer {

    @Override
    public void onInitializeServer() {
        try {
            Bridge.start(Platform.fabric());
        } catch (Throwable t) {
            Log.warn("MC Toolkit Bridge could not start", t);
        }
    }
}
