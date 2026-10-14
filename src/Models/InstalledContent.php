<?php

declare(strict_types=1);

namespace McToolkit\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One toolkit-tracked content file (jar/pack) on a server.
 *
 * Backed by the mc_installed_content table; unique per (server_id, file_name).
 */
class InstalledContent extends Model
{
    public const KIND_PLUGIN = 'plugin';
    public const KIND_MOD = 'mod';
    public const KIND_MODPACK = 'modpack';

    /** @var string[] */
    public const KINDS = [
        self::KIND_PLUGIN,
        self::KIND_MOD,
        self::KIND_MODPACK,
    ];

    protected $table = 'mc_installed_content';

    /** @var string[] */
    protected $fillable = [
        'server_id',
        'kind',
        'registry',
        'project_id',
        'file_name',
        'version',
        'dependencies',
        'checksum',
        'tracked_by_toolkit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dependencies' => 'array',
            'tracked_by_toolkit' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Scope everything to one server — every page query funnels through here. */
    public function scopeForServer(Builder $query, Server $server): Builder
    {
        return $query->where('server_id', $server->id);
    }

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /**
     * Whether this row is one file delivered by a modpack (its project_id is the PACK's id,
     * so it must never be matched, updated or retired as a standalone project).
     */
    public function isPackChild(): bool
    {
        return is_array($this->dependencies) && is_string($this->dependencies['pack'] ?? null);
    }

    /** Human title recorded at install time (pack name for pack children), '' when unknown. */
    public function displayTitle(): string
    {
        $deps = is_array($this->dependencies) ? $this->dependencies : [];

        foreach (['title', 'pack'] as $key) {
            if (is_string($deps[$key] ?? null) && trim($deps[$key]) !== '') {
                return trim($deps[$key]);
            }
        }

        return '';
    }
}
