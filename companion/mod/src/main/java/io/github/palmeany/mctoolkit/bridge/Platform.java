package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Method;
import java.lang.reflect.Modifier;
import java.util.ArrayDeque;
import java.util.Arrays;
import java.util.Deque;
import java.util.HashSet;
import java.util.Optional;
import java.util.Set;

/**
 * What the bridge needs from the mod loader, all reached by reflection so the jar has no compile
 * dependency on any loader: the running server, the loader's name/version, and whether this is a
 * dedicated server.
 */
final class Platform {

    private interface Lookup {
        Object get() throws Exception;
    }

    /** fabric | quilt | neoforge | forge */
    final String id;
    private final Lookup server;
    private final Lookup software;

    private Platform(String id, Lookup server, Lookup software) {
        this.id = id;
        this.server = server;
        this.software = software;
    }

    // ------------------------------------------------------------------ per loader

    /** Fabric, or Quilt (which runs Fabric mods through the same API). */
    static Platform fabric() {
        boolean quilt = present("org.quiltmc.loader.api.QuiltLoader");
        Lookup loader = () -> invokeStatic("net.fabricmc.loader.api.FabricLoader", "getInstance");
        Lookup server = () -> call(loader.get(), "getGameInstance");
        Lookup software = () -> {
            String label = quilt ? "Quilt Loader" : "Fabric Loader";
            for (String modId : quilt ? new String[] {"quilt_loader", "fabricloader"} : new String[] {"fabricloader"}) {
                Object container = unwrap(call(loader.get(), "getModContainer", modId));
                if (container != null) {
                    Object version = call(call(container, "getMetadata"), "getVersion");
                    return label + " " + call(version, "getFriendlyString");
                }
            }
            return label;
        };
        return new Platform(quilt ? "quilt" : "fabric", server, software);
    }

    static Platform neoforge() {
        return new Platform("neoforge",
                () -> invokeStatic("net.neoforged.neoforge.server.ServerLifecycleHooks", "getCurrentServer"),
                () -> "NeoForge " + modVersion("net.neoforged.fml.ModList", "neoforge"));
    }

    /** Forge; NeoForge for 1.20.1 still uses Forge's packages and is told apart by its Maven group. */
    static Platform forge() {
        boolean neo = false;
        try {
            neo = String.valueOf(invokeStatic("net.minecraftforge.versions.forge.ForgeVersion", "getGroup")).contains("neoforged");
        } catch (Exception ignored) {
            // plain Forge
        }
        String label = neo ? "NeoForge" : "Forge";
        return new Platform(neo ? "neoforge" : "forge",
                () -> invokeStatic("net.minecraftforge.server.ServerLifecycleHooks", "getCurrentServer"),
                () -> {
                    try {
                        return label + " " + invokeStatic("net.minecraftforge.versions.forge.ForgeVersion", "getVersion");
                    } catch (Exception e) {
                        return label + " " + modVersion("net.minecraftforge.fml.ModList", "forge");
                    }
                });
    }

    /**
     * True only on a dedicated server: reads FML's {@code FMLEnvironment.dist} (field or getter,
     * depending on the FML version); when FML cannot answer, a client is recognised by its main class.
     */
    static boolean isDedicatedServer(String fmlEnvironment) {
        try {
            Class<?> env = Class.forName(fmlEnvironment, true, Platform.class.getClassLoader());
            Object dist = null;
            try {
                dist = env.getField("dist").get(null);
            } catch (NoSuchFieldException noField) {
                dist = env.getMethod("getDist").invoke(null);
            }
            if (dist != null) {
                return "DEDICATED_SERVER".equals(dist.toString());
            }
        } catch (Throwable ignored) {
            // fall through to the class check
        }
        return Platform.class.getClassLoader().getResource("net/minecraft/client/main/Main.class") == null;
    }

    // ------------------------------------------------------------------ accessors

    /** The running MinecraftServer, or null while the loader has none yet. */
    Object server() {
        try {
            return server.get();
        } catch (Throwable t) {
            Log.warnOnce("server-lookup", "Could not ask " + id + " for the running server (" + t + ")", null);
            return null;
        }
    }

    String software() {
        try {
            Object s = software.get();
            return s == null ? id : s.toString();
        } catch (Throwable t) {
            return id;
        }
    }

    // ------------------------------------------------------------------ reflection helpers

    private static boolean present(String className) {
        try {
            Class.forName(className, false, Platform.class.getClassLoader());
            return true;
        } catch (Throwable t) {
            return false;
        }
    }

    /**
     * {@code ModList.get().getModContainerById(id).get().getModInfo().getVersion()}; Forge 26.x made
     * the ModList methods static (no {@code get()}).
     */
    private static String modVersion(String modListClass, String modId) throws Exception {
        Class<?> type = Class.forName(modListClass, true, Platform.class.getClassLoader());
        Object list = null;
        try {
            list = type.getMethod("get").invoke(null);
        } catch (NoSuchMethodException staticApi) {
            // static ModList
        }
        Object container = unwrap(type.getMethod("getModContainerById", String.class).invoke(list, modId));
        return container == null ? "" : String.valueOf(call(call(container, "getModInfo"), "getVersion"));
    }

    private static Object invokeStatic(String className, String method) throws Exception {
        Class<?> type = Class.forName(className, true, Platform.class.getClassLoader());
        return type.getMethod(method).invoke(null);
    }

    private static Object unwrap(Object maybeOptional) {
        return maybeOptional instanceof Optional<?> o ? o.orElse(null) : maybeOptional;
    }

    /** Calls a public method through a public declaring type (implementation classes may be hidden). */
    static Object call(Object target, String name, Object... args) throws Exception {
        if (target == null) {
            return null;
        }
        Method method = publicMethod(target.getClass(), name, args.length);
        if (method == null) {
            throw new NoSuchMethodException(target.getClass().getName() + "#" + name);
        }
        return method.invoke(target, args);
    }

    private static Method publicMethod(Class<?> type, String name, int arity) {
        Deque<Class<?>> queue = new ArrayDeque<>();
        Set<Class<?>> seen = new HashSet<>();
        queue.add(type);
        while (!queue.isEmpty()) {
            Class<?> c = queue.poll();
            if (!seen.add(c)) {
                continue;
            }
            if (Modifier.isPublic(c.getModifiers())) {
                for (Method m : c.getMethods()) {
                    if (m.getName().equals(name) && m.getParameterCount() == arity
                            && Modifier.isPublic(m.getDeclaringClass().getModifiers())) {
                        return m;
                    }
                }
            }
            if (c.getSuperclass() != null) {
                queue.add(c.getSuperclass());
            }
            queue.addAll(Arrays.asList(c.getInterfaces()));
        }
        return null;
    }
}
