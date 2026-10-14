# Minecraft Toolkit

Nine toolkit pages for **Pelican Panel 1.0.0-rc.1**, rebuilt as a first-party plugin on top of
the panel's native plugin system and Filament design system.

> Versions, plugins, mods, modpacks, properties, players, worlds, map and performance for Minecraft
> servers — with a filesystem-based detection banner and translated activity logs.

---

## Requirements

| Requirement | Notes |
| --- | --- |
| Pelican Panel `^1.0.0` | tested against 1.0.0-rc.1 |
| PHP `^8.4` / `^8.5` | matches the panel's own requirement |
| A working queue worker | installs, updates and uninstalls run as queued jobs |

## Installation

### One-click (Admin → Plugins → Import from URL)

Paste this URL into *Admin → Plugins → **Import from URL***:

```
https://raw.githubusercontent.com/PalMeany/mc-toolkit/main/dist/minecraft-toolkit-v1.0.2.zip
```

The panel verifies the SHA-256 from [update.json](update.json) and drops the plugin into
`plugins/minecraft-toolkit`. Then continue with step 2 below. Future updates appear
in the Plugins table automatically via the same feed.

### Manual

1. Copy the `minecraft-toolkit` folder into your panel's `plugins/` directory
   (so the final path is `<panel-root>/plugins/minecraft-toolkit/plugin.json`).
2. Run the installer:

   ```bash
   php artisan p:plugin:install minecraft-toolkit
   ```

3. **Queue worker required.** The install (like every install/update/uninstall) runs as a queued job.
   If no worker is running, enable the plugin manually after migrating:
   Admin → Plugins → status, or run `php artisan p:plugin:update minecraft-toolkit` once a worker is up.
4. If your panel uses route caching (`route:cache`), rebuild the caches after installing — the
   installer only clears Filament component caches:

   ```bash
   php artisan route:cache && php artisan config:clear
   ```

5. Optional catalog keys can be configured later under Admin → Plugins → Minecraft Toolkit → Settings
   or directly in `.env`.

## Troubleshooting

### `Class "\McToolkit\McToolkitPlugin" not found` (status: Errored)

