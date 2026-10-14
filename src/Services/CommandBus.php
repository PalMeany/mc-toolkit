<?php

declare(strict_types=1);

namespace McToolkit\Services;

use App\Facades\Activity;
use App\Models\Server;
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
 * 3. Map any wings error status (502 when the container is not actually up) to a
 *    friendly HttpException — Http::daemon() hands those back instead of throwing.
 * 4. Log one Activity event per command with event id `mc-toolkit:console.command`.
 *
 * Language keys used here are owned by A1 in lang/en/errors.php:
 * - statuses.invalid_command     → line breaks inside a command
 * - statuses.server_unavailable  → suspended / transferring / restoring server
 * - statuses.running_required    → pre-gate failure
 * - statuses.wings_502           → daemon-side error status
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

        // Wings writes each entry plus a newline to the console's stdin, so a line break
        // inside one entry (e.g. from a player name read off the server's own files) would
        // smuggle further console commands through.
        foreach ($list as $command) {
            if (preg_match('/[\r\n\x00]/', $command) === 1) {
                throw new HttpException(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    trans('minecraft-toolkit::errors.statuses.invalid_command'),
                );
            }
        }

        // Same rule as the core console: suspended, transferring, restoring or
        // maintenance-locked servers take no commands.
        if ($server->isInConflictState()) {
            throw new HttpException(
                Response::HTTP_CONFLICT,
                trans('minecraft-toolkit::errors.statuses.server_unavailable'),
            );
        }

        // Gating intentionally happens here rather than at every page action: parity with
        // the guide §4.1 pipeline; INSTALLING/KILLED/etc. states are excluded on purpose.
        $status = $server->retrieveStatus();

        if (!$status->isStartingOrRunning()) {
            throw new HttpException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                trans('minecraft-toolkit::errors.statuses.running_required'),
            );
        }

        // Http::daemon() does not throw on error statuses, so a refused send (502 when the
        // container is not really up, 4xx on a bad request) has to be read off the response.
        $response = $server->send($list);

        if ($response->getStatusCode() >= 400) {
            throw new HttpException(
                Response::HTTP_BAD_GATEWAY,
                trans('minecraft-toolkit::errors.statuses.wings_502'),
            );
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
        return !$server->isInConflictState() && $server->retrieveStatus()->isStartingOrRunning();
    }
}
