package io.github.palmeany.mctoolkit.bridge.selftest;

import java.util.UUID;
import org.bukkit.Location;
import org.bukkit.entity.Player;
import org.bukkit.inventory.ItemStack;

/** The player the self-test pushes through the bridge: a Proxy fake, or (1.8) a real unconnected server player. */
interface Subject {

    Player player();

    UUID id();

    Location location();

    /** Gives the player the test kit: sword (slot 0), cobblestone (slot 9), helmet, boots, offhand, ender pearls, speed. */
    void equip(ItemStack sword, ItemStack cobblestone, ItemStack helmet, ItemStack boots, ItemStack offhand,
            ItemStack pearls);

    void setSlot(int slot, ItemStack item);

    void moveX(double blocks);

    /** The ping the bridge should report for this subject. */
    Object expectedPing();

    /** Max health the bridge should report. */
    double expectedMaxHealth();
}
