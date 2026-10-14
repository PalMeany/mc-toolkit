package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.Collection;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.UUID;

/**
 * Every Minecraft member the bridge reads, resolved eagerly when the server is found (see
 * {@code tools/members.txt}), with null-safe typed accessors. Where Minecraft changed an API across
 * versions both shapes are resolved and the accessor uses whichever exists.
 */
final class Mc {

    static final String SERVER = Names.SERVER;
    static final String PLAYER_LIST = "net.minecraft.server.players.PlayerList";
    static final String NAME_AND_ID = "net.minecraft.server.players.NameAndId";
    static final String GAME_PROFILE = "com.mojang.authlib.GameProfile";
    static final String ENTITY = "net.minecraft.world.entity.Entity";
    static final String LIVING = "net.minecraft.world.entity.LivingEntity";
    static final String PLAYER = "net.minecraft.world.entity.player.Player";
    static final String SERVER_PLAYER = "net.minecraft.server.level.ServerPlayer";
    static final String GAME_MODE = "net.minecraft.server.level.ServerPlayerGameMode";
    static final String GAME_TYPE = "net.minecraft.world.level.GameType";
    static final String COMMON_LISTENER = "net.minecraft.server.network.ServerCommonPacketListenerImpl";
    static final String FOOD = "net.minecraft.world.food.FoodData";
    static final String CONTAINER = "net.minecraft.world.Container";
    static final String STACK = "net.minecraft.world.item.ItemStack";
    static final String COMPONENT = "net.minecraft.network.chat.Component";
    static final String COMPONENT_HOLDER = "net.minecraft.core.component.DataComponentHolder";
    static final String COMPONENT_TYPE = "net.minecraft.core.component.DataComponentType";
    static final String COMPONENTS = "net.minecraft.core.component.DataComponents";
    static final String BUILT_IN = "net.minecraft.core.registries.BuiltInRegistries";
    static final String REGISTRY = "net.minecraft.core.Registry";
    static final String HOLDER = "net.minecraft.core.Holder";
    static final String EFFECT_INSTANCE = "net.minecraft.world.effect.MobEffectInstance";
    static final String RESOURCE_KEY = "net.minecraft.resources.ResourceKey";
    static final String LEVEL = "net.minecraft.world.level.Level";
    static final String SERVER_LEVEL = "net.minecraft.server.level.ServerLevel";
    static final String CHUNK_CACHE = "net.minecraft.server.level.ServerChunkCache";
    static final String STORAGE_ACCESS = "net.minecraft.world.level.storage.LevelStorageSource$LevelStorageAccess";

    final Ref.Resolver resolver;

    // server
    final Ref playerList;
    final Ref allLevels;
    final Ref tickCount;
    final Ref averageTickNanos;
    final Ref averageTickMillis;
    final Ref serverVersion;
    final Ref running;
    final Ref ready;
    final Ref runningThread;
    final Ref overworld;
    final Ref emptyTicks;
    final Ref pauseWhileEmpty;
    final Ref pauseWhenEmpty;
    final Ref storageSource;
    final Ref levelId;

    // players
    final Ref players;
    final Ref isOpProfile;
    final Ref isOpNameAndId;
    final Ref gameProfile;
    final Ref nameAndId;
    final Ref scoreboardName;
    final Ref uuid;
    final Ref x;
    final Ref y;
    final Ref z;
    final Ref level;
    final Ref entityType;
    final Ref gameModeField;
    final Ref gameModeType;
    final Ref gameTypeName;
    final Ref connection;
    final Ref latencyField;
    final Ref latencyMethod;
    final Ref health;
    final Ref maxHealth;
    final Ref activeEffects;
    final Ref foodData;
    final Ref foodLevel;
    final Ref saturation;
    final Ref experienceLevel;
    final Ref experienceProgress;
    final Ref inventory;
    final Ref enderChest;

    // items
    final Ref containerSize;
    final Ref containerItem;
    final Ref stackEmpty;
    final Ref stackCount;
    final Ref stackItem;
    final Ref stackEnchanted;
    final Ref stackDamageable;
    final Ref stackDamage;
    final Ref stackMaxDamage;
    final Ref stackCustomName;
    final Ref componentGet;
    final Ref customNameField;
    final Ref stackHasCustomHoverName;
    final Ref stackHoverName;
    final Ref componentString;

