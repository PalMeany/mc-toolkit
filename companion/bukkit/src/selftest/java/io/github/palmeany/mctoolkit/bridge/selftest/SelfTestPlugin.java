package io.github.palmeany.mctoolkit.bridge.selftest;

import io.github.palmeany.mctoolkit.bridge.BridgePlugin;
import java.io.IOException;
import java.lang.reflect.Constructor;
import java.lang.reflect.Method;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collection;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.function.Supplier;
import java.util.logging.Level;
import org.bukkit.Bukkit;
import org.bukkit.Location;
import org.bukkit.Material;
import org.bukkit.World;
import org.bukkit.enchantments.Enchantment;
import org.bukkit.entity.Player;
import org.bukkit.event.Event;
import org.bukkit.event.player.PlayerJoinEvent;
import org.bukkit.event.player.PlayerQuitEvent;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.meta.ItemMeta;
import org.bukkit.plugin.java.JavaPlugin;
import org.bukkit.potion.PotionEffect;
import org.bukkit.potion.PotionEffectType;

/**
 * Development only, never shipped: plays a fake player through MCToolkitBridge on a live server without a client
 * and checks the files it writes against PROTOCOL.md. Prints one PASS/FAIL line per check, a RESULT line, then
 * stops the server (unless started with -Dselftest.stay=true). Optional expectations: -Dselftest.platform=paper.
 */
public final class SelfTestPlugin extends JavaPlugin {

    private static final long START_DELAY = 240; // ticks: the bridge has written at least twice by then
    private static final long CYCLE_WAIT = 130; // ticks: a bit more than one 5-second cycle

    private interface Step {
        void run() throws Exception;
    }

    private final List<Object[]> steps = new ArrayList<>();
    private final List<Player> extra = new CopyOnWriteArrayList<>();
    private final Supplier<Collection<? extends Player>> source = () -> extra;
    private BridgePlugin bridge;
    private Path dir;
    private Sched sched;
    private Subject fake;
    private int passed;
    private int failed;
    private long playerFileModified;
    private long playerFileUpdatedAt;
    private long stateUpdatedAt;
    private double stateX;

    @Override
    public void onEnable() {
        bridge = (BridgePlugin) getServer().getPluginManager().getPlugin("MCToolkitBridge");
        sched = Sched.create(this);
        bridge.addPlayerSource(source);
        step("fixture", START_DELAY - CYCLE_WAIT, this::fixture);
        step("baseline", CYCLE_WAIT, this::baseline);
        step("join", 1, this::join);
        step("files", CYCLE_WAIT, this::files);
        step("unchanged", CYCLE_WAIT, this::unchanged);
        step("change", 1, this::change);
        step("rewritten", CYCLE_WAIT, this::rewritten);
        step("quit", 1, this::quit);
        step("deleted", CYCLE_WAIT, this::deleted);
        step("finish", 1, this::finish);
        run(0);
    }

    private void step(String name, long delay, Step body) {
        steps.add(new Object[] {name, delay, body});
    }

    private void run(int index) {
        if (index >= steps.size()) {
            return;
        }
        Object[] step = steps.get(index);
        sched.later((Long) step[1], () -> {
            try {
                ((Step) step[2]).run();
            } catch (Throwable error) {
                failed++;
                getLogger().log(Level.SEVERE, "[SELFTEST] FAIL " + step[0] + " threw", error);
            }
            run(index + 1);
        });
    }

    // ---------------------------------------------------------------- steps

    /**
     * Something to count: keeps the 3x3 chunks around the main world's spawn loaded (plugin chunk tickets, 1.14+;
     * older versions keep spawn chunks loaded anyway) and puts 4 armor stands and 3 dropped items there.
     */
    private void fixture() {
        World world = Bukkit.getWorlds().get(0);
        Location spawn = world.getSpawnLocation();
        sched.at(spawn, 1, () -> {
            int cx = spawn.getBlockX() >> 4;
            int cz = spawn.getBlockZ() >> 4;
            Method ticket = method(World.class, "addPluginChunkTicket", int.class, int.class, org.bukkit.plugin.Plugin.class);
            for (int x = cx - 1; x <= cx + 1; x++) {
                for (int z = cz - 1; z <= cz + 1; z++) {
                    world.loadChunk(x, z);
                    if (ticket != null) {
                        invoke(ticket, world, x, z, this);
                    }
                }
            }
            Location base = new Location(world, spawn.getBlockX() + 0.5, world.getHighestBlockYAt(spawn) + 1, spawn.getBlockZ() + 0.5);
            for (int i = 0; i < 4; i++) {
                world.spawnEntity(base.clone().add(i * 2, 0, 0), org.bukkit.entity.EntityType.ARMOR_STAND);
            }
            Material[] drops = {Material.DIRT, Material.STONE, Material.GRAVEL};
            for (int i = 0; i < drops.length; i++) {
                world.dropItem(base.clone().add(0, 0, 3 + i * 3), new ItemStack(drops[i]));
            }
            getLogger().info("[SELFTEST] fixture: 9 chunks kept loaded, 4 armor stands, 3 items at " + base);
        });
    }

