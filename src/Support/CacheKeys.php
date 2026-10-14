<?php

declare(strict_types=1);

namespace McToolkit\Support;

use App\Models\Server;

/**
 * Central cache-key namespace helper for the minecraft-toolkit plugin.
 *
 * Every cache entry the toolkit writes is prefixed with `mctoolkit.` and scoped to a server
 * (uuid_short when a Server model is in hand, raw scalar otherwise).
 *
 * TTLs mirror the config defaults defined in config/minecraft-toolkit.php under the
 * `cache.*` tree (seconds). Services should always read the runtime value:
 *
 *     $ttl = (int) config('minecraft-toolkit.cache.detection_ttl', CacheKeys::DETECTION_TTL_SECONDS);
 */
final class CacheKeys
{
    /** Root prefix of every toolkit cache key (see CONTRACTS.md: cache prefix mctoolkit.*). */
    public const PREFIX = 'mctoolkit';

    /** DetectionService::detect() result — 15 minutes per contract. */
    public const DETECTION_TTL_SECONDS = 900;

    /** McJarsCatalogService::types() payload — 6 hours per contract. */
    public const MCJARS_TYPES_TTL_SECONDS = 21600;

    /** PerformanceSampler rolling-history staleness so charts survive short polls. */
    public const PERFORMANCE_STALENESS_SECONDS = 30;

    /**
     * Builds `mctoolkit.<key>.<server-uuid-short>` (or `<server-id>` / `<raw>` for non-model
     * context values). Keys are restricted to letters, digits and . _ - so they stay stable
     * across cache drivers (redis/memcached/array).
     *
     * Examples:
     *   ns('detect', $server)        => "mctoolkit.detect.4f2g"
     *   ns('mcjars.types', 'v1')     => "mctoolkit.mcjars.types.v1"
     */
    public static function ns(string $key, Server|string|int $ctx): string
    {
        return sprintf(
            '%s.%s.%s',
            self::PREFIX,
            self::sanitizeKey($key),
            self::scope($ctx),
        );
    }

    private static function sanitizeKey(string $key): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._-]+/', '', trim($key)) ?? '';

        return str($cleaned)->lower()->toString();
    }

    private static function scope(Server|string|int $ctx): string
    {
        if ($ctx instanceof Server) {
            $short = (string) $ctx->getAttribute('uuid_short');

            return $short === '' ? (string) ($ctx->getKey() ?? '') : $short;
        }

        if (is_int($ctx)) {
            return (string) $ctx;
        }

        return trim($ctx) === '' ? 'global' : trim($ctx);
    }
}
