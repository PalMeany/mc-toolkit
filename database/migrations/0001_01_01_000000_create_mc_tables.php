<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Minecraft Toolkit — v1 tables
|--------------------------------------------------------------------------
| - mc_installed_content: one row per toolkit-tracked content file (jar/pack)
|   keyed on (server_id, file_name).
| - mc_map_markers: user-drawn map pins per server.
|
| server_id is an unsigned INT (servers.id is `increments`, MySQL/MariaDB reject a
| BIGINT foreign key onto it) and keeps an index plus a real FK with cascadeOnDelete so rows die with
| their server (mirrors the panel's own pivot migrations, e.g.
| database/migrations/2026_01_16_081858_create_backup_hosts_table.php). No
| migrations touch core tables in v1.
*/

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // v1.0.0-1.0.3 declared server_id as BIGINT, which MySQL/MariaDB refuse to point at
        // servers.id (INT UNSIGNED). DDL is not transactional there, so the failed run left
        // mc_installed_content behind without being recorded as migrated. This migration has
        // not run when we get here, so whatever exists under these names is that leftover.
        Schema::dropIfExists('mc_map_markers');
        Schema::dropIfExists('mc_installed_content');

        Schema::create('mc_installed_content', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            // plugin | mod | modpack (constants live on McToolkit\Models\InstalledContent)
            $table->string('kind', 32);

            // modrinth | hangar | spigot | curseforge | mcjars — null for side-loaded imports
            $table->string('registry', 32)->nullable();

            // Upstream project identifier or slug
            $table->string('project_id', 64);
            $table->string('file_name', 255);
            $table->string('version', 64)->nullable();

            // Resolved dependency list from the registry metadata
            $table->json('dependencies')->nullable();

            // Upstream file checksum when the source provides one
            $table->string('checksum', 64)->nullable();

            // True when the row survives as a tracked install; false = observation only
            $table->boolean('tracked_by_toolkit')->default(true);

            $table->timestamps();

            $table->unique(['server_id', 'file_name']);
        });

        Schema::create('mc_map_markers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->string('label', 255);
            $table->integer('x');
            $table->integer('z');

            // overworld | nether | end (kept short; custom dimensions squeeze into 32 chars too)
            $table->string('dimension', 32);

            // Optional hex pin color like "#RRGGBB"
            $table->string('color', 7)->nullable();

            $table->timestamps();

            $table->index(['server_id', 'dimension']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mc_map_markers');
        Schema::dropIfExists('mc_installed_content');
    }
};