    private void baseline() throws IOException {
        dir = bridge.outputDirectory();
        check("output directory", dir != null && Files.isDirectory(dir), dir);
        Map<String, Object> performance = read("performance.json");
        meta("performance.json", performance, true);
        Object tps = performance.get("tps");
        check("tps is a number in 0..20", tps instanceof Number && num(tps) > 0 && num(tps) <= 20, tps);
        Map<String, Object> averages = map(performance.get("tps_avg"));
        check("tps_avg has one/five/fifteen", averages.keySet().equals(new java.util.HashSet<>(Arrays.asList("one", "five", "fifteen"))), averages);
        for (Object value : averages.values()) {
            check("tps_avg value in 0..20", value instanceof Number && num(value) > 0 && num(value) <= 20, averages);
        }
        Object mspt = performance.get("mspt");
        String expectMspt = System.getProperty("selftest.mspt", "");
        if (expectMspt.equals("null")) {
            check("mspt is null on this platform", mspt == null, mspt);
        } else {
            check("mspt is a positive number", mspt instanceof Number && num(mspt) > 0 && num(mspt) < 10_000, mspt);
        }
        List<Object> worlds = list(performance.get("worlds"));
        check("one row per world", worlds.size() == Bukkit.getWorlds().size(), worlds.size());
        boolean folia = Sched.isFolia();
        for (Object row : worlds) {
            Map<String, Object> world = map(row);
            check("world row keys", world.keySet().equals(new java.util.LinkedHashSet<>(Arrays.asList("name", "dimension",
                    "players", "chunks", "entity_count", "entities"))), world.keySet());
            Object dimension = world.get("dimension");
            check("dimension value", Arrays.asList("overworld", "nether", "end").contains(dimension)
                    || String.valueOf(dimension).contains(":"), dimension);
            check("players is an integer", world.get("players") instanceof Long, world.get("players"));
            check("chunks is an integer", world.get("chunks") instanceof Long && num(world.get("chunks")) >= 0, world.get("chunks"));
            check("entity_count is an integer", world.get("entity_count") instanceof Long, world.get("entity_count"));
            if (folia) {
                check("entities by type is null on Folia", world.get("entities") == null, world.get("entities"));
            } else {
                Map<String, Object> entities = map(world.get("entities"));
                long sum = 0;
                for (Object count : entities.values()) {
                    sum += (Long) count;
                }
                check("at most 10 entity types summing to at most entity_count", entities.size() <= 10
                        && sum <= num(world.get("entity_count")), entities);
            }
            getLogger().info("[SELFTEST] world " + world);
        }
        Map<String, Object> first = map(worlds.get(0));
        check("main world: the 9 fixture chunks are loaded", num(first.get("chunks")) >= 9, first.get("chunks"));
        check("main world: the 7 fixture entities are counted", num(first.get("entity_count")) >= 7, first.get("entity_count"));
        if (!folia) {
            Map<String, Object> entities = map(first.get("entities"));
            check("armor stands by type", entities.get("minecraft:armor_stand") instanceof Long
                    && num(entities.get("minecraft:armor_stand")) >= 4, entities);
            check("items by type", entities.get("minecraft:item") instanceof Long && num(entities.get("minecraft:item")) >= 3, entities);
        }
        Map<String, Object> state = read("state.json");
        meta("state.json", state, true);
        check("no players online", map(state.get("players")).isEmpty(), state.get("players"));
        Map<String, Object> mapFile = read("map.json");
        meta("map.json", mapFile, true);
        Object time = mapFile.get("time");
        check("time is the overworld day time", time instanceof Long && num(time) >= 0 && num(time) < 24000, time);
        check("no pins", list(mapFile.get("players")).isEmpty(), mapFile.get("players"));
        getLogger().info("[SELFTEST] performance " + performance);
    }

