<?php

declare(strict_types=1);

namespace McToolkit\Services\Servers;

use App\Models\Allocation;
use Illuminate\Support\Arr;

/**
 * Minimal pure-PHP implementation of the Minecraft **modern protocol** status ping
 * (post-1.7 handshake + status request over plain TCP, no compression phase).
 *
 * This is the day-one capability tier for the Players page (IMPLEMENTATION-GUIDE §4.5):
 * online/max counters plus a small `sample` list are what vanilla/Paper/Fabric expose,
 * and everything beyond that needs the companion bridge.
 *
 * Wire format recap (status handshaking):
 *   client → [packetId 0x00][protocolVersion VarInt][host String][port UShort][nextState 1]
 *   client → [packetId 0x00]                       (empty status request)
 *   server → [packetId 0x00][jsonString]           (then nothing else in status state)
 *
 * Every failure mode — refused connection, timeout, garbage bytes, invalid JSON —
 * collapses into a `null` return; callers MUST treat null as "server offline right now",
 * never as a hard error. Connection-level infrastructure failures are NOT retried here.
 *
 * Returned shape (frozen contract, CONTRACTS.md):
 *
 *     [
 *         'players' => [
 *             'online' => int,
 *             'max'    => int,
 *             'sample' => list<array{name: string, id: string}>, // id normalized dashes-out lowercase
 *         ],
 *         'motd'    => ?string, // color codes (§ / \u{00A7}) stripped, newlines collapsed
 *         'version' => ?string,
 *     ]
 */
final class ServerListPing
{
    /** Status handshake packet id (also reused for the request and response). */
    private const PACKET_STATUS_HANDSHAKE = 0x00;

    /** Protocol version sent during the handshake; -1 asks the server to pick. */
    private const HANDSHAKE_PROTOCOL_VERSION = -1;

    /** nextState for status queries (as opposed to 0x02 login). */
    private const NEXT_STATE_STATUS = 0x01;

    /** Maximum VarInt wire length (Minecraft encodes int32 fields). */
    private const VARINT_MAX_BYTES = 5;

    /**
     * Largest packet we accept (the protocol's own 3-byte VarInt length ceiling).
     * A status response — favicon included — stays far below it; anything bigger is a
     * garbage/hostile peer, and fread() pre-allocates its length argument.
     */
    private const MAX_PACKET_BYTES = 2_097_151;

    /** Upper bound for one fread() call; longer payloads are collected in a loop. */
    private const READ_CHUNK_BYTES = 8192;

    /** Smallest per-read socket timeout (µs) so a nearly spent budget is not "0 = instant". */
    private const MIN_READ_TIMEOUT_MICROS = 1000;

    /**
     * Legacy formatting codes removed from MOTD text before returning.
     * Both the raw UTF-8 § byte and its § JSON escape land here after decode.
     */
    private const MOTD_CODE_PATTERN = '/(?:\x{00a7}|§)[0-9a-fk-orA-FK-OR]/u';

    /**
     * Ping a reachable game server once.
     *
     * @param  Allocation|string  $host  either the server's primary {@see Allocation}
     *                                   (target picked like the official player-counter
     *                                   plugin: IP alias, else bind IP, wildcard binds
     *                                   replaced by the node FQDN — see normalizeHost())
     *                                   or a host literal `1.2.3.4`, `host.name`,
     *                                   `host:25565`, `[2001:db8::1]:25565`.
     * @param  int  $timeoutMs  whole-operation budget (connect + write + read).
     * @return array<string, mixed>|null null whenever the server could not be queried.
     */
    public function ping(Allocation|string $host, int $timeoutMs = 3000): ?array
    {
        [$address, $port] = $this->normalizeHost($host);

        if ($address === '' || $port < 1 || $port > 65535) {
            return null;
        }

        $deadline = microtime(true) + max(0.25, $timeoutMs / 1000);

        try {
            $socket = $this->connect($address, $port, $deadline);

            if ($socket === null) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        if ($socket === false || ! is_resource($socket)) {
            return null;
        }

        try {
            $this->sendHandshakeAndRequest($socket, $address, $port);

            $packet = $this->readPacket($socket, $deadline);
        } catch (\Throwable) {
            $this->close($socket);

            return null;
        }

        $this->close($socket);

        if ($packet === false || $packet === '') {
            return null;
        }

        // The JSON is peer-controlled: any oddity still collapses into "offline" (null).
        try {
            return $this->decodeResponsePacket($packet);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the caller-friendly host argument to concrete address/port parts.
     *
     * @return array{0: string, 1: int}
     */
    private function normalizeHost(Allocation|string $host): array
    {
        if ($host instanceof Allocation) {
            // Same target rules as the official player-counter plugin (GameQuery::runQuery):
            // an IP alias is the public address of a NAT-ed bind IP, so it wins. A wildcard
            // bind (0.0.0.0 / ::) is not an address the panel can dial — connecting to it
            // lands on the panel host itself — so the node's FQDN takes its place.
            $alias = trim(strval($host->getAttribute('ip_alias') ?? ''));
            $address = filter_var($alias, FILTER_VALIDATE_IP) !== false
                ? $alias
                : trim(strval($host->getAttribute('ip') ?? ''));

            if ($address === '' || self::isWildcardAddress($address)) {
                $address = trim(strval($host->node?->getAttribute('fqdn') ?? ''));
            }

            return [$address, intval($host->getAttribute('port'))];
        }

        $raw = trim($host);

        if ($raw === '') {
            return ['', 0];
        }

        // Bracketed IPv6 literal [addr]:port ([addr] alone keeps default port).
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $raw, $bracketed) === 1) {
            return [$bracketed[1], isset($bracketed[2]) ? intval($bracketed[2]) : 25565];
        }

        // Bare IPv6 address without brackets.
        if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [$raw, 25565];
        }

        $parts = explode(':', $raw, 2);

        $port = count($parts) === 2 && preg_match('/^\d+$/', $parts[1]) === 1
            ? intval($parts[1])
            : 25565;

        return [trim($parts[0]), $port];
    }

