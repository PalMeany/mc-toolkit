package io.github.palmeany.mctoolkit.bridge;

import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.UUID;

/**
 * Development only; compiled into {@code mctoolkit-bridge-selftest.jar}, never into the release jar,
 * and active only with {@code -Dmctoolkit.bridge.selftest=true}.
 *
 * <p>There is no Minecraft client to join a test server, so this builds a real but detached
 * {@code ServerPlayer} on the server thread (not added to the world or the player list), gives it
 * known items, damage, a custom name, an enchantment and an effect, and hands it to the bridge as an
 * extra online player. The bridge then runs its normal player path on it. Timeline (bridge cycles,
 * 5 s apart): 2 = player appears, 5 = an item count changes (detail file must be rewritten),
 * 7 = player disappears (detail file must be deleted). The test harness checks the files.
 */
final class SelfTest implements Bridge.Extension {

    static final UUID ID = UUID.nameUUIDFromBytes("mctoolkit-bridge-selftest".getBytes(StandardCharsets.UTF_8));
    static final String NAME = "BridgeSelfTest";

    private static final String CLIENT_INFO = "net.minecraft.server.level.ClientInformation";
    private static final String ITEM_LIKE = "net.minecraft.world.level.ItemLike";
    private static final String ITEMS = "net.minecraft.world.item.Items";
    private static final String ENCHANTMENT = "net.minecraft.world.item.enchantment.Enchantment";
    private static final String ENCHANTMENTS = "net.minecraft.world.item.enchantment.Enchantments";
    private static final String REGISTRIES = "net.minecraft.core.registries.Registries";
    private static final String PROVIDER = "net.minecraft.core.HolderLookup$Provider";
    private static final String HOLDER_GETTER = "net.minecraft.core.HolderGetter";
    private static final String MOB_EFFECT = "net.minecraft.world.effect.MobEffect";
    private static final String MOB_EFFECTS = "net.minecraft.world.effect.MobEffects";

    private final Names names;
    private final Mc mc;
    private final Ref.Resolver r;
    private Object fake;
    private Object torches;

    SelfTest(Names names, Mc mc) {
        this.names = names;
        this.mc = mc;
        this.r = new Ref.Resolver(names);
    }

    @Override
    public List<Object> extraPlayers(Object server, int cycle) {
        try {
            if (cycle == 2) {
                fake = create(server);
                Log.info("[SELFTEST] cycle 2: added detached player " + NAME + " " + ID);
            } else if (cycle == 5 && fake != null) {
                r.method(Mc.STACK, "setCount", "int").invoke(torches, 7);
                Log.info("[SELFTEST] cycle 5: torches 32 -> 7");
            } else if (cycle == 7 && fake != null) {
                fake = null;
                Log.info("[SELFTEST] cycle 7: removed " + NAME);
            }
        } catch (Throwable t) {
            fake = null;
            Log.warn("[SELFTEST] FAIL at cycle " + cycle + ": " + t, t);
        }
        return fake == null ? List.of() : List.of(fake);
    }

    private Object create(Object server) throws Throwable {
        Object level = mc.overworld.invoke(server);
        Object profile = names.type(Mc.GAME_PROFILE).getConstructor(UUID.class, String.class).newInstance(ID, NAME);
        Ref modern = r.constructor(Mc.SERVER_PLAYER, Mc.SERVER, Mc.SERVER_LEVEL, Mc.GAME_PROFILE, CLIENT_INFO);
        Object player = modern.ok()
                ? modern.invoke(null, server, level, profile, r.method(CLIENT_INFO, "createDefault").invoke(null))
                : r.constructor(Mc.SERVER_PLAYER, Mc.SERVER, Mc.SERVER_LEVEL, Mc.GAME_PROFILE).invoke(null, server, level, profile);

        r.method(Mc.ENTITY, "setPos", "double", "double", "double").invoke(player, 12.5, 64.0, -7.25);
        r.method(Mc.LIVING, "setHealth", "float").invoke(player, 17.5f);

        Ref setItem = r.method(Mc.CONTAINER, "setItem", "int", Mc.STACK);
        Ref setDamage = r.method(Mc.STACK, "setDamageValue", "int");
        Object inventory = mc.inventory.invoke(player);

        Object sword = stack("DIAMOND_SWORD", 1);
        setDamage.invoke(sword, 25);
        name(sword, "Excalibur");
        enchant(server, sword);
        setItem.invoke(inventory, 0, sword);
        torches = stack("TORCH", 32);
        setItem.invoke(inventory, 1, torches);
        setItem.invoke(inventory, 9, stack("DIAMOND", 5));
        Object helmet = stack("IRON_HELMET", 1);
        setDamage.invoke(helmet, 10);
        setItem.invoke(inventory, 39, helmet);
        setItem.invoke(inventory, 40, stack("SHIELD", 1));
        setItem.invoke(mc.enderChest.invoke(player), 0, stack("DIAMOND", 12));

        addSpeed(player);
        return player;
    }

    private Object stack(String item, int count) throws Throwable {
        Object type = Ref.Resolver.staticValue(r.field(ITEMS, item));
        return r.constructor(Mc.STACK, ITEM_LIKE, "int").invoke(null, type, count);
    }

    private void name(Object stack, String text) throws Throwable {
        Object component = r.method(Mc.COMPONENT, "literal", "java.lang.String").invoke(null, text);
        Ref set = r.method(Mc.STACK, "set", Mc.COMPONENT_TYPE, "java.lang.Object");
        if (set.ok() && mc.customNameType != null) {
            set.invoke(stack, mc.customNameType, component);
        } else {
            r.method(Mc.STACK, "setHoverName", Mc.COMPONENT).invoke(stack, component);
        }
    }

    private void enchant(Object server, Object stack) throws Throwable {
        Object sharpness = Ref.Resolver.staticValue(r.field(ENCHANTMENTS, "SHARPNESS"));
        Ref legacy = r.method(Mc.STACK, "enchant", ENCHANTMENT, "int");
        if (legacy.ok()) {
            legacy.invoke(stack, sharpness, 3);
            return;
        }
        Object access = r.method(Mc.SERVER, "registryAccess").invoke(server);
        Object registry = Ref.Resolver.staticValue(r.field(REGISTRIES, "ENCHANTMENT"));
        Object lookup = r.method(PROVIDER, "lookupOrThrow", Mc.RESOURCE_KEY).invoke(access, registry);
        Object holder = r.method(HOLDER_GETTER, "getOrThrow", Mc.RESOURCE_KEY).invoke(lookup, sharpness);
        r.method(Mc.STACK, "enchant", Mc.HOLDER, "int").invoke(stack, holder, 3);
    }

    @SuppressWarnings("unchecked")
    private void addSpeed(Object player) throws Throwable {
        Object speed = Ref.Resolver.staticValue(r.field(MOB_EFFECTS, "SPEED"));
        if (speed == null) {
            speed = Ref.Resolver.staticValue(r.field(MOB_EFFECTS, "MOVEMENT_SPEED"));
        }
        Ref modern = r.constructor(Mc.EFFECT_INSTANCE, Mc.HOLDER, "int", "int");
        Ref legacy = r.constructor(Mc.EFFECT_INSTANCE, MOB_EFFECT, "int", "int");
        Object instance = (modern.ok() ? modern : legacy).invoke(null, speed, 600, 1);
        // LivingEntity#addEffect would notify the player's network connection, which a detached player lacks
        Map<Object, Object> active = (Map<Object, Object>) r.field(Mc.LIVING, "activeEffects").invoke(player);
        active.put(speed, instance);
    }
}