    // registries, effects, keys
    final Ref itemRegistryField;
    final Ref entityTypeRegistryField;
    final Ref effectRegistryField;
    final Ref registryKey;
    final Ref effect;
    final Ref amplifier;
    final Ref duration;
    final Ref holderValue;
    final Ref keyLocation;
    final Ref keyIdentifier;

    // levels
    final Ref dimension;
    final Ref dayTime;
    final Ref overworldClockTime;
    final Ref chunkSource;
    final Ref loadedChunks;
    final Ref allEntities;

    // static values read once
    final Object itemRegistry;
    final Object entityTypeRegistry;
    final Object effectRegistry;
    final Object customNameType;
    final Class<?> holderClass;
    final Class<?> nameAndIdClass;

    Mc(Names names) {
        Ref.Resolver r = new Ref.Resolver(names);
        resolver = r;

        playerList = r.method(SERVER, "getPlayerList");
        allLevels = r.method(SERVER, "getAllLevels");
        tickCount = r.method(SERVER, "getTickCount");
        averageTickNanos = r.method(SERVER, "getAverageTickTimeNanos");
        averageTickMillis = r.method(SERVER, "getAverageTickTime");
        serverVersion = r.method(SERVER, "getServerVersion");
        running = r.method(SERVER, "isRunning");
        ready = r.method(SERVER, "isReady");
        runningThread = r.method(SERVER, "getRunningThread");
        overworld = r.method(SERVER, "overworld");
        emptyTicks = r.field(SERVER, "emptyTicks");
        pauseWhileEmpty = r.method(SERVER, "pauseWhileEmptySeconds");
        pauseWhenEmpty = r.method(SERVER, "pauseWhenEmptySeconds");
        storageSource = r.field(SERVER, "storageSource");
        levelId = r.method(STORAGE_ACCESS, "getLevelId");

        players = r.method(PLAYER_LIST, "getPlayers");
        isOpProfile = r.method(PLAYER_LIST, "isOp", GAME_PROFILE);
        isOpNameAndId = r.method(PLAYER_LIST, "isOp", NAME_AND_ID);
        gameProfile = r.method(PLAYER, "getGameProfile");
        nameAndId = r.method(PLAYER, "nameAndId");
        scoreboardName = r.method(ENTITY, "getScoreboardName");
        uuid = r.method(ENTITY, "getUUID");
        x = r.method(ENTITY, "getX");
        y = r.method(ENTITY, "getY");
        z = r.method(ENTITY, "getZ");
        level = r.method(ENTITY, "level");
        entityType = r.method(ENTITY, "getType");
        gameModeField = r.field(SERVER_PLAYER, "gameMode");
        gameModeType = r.method(GAME_MODE, "getGameModeForPlayer");
        gameTypeName = r.method(GAME_TYPE, "getName");
        connection = r.field(SERVER_PLAYER, "connection");
        latencyField = r.field(SERVER_PLAYER, "latency");
        latencyMethod = r.method(COMMON_LISTENER, "latency");
        health = r.method(LIVING, "getHealth");
        maxHealth = r.method(LIVING, "getMaxHealth");
        activeEffects = r.method(LIVING, "getActiveEffects");
        foodData = r.method(PLAYER, "getFoodData");
        foodLevel = r.method(FOOD, "getFoodLevel");
        saturation = r.method(FOOD, "getSaturationLevel");
        experienceLevel = r.field(PLAYER, "experienceLevel");
        experienceProgress = r.field(PLAYER, "experienceProgress");
        inventory = r.method(PLAYER, "getInventory");
        enderChest = r.method(PLAYER, "getEnderChestInventory");

        containerSize = r.method(CONTAINER, "getContainerSize");
        containerItem = r.method(CONTAINER, "getItem", "int");
        stackEmpty = r.method(STACK, "isEmpty");
        stackCount = r.method(STACK, "getCount");
        stackItem = r.method(STACK, "getItem");
        stackEnchanted = r.method(STACK, "isEnchanted");
        stackDamageable = r.method(STACK, "isDamageableItem");
        stackDamage = r.method(STACK, "getDamageValue");
        stackMaxDamage = r.method(STACK, "getMaxDamage");
        stackCustomName = r.method(STACK, "getCustomName");
        componentGet = r.method(COMPONENT_HOLDER, "get", COMPONENT_TYPE);
        customNameField = r.field(COMPONENTS, "CUSTOM_NAME");
        stackHasCustomHoverName = r.method(STACK, "hasCustomHoverName");
        stackHoverName = r.method(STACK, "getHoverName");
        componentString = r.method(COMPONENT, "getString");

        itemRegistryField = r.field(BUILT_IN, "ITEM");
        entityTypeRegistryField = r.field(BUILT_IN, "ENTITY_TYPE");
        effectRegistryField = r.field(BUILT_IN, "MOB_EFFECT");
        registryKey = r.method(REGISTRY, "getKey", "java.lang.Object");
        effect = r.method(EFFECT_INSTANCE, "getEffect");
        amplifier = r.method(EFFECT_INSTANCE, "getAmplifier");
        duration = r.method(EFFECT_INSTANCE, "getDuration");
        holderValue = r.method(HOLDER, "value");
        keyLocation = r.method(RESOURCE_KEY, "location");
        keyIdentifier = r.method(RESOURCE_KEY, "identifier");

        dimension = r.method(LEVEL, "dimension");
        dayTime = r.method(LEVEL, "getDayTime");
        overworldClockTime = r.method(LEVEL, "getOverworldClockTime");
        chunkSource = r.method(SERVER_LEVEL, "getChunkSource");
        loadedChunks = r.method(CHUNK_CACHE, "getLoadedChunksCount");
        allEntities = r.method(SERVER_LEVEL, "getAllEntities");

        itemRegistry = Ref.Resolver.staticValue(itemRegistryField);
        entityTypeRegistry = Ref.Resolver.staticValue(entityTypeRegistryField);
        effectRegistry = Ref.Resolver.staticValue(effectRegistryField);
        customNameType = Ref.Resolver.staticValue(customNameField);
        holderClass = names.type(HOLDER);
        nameAndIdClass = names.type(NAME_AND_ID);
    }

