<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JothamLec\MarketingToolkit\Support\Sites;

/**
 * A redirect rule as a row of the `mt_redirects` table, where the Eloquent driver stores rules (see
 * RedirectRepository; the file driver keeps them in YAML, and Rule describes a rule either way). Saving
 * or deleting a row through this model writes the database whatever the driver, so a site's own
 * migrations that insert rows keep working. Code that should follow the driver uses the repository.
 *
 * @property int $id
 * @property ?string $site
 * @property string $source
 * @property ?string $target
 * @property int $status
 * @property bool $active
 * @property bool $automatic
 * @property int $hits
 * @property ?Carbon $last_hit_at
 */
class Redirect extends Model
{
    /** @deprecated 0.29.0 Use Rule::STATUSES. Removed in 1.0. */
    public const array STATUSES = Rule::STATUSES;

    /** @deprecated 0.29.0 Use Rule::MAX_SOURCE. Removed in 1.0. */
    public const int MAX_SOURCE = Rule::MAX_SOURCE;

    protected $table = 'mt_redirects';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 301, 'active' => true, 'automatic' => false, 'hits' => 0];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'active' => 'boolean',
            'automatic' => 'boolean',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $redirect) {
            $redirect->site = $redirect->site === '' ? null : $redirect->site;
            $redirect->source = Rule::normalize($redirect->source);
            $redirect->target = $redirect->status === 410 ? null : Rule::normalizeTarget($redirect->target);
        });

        // We flush after the transaction, if any, because a request in between could cache the old rules again.
        static::saved(fn () => DB::afterCommit(fn () => Matcher::flush()));
        static::deleted(fn () => DB::afterCommit(fn () => Matcher::flush()));
    }

    /**
     * Limits the query to rules stored for exactly this site, or to the rules for every site when $site is null.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOnSite(Builder $query, ?string $site): void
    {
        $site === null ? $query->whereNull('site') : $query->where('site', $site);
    }

    /**
     * Limits the query to rules the signed-in user may manage. These are the rules on the sites they may work
     * on, and the rules for every site only if they may work on every site, because a rule for every site
     * also applies on sites they can't see (a `/*` rule could send all of those sites elsewhere).
     *
     * @param  Builder<self>  $query
     */
    public function scopeAccessible(Builder $query): void
    {
        if (Sites::multiple() && ! Sites::accessesAll()) {
            $query->whereIn('site', Sites::accessible());
        }
    }

    public function isAccessible(): bool
    {
        return $this->site === null ? Sites::accessesAll() : in_array($this->site, Sites::accessible(), true);
    }

    /**
     * Limits the query to rules that apply on this site, which are its own rules and the rules for every site.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAppliesOn(Builder $query, string $site): void
    {
        $query->where(fn (Builder $query) => $query->where('site', $site)->orWhereNull('site'));
    }

    /**
     * @deprecated 0.29.0 Use Rule::normalize(). Removed in 1.0.
     */
    public static function normalize(string $path): string
    {
        return Rule::normalize($path);
    }

    /**
     * @deprecated 0.29.0 Use Rule::ignoresCase(). Removed in 1.0.
     */
    public static function ignoresCase(): bool
    {
        return Rule::ignoresCase();
    }

    /**
     * @deprecated 0.29.0 Use Rule::key(). Removed in 1.0.
     */
    public static function key(string $path): string
    {
        return Rule::key($path);
    }

    /**
     * Finds the row that starts from this address on $site (or among the rules for every site when $site is
     * null), in any letter case when matching ignores case. Sources are stored as typed, and databases fold
     * letters differently (SQLite only folds A–Z, and MySQL folds by its collation), so we compare them here.
     * This reads the database whatever the driver; RedirectRepository::forSource() reads the driver's rules.
     */
    public static function forSource(string $source, int|string|null $ignoreId = null, ?string $site = null): ?self
    {
        $source = Rule::normalize($source);
        $query = self::query()->onSite($site)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));

        if (! Rule::ignoresCase()) {
            return $query->where('source', $source)->first();
        }

        $key = Rule::key($source);

        return $query->orderBy('id')->cursor()->first(fn (self $redirect) => Rule::key(Rule::normalize($redirect->source)) === $key);
    }

    /**
     * @deprecated 0.29.0 Use Rule::normalizeTarget(). Removed in 1.0.
     */
    public static function normalizeTarget(?string $target): ?string
    {
        return Rule::normalizeTarget($target);
    }

    /**
     * @deprecated 0.29.0 Use Rule::validator(). Removed in 1.0.
     *
     * @param  array<string, mixed>  $data
     * @param  ?Collection<int|string, Rule>  $active
     * @param  ?list<string>  $sites
     */
    public static function validator(array $data, int|string|null $ignoreId = null, ?bool $taken = null, ?Collection $active = null, ?array $sites = null): ValidatorContract
    {
        return Rule::validator($data, $ignoreId === null ? null : (string) $ignoreId, $taken, $active, $sites);
    }

    /**
     * @deprecated 0.29.0 Use Rule::rules(). Removed in 1.0.
     *
     * @param  ?Collection<int|string, Rule>  $active
     * @param  ?list<string>  $sites
     * @return array<string, mixed>
     */
    public static function rules(string $source, ?string $site, int|string|null $ignoreId = null, ?bool $taken = null, ?Collection $active = null, ?array $sites = null): array
    {
        return Rule::rules($source, $site, $ignoreId === null ? null : (string) $ignoreId, $taken, $active, $sites);
    }

    /**
     * @deprecated 0.29.0 Use Rule::messages(). Removed in 1.0.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return Rule::messages();
    }

    /**
     * @deprecated 0.29.0 Use Rule::pointsBack(). Removed in 1.0.
     */
    public static function pointsBack(string $source, ?string $target): bool
    {
        return Rule::pointsBack($source, $target);
    }

    /**
     * Returns the row as a driver-neutral rule, with its hits.
     */
    public function toRule(): Rule
    {
        return new Rule(
            id: $this->getKey() === null ? null : (string) $this->getKey(),
            site: $this->site,
            source: (string) $this->source,
            target: $this->target,
            status: (int) ($this->status ?? 301),
            active: (bool) ($this->active ?? true),
            automatic: (bool) ($this->automatic ?? false),
            hits: (int) ($this->hits ?? 0),
            lastHitAt: $this->last_hit_at,
        );
    }

    /**
     * Fills the row from a rule's fields (not its id or hits).
     */
    public function fillFromRule(Rule $rule): self
    {
        return $this->fill([
            'site' => $rule->site,
            'source' => $rule->source,
            'target' => $rule->target,
            'status' => $rule->status,
            'active' => $rule->active,
            'automatic' => $rule->automatic,
        ]);
    }

    public function isWildcard(): bool
    {
        return str_contains($this->source, '*');
    }
}
