<?php

namespace JothamLec\MarketingToolkit\Cp;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Statamic\CP\Column;

/**
 * Answers the requests of Statamic's `<Listing>` component for an Eloquent
 * table, or for a page of rows fetched elsewhere (page()). It handles search,
 * sorting by a listed column, and pagination, and it returns the columns in
 * the shape the component expects.
 */
final class Listing
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string>  $columns  Maps each field to its label, and the first field is the default sort.
     * @param  list<string>  $searchable
     * @param  Closure(Collection<int, TModel>): iterable<array<string, mixed>>  $rows  Maps the page's models to rows.
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function respond(Builder $query, Request $request, array $columns, array $searchable, Closure $rows, string $defaultOrder = 'asc'): array
    {
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function (Builder $query) use ($searchable, $search) {
                foreach ($searchable as $field) {
                    // This becomes `ilike` on Postgres, because Postgres's `like` is case-sensitive.
                    $query->orWhereLike($field, '%'.$search.'%', caseSensitive: false);
                }
            });
        }

        [$sort, $order, $perPage] = self::sorting($request, $columns, $defaultOrder);

        return self::page($query->orderBy($sort, $order)->orderBy('id')->paginate($perPage), $columns, $rows);
    }

    /**
     * Reads the sort column, the order and the page size the listing asks for, keeping to the listed
     * columns, `asc` or `desc`, and 10 to 500 rows.
     *
     * @param  array<string, string>  $columns
     * @return array{string, string, int}
     */
    public static function sorting(Request $request, array $columns, string $defaultOrder = 'asc'): array
    {
        $sort = array_key_exists((string) $request->input('sort'), $columns) ? (string) $request->input('sort') : (string) array_key_first($columns);
        $order = in_array($request->input('order'), ['asc', 'desc'], true) ? (string) $request->input('order') : $defaultOrder;

        return [$sort, $order, min(500, max(10, (int) $request->input('perPage', 50)))];
    }

    /**
     * Answers with a page already fetched, such as a page of redirects from either driver.
     *
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $page
     * @param  array<string, string>  $columns
     * @param  Closure(Collection<int, TItem>): iterable<array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function page(LengthAwarePaginator $page, array $columns, Closure $rows): array
    {
        return [
            'data' => collect($rows($page instanceof AbstractPaginator ? $page->getCollection() : collect($page->items())))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'activeFilterBadges' => [],
                'columns' => collect($columns)->map(fn ($label, $field) => Column::make($field)
                    ->label($label)
                    ->listable(true)
                    ->visible(true)
                    ->defaultVisibility(true)
                    ->sortable(true)
                    ->toArray())->values()->all(),
            ],
        ];
    }
}
