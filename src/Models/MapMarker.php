<?php

declare(strict_types=1);

namespace McToolkit\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * A user-drawn pin on the toolkit map.
 *
 * Backed by the mc_map_markers table; indexed on (server_id, dimension). Markers are
 * scoped to one world folder through the nullable `world` column (migration
 * 0001_01_01_000001); rows created before that column existed carry NULL and stay
 * visible on every world of their dimension.
 *
 * @property int $id
 * @property int $server_id
 * @property string $label
 * @property int $x
 * @property int $z
 * @property string $dimension
 * @property ?string $world
 * @property ?string $color
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
        'world',
        'color',
    ];

    /** Per-process memo of the `world` column check (migration may still be pending). */
    private static ?bool $hasWorldColumn = null;

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

    /** True once the `world` column migration has run. */
    public static function supportsWorldColumn(): bool
    {
        if (self::$hasWorldColumn === null) {
            try {
                self::$hasWorldColumn = Schema::hasColumn((new self())->getTable(), 'world');
            } catch (\Throwable) {
                self::$hasWorldColumn = false;
            }
        }

        return self::$hasWorldColumn;
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

    /** Markers of one world folder plus legacy rows that never recorded a world. */
    public function scopeForWorld(Builder $query, string $world): Builder
    {
        if (! self::supportsWorldColumn()) {
            return $query;
        }

        return $query->where(static function (Builder $inner) use ($world): void {
            $inner->where('world', $world)->orWhereNull('world');
        });
    }
}
