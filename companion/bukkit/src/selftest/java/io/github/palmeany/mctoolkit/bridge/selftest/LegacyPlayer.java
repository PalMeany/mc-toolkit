package io.github.palmeany.mctoolkit.bridge.selftest;

import java.lang.reflect.Constructor;
import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.util.Map;
import java.util.UUID;
import org.bukkit.Bukkit;
import org.bukkit.Location;
import org.bukkit.World;
import org.bukkit.entity.Player;
import org.bukkit.inventory.ItemStack;

/**
 * For servers whose Player interface cannot be proxied (1.8.8 carries an int/double getHealth() bridge pair): a
 * real server player (CraftPlayer around an NMS EntityPlayer) that is never added to a world and has no network
 * connection. Its state is set through fields where the API would send packets to the (missing) connection.
 */
final class LegacyPlayer implements Subject {

    private final String nms;
    private final String craft;
    private final Object handle;
    private final Player player;
    private final UUID id = UUID.nameUUIDFromBytes(("selftest:" + FakePlayer.NAME).getBytes(java.nio.charset.StandardCharsets.UTF_8));

    LegacyPlayer(Location location) throws ReflectiveOperationException {
        craft = Bukkit.getServer().getClass().getPackage().getName();
        nms = "net.minecraft.server." + craft.substring(craft.lastIndexOf('.') + 1);
        Object server = Bukkit.getServer().getClass().getMethod("getServer").invoke(Bukkit.getServer());
        Object world = location.getWorld().getClass().getMethod("getHandle").invoke(location.getWorld());
        Object profile = Class.forName("com.mojang.authlib.GameProfile").getConstructor(UUID.class, String.class)
                .newInstance(id, FakePlayer.NAME);
        Class<?> nmsWorld = nms("World");
        Object manager = nms("PlayerInteractManager").getConstructor(nmsWorld).newInstance(world);
        Constructor<?> constructor = null;
        for (Constructor<?> candidate : nms("EntityPlayer").getConstructors()) {
            if (candidate.getParameterTypes().length == 4) {
                constructor = candidate;
            }
        }
        handle = constructor.newInstance(server, world, profile, manager);
        player = (Player) handle.getClass().getMethod("getBukkitEntity").invoke(handle);

        // game mode without PlayerInteractManager#setGameMode, which would send packets
        Field gamemode = field(manager.getClass(), "gamemode");
        gamemode.set(manager, enumConstant(gamemode.getType(), "CREATIVE"));
        place(location);
        method(handle.getClass(), "setHealth", float.class).invoke(handle, 18.5f);
        player.setFoodLevel(17);
        player.setSaturation(3.2f);
        player.setLevel(30);
        player.setExp(0.42f);
        @SuppressWarnings("unchecked")
        Map<Integer, Object> effects = (Map<Integer, Object>) field(handle.getClass(), "effects").get(handle);
        effects.put(1, nms("MobEffect").getConstructor(int.class, int.class, int.class).newInstance(1, 600, 1));
    }

    private Class<?> nms(String name) throws ClassNotFoundException {
        return Class.forName(nms + "." + name, false, Bukkit.getServer().getClass().getClassLoader());
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
        return player.getLocation();
    }

    private void place(Location location) {
        try {
            method(handle.getClass(), "setLocation", double.class, double.class, double.class, float.class, float.class)
                    .invoke(handle, location.getX(), location.getY(), location.getZ(), 0f, 0f);
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException(e);
        }
    }

    @Override
    public void equip(ItemStack sword, ItemStack cobblestone, ItemStack helmet, ItemStack boots, ItemStack offhand,
            ItemStack pearls) {
        setSlot(0, sword);
        setSlot(9, cobblestone);
        Object[] armor = (Object[]) get(inventory(), "armor");
        armor[3] = nmsCopy(helmet);
        armor[0] = nmsCopy(boots);
        player.getEnderChest().setItem(4, pearls);
    }

    @Override
    public void setSlot(int slot, ItemStack item) {
        Object[] items = (Object[]) get(inventory(), "items");
        items[slot] = nmsCopy(item);
    }

    @Override
    public void moveX(double blocks) {
        place(player.getLocation().add(blocks, 0, 0));
    }

    @Override
    public Object expectedPing() {
        return 0L; // read from EntityPlayer#ping by the bridge; never set without a connection
    }

    @Override
    public double expectedMaxHealth() {
        return 20.0;
    }

    World world() {
        return player.getWorld();
    }

    private Object inventory() {
        return get(handle, "inventory");
    }

    private Object nmsCopy(ItemStack item) {
        try {
            Class<?> craftStack = Class.forName(craft + ".inventory.CraftItemStack", false, Bukkit.getServer().getClass().getClassLoader());
            return craftStack.getMethod("asNMSCopy", ItemStack.class).invoke(null, item);
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Object get(Object target, String name) {
        try {
            return field(target.getClass(), name).get(target);
        } catch (ReflectiveOperationException e) {
            throw new IllegalStateException(e);
        }
    }

    private static Field field(Class<?> owner, String name) throws NoSuchFieldException {
        for (Class<?> type = owner; type != null; type = type.getSuperclass()) {
            try {
                Field field = type.getDeclaredField(name);
                field.setAccessible(true);
                return field;
            } catch (NoSuchFieldException ignored) {
                // keep walking up
            }
        }
        throw new NoSuchFieldException(owner.getName() + "." + name);
    }

    private static Method method(Class<?> owner, String name, Class<?>... parameters) throws NoSuchMethodException {
        for (Class<?> type = owner; type != null; type = type.getSuperclass()) {
            try {
                Method method = type.getDeclaredMethod(name, parameters);
                method.setAccessible(true);
                return method;
            } catch (NoSuchMethodException ignored) {
                // keep walking up
            }
        }
        throw new NoSuchMethodException(owner.getName() + "." + name);
    }

    @SuppressWarnings({"unchecked", "rawtypes"})
    private static Object enumConstant(Class<?> type, String name) {
        return Enum.valueOf((Class) type, name);
    }
}
