<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Minecraft Toolkit — map markers per world
|--------------------------------------------------------------------------
| Markers used to be keyed by dimension only, so two overworld-type worlds of
| one server (e.g. `world` and `lobby`) shared every marker. The nullable
| `world` column records the world folder a marker belongs to; existing rows
| keep NULL and stay visible on every world of their dimension.
*/

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mc_map_markers') || Schema::hasColumn('mc_map_markers', 'world')) {
            return;
        }

        Schema::table('mc_map_markers', function (Blueprint $table) {
            // 191 chars keeps an index on (server_id, world) inside utf8mb4 key limits.
            $table->string('world', 191)->nullable()->after('dimension');

            $table->index(['server_id', 'world']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mc_map_markers') || ! Schema::hasColumn('mc_map_markers', 'world')) {
            return;
        }

        Schema::table('mc_map_markers', function (Blueprint $table) {
            $table->dropIndex(['server_id', 'world']);
            $table->dropColumn('world');
        });
    }
};