    /**
     * Open the TCP connection; returns a live stream resource or null on refusal/timeout.
     */
    private function connect(string $address, int $port, float $deadline): mixed
    {
        $seconds = max(0.05, min($deadline - microtime(true), 10.0));

        $context = stream_context_create(['socket' => ['tcp_nodelay' => true]]);

        // IPv6 literals need brackets inside the transport URL ("tcp://[::1]:25565").
        $target = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $address . ']'
            : $address;

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $target, $port),
            $errno,
            $errstr,
            $seconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false || $socket === null) {
            return null;
        }

        if (! is_resource($socket)) {
            return null;
        }

        stream_set_blocking($socket, true);

        if (! $this->applyReadTimeout($socket, $deadline)) {
            $this->close($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Arms the socket's per-read timeout with what is left of the whole-operation
     * budget (fractional seconds kept via fmod). Returns false once the budget is spent.
     */
    private function applyReadTimeout(mixed $socket, float $deadline): bool
    {
        $remaining = $deadline - microtime(true);

        if ($remaining <= 0) {
            return false;
        }

        $seconds = (int) floor($remaining);
        $micros = (int) floor(fmod($remaining, 1.0) * 1_000_000);

        if ($seconds === 0 && $micros < self::MIN_READ_TIMEOUT_MICROS) {
            $micros = self::MIN_READ_TIMEOUT_MICROS;
        }

        return stream_set_timeout($socket, $seconds, $micros);
    }

    /** True for the "any address" binds (0.0.0.0, ::, and their long spellings). */
    private static function isWildcardAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = @inet_pton($address);

        return $packed !== false && trim($packed, "\0") === '';
    }

    private function close(mixed $socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    private function sendHandshakeAndRequest(mixed $socket, string $address, int $port): void
    {
        $handshakeInner =
            self::varint(self::PACKET_STATUS_HANDSHAKE)
            . self::varint(self::HANDSHAKE_PROTOCOL_VERSION)
            . self::stringField($address)
            . pack('n', $port)
            . self::varint(self::NEXT_STATE_STATUS);

        $this->assertBytesWritten(fwrite($socket, self::frame($handshakeInner)));

        // Status request carries only the packet id; no compression phase was enabled.
        $requestInner = self::varint(self::PACKET_STATUS_HANDSHAKE);

        $this->assertBytesWritten(fwrite($socket, self::frame($requestInner)));
    }

    /**
     * Reads exactly ONE length-prefixed packet into memory.
     *
     * @return string|false false when the peer closed early / timed out.
     */
    private function readPacket(mixed $socket, float $deadline): string|false
    {
        $outerLength = $this->readVarInt($socket, $deadline);

        if ($outerLength === null || $outerLength < 1 || $outerLength > self::MAX_PACKET_BYTES) {
            return false;
        }

        $body = $this->readExact($socket, $outerLength, $deadline);

        if ($body === null) {
            return false;
        }

        return $body;
    }

    /**
     * Decode `packetId|jsonLen|json` and produce the frozen result shape.
     *
     * @return array<string, mixed>|null
     */
    private function decodeResponsePacket(string $packet): ?array
    {
        $reader = 0;

        $packetId = self::readBufferVarInt($packet, $reader);

        if ($packetId !== self::PACKET_STATUS_HANDSHAKE) {
            return null;
        }

        $stringLength = self::readBufferVarInt($packet, $reader);

        if ($stringLength === null || $stringLength < 0) {
            return null;
        }

        $jsonBytes = substr($packet, $reader, $stringLength);

        if ($jsonBytes === false || strlen($jsonBytes) !== $stringLength) {
            return null;
        }

        try {
            $decoded = json_decode($jsonBytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $sampleEntries = [];

        foreach (Arr::wrap($decoded['players']['sample'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = is_string($entry['name'] ?? null) ? strval($entry['name']) : '';

            if ($name === '') {
                continue;
            }

            // Peer-controlled JSON: a non-string id must not reach strval() (array → warning).
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : '';

            $sampleEntries[] = [
                'name' => $name,
                'id' => strtolower(str_replace('-', '', $id)),
            ];
        }

        return [
            'players' => [
                'online' => max(0, intval($decoded['players']['online'] ?? 0)),
                'max' => max(0, intval($decoded['players']['max'] ?? 0)),
                'sample' => $sampleEntries,
            ],
            'motd' => $this->flattenDescription($decoded['description'] ?? null),
            'version' => is_string($decoded['version']['name'] ?? null)
                ? strval($decoded['version']['name'])
                : null,
        ];
    }

    /* ---------------------------------------------------------------------
     |  Wire helpers
     --------------------------------------------------------------------- */

    /** Frame = VarInt(payload length) + payload. */
    private static function frame(string $payload): string
    {
        return self::varint(strlen($payload)) . $payload;
    }

    /** Standard int32 VarInt encoding, tolerating negatives via unsigned wrap. */
    public static function varint(int $value): string
    {
        $value &= 0xFFFFFFFF;

        $bytes = '';

        do {
            if (($value & ~0x7F) === 0) {
                $bytes .= chr($value);

                break;
            }

            $bytes .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        } while (true);

        return $bytes;
    }

    /** Length-prefixed UTF-8 string fragment used by the handshake. */
    private static function stringField(string $value): string
    {
        return self::varint(strlen($value)) . $value;
    }

    /**
     * Blocking bounded read of exactly $length bytes: one fread() rarely returns a
     * whole packet, so chunks (≤ READ_CHUNK_BYTES each) are collected until done, with
     * the socket timeout re-armed from the overall deadline before every read.
     */
    private function readExact(mixed $socket, int $length, float $deadline): ?string
    {
        $collected = '';
        $remaining = $length;

        while ($remaining > 0) {
            if (! $this->applyReadTimeout($socket, $deadline)) {
                return null;
            }

            $chunk = @fread($socket, min($remaining, self::READ_CHUNK_BYTES));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $collected .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $collected;
    }

    /** Bounded blocking VarInt read straight from the socket. */
    private function readVarInt(mixed $socket, float $deadline): ?int
    {
        $value = 0;
        $shift = 0;
        $count = 0;

        do {
            if ($count++ >= self::VARINT_MAX_BYTES || microtime(true) >= $deadline) {
                return null;
            }

            $byteFragment = $this->readExact($socket, 1, $deadline);

            if ($byteFragment === null) {
                return null;
            }

            $current = ord($byteFragment);
            $value |= ($current & 0x7F) << $shift;
            $shift += 7;
        } while (($current & 0x80) !== 0);

        return $value;
    }

    /** In-buffer VarInt walk sharing the wire semantics with {@see self::readVarInt()}. */
    private static function readBufferVarInt(string $buffer, int &$offset): ?int
    {
        $value = 0;
        $shift = 0;
        $count = 0;

        do {
            if ($offset >= strlen($buffer) || $count++ >= self::VARINT_MAX_BYTES) {
                return null;
            }

            $byte = ord($buffer[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return $value;
    }

    private function assertBytesWritten(int|false $written): void
    {
        if ($written === false || $written === 0) {
            throw new \RuntimeException('Write failed');
        }
    }

    /**
     * Flattens `description` variants (plain string | {"text"} object | chat segment
     * lists | extra segments) into one plain-text line, then strips formatting codes.
     */
    private function flattenDescription(mixed $description): ?string
    {
        $textOf = null;

        $textOf = static function (mixed $node) use (&$textOf): string {
            if (is_string($node)) {
                return $node;
            }

            if (! is_array($node)) {
                return '';
            }

            if (array_is_list($node)) {
                $joinedParts = [];

                foreach ($node as $child) {
                    $joinedParts[] = $textOf($child);
                }

                return implode('', $joinedParts);
            }

            if (isset($node['extra']) && is_array($node['extra'])) {
                return (is_string($node['text'] ?? null) ? strval($node['text']) : '')
                    . $textOf($node['extra']);
            }

            return is_string($node['text'] ?? null) ? strval($node['text']) : '';
        };

        // json_decode has already turned § escapes into raw § bytes at this point.
        $collapsed = str_replace(["\r", "\n"], ' ', $textOf($description));
        $stripped = trim(preg_replace_callback(self::MOTD_CODE_PATTERN, static fn (): string => '', $collapsed) ?? '');

        return $stripped === '' ? null : $stripped;
    }
}
