package io.github.palmeany.mctoolkit.bridge;

import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.Collection;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.function.Supplier;
import java.util.logging.Level;
import org.bukkit.entity.Player;
import org.bukkit.event.EventHandler;
import org.bukkit.event.EventPriority;
import org.bukkit.event.Listener;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.event.player.PlayerQuitEvent;
import org.bukkit.plugin.java.JavaPlugin;

/**
 * MC Toolkit companion bridge for the Bukkit family (Spigot, Paper and its forks, Folia, Bukkit-loading hybrids;
 * Minecraft 1.8.8 – 26.x). Writes live game data as JSON files into {@code .toolkit/} every 5 seconds for the
 * MC Toolkit panel plugin to read; see companion/PROTOCOL.md. It only writes files: no commands, no network, no
 * configuration.
 */
public final class BridgePlugin extends JavaPlugin implements Listener {

    /** JVM property that overrides the output directory. */
    static final String DIRECTORY_PROPERTY = "mctoolkit.bridge.dir";
    /** Environment variable that overrides the output directory (the property wins). */
    static final String DIRECTORY_ENV = "MCTOOLKIT_BRIDGE_DIR";
    static final String DEFAULT_DIRECTORY = ".toolkit";

    private final Map<UUID, Long> joinedAt = new ConcurrentHashMap<>();
    private final List<Supplier<? extends Collection<? extends Player>>> extraPlayers = new CopyOnWriteArrayList<>();
    private BridgeWriter writer;
    private Driver driver;

    @Override
    public void onEnable() {
        Platform platform = Platform.detect(getServer());
        Path directory = resolveDirectory(System.getProperty(DIRECTORY_PROPERTY), System.getenv(DIRECTORY_ENV),
                Paths.get("").toAbsolutePath());
        Documents documents = new Documents(getDescription().getVersion(), platform.id, platform.software,
                platform.minecraft);
        writer = new BridgeWriter(directory, documents, getLogger());

        // after a reload the players are already online: their login time is the best join time there is
        for (Player player : getServer().getOnlinePlayers()) {
            Long login = lastLogin(player);
            if (login != null) {
                joinedAt.put(player.getUniqueId(), login);
            }
        }
        getServer().getPluginManager().registerEvents(this, this);

        TpsMeter meter = platform.folia || platform.paperTps != null ? null : new TpsMeter();
        Collector collector = new Collector(getServer(), platform, meter, joinedAt, extraPlayers, getLogger());
        try {
            driver = platform.folia
                    ? new FoliaDriver(collector, writer, FoliaScheduler.create(this))
                    : new BukkitDriver(this, collector, writer, meter);
            driver.start();
        } catch (RuntimeException | LinkageError e) {
            getLogger().log(Level.SEVERE, "Cannot schedule the bridge tasks; no live data will be written", e);
            driver = null;
            return;
        }
        getLogger().info("Writing live data to " + directory + " every " + Documents.INTERVAL_SECONDS + " s ("
                + platform.id + ", Minecraft " + platform.minecraft + "; TPS: " + driver.tpsSource() + ", MSPT: "
                + driver.msptSource() + ")");
    }

    @Override
    public void onDisable() {
        if (driver != null) {
            try {
                driver.stop();
            } catch (RuntimeException | LinkageError ignored) {
                // the server drops a disabled plugin's tasks anyway
            }
            driver = null;
        }
        if (writer != null) {
            writer.close(); // running:false, no players; synchronous, there is no later chance
        }
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onJoin(PlayerJoinEvent event) {
        joinedAt.put(event.getPlayer().getUniqueId(), System.currentTimeMillis() / 1000L);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onQuit(PlayerQuitEvent event) {
        joinedAt.remove(event.getPlayer().getUniqueId());
    }

    /**
     * Development hook used by the self-test plugin (harmless otherwise): players to report in addition to the
     * online ones. The supplier is called from the collecting thread every cycle.
     */
    public void addPlayerSource(Supplier<? extends Collection<? extends Player>> source) {
        extraPlayers.add(source);
    }

    public void removePlayerSource(Supplier<? extends Collection<? extends Player>> source) {
        extraPlayers.remove(source);
    }

    /** The directory the files are written to, null before the plugin is enabled. */
    public Path outputDirectory() {
        return writer == null ? null : writer.directory();
    }

    /** {@code -Dmctoolkit.bridge.dir}, else {@code MCTOOLKIT_BRIDGE_DIR}, else {@code .toolkit}; relative to {@code workDir}. */
    static Path resolveDirectory(String property, String environment, Path workDir) {
        String chosen = DEFAULT_DIRECTORY;
        if (property != null && !property.trim().isEmpty()) {
            chosen = property.trim();
        } else if (environment != null && !environment.trim().isEmpty()) {
            chosen = environment.trim();
        }
        Path path = Paths.get(chosen);
        return (path.isAbsolute() ? path : workDir.resolve(path)).normalize();
    }

    /** Paper's OfflinePlayer#getLastLogin in seconds, null where it does not exist. */
    private static Long lastLogin(Player player) {
        Object millis = Reflect.call(player, Player.class, "getLastLogin");
        return millis instanceof Number && ((Number) millis).longValue() > 0 ? ((Number) millis).longValue() / 1000L : null;
    }
}
