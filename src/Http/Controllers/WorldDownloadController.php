<?php

declare(strict_types=1);

namespace McToolkit\Http\Controllers;

use App\Facades\Activity;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Streams a world folder to the browser as a zip archive.
 *
 * GET /server/{uuid_short}/mc-toolkit/download/world?world={name}&token={expires.sign}
 *
 * The route lives outside Filament/Livewire so the download survives SPA navigation and
 * can be opened straight from the worlds page. This is a deliberately thin passthrough:
 * wings compresses the world directory, we pull the archive bytes through the node HTTP
 * client, delete the temporary archive on the daemon, then stream the payload with an
 * attachment header.
 */
final class WorldDownloadController
{
    public function __construct(private DaemonFileRepository $files) {}

    public function __invoke(Request $request, string $uuid_short): HttpResponse|JsonResponse
    {
        $server = Server::query()->where('uuid_short', $uuid_short)->firstOrFail();

        // Manual subuser gate (owners + root admins bypass automatically upstream).
        abort_unless(
            user()?->can('minecraft-toolkit.worlds', $server) && user()?->can('minecraft-toolkit.manage', $server),
            403,
        );

        $world = basename((string) $request->query('world', ''));
        if ($world === '') {
            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.download_failed')], 422);
        }

        if (! $this->validToken($request, $server, $world)) {
            return response()->json(
                ['error' => trans('minecraft-toolkit::errors.statuses.link_expired')],
                401,
            );
        }

        try {
            $archiveName = (string) ($this->files->setServer($server)->compressFiles(
                '/',
                [$world],
                (string) Str::kebab('mctk-world-'.substr(sha1($world.$server->uuid.str_replace(',', '', (string) microtime(true))), 0, 8)),
                'zip',
            )['name'] ?? '');

            if ($archiveName === '') {
                throw new Exception('Wings did not return an archive name.');
            }

            /** @var string $payload */
            $payload = $this->files->setServer($server)->getContent('/'.$archiveName);

            // Remove the temporary archive before the response leaves — nothing lingers in /.
            $this->files->setServer($server)->deleteFiles('/', [$archiveName]);
        } catch (ConnectionException $exception) {
            report($exception);

            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.wings_502')], 502);
        } catch (Exception $exception) {
            report($exception);

            $previous = $exception->getPrevious();

            if ($previous instanceof BadResponseException && $previous->getResponse()->getStatusCode() === 502) {
                return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.wings_502')], 502);
            }

            return response()->json(['error' => trans('minecraft-toolkit::errors.statuses.download_failed')], 500);
        }

        Activity::event('mc-toolkit:worlds.archive')
            ->property('world', $world)
            ->log();

        return response()->streamDownload(function () use ($payload): void {
            echo $payload;
        }, Str::kebab($world).'.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Validates the `{expires}.{sign}` pair against
     * substr(sha1(config('app.key').'mc-download'.$server->uuid_short.$world.$expires), 0, 32)
     * with a ±5 minute tolerance around the minute bucket.
     */
    private function validToken(Request $request, Server $server, string $world): bool
    {
        $raw = (string) $request->query('token', '');
        if (! str_contains($raw, '.')) {
            return false;
        }

        [$expiresRaw, $sign] = explode('.', $raw, 2);
        $expires = (int) $expiresRaw;

        if ($sign === '' || abs(time() - $expires) > 300) {
            return false;
        }

        return hash_equals(self::sign($server, $world, $expires), $sign);
    }

    /**
     * Produces the signature half of a download token: first 32 hex chars of sha1 over
     * app key + static salt + server uuid-short + world name + expiration minute-bucket.
     */
    public static function sign(Server $server, string $world, int $expires): string
    {
        return substr(
            sha1((string) config('app.key').'mc-download'.$server->uuid_short.$world.$expires),
            0,
            32,
        );
    }

    /** @return array{token:string} ready-to-append query value for WorldsPage link generation */
    public static function makeQuery(Server $server, string $world): array
    {
        $expires = intdiv(time(), 60) * 60;

        return ['token' => $expires.'.'.self::sign($server, $world, $expires)];
    }
}