    // ------------------------------------------------------------------ capability summary

    /** Feature name -> available, in log order. Each feature lists the members it needs (alternatives with "or"). */
    Map<String, Boolean> features() {
        Map<String, Boolean> f = new LinkedHashMap<>();
        f.put("players", all(playerList, players, uuid));
        f.put("names", all(scoreboardName));
        f.put("position", all(x, y, z));
        f.put("dimension", all(level, dimension) && any(keyLocation, keyIdentifier));
        f.put("game mode", all(gameModeField, gameModeType, gameTypeName));
        f.put("health", all(health, maxHealth));
        f.put("food", all(foodData, foodLevel, saturation));
        f.put("experience", all(experienceLevel, experienceProgress));
        f.put("ping", latencyField.ok() || all(connection, latencyMethod));
        f.put("op", all(isOpNameAndId, nameAndId) || all(isOpProfile, gameProfile));
        f.put("inventory", all(inventory, containerSize, containerItem, stackEmpty, stackCount, stackItem, registryKey) && itemRegistry != null);
        f.put("ender chest", all(enderChest, containerSize, containerItem));
        f.put("item names", componentString.ok() && (stackCustomName.ok() || (componentGet.ok() && customNameType != null)
                || all(stackHasCustomHoverName, stackHoverName)));
        f.put("enchanted", all(stackEnchanted));
        f.put("durability", all(stackDamageable, stackDamage, stackMaxDamage));
        f.put("effects", all(activeEffects, effect, amplifier, duration, registryKey) && effectRegistry != null);
        f.put("tps", all(tickCount));
        // 1.21.2+ pauses an empty server; both members or neither (older versions never pause)
        f.put("pause detection", emptyTicks.ok() == any(pauseWhileEmpty, pauseWhenEmpty));
        f.put("mspt", any(averageTickNanos, averageTickMillis));
        f.put("levels", all(allLevels));
        f.put("chunks", all(chunkSource, loadedChunks));
        f.put("entities", all(allEntities, entityType, registryKey) && entityTypeRegistry != null);
        f.put("day time", overworld.ok() && any(dayTime, overworldClockTime));
        f.put("level name", all(storageSource, levelId));
        f.put("lifecycle", all(running, ready, runningThread));
        return f;
    }

    /** Members that did not resolve (most are the other side of a version alternative). */
    List<String> unresolved() {
        List<String> out = new ArrayList<>();
        for (Ref ref : resolver.all) {
            if (!ref.ok()) {
                out.add(ref.key);
            }
        }
        return out;
    }

    private static boolean all(Ref... refs) {
        for (Ref r : refs) {
            if (!r.ok()) {
                return false;
            }
        }
        return true;
    }

