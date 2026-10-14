package io.github.palmeany.mctoolkit.bridge;

import java.lang.reflect.Field;
import java.lang.reflect.Method;
import java.util.Arrays;
import java.util.concurrent.ConcurrentHashMap;
import org.bukkit.Bukkit;
import org.bukkit.Server;

/**
 * Cached reflection that never throws: every lookup answers {@code null} when the running server lacks the class or
 * member, and every invocation answers {@code null} when the call fails. This is how one jar compiled against the
 * 1.8.8 API reaches methods that only exist on newer servers (NamespacedKey, attributes, offhand, ping, Paper and
 * Folia API) without a {@link NoSuchMethodError} or {@link NoClassDefFoundError} ever escaping.
 */
final class Reflect {

    private static final Object MISSING = new Object();
    private static final ConcurrentHashMap<String, Object> TYPES = new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<String, Object> SERVER_TYPES = new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<Class<?>, ConcurrentHashMap<String, Object>> MEMBERS = new ConcurrentHashMap<>();

    private Reflect() {
    }

    /** A class visible to this plugin (the server's and its libraries' classes included), or null. */
    static Class<?> type(String name) {
        Object hit = TYPES.get(name);
        if (hit == null) {
            hit = load(name, Reflect.class.getClassLoader());
            if (hit == MISSING) {
                hit = load(name, serverLoader());
            }
            TYPES.put(name, hit);
        }
        return hit == MISSING ? null : (Class<?>) hit;
    }

    /**
     * A class of the server itself, looked up through the server's own class loader only, so that a class shaded
     * into some other plugin cannot fool platform detection.
     */
    static Class<?> serverType(String name) {
        ClassLoader loader = serverLoader();
        if (loader == null) {
            return null;
        }
        Object hit = SERVER_TYPES.get(name);
        if (hit == null) {
            hit = load(name, loader);
            SERVER_TYPES.put(name, hit);
        }
        return hit == MISSING ? null : (Class<?>) hit;
    }

    private static ClassLoader serverLoader() {
        Server server = Bukkit.getServer();
        return server == null ? null : server.getClass().getClassLoader();
    }

    private static Object load(String name, ClassLoader loader) {
        if (loader == null) {
            return MISSING;
        }
        try {
            return Class.forName(name, false, loader);
        } catch (ClassNotFoundException | LinkageError | SecurityException ignored) {
            return MISSING;
        }
    }

    /** A public method (declared or inherited) of {@code owner}, or null. */
    static Method method(Class<?> owner, String name, Class<?>... parameters) {
        if (owner == null) {
            return null;
        }
        ConcurrentHashMap<String, Object> members = members(owner);
        String key = "m:" + name + Arrays.toString(parameters);
        Object hit = members.get(key);
        if (hit == null) {
            hit = MISSING;
            try {
                Method method = owner.getMethod(name, parameters);
                accessible(method);
                hit = method;
            } catch (NoSuchMethodException | LinkageError | SecurityException ignored) {
                // stays MISSING
            }
            members.put(key, hit);
        }
        return hit == MISSING ? null : (Method) hit;
    }

    /** A field of {@code owner} or one of its superclasses, whatever its visibility, or null. */
    static Field field(Class<?> owner, String name) {
        if (owner == null) {
            return null;
        }
        ConcurrentHashMap<String, Object> members = members(owner);
        String key = "f:" + name;
        Object hit = members.get(key);
        if (hit == null) {
            hit = MISSING;
            for (Class<?> type = owner; type != null && hit == MISSING; type = type.getSuperclass()) {
                try {
                    Field field = type.getDeclaredField(name);
                    accessible(field);
                    hit = field;
                } catch (NoSuchFieldException | LinkageError | SecurityException ignored) {
                    // keep walking up
                }
            }
            members.put(key, hit);
        }
        return hit == MISSING ? null : (Field) hit;
    }

    /** The value of a public static field, or null. */
    static Object staticValue(Class<?> owner, String name) {
        if (owner == null) {
            return null;
        }
        try {
            return owner.getField(name).get(null);
        } catch (Exception | LinkageError ignored) {
            return null;
        }
    }

    /** Calls {@code method} and answers its result, or null when the method is missing or the call fails. */
    static Object invoke(Method method, Object target, Object... arguments) {
        if (method == null || (target == null && !java.lang.reflect.Modifier.isStatic(method.getModifiers()))) {
            return null;
        }
        try {
            return method.invoke(target, arguments);
        } catch (Exception | LinkageError ignored) {
            return null;
        }
    }

    /** Shorthand for a public no-argument method looked up on {@code owner} and called on {@code target}. */
    static Object call(Object target, Class<?> owner, String name) {
        return invoke(method(owner, name), target);
    }

    private static ConcurrentHashMap<String, Object> members(Class<?> owner) {
        ConcurrentHashMap<String, Object> members = MEMBERS.get(owner);
        if (members == null) {
            members = new ConcurrentHashMap<>();
            ConcurrentHashMap<String, Object> raced = MEMBERS.putIfAbsent(owner, members);
            if (raced != null) {
                members = raced;
            }
        }
        return members;
    }

    private static void accessible(java.lang.reflect.AccessibleObject member) {
        try {
            member.setAccessible(true);
        } catch (RuntimeException ignored) {
            // public members of public types work without it
        }
    }
}
