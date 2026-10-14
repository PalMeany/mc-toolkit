package io.github.palmeany.mctoolkit.bridge.selftest;

import java.lang.reflect.Method;
import java.lang.reflect.Proxy;
import java.util.ArrayList;
import java.util.Collection;
import java.util.Collections;
import java.util.List;
import java.util.UUID;
import org.bukkit.Bukkit;
import org.bukkit.GameMode;
import org.bukkit.Location;
import org.bukkit.World;
import org.bukkit.entity.Player;
import org.bukkit.inventory.Inventory;
import org.bukkit.inventory.ItemStack;
import org.bukkit.inventory.PlayerInventory;
import org.bukkit.potion.PotionEffect;

/**
 * A player that exists only as far as the bridge looks at one, built from the server's own interfaces with
 * java.lang.reflect.Proxy. Items, the ender chest inventory, effects and the world are real server objects. On Folia
 * its EntityScheduler runs tasks on the region that owns its location, as a real player's would.
 */
final class FakePlayer implements Subject {

    static final String NAME = "SelfTestSteve";
    static final double MAX_HEALTH_ATTRIBUTE = 24.0;

    final UUID id = UUID.nameUUIDFromBytes(("selftest:" + NAME).getBytes(java.nio.charset.StandardCharsets.UTF_8));
    final ItemStack[] slots = new ItemStack[36];
    volatile ItemStack helmet;
    volatile ItemStack boots;
    volatile ItemStack offhand;
    final Inventory enderChest = Bukkit.createInventory(null, 27);
    final List<PotionEffect> effects = new ArrayList<>();
    volatile Location location;
    final Player player;
    private final PlayerInventory inventory;
    private final Sched sched;
    private final Object entityScheduler;
    private final Object attributeInstance;

    FakePlayer(Location location, Sched sched) {
        this.location = location;
        this.sched = sched;
        ClassLoader loader = getClass().getClassLoader();
        player = (Player) Proxy.newProxyInstance(loader, new Class<?>[] {Player.class}, (self, method, args) -> player(self, method, args));
        inventory = (PlayerInventory) Proxy.newProxyInstance(loader, new Class<?>[] {PlayerInventory.class},
                (self, method, args) -> inventory(self, method, args));
        entityScheduler = optionalProxy("io.papermc.paper.threadedregions.scheduler.EntityScheduler", this::scheduler);
        attributeInstance = optionalProxy("org.bukkit.attribute.AttributeInstance",
                (self, method, args) -> "getValue".equals(method.getName()) ? (Object) MAX_HEALTH_ATTRIBUTE : basic(self, method, args));
    }

    private Object optionalProxy(String type, java.lang.reflect.InvocationHandler handler) {
        try {
            Class<?> cls = Class.forName(type, false, Bukkit.getServer().getClass().getClassLoader());
            return Proxy.newProxyInstance(getClass().getClassLoader(), new Class<?>[] {cls}, handler);
        } catch (ClassNotFoundException e) {
            return null;
        }
    }

    private Object player(Object self, Method method, Object[] args) {
        switch (method.getName()) {
            case "getUniqueId":
                return id;
            case "getName":
            case "getDisplayName":
                return NAME;
            case "getGameMode":
                return GameMode.CREATIVE;
            case "getLocation":
                return location.clone();
            case "getWorld":
                return location.getWorld();
            case "getHealth":
                return 18.5;
            case "getMaxHealth":
                return 20.0;
            case "getAttribute":
                return attributeInstance;
            case "getFoodLevel":
                return 17;
            case "getSaturation":
                return 3.2f;
            case "getLevel":
                return 30;
            case "getExp":
                return 0.42f;
            case "getPing":
                return 42;
            case "isOp":
                return false;
            case "isOnline":
            case "isValid":
                return true;
            case "getInventory":
                return inventory;
            case "getEnderChest":
                return enderChest;
            case "getActivePotionEffects":
                return Collections.unmodifiableList(new ArrayList<>(effects));
            case "getScheduler":
                return entityScheduler;
            default:
                return basic(self, method, args);
        }
    }

    private Object inventory(Object self, Method method, Object[] args) {
        switch (method.getName()) {
            case "getItem":
                return args[0] instanceof Integer && (Integer) args[0] < slots.length ? slots[(Integer) args[0]] : null;
            case "getSize":
                return 41;
            case "getHelmet":
                return helmet;
            case "getBoots":
                return boots;
            case "getChestplate":
            case "getLeggings":
                return null;
            case "getItemInOffHand":
                return offhand;
            case "getHolder":
                return player;
            default:
                return basic(self, method, args);
        }
    }

    /** EntityScheduler#execute / #run: run on the region owning the fake player's location. */
    private Object scheduler(Object self, Method method, Object[] args) {
        switch (method.getName()) {
            case "execute": {
                Runnable task = (Runnable) args[1];
                long delay = (Long) args[3];
                sched.at(location, delay, task);
                return true;
            }
            case "run": {
                @SuppressWarnings("unchecked")
                java.util.function.Consumer<Object> task = (java.util.function.Consumer<Object>) args[1];
                sched.at(location, 1, () -> task.accept(null));
                return null;
            }
            default:
                return basic(self, method, args);
        }
    }

    private static Object basic(Object self, Method method, Object[] args) {
        switch (method.getName()) {
            case "equals":
                return self == args[0];
            case "hashCode":
                return System.identityHashCode(self);
            case "toString":
                return "FakePlayer(" + NAME + ")";
            default:
                return zero(method.getReturnType());
        }
    }

    static Object zero(Class<?> type) {
        if (type == boolean.class) {
            return false;
        }
        if (type == int.class) {
            return 0;
        }
        if (type == long.class) {
            return 0L;
        }
        if (type == double.class) {
            return 0d;
        }
        if (type == float.class) {
            return 0f;
        }
        if (type == short.class) {
            return (short) 0;
        }
        if (type == byte.class) {
            return (byte) 0;
        }
        if (type == char.class) {
            return '\0';
        }
        if (type == Collection.class || type == List.class) {
            return Collections.emptyList();
        }
        return null;
    }

    World world() {
        return location.getWorld();
    }

    @Override
    public Player player() {
        return player;
    }

    @Override
    public UUID id() {
        return id;
    }

    @Override
    public Location location() {
        return location;
    }

    @Override
    public void equip(ItemStack sword, ItemStack cobblestone, ItemStack helmet, ItemStack boots, ItemStack offhand,
            ItemStack pearls) {
        slots[0] = sword;
        slots[9] = cobblestone;
        this.helmet = helmet;
        this.boots = boots;
        this.offhand = offhand;
        enderChest.setItem(4, pearls);
        effects.add(new PotionEffect(org.bukkit.potion.PotionEffectType.SPEED, 600, 1));
    }

    @Override
    public void setSlot(int slot, ItemStack item) {
        slots[slot] = item;
    }

    @Override
    public void moveX(double blocks) {
        location = location.clone().add(blocks, 0, 0);
    }

    @Override
    public Object expectedPing() {
        // Player#getPing exists from Paper 1.16.5 / Spigot 1.17; before that a proxy has nothing the bridge can read
        try {
            Player.class.getMethod("getPing");
            return 42L;
        } catch (NoSuchMethodException e) {
            return null;
        }
    }

    @Override
    public double expectedMaxHealth() {
        return attributeInstance != null ? MAX_HEALTH_ATTRIBUTE : 20.0;
    }
}
