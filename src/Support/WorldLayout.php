<?php

declare(strict_types=1);

namespace McToolkit\Support;

/**
 * On-disk world layouts the toolkit reads, kept in one place so the Worlds listing, the
 * map and the fallbacks agree on where a dimension lives.
 *
 * Classic layout (Minecraft up to 1.21.x):
 *
 *     world/level.dat, world/region, world/playerdata, world/stats, world/advancements
 *     world/DIM-1/region, world/DIM1/region          vanilla / Fabric / Forge
 *     world_nether/DIM-1/region, world_the_end/DIM1/region   Bukkit / Paper split worlds
 *
 * "Dimensions" layout (Minecraft 26.1+, Paper/Folia 26.x included) — ONE level folder,
 * no `_nether` / `_the_end` siblings any more:
 *
 *     world/level.dat, world/data/minecraft/*.dat (global saved data), world/datapacks
 *     world/dimensions/<namespace>/<path>/{region,entities,poi,data}
 *         minecraft/overworld, minecraft/the_nether, minecraft/the_end, datapack dims
 *     world/players/{data,stats,advancements}
 *
 * level.dat itself shrank in 26.1: the seed (`WorldGenSettings`), game rules, weather and
 * the world border moved into saved-data files, and `hardcore` moved under
 * `difficulty_settings` (see {@see \McToolkit\Services\Nbt\NbtReader}).
 */
final class WorldLayout
{
    /** Folder inside a level folder that marks the 26.1+ layout. */
    public const DIMENSIONS_DIR = 'dimensions';

    /** Namespace folder holding the vanilla dimensions, relative to the level folder. */
    public const VANILLA_NAMESPACE_DIR = 'dimensions/minecraft';

    /** Map dimension id => vanilla dimension folder under {@see self::VANILLA_NAMESPACE_DIR}. */
    public const VANILLA_DIMENSIONS = [
        'overworld' => 'overworld',
        'nether' => 'the_nether',
        'end' => 'the_end',
    ];

    /**
     * Region folders per map dimension, relative to the level folder, tried in order.
     * The 26.1+ location leads: a folder that has it is written by a 26.x server, so any
     * classic folder next to it is a pre-upgrade leftover. The plain `region` fallback of
     * nether/end covers forks that flatten Bukkit split worlds (`world_nether/region`).
     */
    public const REGION_CANDIDATES = [
        'overworld' => ['dimensions/minecraft/overworld/region', 'region'],
        'nether' => ['dimensions/minecraft/the_nether/region', 'DIM-1/region', 'region'],
        'end' => ['dimensions/minecraft/the_end/region', 'DIM1/region', 'region'],
    ];

    /**
     * Where 26.1+ keeps the world-gen settings (seed) that left level.dat, relative to the
     * level folder: Paper/Folia write one copy per dimension (verified on a Paper 26.3
     * world), vanilla keeps world-wide saved data under `data/minecraft`.
     */
    public const WORLD_GEN_SETTINGS_FILES = [
        'dimensions/minecraft/overworld/data/minecraft/world_gen_settings.dat',
        'data/minecraft/world_gen_settings.dat',
    ];
}
