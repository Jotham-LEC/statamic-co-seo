<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The hits of a rule kept in files (the file driver), by its id. Files are committed with the site, so a
 * visit never writes one: the count is kept here instead.
 *
 * @property int $id
 * @property string $redirect_id
 * @property int $hits
 * @property ?Carbon $last_hit_at
 */
class RedirectHit extends Model
{
    protected $table = 'mt_redirect_hits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'last_hit_at' => 'datetime'];
    }

    /**
     * Counts one visit, as the 404 log counts one: an update, an insert when there was no row, and an
     * update again when another request inserted the row a moment before.
     */
    public static function record(string $id): void
    {
        $now = now();

        if (self::query()->where('redirect_id', $id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => $now, 'updated_at' => $now])) {
            return;
        }

        try {
            // A savepoint, because on Postgres a failed statement would abort a transaction this runs in.
            DB::transaction(fn () => self::query()->create(['redirect_id' => $id, 'hits' => 1, 'last_hit_at' => $now]));
        } catch (UniqueConstraintViolationException) {
            self::query()->where('redirect_id', $id)->increment('hits', 1, ['last_hit_at' => $now]);
        }
    }

    /**
     * Adds counts from elsewhere (the database driver's rows) to a rule's, keeping the larger count and
     * the later date.
     */
    public static function merge(string $id, int $hits, ?Carbon $lastHitAt): void
    {
        $row = self::query()->firstOrNew(['redirect_id' => $id]);
        $row->hits = max((int) $row->hits, $hits);
        $row->last_hit_at = $row->last_hit_at === null || ($lastHitAt !== null && $lastHitAt->greaterThan($row->last_hit_at)) ? $lastHitAt : $row->last_hit_at;
        $row->save();
    }
}