    private void join() throws ReflectiveOperationException {
        World world = Bukkit.getWorlds().get(0);
        Location spawn = world.getSpawnLocation();
        Location at = new Location(world, spawn.getBlockX() + 0.5, spawn.getBlockY() + 1.0, spawn.getBlockZ() + 0.25);
        try {
            fake = new FakePlayer(at, sched);
        } catch (IllegalArgumentException proxyImpossible) {
            // 1.8.8: Player has an int and a double getHealth(), which java.lang.reflect.Proxy refuses
            getLogger().info("[SELFTEST] Player cannot be proxied here (" + proxyImpossible.getMessage()
                    + "); using a real unconnected server player");
            fake = new LegacyPlayer(at);
        }
        Material shield = Material.matchMaterial("SHIELD");
        fake.equip(sword(), new ItemStack(Material.COBBLESTONE, 64), new ItemStack(Material.DIAMOND_HELMET),
                new ItemStack(Material.DIAMOND_BOOTS), shield == null ? null : new ItemStack(shield),
                new ItemStack(Material.ENDER_PEARL, 16));
        // the join event is fired where a real join would happen: on the thread that owns the player
        sched.at(fake.location(), 1, () -> {
            fire(PlayerJoinEvent.class, fake.player());
            extra.add(fake.player());
        });
    }

    private void files() throws IOException {
        String id = fake.id().toString();
        Map<String, Object> state = read("state.json");
        meta("state.json", state, true);
        Map<String, Object> players = map(state.get("players"));
        check("state lists the fake player under its dashed uuid", players.keySet().equals(Collections.singleton(id)), players.keySet());
        Map<String, Object> steve = map(players.get(id));
        check("state keys in protocol order", new ArrayList<>(steve.keySet()).equals(Arrays.asList("name", "mode", "world",
                "dimension", "x", "y", "z", "health", "max_health", "food", "saturation", "level", "exp", "ping", "op",
                "joined_at")), steve.keySet());
        equal("name", FakePlayer.NAME, steve.get("name"));
        equal("mode", "creative", steve.get("mode"));
        equal("world", fake.location().getWorld().getName(), steve.get("world"));
        equal("dimension", "overworld", steve.get("dimension"));
        equal("x", fake.location().getX(), num(steve.get("x")));
        equal("y", fake.location().getY(), num(steve.get("y")));
        equal("z", fake.location().getZ(), num(steve.get("z")));
        equal("health", 18.5, num(steve.get("health")));
        equal("max_health (attribute on 1.9+, getMaxHealth before)", fake.expectedMaxHealth(), num(steve.get("max_health")));
        equal("food", 17L, steve.get("food"));
        equal("saturation", 3.2, num(steve.get("saturation")));
        equal("level", 30L, steve.get("level"));
        equal("exp", 0.42, num(steve.get("exp")));
        equal("ping", fake.expectedPing(), steve.get("ping"));
        equal("op", false, steve.get("op"));
        Object joined = steve.get("joined_at");
        check("joined_at from the join event", joined instanceof Long && Math.abs(num(joined) - now()) < 60, joined);
        stateUpdatedAt = updatedAt(state);
        stateX = num(steve.get("x"));

        Map<String, Object> mapFile = read("map.json");
        List<Object> pins = list(mapFile.get("players"));
        check("one pin", pins.size() == 1, pins);
        Map<String, Object> pin = map(pins.get(0));
        check("pin keys", new ArrayList<>(pin.keySet()).equals(Arrays.asList("uuid", "name", "world", "dimension", "x", "y", "z")), pin.keySet());
        equal("pin uuid", id, pin.get("uuid"));
        equal("pin x", fake.location().getX(), num(pin.get("x")));

        Map<String, Object> performance = read("performance.json");
        Map<String, Object> mainWorld = map(list(performance.get("worlds")).get(0));
        equal("players counted in the fake player's world", 1L, mainWorld.get("players"));

        Path file = dir.resolve("players").resolve(id + ".json");
        check("player file exists", Files.isRegularFile(file), file);
        Map<String, Object> detail = read("players/" + id + ".json");
        meta("players/<uuid>.json", detail, true);
        check("detail keys", new ArrayList<>(detail.keySet()).equals(Arrays.asList("bridge", "uuid", "name", "inventory",
                "armor", "offhand", "ender_chest", "effects")), detail.keySet());
        equal("detail uuid", id, detail.get("uuid"));
        List<Object> inventory = list(detail.get("inventory"));
        check("two inventory items", inventory.size() == 2, inventory);
        Map<String, Object> sword = map(inventory.get(0));
        equal("sword", "{slot=0, id=minecraft:diamond_sword, count=1, name=Excalibur, enchanted=true, damage=12, "
                + "max_damage=1561}", sword.toString());
        equal("cobblestone", "{slot=9, id=minecraft:cobblestone, count=64, name=null, enchanted=false, damage=null, "
                + "max_damage=null}", map(inventory.get(1)).toString());
        Map<String, Object> armor = map(detail.get("armor"));
        check("armor keys", new ArrayList<>(armor.keySet()).equals(Arrays.asList("head", "chest", "legs", "feet")), armor.keySet());
        equal("helmet", "minecraft:diamond_helmet", map(armor.get("head")).get("id"));
        equal("helmet undamaged", 0L, map(armor.get("head")).get("damage"));
        equal("helmet max damage", 363L, map(armor.get("head")).get("max_damage"));
        check("no chestplate", armor.get("chest") == null, armor.get("chest"));
        equal("boots", "minecraft:diamond_boots", map(armor.get("feet")).get("id"));
        if (Material.matchMaterial("SHIELD") != null) {
            equal("offhand shield", "{id=minecraft:shield, count=1, name=null, enchanted=false, damage=0, max_damage=336}",
                    String.valueOf(detail.get("offhand")));
        } else {
            check("no offhand before 1.9", detail.get("offhand") == null, detail.get("offhand"));
        }
        equal("ender chest", "[{slot=4, id=minecraft:ender_pearl, count=16, name=null, enchanted=false, damage=null, "
                + "max_damage=null}]", String.valueOf(detail.get("ender_chest")));
        equal("effects", "[{id=minecraft:speed, amplifier=1, duration=600}]", String.valueOf(detail.get("effects")));
        playerFileModified = Files.getLastModifiedTime(file).toMillis();
        playerFileUpdatedAt = updatedAt(detail);
        getLogger().info("[SELFTEST] state " + state);
        getLogger().info("[SELFTEST] player " + detail);
    }

