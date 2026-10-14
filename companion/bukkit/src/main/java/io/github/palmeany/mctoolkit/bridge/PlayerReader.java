package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Callable;
import org.bukkit.GameMode;
import org.bukkit.Location;
import org.bukkit.World;
import org.bukkit.entity.Player;
import org.bukkit.inventory.Inventory;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.PlayerInventory;
import org.bukkit.potion.PotionEffect;

/**
 * Reads one player into a {@link PlayerSnapshot}. Must run on the thread that owns the player (the main thread, or
 * the player's region thread on Folia). Every field is read on its own, so a failure (a method missing on this
 * version, a plugin-provided fake player) leaves that field null and nothing else.
 */
final class PlayerReader {

    static final int MAIN_INVENTORY_SLOTS = 36;
    private static final int DEFAULT_ENDER_SLOTS = 27;

    private PlayerReader() {
    }

    static PlayerSnapshot read(Player player, Long joinedAt) {
        PlayerSnapshot snapshot = new PlayerSnapshot(player.getUniqueId(), player.getName());
        snapshot.mode = safe(() -> {
            GameMode mode = player.getGameMode();
            return mode == null ? null : Ids.lower(mode.toString());
        });
        Location location = safe(player::getLocation);
        if (location != null) {
            World world = location.getWorld();
            if (world != null) {
                snapshot.world = safe(world::getName);
                snapshot.dimension = safe(() -> Worlds.dimension(world));
            }
            snapshot.x = location.getX();
            snapshot.y = location.getY();
            snapshot.z = location.getZ();
        }
        snapshot.health = safe(player::getHealth);
        snapshot.maxHealth = safe(() -> maxHealth(player));
        snapshot.food = safe(player::getFoodLevel);
        snapshot.saturation = safe(() -> (double) player.getSaturation());
        snapshot.level = safe(player::getLevel);
        snapshot.exp = safe(() -> (double) player.getExp());
        snapshot.ping = safe(() -> ping(player));
        snapshot.op = safe(player::isOp);
        snapshot.joinedAt = joinedAt;
        snapshot.detail = detail(player, snapshot);
        return snapshot;
    }

    /** Body of {@code players/<uuid>.json}: inventory, armor, offhand, ender chest and effects. */
    static Map<String, Object> detail(Player player, PlayerSnapshot snapshot) {
        Map<String, Object> detail = new LinkedHashMap<>();
        detail.put("uuid", snapshot.id());
        detail.put("name", snapshot.name);

        PlayerInventory inventory = safe(player::getInventory);
        List<Object> main = new ArrayList<>();
        if (inventory != null) {
            for (int slot = 0; slot < MAIN_INVENTORY_SLOTS; slot++) {
                int index = slot;
                add(main, ItemReader.read(safe(() -> inventory.getItem(index)), slot));
            }
        }
        detail.put("inventory", main);

        Map<String, Object> armor = new LinkedHashMap<>();
        armor.put("head", inventory == null ? null : ItemReader.read(safe(inventory::getHelmet), null));
        armor.put("chest", inventory == null ? null : ItemReader.read(safe(inventory::getChestplate), null));
        armor.put("legs", inventory == null ? null : ItemReader.read(safe(inventory::getLeggings), null));
        armor.put("feet", inventory == null ? null : ItemReader.read(safe(inventory::getBoots), null));
        detail.put("armor", armor);

        // PlayerInventory#getItemInOffHand exists from 1.9 on
        Object offhand = inventory == null ? null : Reflect.call(inventory, PlayerInventory.class, "getItemInOffHand");
        detail.put("offhand", offhand instanceof ItemStack ? ItemReader.read((ItemStack) offhand, null) : null);

        Inventory ender = safe(player::getEnderChest);
        List<Object> enderItems = new ArrayList<>();
        if (ender != null) {
            Integer size = safe(ender::getSize); // Purpur can give the ender chest up to six rows
            for (int slot = 0; slot < (size == null ? DEFAULT_ENDER_SLOTS : size); slot++) {
                int index = slot;
                add(enderItems, ItemReader.read(safe(() -> ender.getItem(index)), slot));
            }
        }
        detail.put("ender_chest", enderItems);
        detail.put("effects", effects(player));
        return detail;
    }

