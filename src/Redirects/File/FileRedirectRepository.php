<?php

namespace JothamLec\MarketingToolkit\Redirects\File;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\Events\RedirectDeleted;
use JothamLec\MarketingToolkit\Events\RedirectSaved;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\RedirectHit;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Sites;
use RuntimeException;

/**
 * The file driver: rules are YAML files in `redirects.directory`, one per site (RedirectsStore), committed
 * with the site. Hits are counted in the `mt_redirect_hits` table, so a visit never writes a file. Every
 * question is answered from the files in memory, which suits thousands of rules; for tens of thousands,
 * the Eloquent driver is the better fit.
 */
final class FileRedirectRepository implements RedirectRepository
{
    /** @var array<string, SiteRules> the files changed in the batch that is running, by key */
    private array $changed = [];

    /** @var list<array{class-string, Rule}> the events to fire once the batch is written */
    private array $events = [];

    private int $depth = 0;

    public function __construct(private RedirectsStore $store) {}

    public function driver(): string
    {
        return 'file';
    }

    public function all(): Collection
    {
        return collect($this->files())->flatMap(fn (SiteRules $file) => $this->copies($file->rules))->values();
    }

    public function find(string $id): ?Rule
    {
        foreach ($this->files() as $file) {
            foreach ($file->rules as $rule) {
                if ($rule->id === $id) {
                    return $this->withStats(collect([clone $rule]))->first();
                }
            }
        }

        return null;
    }

    public function onSite(?string $site): Collection
    {
        return collect($this->copies($this->files()[SiteRules::keyFor($site)]->rules ?? []));
    }

    public function appliesOn(string $site): Collection
    {
        $files = $this->files();
        $everySite = $this->firstOfEach($files[SiteRules::EVERY_SITE]->rules ?? []);
        $own = $site === SiteRules::EVERY_SITE ? [] : $this->firstOfEach($files[$site]->rules ?? []);

        return collect([...$everySite, ...$own])->filter(fn (Rule $rule) => $rule->active)->map(fn (Rule $rule) => clone $rule)->values();
    }

    public function candidates(string $path, ?string $site, ?string $ignoreId = null): Collection
    {
        $key = Rule::key($path);

        return ($site === null ? $this->all()->filter(fn (Rule $rule) => $rule->active) : $this->appliesOn($site))
            ->reject(fn (Rule $rule) => $ignoreId !== null && $rule->id === $ignoreId)
            ->filter(fn (Rule $rule) => $rule->isWildcard() || Rule::key(Rule::normalize($rule->source)) === $key)
            ->values();
    }

    public function forSource(string $source, ?string $site = null, ?string $ignoreId = null): ?Rule
    {
        $key = Rule::key(Rule::normalize($source));

        return $this->onSite($site)->first(fn (Rule $rule) => ($ignoreId === null || $rule->id !== $ignoreId) && Rule::key(Rule::normalize($rule->source)) === $key);
    }

    public function pointingTo(array $targets, ?string $site): Collection
    {
        $rules = ($site === null ? $this->all() : $this->appliesOn($site))
            ->filter(fn (Rule $rule) => $rule->active && in_array($rule->target, $targets, true))
            ->values();

        return $this->withStats($rules)->sort(fn (Rule $a, Rule $b) => $b->hits <=> $a->hits)->values();
    }

    public function countActive(?string $site, bool $automatic = false): int
    {
        return ($site === null ? $this->all() : $this->appliesOn($site))
            ->filter(fn (Rule $rule) => $rule->active && (! $automatic || $rule->automatic))
            ->count();
    }

