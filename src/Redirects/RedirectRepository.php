<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where redirect rules are stored: in files (`redirects.driver` set to `file`, the default) or in the
 * `mt_redirects` table (`eloquent`). Every part of the addon reads and writes rules through this, so it
 * works the same on either. Resolve it with `app(RedirectRepository::class)` when you need it, rather
 * than holding on to it: the driver is chosen from the config each time (see Drivers).
 *
 * A site handle of null means the rules for every site (the only kind on a single site).
 */
interface RedirectRepository
{
    /**
     * Names the driver: `file` or `eloquent`.
     */
    public function driver(): string;

    /**
     * Every rule, on every site.
     *
     * @return Collection<int, Rule>
     */
    public function all(): Collection;

    public function find(string $id): ?Rule;

    /**
     * The rules stored for exactly this site, or the rules for every site when $site is null.
     *
     * @return Collection<int, Rule>
     */
    public function onSite(?string $site): Collection;

    /**
     * The active rules that apply on $site: its own and those for every site. These are what the matcher
     * compiles. Where a file holds two rules with one source, only the first is returned.
     *
     * @return Collection<int, Rule>
     */
    public function appliesOn(string $site): Collection;

    /**
     * The active rules that apply on $site (every rule when $site is null) and could match $path: those
     * whose source is $path (in any letter case when matching ignores case) and every wildcard. The rule
     * $ignoreId stands aside.
     *
     * @return Collection<int, Rule>
     */
    public function candidates(string $path, ?string $site, ?string $ignoreId = null): Collection;

    /**
     * Finds the rule that starts from this address on $site (or among the rules for every site when $site
     * is null), in any letter case when matching ignores case.
     */
    public function forSource(string $source, ?string $site = null, ?string $ignoreId = null): ?Rule;

    /**
     * The active rules that apply on $site (every rule when $site is null) whose target is one of these, with
     * their hits, most used first.
     *
     * @param  list<string>  $targets
     * @return Collection<int, Rule>
     */
    public function pointingTo(array $targets, ?string $site): Collection;

    /**
     * Counts the active rules that apply on $site (every rule when $site is null), or only the automatic ones.
     */
    public function countActive(?string $site, bool $automatic = false): int;

    /**
     * One page of the rules on $sites (null for every rule), with their hits, for the Redirects screen.
     * $search looks in the source and target in any letter case; $sort is a field of the listing
     * (`source`, `target`, `site`, `status`, `active`, `hits`, `last_hit_at`).
     *
     * @param  ?list<string>  $sites  the sites whose rules are listed, or null for every rule
     * @return LengthAwarePaginator<int, Rule>
     */
    public function listing(?string $search, string $sort, string $order, int $perPage, ?array $sites): LengthAwarePaginator;

    /**
     * Saves the rule, tidied as normalized() tidies it, and gives a new rule its id.
     */
    public function save(Rule $rule): Rule;

    public function delete(Rule $rule): void;

    /**
     * Runs $callback, which saves or deletes many rules, and writes them once at the end: in one
     * transaction in the database, or each changed file once. The cached rules are flushed once, after.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function batch(Closure $callback): mixed;

    /**
     * Counts a visit that the rule answered. It runs after the response has gone out.
     */
    public function recordHit(string $id): void;

    /**
     * Reads the hits and last use of these rules.
     *
     * @param  list<string>  $ids
     * @return array<array-key, array{hits: int, last_hit_at: ?Carbon}> by id (PHP makes a numeric id an int key)
     */
    public function stats(array $ids): array;

    /**
     * Problems an editor should know about, for the Redirects screen: a file that doesn't parse, two rules
     * with one source, and the like. Each is a level (`error` or `warning`) and a message.
     *
     * @return list<array{level: string, message: string}>
     */
    public function problems(): array;
}
