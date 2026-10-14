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
https://raw.githubusercontent.com/PalMeany/mc-toolkit/main/dist/minecraft-toolkit-v1.0.5.zip
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
| [Hangar (hangar.papermc.io)](https://hangar.papermc.io) | Paper plugins | no |
| [CurseForge](https://console.curseforge.com)\* | plugins/mods extras | yes (`CURSEFORGE_KEY`) |

\* Stars mark admin-provided keys configured under Admin → Plugins → Settings (or `.env`). Without
a key those sources are skipped gracefully — exactly like the original addon hides its chip when a key is absent.

## Settings & .env keys

Settings are persisted to the panel `.env` (values written by the built-in settings slide-over):

```
MC_TOOLKIT_CURSEFORGE_KEY=...    # optional CurseForge API key
MC_TOOLKIT_ENABLED_PAGES=        # comma-separated page slugs; blank/all = all nine, none = no page
```

The bare name `CURSEFORGE_KEY` also works when pre-defined by other tooling.
Cache lifetimes, registry toggles and limits are tuned in `config/minecraft-toolkit.php`.

## Limitations (honest scope of v1)

- **No live inventory / position / health editing, no live TPS via websocket feed, no real-time map
  player pins** without the future companion bridge plugin. Tier-2 surfaces degrade into clear notices.
- **The map renders colored block tiles** computed from `.mca` region chunks — not textured
  renders of actual resource-pack textures; treat it as an exploration overview.
- Region rendering streams chunk bytes from wings and stays inside the configured
  `limits.region_fetch_max_bytes` guard (8 MiB per request by default).
- World downloads create a temporary zip archive through wings at the server root; the browser
  downloads it straight from the node with a single-use token and the archive is deleted a few
  minutes later (right away when the panel's queue driver is `sync`: on the next page visit).
- Software and modpack installs run as queued jobs and need the panel's queue worker; the
  Docker image and startup command are only switched within what the egg offers.
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
php artisan p:plugin:list          # expect: Minecraft Toolkit | enabled | 1.0.5
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

### "The selected mc_toolkit_enabled_pages is invalid" when saving plugin settings (fixed in v1.0.3)

v1.0.1/1.0.2 built the *Enabled pages* checkbox list with the labels only, so Filament treated
the option keys as numeric indexes `0..8` while the form was filled with page slugs
(`versions`, `plugins`, …). Every save attempt — regardless of which boxes were checked — then
failed the built-in `in:` validation. v1.0.3 keys options by slug again and also tolerates
index-shaped values submitted by the earlier UI when persisting.

### Pages look unstyled — white badges, huge icons, no spacing (fixed in v1.0.5)

The panel compiles its own Tailwind stylesheet and skips git-ignored folders, which includes
every plugin. v1.0.4 shipped a Tailwind entry that the panel was supposed to build with
`yarn build` while installing the plugin — but production panels usually have no Node/Yarn, the
build failed silently (`Could not install yarn dependencies: yarn: not found` in the panel log)
and the toolkit pages lost every grid, gap, badge colour and image size.

Since v1.0.5 the stylesheet ships prebuilt (`resources/dist/toolkit.css`) and is inlined on the
toolkit pages only, so nothing has to be built on the panel host. After changing a Blade view,
rebuild it with `./scripts/build-css.sh` (needs Node; uses Tailwind 4.3.0 like the panel).

## Changelog

### v1.0.5

- **Styles on real panels.** The toolkit pages lost all their styling (white badges, oversized
  icons, no spacing) on every panel without Node/Yarn: the v1.0.4 stylesheet relied on the panel
  running `yarn build` on install. It now ships prebuilt and is inlined on the toolkit pages only.
- **Map page errors.** Every action on the Map page failed with "Error while loading page": a
  private `hydrateWorlds()` collided with Livewire's `hydrate{Property}` hook for `$worlds`.
- **Broken buttons.** Worlds Download / Reset / Play passed a raw `@js(...)` to the browser
  (Blade directives are not compiled inside component attributes) and the MOTD format buttons
  had an unescaped quote in their handler — both threw in the browser and did nothing.
- **Minecraft 26.x worlds.** 26.1+ keeps every dimension inside the level folder
  (`world/dimensions/minecraft/<dim>/region`, `world/players/…`) and writes block palettes as
  plain ids. Worlds, Map, Performance and world import now understand that layout (and the
  seed/hardcore fields that moved), next to the classic `world_nether` / `DIM-1` one.
- **UI polish.** Filament-native search/sort inputs, MOTD format buttons on the MOTD field and
  the server-list preview at the top, layer switches on the map, cleaner Performance tiles
  without the companion bridge, no duplicated detection chip or loader list.
- **Long installs run in the panel's queue.** Software (Versions) and modpack installs are
  queued jobs now, so a web-server timeout can no longer cut them off halfway. The page shows a
  live task card (step, progress, notes, cancel while queued) and a panel notification arrives
  when the job is done. They need the panel's queue worker (`pelican-queue` /
  `php artisan queue:work`) — the card says so when a job waits for more than two minutes.
- **Modpacks install their loader.** The Fabric / Quilt / Forge / NeoForge build named by the
  pack (`modrinth.index.json`) is installed together with the pack, the exact loader version when
  MCJars has it. The server is re-checked right before files are moved into place.
- **Changing the software family fixes the Java image and startup.** After a software swap the
  Docker image is switched to the egg image with the Java version that release needs (MCJars'
  `java`, else 8 / 16 / 17 / 21 / 25 by Minecraft version), archive builds (Forge, NeoForge,
  Quilt) leave their launcher under the egg's `SERVER_JARFILE`, a stale `unix_args.txt` of an old
  Forge install is removed, and a startup command that cannot launch the jar is swapped for one
  of the egg's that can. Each change needs the same permission as on the Startup page; anything
  skipped is listed in the task notes.
- **World download archives** are deleted ~4 minutes after the link is handed out (the node's
  download token is single-use and now lives 3 minutes) instead of lingering for half an hour.

### v1.0.4

Bug-fix release; nothing new on purpose.

- **Pages crashed on render.** The detection banner and the sparkline used component names Blade
  cannot resolve (`minecraft-toolkit::components.*`), the banner rendered itself recursively,
  broken Blade comments swallowed half of the Players and Worlds views, the Worlds view had a
  `use` statement inside a component slot, the Properties form evaluated a closure with no record,
  the Versions page assigned `null` to a string property and called an undefined `canBackup()`,
  the Modpacks page referenced `Filament` without importing it (500 on every server page), the
  Players page passed a `Stringable` to `Notification::body()`, and the map script had a syntax error.
- **Catalogs never answered.** Every HTTP call used `Http::retries()`, which does not exist.
  Modrinth loader facets were AND-ed and search hits were all marked incompatible, CurseForge used
  the resource-pack class for plugins and ignored the search text, Hangar was rewritten against its
  real (key-less) API, and Spiget fields/paging headers were wrong.
- **Installs.** Wings pulls now run in the foreground with an explicit file name, so nothing
  touches a half-downloaded file; updates replace the old jar; Refresh no longer forgets tracked
  plugins; modpacks are staged, validated against the `.mrpack` spec and merged with their
  overrides; the Spiget redirect walk on the panel host (blind SSRF) is gone. The Versions page
  downloads into staging first, stops the server and waits until it is really offline, and only
  then swaps jars (named after the egg's `SERVER_JARFILE`).
- **Properties.** Booleans showed as ON and were written back as `true`; the baseline was lost
  between requests, so saving one group could zero hidden values; the parser now round-trips
  Java's escapes, keeps the trailing newline stable and survives duplicate keys.
- **Worlds and map.** 1.18+ chunks, int arrays in `level.dat`, Nether/End region folders, world
  import (staging, never over a live world), downloads through the panel's own node-token flow
  instead of buffering the zip in PHP, and reset/import refuse a running server.
- **Security.** Every page checks its domain permission and the server's conflict state on each
  request (only `use` was checked before), mutating actions re-check `manage`, `wire:click`
  arguments are JS-encoded, console commands reject line breaks and invalid player names, world
  download/reset only accept real world folders, the plugin routes require a session login.
- **Database.** `server_id` is now `INT UNSIGNED` like `servers.id`; MySQL/MariaDB refused the
  previous BIGINT foreign key, so the plugin could not be installed there at all.
- **Settings.** Unticking every page now really hides them all (`none`); the Hangar token field
  is gone because Hangar needs no key.
