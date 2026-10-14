<?php

declare(strict_types=1);

namespace McToolkit\Http\Controllers;

use App\Enums\NodeJwtScope;
use App\Facades\Activity;
use App\Models\Server;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use McToolkit\Services\Worlds\WorldsService;

/**
 * Hands a world (plus its linked `_nether` / `_the_end` folders) to the browser.
 *
 * GET /server/{uuid_short}/mc-toolkit/download/world?world={name}&token={expires.sign}
 *
 * Same flow as the panel's own file downloads (FileController::download /
 * DownloadFiles page): wings compresses the world set into a temporary zip in the
 * server root, the panel mints a 15-minute `file-download` node JWT for exactly that
 * file and the browser is redirected to `{node}/download/file?token=…`. The bytes
 * never pass through PHP, so world size and the daemon HTTP timeout do not matter.
 * Temporary archives are removed by WorldsService::sweepStaleArchives() once older
 * than half an hour (on the next download or Worlds page load), or right away when
 * the hand-over fails.
 *
 * The `token` query (minted by the Worlds page) binds world + server + user and expires
 * after ~5 minutes, so a cross-site GET cannot make the node build archives.
 */
final class WorldDownloadController
{
    /** Validity window of page-minted links, in seconds. */
    private const LINK_TTL_SECONDS = 300;

    public function __construct(
        private readonly WorldsService $worlds,
        private readonly NodeJWTService $jwt,
    ) {}

    public function __invoke(Request $request, string $uuid_short): RedirectResponse|JsonResponse
    {
        $server = Server::query()->where('uuid_short', $uuid_short)->firstOrFail();
        $user = $request->user();

        abort_unless(
            $user !== null
                && $user->can('minecraft-toolkit.use', $server)
                && $user->can('minecraft-toolkit.worlds', $server)
                && $user->can('minecraft-toolkit.manage', $server),
            403,
        );

        if ($server->isInConflictState()) {
            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.server_unavailable')], 409);
        }

        $world = $request->query('world');

        if (! is_string($world) || ! WorldsService::isSafeDirectoryName($world)) {
            return response()->json(['error' => trans('minecraft-toolkit::pages.worlds.unknown_world')], 422);
        }

        if (! $this->validToken($request, $server, $world)) {
            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.link_expired')], 401);
        }

        $archive = null;

        try {
            // Throws DomainException when $world is not a folder the world listing returns.
            $archive = $this->worlds->prepareDownload($server, $world);

            $token = $this->jwt
                ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
                ->setScopes(NodeJwtScope::FileDownload)
                ->setUser($user)
                ->setClaims([
                    'file_path' => '/'.$archive,
                    'server_uuid' => $server->uuid,
                ])
                ->handle($server->node, $user->id.$server->uuid);

            $target = $server->node->getConnectionAddress().'/download/file?token='.$token->toString();
        } catch (\DomainException $exception) {
            return response()->json(['error' => $exception->getMessage()], 404);
        } catch (ConnectionException $exception) {
            report($exception);

            if ($archive !== null) {
                $this->worlds->discardArchive($server, $archive);
            }

            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.wings_502')], 502);
        } catch (\Throwable $exception) {
            report($exception);

            if ($archive !== null) {
                $this->worlds->discardArchive($server, $archive);
            }

            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.download_failed')], 500);
        }

        Activity::event('mc-toolkit:worlds.archive')
            ->subject($server)
            ->property('world', $world)
            ->log();

        return redirect()->away($target);
    }

    /** Validates the `{expires}.{sign}` pair minted by {@see self::makeQuery()}. */
    private function validToken(Request $request, Server $server, string $world): bool
    {
        $raw = $request->query('token');

        if (! is_string($raw) || ! str_contains($raw, '.')) {
            return false;
        }

        [$issuedRaw, $sign] = explode('.', $raw, 2);
        $issued = filter_var($issuedRaw, FILTER_VALIDATE_INT);

        if (! is_int($issued) || $sign === '' || abs(time() - $issued) > self::LINK_TTL_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($server, $world, $issued), $sign);
    }

    /**
     * Signature half of a download link: HMAC-SHA256 (app key) over server, user,
     * world and the issue time.
     */
    public static function sign(Server $server, string $world, int $issued): string
    {
        return hash_hmac(
            'sha256',
            implode("\n", ['mc-download', $server->uuid_short, (string) (user()?->id ?? ''), $world, (string) $issued]),
            (string) config('app.key'),
        );
    }

    /** @return array{token:string} ready-to-append query value for WorldsPage link generation */
    public static function makeQuery(Server $server, string $world): array
    {
        $issued = time();

        return ['token' => $issued.'.'.self::sign($server, $world, $issued)];
    }
}
