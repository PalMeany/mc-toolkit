<?php

declare(strict_types=1);

namespace McToolkit\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use McToolkit\Services\Maps\MapTileService;
use McToolkit\Services\Worlds\WorldsService;

/**
 * Returns one top-down map tile as JSON.
 *
 * GET /server/{uuid_short}/mc-toolkit/map/tile?world={dir}&dim={overworld|nether|end}&rx={int}&rz={int}&v={mtime}
 *
 * `world` is the world folder name exactly as listed (any character but path
 * separators / dot segments), `dim` picks the dimension inside it, `v` is the region
 * file's mtime from the page listing (part of the render cache key, 0 when unknown).
 *
 * Response shape: {tile: {width, height, pixels(base64 RGBA), originX, originZ, …}|null}.
 * A `null` tile with HTTP 200 means "nothing to draw here" and is final for the client;
 * 4xx/5xx answers carry {error} and the client backs off.
 */
final class MapTileController
{
    public function __invoke(Request $request, string $uuid_short): JsonResponse
    {
        $server = Server::query()->where('uuid_short', $uuid_short)->firstOrFail();

        $user = $request->user();

        abort_unless(
            $user !== null
                && $user->can('minecraft-toolkit.use', $server)
                && $user->can('minecraft-toolkit.map', $server),
            403,
        );

        if ($server->isInConflictState()) {
            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.server_unavailable'),
            ], 409);
        }

        $world = $request->query('world');
        $dimension = $request->query('dim', 'overworld');
        $regionX = filter_var($request->query('rx'), FILTER_VALIDATE_INT);
        $regionZ = filter_var($request->query('rz'), FILTER_VALIDATE_INT);
        $mtime = filter_var($request->query('v', '0'), FILTER_VALIDATE_INT);

        if (! is_string($world)
            || ! WorldsService::isSafeDirectoryName($world)
            || ! is_string($dimension)
            || ! in_array($dimension, MapTileService::DIMENSIONS, true)
            || ! is_int($regionX)
            || ! is_int($regionZ)
            || abs($regionX) > MapTileService::MAX_REGION_COORDINATE
            || abs($regionZ) > MapTileService::MAX_REGION_COORDINATE) {
            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.download_failed'),
            ], 422);
        }

        try {
            $tile = app(MapTileService::class)->renderRegion(
                $server,
                $world,
                $dimension,
                $regionX,
                $regionZ,
                is_int($mtime) ? max(0, $mtime) : 0,
            );
        } catch (\Throwable $exception) {
            \McToolkit\Support\QuietReport::report($exception, 'tile.'.$server->getKey());

            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.wings_502'),
            ], 502);
        }

        return response()->json([
            'tile' => $tile,
        ]);
    }
}
