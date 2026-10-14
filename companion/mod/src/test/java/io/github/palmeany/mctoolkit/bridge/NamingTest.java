package io.github.palmeany.mctoolkit.bridge;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;
import java.nio.file.Path;
import org.junit.jupiter.api.Test;

class NamingTest {

    @Test
    void levelsAreNamedTheBukkitWay() {
        assertEquals("world", Collector.worldName("world", "minecraft:overworld"));
        assertEquals("world_nether", Collector.worldName("world", "minecraft:the_nether"));
        assertEquals("world_the_end", Collector.worldName("world", "minecraft:the_end"));
        assertEquals("survival_twilightforest_twilight_forest", Collector.worldName("survival", "twilightforest:twilight_forest"));
        assertEquals("world_mod_deep_caves", Collector.worldName("world", "mod:deep/caves"));
    }

    @Test
    void dimensionLabels() {
        assertEquals("overworld", Collector.dimensionLabel("minecraft:overworld"));
        assertEquals("nether", Collector.dimensionLabel("minecraft:the_nether"));
        assertEquals("end", Collector.dimensionLabel("minecraft:the_end"));
        assertEquals("twilightforest:twilight_forest", Collector.dimensionLabel("twilightforest:twilight_forest"));
    }

    @Test
    void bridgeDirDefaultsToToolkitInTheWorkingDirectory() {
        Path dir = Bridge.bridgeDir();
        assertTrue(dir.isAbsolute());
        assertEquals(".toolkit", dir.getFileName().toString());
    }

    @Test
    void nameTablesArePackagedAndCoverTheProbe() throws IOException {
        for (String table : new String[] {"names/intermediary.tsv", "names/srg.tsv"}) {
            boolean probe = false;
            try (InputStream in = Names.class.getResourceAsStream(table)) {
                assertTrue(in != null, table);
                BufferedReader reader = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8));
                for (String line; (line = reader.readLine()) != null; ) {
                    if (line.startsWith("#") || line.isEmpty()) {
                        continue;
                    }
                    String[] cols = line.split("\t");
                    assertTrue(cols[0].equals("C") && cols.length == 3 || cols[0].equals("F") && cols.length == 4
                            || cols[0].equals("M") && cols.length == 5, line);
                    probe |= line.startsWith("M\tnet.minecraft.server.MinecraftServer\tgetPlayerList\t()\t");
                }
            }
            assertTrue(probe, table + " lacks the namespace probe");
        }
        assertFalse(Bridge.VERSION.contains("$"), "bridge.properties is filtered");
    }
}
