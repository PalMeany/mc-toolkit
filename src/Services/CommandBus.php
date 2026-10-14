<?php

declare(strict_types=1);

namespace McToolkit\Services;

use App\Facades\Activity;
use App\Models\Server;
use GuzzleHttp\Exception\BadResponseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The single funnel through which Minecraft Toolkit sends game commands.
 *
 * Pipeline mirrors the panel's own CommandController (app/Http/Controllers/Api/
 * Client/Servers/CommandController.php:26-45, verified against the v1.0.0-rc.1 clone):
 * 1. Pre-gate on `ContainerStatus::isStartingOrRunning()` via Server::retrieveStatus()
 *    (the same helper core's console UI gating uses; result is 15s-cached server-side).
 * 2. Send with `Server::send(array|string $commands)` — one HTTP call even for batches,
 *    wings always receives `{"commands": [...]}` (Server.php:513-518).
 * 3. Map a wings HTTP 502 (container not actually up) to a friendly HttpException,
 *    chaining the previous exception exactly like CommandController does.
 * 4. Log one Activity event per command with event id `mc-toolkit:console.command`.
 *
 * Language keys used here are owned by A1 in lang/en/errors.php:
 * - statuses.stopped_required  → pre-gate failure
 * - statuses.wings_502         → daemon-side 502 mapping
 */
final class CommandBus
{
    /**
     * @param  list<string>|string  $commands  single command or batch; strings only.
     *
     * @throws ConnectionException when the node is unreachable.
     * @throws HttpException 422 pre-gate failure / 502 when the daemon rejects the send.
     */
    public function send(Server $server, array|string $commands): void
    {
        $list = array_values(
            array_filter(
                is_array($commands) ? $commands : [$commands],
                fn ($command): bool => is_string($command) && trim($command) !== '',
            ),
        );

        if ($list === []) {
            return;
        }

        // Gating intentionally happens here rather than at every page action: parity with
        // the guide §4.1 pipeline; INSTALLING/KILLED/etc. states are excluded on purpose.
        $status = $server->retrieveStatus();

        if (!$status->isStartingOrRunning()) {
            throw new HttpException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                trans('minecraft-toolkit::errors.statuses.stopped_required'),
            );
        }

        try {
            $server->send($list);
        } catch (\Throwable $exception) {
            $previous = $exception instanceof \Exception ? $exception->getPrevious() : null;

            if ($previous instanceof BadResponseException) {
                throw_if(
                    $previous->getResponse()->getStatusCode() === Response::HTTP_BAD_GATEWAY,
                    new HttpException(
                        Response::HTTP_BAD_GATEWAY,
                        trans('minecraft-toolkit::errors.statuses.wings_502'),
                        $exception,
                    ),
                );
            }

            throw $exception;
        }

        foreach ($list as $command) {
            Activity::event('mc-toolkit:console.command')
                ->subject($server)
                ->property([
                    'command' => $command,
                    'server' => $server->uuid,
                ])
                ->log();
        }
    }

    /**
     * Cheap pre-check for button/disabled state: true while the container is starting or running.
     *
     * @throws ConnectionException
     */
    public function canSend(Server $server): bool
    {
        return $server->retrieveStatus()->isStartingOrRunning();
    }
}
