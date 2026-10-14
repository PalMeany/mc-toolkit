<?php

declare(strict_types=1);

namespace McToolkit\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use McToolkit\Services\Worlds\WorldsService;

/**
 * Deletes one world download archive once its node download token has expired.
 *
 * Wings' file-download token is single-use and the archive is opened once and streamed
 * without Range support, so after the token's lifetime the archive can never be fetched
 * again; deleting it while a transfer still runs is safe on Linux (the open file handle
 * keeps the data). Dispatched with a delay by WorldDownloadController — never on the
 * `sync` driver, which ignores delays and would delete the archive before the browser
 * fetched it. WorldsService::sweepStaleArchives() stays the fallback.
 */
final class DiscardWorldArchiveJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $serverId,
        public readonly string $archive,
    ) {}

    public function handle(WorldsService $worlds): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server instanceof Server) {
            // Only exact toolkit archive names are ever deleted (pattern-checked there).
            $worlds->discardArchive($server, $this->archive);
        }
    }
}
