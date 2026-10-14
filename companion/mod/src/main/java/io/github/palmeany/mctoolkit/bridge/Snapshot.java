package io.github.palmeany.mctoolkit.bridge;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;

/** Plain data copied out of the game on the server thread; serialised on the bridge thread. */
final class Snapshot {

    Double mspt;
    Long dayTime;
    final List<Player> players = new ArrayList<>();
    final List<World> worlds = new ArrayList<>();

    static final class Player {
        String uuid;
        String name;
        String mode;
        String world;
        String dimension;
        Double x;
        Double y;
        Double z;
        Double health;
        Double maxHealth;
        Integer food;
        Double saturation;
        Integer level;
        Double exp;
        Integer ping;
        Boolean op;

        // detail file; null lists = the platform cannot read them
        List<Item> inventory;
        Item head;
        Item chest;
        Item legs;
        Item feet;
        Item offhand;
        boolean equipmentKnown;
        List<Item> enderChest;
        List<Effect> effects;
    }

    static final class Item {
        int slot = -1;
        String id;
        int count;
        String name;
        boolean enchanted;
        Integer damage;
        Integer maxDamage;
    }

    static final class Effect {
        String id;
        int amplifier;
        int duration;
    }

    static final class World {
        String name;
        String dimension;
        int players;
        Integer chunks;
        Integer entityCount;
        /** At most 10 most common entity types, most common first; null when unknown. */
        LinkedHashMap<String, Integer> topEntities;
    }
}
