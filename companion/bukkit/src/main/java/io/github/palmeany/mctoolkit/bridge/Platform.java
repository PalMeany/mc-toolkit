package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import org.bukkit.Server;

/** What the bridge is running on, and which optional server APIs it can use there. */
final class Platform {

    private static final Pattern VERSION = Pattern.compile("^\\d+(\\.\\d+)+$");
    private static final Pattern MC_IN_VERSION = Pattern.compile("\\(MC: ([0-9][0-9.]*[0-9])\\)");

    /** spigot | paper | purpur | folia | forge | neoforge | fabric | quilt | unknown. */
    final String id;
    final String software;
    final String minecraft;
    final boolean folia;
    /** Paper's {@code Server#getTPS()} (1m/5m/15m), null on Spigot/CraftBukkit. */
    final Method paperTps;
    /** Paper's {@code Server#getAverageTickTime()} in milliseconds, null when absent. */
    final Method paperMspt;

    Platform(String id, String software, String minecraft, boolean folia, Method paperTps, Method paperMspt) {
        this.id = id;
        this.software = software;
        this.minecraft = minecraft;
        this.folia = folia;
        this.paperTps = paperTps;
        this.paperMspt = paperMspt;
    }

    static Platform detect(Server server) {
        boolean folia = Reflect.serverType("io.papermc.paper.threadedregions.RegionizedServer") != null;
        String software = safe(server.getName()) + " version " + safe(server.getVersion());
        return new Platform(family(folia), software, minecraftVersion(server), folia,
                Reflect.method(Server.class, "getTPS"), Reflect.method(Server.class, "getAverageTickTime"));
    }

    /**
     * The platform family. Hybrids report the mod loader they run on (Mohist → forge, Arclight NeoForge → neoforge,
     * Arclight Fabric / Banner → fabric); Paper forks without their own entry (Pufferfish, Leaves, ...) report paper.
     */
    private static String family(boolean folia) {
        if (any("net.neoforged.neoforge.common.NeoForge")) {
            return "neoforge";
        }
        if (any("net.minecraftforge.common.MinecraftForge")) {
            return "forge";
        }
        if (any("org.quiltmc.loader.api.QuiltLoader")) {
            return "quilt";
        }
        if (any("net.fabricmc.loader.api.FabricLoader")) {
            return "fabric";
        }
        if (folia) {
            return "folia";
        }
        if (any("org.purpurmc.purpur.PurpurConfig", "net.pl3x.purpur.PurpurConfig")) {
            return "purpur";
        }
        if (any("io.papermc.paper.configuration.Configuration", "com.destroystokyo.paper.PaperConfig",
                "io.papermc.paper.ServerBuildInfo", "org.github.paperspigot.PaperSpigotConfig")) {
            return "paper";
        }
        if (any("org.spigotmc.SpigotConfig")) {
            return "spigot";
        }
        return "unknown";
    }

    private static boolean any(String... classes) {
        for (String name : classes) {
            if (Reflect.serverType(name) != null) {
                return true;
            }
        }
        return false;
    }

    /** Paper's {@code getMinecraftVersion()}, else parsed from the Bukkit version ({@code 1.8.8-R0.1-SNAPSHOT}). */
    static String minecraftVersion(Server server) {
        Object direct = Reflect.call(server, Server.class, "getMinecraftVersion");
        if (direct instanceof String && !((String) direct).trim().isEmpty()) {
            return (String) direct;
        }
        String parsed = parseBukkitVersion(PlayerReader.safe(server::getBukkitVersion));
        return parsed != null ? parsed : parseServerVersion(PlayerReader.safe(server::getVersion));
    }

    static String parseBukkitVersion(String bukkitVersion) {
        if (bukkitVersion == null) {
            return null;
        }
        int dash = bukkitVersion.indexOf('-');
        String candidate = dash < 0 ? bukkitVersion : bukkitVersion.substring(0, dash);
        return VERSION.matcher(candidate).matches() ? candidate : null;
    }

    static String parseServerVersion(String version) {
        if (version == null) {
            return null;
        }
        Matcher matcher = MC_IN_VERSION.matcher(version);
        return matcher.find() ? matcher.group(1) : null;
    }

    private static String safe(String text) {
        return text == null ? "unknown" : text;
    }
}
