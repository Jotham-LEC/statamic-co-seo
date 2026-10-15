<?php

namespace JothamLec\MarketingToolkit\Redirects\Eloquent;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JothamLec\MarketingToolkit\Events\RedirectDeleted;
use JothamLec\MarketingToolkit\Events\RedirectSaved;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;

/**
 * The Eloquent driver: rules are rows of `mt_redirects` (the Redirect model), and each row counts its own
 * hits. The model's events flush the cached rules after each save and delete.
 */
final class EloquentRedirectRepository implements RedirectRepository
{
    private const array COLUMNS = ['id', 'site', 'source', 'target', 'status'];

    public function driver(): string
    {
        return 'eloquent';
    }

    public function all(): Collection
    {
        return Redirect::query()->orderBy('id')->get()->map(fn (Redirect $redirect) => $redirect->toRule())->values();
    }

    public function find(string $id): ?Rule
    {
        return ctype_digit($id) ? Redirect::query()->find((int) $id)?->toRule() : null;
    }

    public function onSite(?string $site): Collection
    {
        return Redirect::query()->onSite($site)->orderBy('id')->get()->map(fn (Redirect $redirect) => $redirect->toRule())->values();
    }

    public function appliesOn(string $site): Collection
    {
        return Redirect::query()->where('active', true)->appliesOn($site)->orderBy('id')->get(self::COLUMNS)
            ->map(fn (Redirect $redirect) => $redirect->toRule())->values();
    }

    /**
     * Reads the rule from this exact address and the wildcards. When ignoring case, an exact source can't
     * be looked up in SQL (see Redirect::forSource()), so all the active rules are read and compared here.
     */
    public function candidates(string $path, ?string $site, ?string $ignoreId = null): Collection
    {
        $query = Redirect::query()->where('active', true)
            ->when($ignoreId !== null && ctype_digit($ignoreId), fn (Builder $query) => $query->whereKeyNot((int) $ignoreId))
            ->when($site, fn (Builder $query, string $site) => $query->appliesOn($site))
            ->orderBy('id');

        if (Rule::ignoresCase()) {
            return $query->get(self::COLUMNS)
                ->filter(fn (Redirect $redirect) => str_contains($redirect->source, '*') || Rule::key(Rule::normalize($redirect->source)) === Rule::key($path))
                ->map(fn (Redirect $redirect) => $redirect->toRule())->values();
        }

        return $query->where(fn (Builder $query) => $query->where('source', $path)->orWhere('source', 'like', '%*%'))->get(self::COLUMNS)
            ->map(fn (Redirect $redirect) => $redirect->toRule())->values();
    }

    public function forSource(string $source, ?string $site = null, ?string $ignoreId = null): ?Rule
    {
        return Redirect::forSource($source, $ignoreId !== null && ctype_digit($ignoreId) ? (int) $ignoreId : null, $site)?->toRule();
    }

    public function pointingTo(array $targets, ?string $site): Collection
    {
        return Redirect::query()
            ->where('active', true)
            ->when($site, fn (Builder $query, string $site) => $query->appliesOn($site))
            ->whereIn('target', $targets)
            ->orderByDesc('hits')
            ->orderBy('id')
            ->get()
            ->map(fn (Redirect $redirect) => $redirect->toRule())->values();
    }

    public function countActive(?string $site, bool $automatic = false): int
    {
        return Redirect::query()->where('active', true)
            ->when($site, fn (Builder $query, string $site) => $query->appliesOn($site))
            ->when($automatic, fn (Builder $query) => $query->where('automatic', true))
            ->count();
    }

    public function listing(?string $search, string $sort, string $order, int $perPage, ?array $sites): LengthAwarePaginator
    {
        $query = Redirect::query()->when($sites !== null, fn (Builder $query) => $query->whereIn('site', $sites));

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $query) use ($search) {
                foreach (['source', 'target'] as $field) {
                    // This becomes `ilike` on Postgres, because Postgres's `like` is case-sensitive.
                    $query->orWhereLike($field, '%'.$search.'%', caseSensitive: false);
                }
            });
        }

        return $query->orderBy($sort, $order)->orderBy('id')->paginate($perPage)->through(fn (Redirect $redirect) => $redirect->toRule());
    }

    public function save(Rule $rule): Rule
    {
        $redirect = ($rule->id !== null && ctype_digit($rule->id) ? Redirect::query()->find((int) $rule->id) : null) ?? new Redirect;
        $redirect->fillFromRule($rule)->save();

        $saved = $redirect->toRule();
        $rule->fill($saved->toArray());

        RedirectSaved::dispatch($rule);

        return $rule;
    }

    public function delete(Rule $rule): void
    {
        if ($rule->id !== null && ctype_digit($rule->id)) {
            Redirect::query()->find((int) $rule->id)?->delete();
        }

        RedirectDeleted::dispatch($rule);
    }

    public function batch(Closure $callback): mixed
    {
        return Matcher::flushAfter(fn () => DB::transaction($callback));
    }

    public function recordHit(string $id): void
    {
        if (ctype_digit($id)) {
            Redirect::query()->whereKey((int) $id)->increment('hits', 1, ['last_hit_at' => now()]);
        }
    }

    public function stats(array $ids): array
    {
        return Redirect::query()->whereIn('id', array_map('intval', array_filter($ids, 'ctype_digit')))->get(['id', 'hits', 'last_hit_at'])
            ->mapWithKeys(fn (Redirect $redirect) => [(string) $redirect->id => ['hits' => (int) $redirect->hits, 'last_hit_at' => $redirect->last_hit_at]])
            ->all();
    }

    public function problems(): array
    {
        return [];
    }
}