    private void unchanged() throws IOException {
        Map<String, Object> state = read("state.json");
        long updated = updatedAt(state);
        check("state.json rewritten by the next cycle (updated_at +5 s or more)", updated >= stateUpdatedAt + 5,
                stateUpdatedAt + " -> " + updated);
        Path file = dir.resolve("players").resolve(fake.id() + ".json");
        check("unchanged player file not rewritten (mtime)", Files.getLastModifiedTime(file).toMillis() == playerFileModified,
                playerFileModified + " vs " + Files.getLastModifiedTime(file).toMillis());
        equal("unchanged player file keeps its updated_at", playerFileUpdatedAt, updatedAt(read("players/" + fake.id() + ".json")));
    }

    private void change() {
        sched.at(fake.location(), 1, () -> {
            fake.setSlot(9, new ItemStack(Material.COBBLESTONE, 32));
            fake.moveX(10);
        });
    }

    private void rewritten() throws IOException {
        Map<String, Object> detail = read("players/" + fake.id() + ".json");
        check("changed player file rewritten", updatedAt(detail) > playerFileUpdatedAt, playerFileUpdatedAt + " -> " + updatedAt(detail));
        equal("new stack size", 32L, map(list(detail.get("inventory")).get(1)).get("count"));
        Map<String, Object> steve = map(map(read("state.json").get("players")).get(fake.id().toString()));
        equal("moved 10 blocks east", stateX + 10, num(steve.get("x")));
    }

    private void quit() {
        sched.at(fake.location(), 1, () -> {
            fire(PlayerQuitEvent.class, fake.player());
            extra.remove(fake.player());
        });
    }

    private void deleted() throws IOException {
        check("player file deleted after the player left", !Files.exists(dir.resolve("players").resolve(fake.id() + ".json")), fake.id());
        check("state.json empty again", map(read("state.json").get("players")).isEmpty(), "");
        check("map.json empty again", list(read("map.json").get("players")).isEmpty(), "");
        equal("no players counted", 0L, map(list(read("performance.json").get("worlds")).get(0)).get("players"));
    }

    private void finish() {
        bridge.removePlayerSource(source);
        getLogger().info("[SELFTEST] RESULT: " + passed + " passed, " + failed + " failed");
        if (!Boolean.getBoolean("selftest.stay")) {
            Bukkit.shutdown();
        }
    }

    // ---------------------------------------------------------------- helpers

    private ItemStack sword() {
        ItemStack sword = new ItemStack(Material.DIAMOND_SWORD);
        Object sharpness = staticValue(Enchantment.class, "SHARPNESS");
        if (sharpness == null) {
            sharpness = staticValue(Enchantment.class, "DAMAGE_ALL");
        }
        sword.addUnsafeEnchantment((Enchantment) sharpness, 5);
        ItemMeta meta = sword.getItemMeta();
        meta.setDisplayName("§bExcalibur");
        Class<?> damageable = type("org.bukkit.inventory.meta.Damageable");
        if (damageable != null && damageable.isInstance(meta)) {
            invoke(method(damageable, "setDamage", int.class), meta, 12);
            sword.setItemMeta(meta);
        } else {
            sword.setItemMeta(meta);
            invoke(method(ItemStack.class, "setDurability", short.class), sword, (short) 12);
        }
        return sword;
    }