    private static boolean any(Ref... refs) {
        for (Ref r : refs) {
            if (r.ok()) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------ server

    boolean isRunning(Object server) {
        Object v = running.get(server);
        return !(v instanceof Boolean b) || b;
    }

    /** Null when unknown (member missing). */
    Boolean isReady(Object server) {
        Object v = ready.get(server);
        return v instanceof Boolean b ? b : null;
    }

    Thread serverThread(Object server) {
        Object v = runningThread.get(server);
        return v instanceof Thread t ? t : null;
    }

    Integer tickCount(Object server) {
        return asInt(tickCount.get(server));
    }

    /**
     * Whether an empty server is paused (1.21.2+, {@code pause-when-empty-seconds}): it then skips
     * whole ticks, so its tick counter stands still although nothing is wrong. Plain int reads.
     */
    boolean pausedWhenEmpty(Object server) {
        Integer empty = asInt(emptyTicks.get(server));
        if (empty == null) {
            return false;
        }
        Integer seconds = asInt(pauseWhenEmpty.ok() ? pauseWhenEmpty.get(server) : pauseWhileEmpty.get(server));
        return seconds != null && seconds > 0 && empty >= seconds * 20;
    }

    /** Average milliseconds per tick (last 100 ticks), null when unknown. */
    Double mspt(Object server) {
        Object nanos = averageTickNanos.get(server);
        if (nanos instanceof Number n) {
            return n.longValue() / 1_000_000.0;
        }
        Object millis = averageTickMillis.get(server);
        return millis instanceof Number n ? n.doubleValue() : null;
    }

    String minecraftVersion(Object server) {
        Object v = serverVersion.get(server);
        return v instanceof String s && !s.isBlank() ? s : null;
    }

    String levelFolder(Object server) {
        Object storage = storageSource.get(server);
        Object id = storage == null ? null : levelId.get(storage);
        return id instanceof String s && !s.isBlank() ? s : null;
    }

    List<Object> levels(Object server) {
        List<Object> out = new ArrayList<>();
        if (allLevels.get(server) instanceof Iterable<?> it) {
            for (Object level : it) {
                out.add(level);
            }
        }
        return out;
    }

    List<Object> onlinePlayers(Object server) {
        Object list = playerList.get(server);
        Object players = list == null ? null : this.players.get(list);
        return players instanceof Collection<?> c ? new ArrayList<>(c) : new ArrayList<>();
    }

    /** Overworld day time in ticks, 0..23999. */
    Long dayTime(Object server) {
        Object overworld = this.overworld.get(server);
        if (overworld == null) {
            return null;
        }
        Object time = dayTime.ok() ? dayTime.get(overworld) : overworldClockTime.get(overworld);
        return time instanceof Number n ? Math.floorMod(n.longValue(), 24000L) : null;
    }

    // ------------------------------------------------------------------ levels

    /** Dimension id of a level ("minecraft:the_nether"), null when unknown. */
    String dimensionId(Object level) {
        Object key = level == null ? null : dimension.get(level);
        if (key == null) {
            return null;
        }
        Object location = keyLocation.ok() ? keyLocation.get(key) : keyIdentifier.get(key);
        if (location != null) {
            return location.toString();
        }
        // ResourceKey#toString is "ResourceKey[minecraft:dimension / minecraft:overworld]" in every version
        String text = key.toString();
        int slash = text.lastIndexOf(" / ");
        return slash >= 0 && text.endsWith("]") ? text.substring(slash + 3, text.length() - 1) : null;
    }

    Integer loadedChunks(Object level) {
        Object cache = chunkSource.get(level);
        return cache == null ? null : asInt(loadedChunks.get(cache));
    }

    Iterable<?> entities(Object level) {
        return allEntities.get(level) instanceof Iterable<?> it ? it : null;
    }

    // ------------------------------------------------------------------ entities and players

    UUID uuid(Object entity) {
        return uuid.get(entity) instanceof UUID u ? u : null;
    }

    String name(Object player) {
        return scoreboardName.get(player) instanceof String s ? s : null;
    }

    Object level(Object entity) {
        return level.get(entity);
    }

    Double x(Object entity) {
        return asDouble(x.get(entity));
    }

    Double y(Object entity) {
        return asDouble(y.get(entity));
    }

    Double z(Object entity) {
        return asDouble(z.get(entity));
    }

    /** survival | creative | adventure | spectator */
    String gameMode(Object player) {
        Object manager = gameModeField.get(player);
        Object type = manager == null ? null : gameModeType.get(manager);
        if (type == null) {
            return null;
        }
        if (gameTypeName.get(type) instanceof String s) {
            return s;
        }
        return type instanceof Enum<?> e ? e.name().toLowerCase(Locale.ROOT) : null;
    }

    /** Latency in ms; null without a network connection (or when the platform hides it). */
    Integer ping(Object player) {
        if (latencyField.ok()) {
            return player != null && connection.ok() && connection.get(player) == null ? null : asInt(latencyField.get(player));
        }
        Object conn = connection.get(player);
        return conn == null ? null : asInt(latencyMethod.get(conn));
    }

    Boolean op(Object server, Object player) {
        Object list = playerList.get(server);
        if (list == null) {
            return null;
        }
        if (isOpNameAndId.ok() && nameAndId.ok()) {
            Object id = nameAndId.get(player);
            if (id != null) {
                return asBool(isOpNameAndId.call(list, id));
            }
        }
        if (isOpProfile.ok() && gameProfile.ok()) {
            Object profile = gameProfile.get(player);
            if (profile != null) {
                return asBool(isOpProfile.call(list, profile));
            }
        }
        return null;
    }

    Double health(Object player) {
        return asDouble(health.get(player));
    }

    Double maxHealth(Object player) {
        return asDouble(maxHealth.get(player));
    }

    Integer food(Object player) {
        Object data = foodData.get(player);
        return data == null ? null : asInt(foodLevel.get(data));
    }

    Double saturation(Object player) {
        Object data = foodData.get(player);
        return data == null ? null : asDouble(saturation.get(data));
    }

    Integer xpLevel(Object player) {
        return asInt(experienceLevel.get(player));
    }

    Double xpProgress(Object player) {
        return asDouble(experienceProgress.get(player));
    }

    String entityTypeId(Object type) {
        return registryId(entityTypeRegistry, type);
    }

    // ------------------------------------------------------------------ items and effects

    Integer containerSize(Object container) {
        return container == null ? null : asInt(containerSize.get(container));
    }

    Object containerItem(Object container, int slot) {
        return containerItem.call(container, slot);
    }

    /** Null for empty stacks. */
    Snapshot.Item item(Object stack, int slot) {
        if (stack == null || Boolean.TRUE.equals(stackEmpty.get(stack))) {
            return null;
        }
        Snapshot.Item item = new Snapshot.Item();
        item.slot = slot;
        item.id = registryId(itemRegistry, stackItem.get(stack));
        if (item.id == null) {
            return null;
        }
        Integer count = asInt(stackCount.get(stack));
        item.count = count == null ? 1 : count;
        item.name = customName(stack);
        item.enchanted = Boolean.TRUE.equals(stackEnchanted.get(stack));
        if (Boolean.TRUE.equals(stackDamageable.get(stack))) {
            item.damage = asInt(stackDamage.get(stack));
            item.maxDamage = asInt(stackMaxDamage.get(stack));
        }
        return item;
    }

    /** Plain-text custom name of a stack, null when it has none. */
    String customName(Object stack) {
        Object component = null;
        if (stackCustomName.ok()) {
            component = stackCustomName.get(stack);
        } else if (componentGet.ok() && customNameType != null) {
            component = componentGet.call(stack, customNameType);
        } else if (Boolean.TRUE.equals(stackHasCustomHoverName.get(stack))) {
            component = stackHoverName.get(stack);
        }
        return component == null ? null : componentString.get(component) instanceof String s ? s : null;
    }

    Collection<?> effects(Object entity) {
        return activeEffects.get(entity) instanceof Collection<?> c ? c : null;
    }

    Snapshot.Effect effect(Object instance) {
        Object type = effect.get(instance);
        if (type != null && holderClass != null && holderClass.isInstance(type)) {
            type = holderValue.get(type);
        }
        String id = registryId(effectRegistry, type);
        if (id == null) {
            return null;
        }
        Snapshot.Effect e = new Snapshot.Effect();
        e.id = id;
        Integer amp = asInt(amplifier.get(instance));
        Integer dur = asInt(duration.get(instance));
        e.amplifier = amp == null ? 0 : amp;
        e.duration = dur == null ? 0 : dur;
        return e;
    }

    private String registryId(Object registry, Object value) {
        if (registry == null || value == null) {
            return null;
        }
        Object key = registryKey.call(registry, value);
        return key == null ? null : key.toString();
    }

    // ------------------------------------------------------------------ conversions

    static Integer asInt(Object v) {
        return v instanceof Number n ? n.intValue() : null;
    }

    static Double asDouble(Object v) {
        return v instanceof Number n ? n.doubleValue() : null;
    }

    static Boolean asBool(Object v) {
        return v instanceof Boolean b ? b : null;
    }
}