    public function listing(?string $search, string $sort, string $order, int $perPage, ?array $sites): LengthAwarePaginatorContract
    {
        $rules = $this->all()
            ->filter(fn (Rule $rule) => $sites === null || in_array($rule->site, $sites, true))
            ->when($search !== null && $search !== '', fn (Collection $rules) => $rules->filter(
                fn (Rule $rule) => mb_stripos($rule->source, (string) $search) !== false || mb_stripos((string) $rule->target, (string) $search) !== false,
            ));

        // Sorting by hits needs every rule's, read in one query; otherwise only the page's are read.
        $byStats = in_array($sort, ['hits', 'last_hit_at'], true);

        if ($byStats) {
            $rules = $this->withStats($rules);
        }

        $value = fn (Rule $rule) => match ($sort) {
            'target' => (string) $rule->target,
            'site' => (string) $rule->site,
            'status' => $rule->status,
            'active' => (int) $rule->active,
            'automatic' => (int) $rule->automatic,
            'hits' => $rule->hits,
            'last_hit_at' => $rule->lastHitAt?->getTimestamp() ?? 0,
            default => $rule->source,
        };
        $direction = $order === 'desc' ? -1 : 1;
        $sorted = $rules->sort(fn (Rule $a, Rule $b) => ($value($a) <=> $value($b)) * $direction ?: strcmp((string) $a->id, (string) $b->id))->values();

        $page = Paginator::resolveCurrentPage();
        $items = $sorted->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            $byStats ? $items : $this->withStats($items),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    public function save(Rule $rule): Rule
    {
        $rule->normalized();

        if ($rule->site === SiteRules::EVERY_SITE) {
            throw new RuntimeException(__('marketing-toolkit::cp.redirects.files.all_handle'));
        }

        return $this->batch(function () use ($rule) {
            $rule->id ??= (string) Str::uuid();
            $key = SiteRules::keyFor($rule->site);

            // A rule that moved to another site leaves its old file.
            foreach ($this->files() as $file) {
                if ($file->key !== $key && $this->without($file, $rule->id)) {
                    $this->change($file);
                }
            }

            $file = $this->working($key);
            $saved = clone $rule;
            $saved->hits = 0;
            $saved->lastHitAt = null;
            $index = $this->indexOf($file, $rule->id);
            $index === null ? $file->rules[] = $saved : $file->rules[$index] = $saved;
            $this->change($file);
            $this->events[] = [RedirectSaved::class, $rule];

            return $rule;
        });
    }

    public function delete(Rule $rule): void
    {
        if ($rule->id === null) {
            return;
        }

        $this->batch(function () use ($rule) {
            foreach ($this->files() as $file) {
                if ($this->without($file, (string) $rule->id)) {
                    $this->change($file);
                }
            }

            rescue(fn () => RedirectHit::query()->where('redirect_id', $rule->id)->delete());
            $this->events[] = [RedirectDeleted::class, $rule];
        });
    }

    /**
     * Collects the changes $callback makes and writes each changed file once at the end, then flushes the
     * compiled rules once. If $callback throws, nothing is written.
     */
    public function batch(Closure $callback): mixed
    {
        $this->depth++;

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            if (--$this->depth === 0) {
                $this->changed = [];
                $this->events = [];
            }

            throw $exception;
        }

        if (--$this->depth > 0) {
            return $result;
        }

        [$changed, $events] = [$this->changed, $this->events];
        $this->changed = [];
        $this->events = [];

        foreach ($changed as $file) {
            // A rule typed into the file without an id gets one now.
            foreach ($file->rules as $rule) {
                $rule->id ??= (string) Str::uuid();
            }

            $this->store->save($file);
        }

        if ($changed !== []) {
            Matcher::flush();
        }

        foreach ($events as [$event, $rule]) {
            $event::dispatch($rule);
        }

        return $result;
    }

    public function recordHit(string $id): void
    {
        RedirectHit::record($id);
    }

    public function stats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = rescue(fn () => RedirectHit::query()->whereIn('redirect_id', $ids)->get(), collect(), report: false);