This happens when the install job runs inside a **queue worker process that was started before
the plugin files existed** — its in-memory composer autoloader has no `McToolkit\` PSR-4 prefix,
so `class_exists()` fails during the post-install component-cache rebuild. Fix on the server:

```bash
php artisan queue:restart          # respawn queue workers with a fresh autoloader
php artisan optimize:clear         # drop config/route/view caches
# if you run the official Docker image (opcache caches timestamps=0):
docker compose restart panel       # or restart php-fpm
```

Then reinstall from a fresh CLI process:

```bash
php artisan p:plugin:install minecraft-toolkit
```

The CLI runs its own bootstrap, so it registers the autoloader correctly. Finally, if the
Plugins table still shows *Errored*, use the row's Enable action to clear the flag.

### Verifying file placement after import

The manifest must sit at `<panel-root>/plugins/minecraft-toolkit/plugin.json` and the main class
at `<panel-root>/plugins/minecraft-toolkit/src/McToolkitPlugin.php` (not nested one level deeper).

## Uninstallation

```bash
php artisan p:plugin:uninstall minecraft-toolkit --delete   # keeps server data until --delete
```

## Feature pages (server panel)

All pages live in a "Minecraft" navigation group on `/server/{uuid_short}/mc-toolkit/...`
after the standard items:

| # | Page | What it does |
| - | ---- | ------------ |
| 1 | **Versions** | Install or change the server software (Paper, Purpur, Fabric, Forge...) and its version. Tier tabs, releases/snapshots/all, per-version Java badge + build counts, optional backup before switching, optional autostart afterwards. |
| 2 | **Plugins** | Catalog from Modrinth, Hangar, SpigotMC (+ CurseForge with an admin key). Compatible-with-my-server filter keyed to the detected loader/version; installed tracking vs. foreign jars. |
| 3 | **Mods** | Modrinth-based mod catalog with dependency handling; blocked-state notice pointing to Versions on non-mod loaders. |
| 4 | **Modpacks** | One-shot install of complete Modrinth `.mrpack` packs: loader, mods and configs placed for you. |
| 5 | **Properties** | `server.properties` editor grouped General/Players/World/Network & RCON/Advanced, MOTD live preview; panel-owned keys (`server-port`, `server-ip`, `query.port`) stay locked as "Set by the panel". |
| 6 | **Players** | Online list plus identity data from `usercache.json`; op/deop/kick/ban/whitelist controls through game commands. Live inventory and positions require the companion bridge (see limitations). |
| 7 | **Worlds** | Dimension grouping of the default world set, size + seed + last-played metadata, Download / Reset / Import actions driven by wings. |
| 8 | **Map** | First-party top-down render drawn from the region files' blocks as colored block tiles, markers per dimension, spawn/world-border layers, player list readout. |
| 9 | **Performance** | TPS / MSPT tiles with 1m/5m/15m averages, CPU %, JVM memory, plus a bridge-fed per-world entity/chunk breakdown when available. |

A detection banner ("Detected from the files") appears across pages with a manual re-detect button,
mirroring the original addon's behavior without touching any panel internals it doesn't need.

## Permission matrix

Registered subuser permission group `minecraft-toolkit` — assign per subuser under
Server → Users → edit permissions.

| Permission string | Required for |
| ----------------- | ------------ |
| `minecraft-toolkit.use` | opening ANY toolkit page |
| `minecraft-toolkit.versions` | using the Versions page |
| `minecraft-toolkit.content` | Plugins / Mods / Modpacks pages |
| `minecraft-toolkit.properties` | Properties page |
| `minecraft-toolkit.players` | Players page |
| `minecraft-toolkit.worlds` | Worlds page (and world downloads) |
| `minecraft-toolkit.map` | Map page and tile API |
| `minecraft-toolkit.manage` | every mutation (install, edit, reset, download archive creation...) |
| `minecraft-toolkit.administer` | destructive extras — resets, imports, long-running operation cancels |

Server owners are always allowed; root admins bypass permission checks globally.

## External services

| Service | Used for | Needs a key? |
| ------- | -------- | ------------ |
| [mcjars.app](https://mcjars.app) | versions/builds catalog | no |
| [Modrinth](https://modrinth.com) | plugins, mods, modpacks | no |
| [Spiget.org](https://spiget.org) | SpigotMC plugins | no |
| [Hangar (hangar.papermc.io)](https://hangar.papermc.io)\* | Paper plugins | yes (`HANGAR_KEY`) |
| [CurseForge](https://console.curseforge.com)\* | plugins/mods extras | yes (`CURSEFORGE_KEY`) |

\* Stars mark admin-provided keys configured under Admin → Plugins → Settings (or `.env`). Without
a key those sources are skipped gracefully — exactly like the original addon hides its chip when a key is absent.

## Settings & .env keys

Settings are persisted to the panel `.env` (values written by the built-in settings slide-over):

```
MC_TOOLKIT_CURSEFORGE_KEY=...    # optional CurseForge API key
MC_TOOLKIT_HANGAR_KEY=...        # optional Hangar personal token
MC_TOOLKIT_ENABLED_PAGES=        # comma-separated page slugs; blank = all nine enabled
```

The bare names (`CURSEFORGE_KEY`, `HANGAR_KEY`) also work when pre-defined by other tooling.
Cache lifetimes, registry toggles and limits are tuned in `config/minecraft-toolkit.php`.

## Limitations (honest scope of v1)

- **No live inventory / position / health editing, no live TPS via websocket feed, no real-time map
  player pins** without the future companion bridge plugin. Tier-2 surfaces degrade into clear notices.
- **The map renders colored block tiles** computed from `.mca` region chunks — not textured
  renders of actual resource-pack textures; treat it as an exploration overview.
- Region rendering streams chunk bytes from wings and stays inside the configured
  `limits.region_fetch_max_bytes` guard (8 MiB per request by default).
- World downloads create a temporary zip archive through wings at the root, stream it, then delete it.
  Very large worlds are limited by node memory/timeouts rather than hard caps here.
- Panel-side import relies on wings' remote `pull()` — URLs must be publicly reachable from the node.
- Deleting the plugin leaves previously-assigned custom permission strings in role/subuser rows;
  remove them manually if you ever uninstall permanently.

## FAQ

**Does this work with Pterodactyl?**
No — this build targets Pelican's native plugin system only. The original addon covers Pterodactyl panels.

**Why does the Plugins page not show CurseForge results?**
Add a CurseForge API key in Admin → Plugins → Settings. The source chip only appears once a key exists.

**How do I restrict what a subuser sees?**
Grant them `minecraft-toolkit.use` alone for read-only browsing; add domain permissions and
`manage` for edits. Owners always see everything.

**Installing hangs forever?**
Start your queue worker (`php artisan queue:work`) — installation jobs only progress while a worker runs.

**Does it collect license keys or ping anyone besides mcjars/registries?**
No. There is no DRM/licensing layer in this build.

**Can I hide a single page (e.g. Performance)?**
Yes — uncheck it under Admin → Plugins → Settings, or set `MC_TOOLKIT_ENABLED_PAGES` in `.env`.

## Additional troubleshooting

### Status stuck at "Errored" — and why there is no Enable button

While a plugin is marked *Errored*, the Admin → Plugins row intentionally hides the Enable
action (only Delete is offered). The panel cannot trust half-installed code paths. The loader
also **self-heals**: the next process that loads plugins successfully (any fresh
`php artisan p:plugin:list`, a queue-worker respawn, or a php-fpm/Docker restart) promotes
Errored → Enabled automatically once the plugin classes load without throwing.

Full recovery recipe for this exact incident:

```bash
php artisan queue:restart          # fresh autoloader/opcache for install workers
php artisan optimize:clear
docker compose restart panel       # official image ships opcache with validate_timestamps=0
php artisan p:plugin:list          # expect: Minecraft Toolkit | enabled | 1.0.2
```

If it still reads *Errored*, press the row's **Delete** action, then Import from URL again
with the newest zip link from this page, and finally finish registration from the CLI:

```bash
php artisan p:plugin:install minecraft-toolkit
```

CLI runs have a clean bootstrap each invocation, so they are immune to the stale-autoloader
class-not-found case that long-lived HTTP/queue processes hit.

### Why the manifest has no `"panel_version"` field

Intentionally omitted since v1.0.2. In rc-stage panels (1.0.0-rc.1) version-requirement checks
run during very early plugin discovery — before Laravel installs its deferred-services map —
so a declared constraint can crash the whole boot with `Target class [cache] does not exist`.
Compatibility is documented here instead: this plugin requires Pelican ≥ 1.0.0-rc.1.
