<?php

declare(strict_types=1);

namespace McToolkit\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Convenience façade over {@see DaemonFileRepository} for everything Minecraft Toolkit
 * reads or writes on a server filesystem.
 *
 * Every method re-scopes the shared repository to the passed server before acting
 * (the repository keeps mutable $server/$node state, see the DeleteFilesService precedent
 * in the panel: app/Services/Files/DeleteFilesService.php:29/37 does exactly one
 * "->setServer($server)" per call, and DaemonRepository::setServer() already assigns
 * $this->node from $server->node — an explicit second setNode() would be redundant,
 * DaemonFileRepository.php/DaemonRepository.php:24-31).
 *
 * Normalization note for {@see self::ls()}: wings' list-directory rows carry the keys
 * name, created, modified, mode, mode_bits, size, directory, file, symlink, mime
 * (verified against app/Models/File.php:getRows(), lines 214-226 of the panel clone).
 * Rows are flattened to snake_case-friendly fields below plus the untouched payload.
 *
 * Error semantics:
 * - {@see self::read()} returns null for missing/too-large files, bubbles connection outages.
 * - {@see self::ls()} throws a plain \RuntimeException carrying wings' own "error" payload;
 *   callers (pages, DetectionService) should catch Throwable and map it to the
 *   `minecraft-toolkit::banner.failures.gateway_down` callout instead of leaking internals.
 */
final class FileBridge
{
    public function __construct(private DaemonFileRepository $files) {}

    /**
     * Read a file's contents, capped in bytes when requested.
     *
     * @return string|null null when the file is missing or exceeds $maxSize; connection failures bubble up.
     *
     * @throws ConnectionException
     */
    public function read(Server $server, string $path, ?int $maxSize = null): ?string
    {
        try {
            return (string) $this->scope($server)->getContent($path, $maxSize);
        } catch (FileNotFoundException) {
            // Wings maps HTTP 404 to this exception inside DaemonFileRepository itself.
            return null;
        } catch (\App\Exceptions\Http\Server\FileSizeTooLargeException) {
            // "Unreadable" under the contract: too large to fetch in one shot.
            return null;
        }
    }