    private static void add(List<Object> items, Map<String, Object> item) {
        if (item != null) {
            items.add(item);
        }
    }

    /** Active effects sorted by id, so an unchanged set always serialises the same way. */
    static List<Object> effects(Player player) {
        Collection<PotionEffect> active = safe(player::getActivePotionEffects);
        List<Map<String, Object>> effects = new ArrayList<>();
        if (active != null) {
            for (PotionEffect effect : active) {
                Map<String, Object> entry = effect(effect);
                if (entry != null) {
                    effects.add(entry);
                }
            }
        }
        effects.sort((a, b) -> {
            int byId = String.valueOf(a.get("id")).compareTo(String.valueOf(b.get("id")));
            return byId != 0 ? byId : Integer.compare((Integer) a.get("amplifier"), (Integer) b.get("amplifier"));
        });
        return new ArrayList<Object>(effects);
    }

    static Map<String, Object> effect(PotionEffect effect) {
        if (effect == null) {
            return null;
        }
        try {
            Map<String, Object> entry = new LinkedHashMap<>();
            entry.put("id", Ids.effect(effect.getType()));
            entry.put("amplifier", effect.getAmplifier());
            int duration = effect.getDuration();
            // PotionEffect#isInfinite is 1.19.4+; before that "endless" effects were given Integer.MAX_VALUE ticks
            if (Boolean.TRUE.equals(Reflect.call(effect, PotionEffect.class, "isInfinite"))
                    || duration < 0 || duration == Integer.MAX_VALUE) {
                duration = -1;
            }
            entry.put("duration", duration);
            return entry;
        } catch (RuntimeException | LinkageError e) {
            return null;
        }
    }

    /** Max health from the MAX_HEALTH attribute (1.9+; GENERIC_MAX_HEALTH before 1.21.3), else getMaxHealth(). */
    @SuppressWarnings("deprecation")
    static Double maxHealth(Player player) {
        Class<?> attribute = Reflect.type("org.bukkit.attribute.Attribute");
        if (attribute != null) {
            Object maxHealth = Reflect.staticValue(attribute, "MAX_HEALTH");
            if (maxHealth == null) {
                maxHealth = Reflect.staticValue(attribute, "GENERIC_MAX_HEALTH");
            }
            Method getAttribute = Reflect.method(Reflect.type("org.bukkit.attribute.Attributable"), "getAttribute", attribute);
            if (maxHealth != null && getAttribute != null) {
                Object instance = Reflect.invoke(getAttribute, player, maxHealth);
                Object value = Reflect.call(instance, Reflect.type("org.bukkit.attribute.AttributeInstance"), "getValue");
                if (value instanceof Number) {
                    return ((Number) value).doubleValue();
                }
            }
        }
        return player.getMaxHealth();
    }

    /**
     * Latency in milliseconds: Player#getPing (Paper 1.16.5+, Spigot 1.17+), Player.Spigot#getPing (forks that
     * had it earlier), the server player's {@code ping} field (CraftBukkit 1.8 – 1.16); null when none exist.
     */
    static Integer ping(Player player) {
        Method getPing = Reflect.method(Player.class, "getPing");
        if (getPing != null) {
            return number(Reflect.invoke(getPing, player));
        }
        Method spigotPing = Reflect.method(Reflect.type("org.bukkit.entity.Player$Spigot"), "getPing");
        if (spigotPing != null) {
            Integer ping = number(Reflect.invoke(spigotPing, Reflect.call(player, Player.class, "spigot")));
            if (ping != null) {
                return ping;
            }
        }
        Object handle = Reflect.call(player, player.getClass(), "getHandle");
        Field field = handle == null ? null : Reflect.field(handle.getClass(), "ping");
        if (field != null && field.getType() == int.class) {
            try {
                return field.getInt(handle);
            } catch (IllegalAccessException | RuntimeException ignored) {
                return null;
            }
        }
        return null;
    }

    private static Integer number(Object value) {
        return value instanceof Number ? ((Number) value).intValue() : null;
    }

    /** The value, or null when reading it failed in any way (missing method, plugin-made fake player, ...). */
    static <T> T safe(Callable<T> read) {
        try {
            return read.call();
        } catch (Exception | LinkageError e) {
            return null;
        }
    }
}
