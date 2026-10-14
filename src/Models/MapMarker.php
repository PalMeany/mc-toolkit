<?php

declare(strict_types=1);

namespace McToolkit\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-drawn pin on the toolkit map.
 *
 * Backed by the mc_map_markers table; indexed on (server_id, dimension).
 */
class MapMarker extends Model
{
    public const DIMENSION_OVERWORLD = 'overworld';
    public const DIMENSION_NETHER = 'nether';
    public const DIMENSION_END = 'end';

    protected $table = 'mc_map_markers';

    /** @var string[] */
    protected $fillable = [
        'server_id',
        'label',
        'x',
        'z',
        'dimension',
        'color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'z' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Scope everything to one server. */
    public function scopeForServer(Builder $query, Server $server): Builder
    {
        return $query->where('server_id', $server->id);
    }

    public function scopeDimension(Builder $query, string $dimension): Builder
    {
        return $query->where('dimension', $dimension);
    }
}
