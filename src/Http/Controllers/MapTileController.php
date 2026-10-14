<?php

declare(strict_types=1);

namespace McToolkit\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns one top-down map tile as JSON.
 *
 * GET /server/{uuid_short}/mc-toolkit/map/tile?world={dir}&rx={int}&rz={int}
 *
 * The renderer (McToolkit\Services\Maps\MapTileService, wave-B) may not exist yet
 * during rolling deploys — until the class is present this endpoint degrades to a 503
 * JSON body instead of crashing. Once present it simply proxies
 * MapTileService::renderRegion() output.
 */
final class MapTileController
{
    /** Default top-of-view Y for renders when no explicit level is requested. */
    private const DEFAULT_Y_RANGE_TOP = 320;

    public function __invoke(Request $request, string $uuid_short): JsonResponse
    {
        $server = Server::query()->where('uuid_short', $uuid_short)->firstOrFail();

        abort_unless(
            user()?->can('minecraft-toolkit.map', $server) && user()?->can('minecraft-toolkit.use', $server),
            403,
        );

        if (! class_exists(\McToolkit\Services\Maps\MapTileService::class)) {
            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.service_unavailable'),
            ], 503);
        }

        $world = basename((string) $request->query('world', ''));
        $regionX = (int) $request->query('rx', '0');
        $regionZ = (int) $request->query('rz', '0');

        if ($world === '') {
            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.download_failed'),
            ], 422);
        }

        try {
            $tile = app(\McToolkit\Services\Maps\MapTileService::class)
                ->renderRegion($server, $world, $regionX, $regionZ, self::DEFAULT_Y_RANGE_TOP);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'tile' => null,
                'error' => trans('minecraft-toolkit::errors.statuses.wings_502'),
            ], 502);
        }

        // shape: {width: int, height: int, pixels: string(base64 rgba)} — fully scalar-safe JSON.
        return response()->json([
            'tile' => $tile,
        ]);
    }
}