        return $rows->mapWithKeys(fn (RedirectHit $row) => [$row->redirect_id => ['hits' => (int) $row->hits, 'last_hit_at' => $row->last_hit_at]])->all();
    }

    public function problems(): array
    {
        $problems = [];
        $handles = Sites::handles();

        if (in_array(SiteRules::EVERY_SITE, $handles, true)) {
            $problems[] = ['level' => 'error', 'message' => __('marketing-toolkit::cp.redirects.files.all_handle')];
        }

        foreach ($this->store->files() as $file) {
            $name = self::relative($file->path());

            if ($file->error !== null) {
                $problems[] = ['level' => 'error', 'message' => __('marketing-toolkit::cp.redirects.files.unreadable', ['file' => $name, 'error' => $file->error])];

                continue;
            }

            if ($file->key !== SiteRules::EVERY_SITE && ! in_array($file->key, $handles, true)) {
                $problems[] = ['level' => 'warning', 'message' => __('marketing-toolkit::cp.redirects.files.unknown_site', ['file' => $name, 'site' => $file->key])];

                continue;
            }

            $withoutId = count(array_filter($file->rules, fn (Rule $rule) => $rule->id === null));

            if ($withoutId > 0) {
                $problems[] = ['level' => 'warning', 'message' => trans_choice('marketing-toolkit::cp.redirects.files.no_id', $withoutId, ['file' => $name, 'count' => $withoutId])];
            }

            $sources = [];
            $ids = [];

            foreach ($file->rules as $rule) {
                $key = Rule::key(Rule::normalize($rule->source));

                if (isset($sources[$key])) {
                    $problems[] = ['level' => 'warning', 'message' => __('marketing-toolkit::cp.redirects.files.same_source', ['file' => $name, 'source' => $rule->source])];
                }

                if ($rule->id !== null && isset($ids[$rule->id])) {
                    $problems[] = ['level' => 'warning', 'message' => __('marketing-toolkit::cp.redirects.files.same_id', ['file' => $name, 'id' => $rule->id])];
                }

                $sources[$key] = true;
                $ids[$rule->id ?? ''] = $rule->id !== null;
            }
        }

        return $problems;
    }

    /**
     * Returns the files of the sites this install has, and the file for every site, with the changes of the
     * batch that is running.
     *
     * @return array<string, SiteRules>
     */
    private function files(): array
    {
        $handles = Sites::handles();
        $files = array_filter(
            array_replace($this->store->files(), $this->changed),
            fn (SiteRules $file) => $file->key === SiteRules::EVERY_SITE || in_array($file->key, $handles, true),
        );
        ksort($files);

        return $files;
    }

    /**
     * Returns the file for $key as the batch is changing it: a copy, so the store's own is left alone
     * until it is written.
     */
    private function working(string $key): SiteRules
    {
        if (isset($this->changed[$key])) {
            return $this->changed[$key];
        }

        $file = $this->store->file($key);

        if ($file->error !== null) {
            throw new RuntimeException(__('marketing-toolkit::cp.redirects.files.unreadable', ['file' => self::relative($file->path()), 'error' => $file->error]));
        }

        return new SiteRules($file->key, $file->path(), $this->copies($file->rules));
    }

    private function change(SiteRules $file): void
    {
        $this->changed[$file->key] = $file;
    }

    /**
     * Takes the rule $id out of the file (a working copy of it), and says whether it was there.
     */
    private function without(SiteRules &$file, string $id): bool
    {
        if ($this->indexOf($file, $id) === null) {
            return false;
        }

        $file = $this->working($file->key);
        $index = $this->indexOf($file, $id);
        array_splice($file->rules, (int) $index, 1);

        return true;
    }

    private function indexOf(SiteRules $file, string $id): ?int
    {
        foreach ($file->rules as $index => $rule) {
            if ($rule->id === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Keeps the first rule of each source and of each id, in file order, from a file edited by hand. Two
     * sources that differ only in letter case are both kept, as two rows of the database would be: the
     * matcher prefers the one in the case asked for, and otherwise the first.
     *
     * @param  list<Rule>  $rules
     * @return list<Rule>
     */
    private function firstOfEach(array $rules): array
    {
        $sources = [];
        $ids = [];
        $kept = [];

        foreach ($rules as $rule) {
            $key = Rule::normalize($rule->source);

            if (isset($sources[$key]) || ($rule->id !== null && isset($ids[$rule->id]))) {
                continue;
            }

            $sources[$key] = true;

            if ($rule->id !== null) {
                $ids[$rule->id] = true;
            }

            $kept[] = $rule;
        }

        return $kept;
    }

    /**
     * @param  list<Rule>  $rules
     * @return list<Rule>
     */
    private function copies(array $rules): array
    {
        return array_map(fn (Rule $rule) => clone $rule, $rules);
    }

    /**
     * @param  Collection<int, Rule>  $rules
     * @return Collection<int, Rule>
     */
    private function withStats(Collection $rules): Collection
    {
        $stats = $this->stats($rules->pluck('id')->filter()->unique()->values()->all());

        return $rules->each(function (Rule $rule) use ($stats) {
            $rule->hits = $stats[$rule->id]['hits'] ?? 0;
            $rule->lastHitAt = $stats[$rule->id]['last_hit_at'] ?? null;
        });
    }

    private static function relative(string $path): string
    {
        return Str::after($path, rtrim(base_path(), '/').'/');
    }
}