    /**
     * Read a JSON document. Returns null when unreadable or not valid JSON.
     *
     * @throws ConnectionException
     */
    public function readJson(Server $server, string $path): mixed
    {
        $raw = $this->read($server, $path);

        if ($raw === null) {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Create or overwrite a file. Wings expects the raw contents as the request body
     * (verified usages: MinecraftEulaSchema.php:50, ListFiles.php:483, FileController.php:130).
     *
     * @throws ConnectionException|\App\Exceptions\Repository\FileExistsException
     */
    public function write(Server $server, string $path, string $contents): void
    {
        $this->scope($server)->putContent($path, $contents);
    }

    /**
     * Directory listing, strictly normalized.
     *
     * @return list<array{
     *     name: string,
     *     file: bool,
     *     directory: bool,
     *     symlink: bool,
     *     size: int,
     *     mime: string,
     *     mode: string,
     *     mode_bits: int,
     *     created: ?string,
     *     modified: ?string,
     *     object: array<string, mixed>,
     * }> `file` is wings' regular-file flag (`true` even for symlinks pointing at files),
     *    `directory` marks folders; `object` keeps the untouched wings row.
     *
     * @throws ConnectionException when the node cannot be reached.
     * @throws \RuntimeException when wings answers with its `{error: …}` shape.
     */
    public function ls(Server $server, string $dir = '.'): array
    {
        $target = $dir;

        if ($target === '' || $target === '.') {
            $target = '/';
        }

        $listing = $this->scope($server)->getDirectory($target);

        if (!is_array($listing)) {
            return [];
        }

        // wings signals rejected paths (permissions, bad root) through an associative
        // `error` element instead of an HTTP status — seen in app/Models/File.php:210.
        if (isset($listing['error'])) {
            throw new \RuntimeException(strval($listing['error']));
        }

        $rows = [];

        foreach ($listing as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rows[] = [
                'name' => strval($entry['name'] ?? ''),
                'file' => (bool) ($entry['file'] ?? false),
                'directory' => (bool) ($entry['directory'] ?? false),
                'symlink' => (bool) ($entry['symlink'] ?? false),
                'size' => intval($entry['size'] ?? 0),
                'mime' => strval($entry['mime'] ?? ''),
                'mode' => strval($entry['mode'] ?? ''),
                'mode_bits' => intval($entry['mode_bits'] ?? 0),
                'created' => isset($entry['created']) ? strval($entry['created']) : null,
                'modified' => isset($entry['modified']) ? strval($entry['modified']) : null,
                'object' => $entry,
            ];
        }

        return $rows;
    }

    /**
     * Whether a path exists (file OR directory), decided by scanning its parent listing.
     *
     * Never throws: infrastructure problems count as "missing", mirroring the guard rails
     * of app/Models/File.php::getRows().
     */
    public function exists(Server $server, string $path): bool
    {
        $path = rtrim($path, '/');

        if ($path === '' || $path === '/') {
            return true;
        }

        try {
            $parent = $this->ls($server, str_contains($path, '/') ? dirname($path) : '/');
        } catch (\Throwable) {
            return false;
        }

        $needle = basename($path);

        foreach ($parent as $entry) {
            if (mb_strtolower($entry['name']) === mb_strtolower($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Basenames of everything under $dir (directories included unless filtered by caller).
     *
     * @return list<string>
     */
    public function names(Server $server, string $dir = '/'): array
    {
        return array_column($this->safeLs($server, $dir), 'name');
    }

    /**
     * Like {@see self::ls()} but collapses every failure (node down, rejected path) into [].
     *
     * @return list<array<string, mixed>>
     */
    public function safeLs(Server $server, string $dir = '/'): array
    {
        try {
            return $this->ls($server, $dir);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Delete entries relative to $root.
     *
     * @param list<string> $paths
     *
     * @throws ConnectionException
     */
    public function delete(Server $server, array $paths, ?string $root = null): void
    {
        if ($paths === []) {
            return;
        }

        $this->scope($server)->deleteFiles($root ?? '/', $paths);
    }

    /**
     * Rename/move inside the archive root.
     *
     * @throws ConnectionException
     */
    public function rename(Server $server, string $from, string $to): void
    {
        $this->scope($server)->renameFiles('/', [['from' => $from, 'to' => $to]]);
    }

    /**
     * Create a directory (last segment becomes the name, everything before it the base).
     *
     * @throws ConnectionException|\App\Exceptions\Repository\FileExistsException
     */
    public function mkdir(Server $server, string $path): void
    {
        $clean = trim($path, "/\\ ");

        if ($clean === '') {
            return;
        }

        $base = dirname($clean);

        $this->scope($server)->createDirectory(
            basename($clean),
            $base === '.' || $base === '\\' ? '/' : '/' . $base,
        );
    }

    /**
     * Compress files/folders inside $root into an archive placed next to them.
     * Mirrors panel usage in ListFiles.php:325/437 — the response carries the created
     * archive's name (`$archive['name']`).
     *
     * @param list<string> $files
     *
     * @return string archive file name inside $root, '' when wings omitted it.
     *
     * @throws ConnectionException
     */
    public function compress(Server $server, string $root, array $files, string $name = 'archive', string $ext = 'zip'): string
    {
        $result = $this->scope($server)->compressFiles($root ?: '/', $files, $name, $ext);

        return is_array($result) && isset($result['name']) ? strval($result['name']) : '';
    }

    /**
     * Decompress an archive located at "$root/$file".
     *
     * @throws ConnectionException
     */
    public function decompress(Server $server, string $root, string $file): void
    {
        $this->scope($server)->decompressFile($root ?: '/', $file);
    }

    /**
     * Ask wings to fetch a remote URL onto the server.
     *
     * By default the request runs in the FOREGROUND: wings only answers once the file is
     * fully on disk (and answers with an error when the download failed), returning the
     * file's stat row. Without it wings downloads in the background and replies at once,
     * so anything that touches the file right after the pull races a partial download.
     * The same reasoning drives the explicit file name — otherwise wings derives one from
     * the URL (e.g. "download" for Spiget), which callers cannot predict.
     *
     * The daemon holds a foreground request open for the whole download, so the client
     * timeout is raised well past the panel's default used for small file operations.
     * Wings refuses more than three simultaneous pulls per server.
     *
     * @return array<string, mixed> wings' stat row of the written file (foreground) or its
     *                              `{identifier}` acknowledgement (background)
     *
     * @throws ConnectionException
     * @throws \Illuminate\Http\Client\RequestException when wings rejects or fails the download
     */
    public function pull(Server $server, string $url, string $directory = '/', ?string $fileName = null, bool $foreground = true, int $timeout = 900): array
    {
        $attributes = [
            'url' => $url,
            'root' => $directory !== '' ? $directory : '/',
            'foreground' => $foreground,
        ];

        if ($fileName !== null && $fileName !== '') {
            $attributes['file_name'] = $fileName;
        }

        $response = $this->scope($server)
            ->getHttpClient()
            ->timeout(max((int) config('panel.guzzle.timeout', 15), $timeout))
            ->post("/api/servers/{$server->uuid}/files/pull", $attributes);

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function scope(Server $server): DaemonFileRepository
    {
        return $this->files->setServer($server);
    }
}
