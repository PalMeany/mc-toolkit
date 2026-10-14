<?php

declare(strict_types=1);

namespace McToolkit\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Small shared helper for cached JSON over HTTP, used by every catalog service.
 *
 * Owner: A3.
 *
 * All requests share the plugin-wide client policy: 10s total timeout,
 * 5s connect timeout and two automatic retries (100ms backoff) on transport errors / 5xx.
 * Cached payloads are keyed under the `mctoolkit.http.` cache prefix so
 * admins can clear them together with the rest of the plugin state.
 */
final class HttpJsonCache
{
    public const PREFIX = 'mctoolkit.http.';

    /** Modrinth and Spiget ask API clients to identify themselves instead of sending Guzzle's default. */
    public const USER_AGENT = 'mc-toolkit (Pelican panel plugin; +https://github.com/PalMeany/mc-toolkit)';

    /**
     * Fetch a JSON document, decoding it defensively (associative arrays only),
     * with a short-lived cache in front of the network call.
     *
     * @param  string  $url  absolute URL to GET
     * @param  int  $ttlSeconds  cache lifetime; 0 disables caching entirely
     * @param  array<string, scalar|array|null>  $query  query parameters merged onto the URL
     * @param  array<string, string>  $headers  extra request headers (values must be strings)
     * @return mixed decoded JSON payload or null when the body was not decodable JSON
     *
     * @throws ConnectionException transport failure after retries
     * @throws RequestException non-2xx response after retries
     */
    public static function get(string $url, int $ttlSeconds = 0, array $query = [], array $headers = []): mixed
    {
        if ($ttlSeconds <= 0) {
            return self::fetch($url, $query, $headers);
        }

        $key = self::PREFIX.hash('sha256', $url.'|'.serialize($query).'|'.serialize($headers));

        /** @var array<int|string, mixed>|null $cached */
        $cached = cache()->get($key);

        // Cache entries may be missing OR stored as an explicit null sentinel;
        // an absent key means "go fetch".
        if ($cached !== null || array_key_exists('payload', is_array($cached) ? $cached : [])) {
            if (is_array($cached) && array_key_exists('payload', $cached)) {
                return $cached['payload'];
            }
        }

        $payload = self::fetch($url, $query, $headers);

        cache()->put($key, ['payload' => $payload, 'fetched_at' => now()->toIso8601String()], $ttlSeconds);

        return $payload;
    }

    /**
     * Uncached variant of {@see get()}.
     */
    public static function fetch(string $url, array $query = [], array $headers = []): mixed
    {
        $response = Http::asJson()
            ->acceptJson()
            ->withUserAgent(self::USER_AGENT)
            ->timeout(10)
            ->connectTimeout(5)
            // Only transport failures and 5xx are worth another attempt; a 404 or 429
            // answers the same way 100ms later.
            ->retry(2, 100, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()))
            ->when($headers !== [], fn ($client) => $client->withHeaders($headers))
            ->get($url, $query);

        // Fail loudly on non-2xx so callers can classify (rate limits, auth keys, ...).
        $response->throw();

        return $response->json();
    }
}