    /** Fires a join/quit event for the fake player with whichever constructor this server has. */
    private void fire(Class<? extends Event> type, Player player) {
        try {
            Event event;
            Constructor<? extends Event> legacy = constructor(type, Player.class, String.class);
            if (legacy != null) {
                event = legacy.newInstance(player, "");
            } else {
                Class<?> component = type("net.kyori.adventure.text.Component");
                Object empty = invoke(method(component, "empty"), null);
                event = type.getConstructor(Player.class, component).newInstance(player, empty);
            }
            Bukkit.getPluginManager().callEvent(event);
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException(e);
        }
    }

    private Map<String, Object> read(String name) throws IOException {
        return MiniJson.object(new String(Files.readAllBytes(dir.resolve(name)), StandardCharsets.UTF_8));
    }

    private void meta(String file, Map<String, Object> document, boolean running) {
        check(file + ": bridge block first", document.keySet().iterator().next().equals("bridge"), document.keySet());
        Map<String, Object> meta = map(document.get("bridge"));
        check(file + ": meta keys", new ArrayList<>(meta.keySet()).equals(Arrays.asList("protocol", "version", "platform",
                "software", "minecraft", "updated_at", "interval", "running")), meta.keySet());
        equal(file + ": protocol", 1L, meta.get("protocol"));
        equal(file + ": version", bridge.getDescription().getVersion(), meta.get("version"));
        String platform = System.getProperty("selftest.platform");
        if (platform != null) {
            equal(file + ": platform", platform, meta.get("platform"));
        }
        String minecraft = System.getProperty("selftest.minecraft");
        if (minecraft != null) {
            equal(file + ": minecraft", minecraft, meta.get("minecraft"));
        } else {
            check(file + ": minecraft known", meta.get("minecraft") instanceof String, meta.get("minecraft"));
        }
        check(file + ": software", meta.get("software") instanceof String && !((String) meta.get("software")).isEmpty(), meta.get("software"));
        check(file + ": updated_at recent", Math.abs(num(meta.get("updated_at")) - now()) <= 12, meta.get("updated_at"));
        equal(file + ": interval", 5L, meta.get("interval"));
        equal(file + ": running", running, meta.get("running"));
    }

    private static long updatedAt(Map<String, Object> document) {
        return (Long) map(document.get("bridge")).get("updated_at");
    }

    private void equal(String name, Object expected, Object actual) {
        boolean ok = expected == null ? actual == null : expected.equals(actual);
        check(name, ok, "expected " + expected + ", got " + actual);
    }

    private void check(String name, boolean ok, Object detail) {
        if (ok) {
            passed++;
            getLogger().info("[SELFTEST] PASS " + name);
        } else {
            failed++;
            getLogger().severe("[SELFTEST] FAIL " + name + " -- " + detail);
        }
    }

    private static long now() {
        return System.currentTimeMillis() / 1000L;
    }

    private static double num(Object value) {
        return value instanceof Number ? ((Number) value).doubleValue() : Double.NaN;
    }

    @SuppressWarnings("unchecked")
    private static Map<String, Object> map(Object value) {
        if (!(value instanceof Map)) {
            throw new IllegalStateException("expected an object, got " + value);
        }
        return (Map<String, Object>) value;
    }

    @SuppressWarnings("unchecked")
    private static List<Object> list(Object value) {
        if (!(value instanceof List)) {
            throw new IllegalStateException("expected an array, got " + value);
        }
        return (List<Object>) value;
    }

    private static Class<?> type(String name) {
        try {
            return Class.forName(name, false, Bukkit.getServer().getClass().getClassLoader());
        } catch (ClassNotFoundException e) {
            return null;
        }
    }

    private static Method method(Class<?> owner, String name, Class<?>... parameters) {
        try {
            return owner == null ? null : owner.getMethod(name, parameters);
        } catch (NoSuchMethodException e) {
            return null;
        }
    }

    private static Object invoke(Method method, Object target, Object... args) {
        try {
            return method == null ? null : method.invoke(target, args);
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Object staticValue(Class<?> owner, String name) {
        try {
            return owner.getField(name).get(null);
        } catch (ReflectiveOperationException e) {
            return null;
        }
    }

    private static <T> Constructor<T> constructor(Class<T> type, Class<?>... parameters) {
        try {
            return type.getConstructor(parameters);
        } catch (NoSuchMethodException e) {
            return null;
        }
    }
}
