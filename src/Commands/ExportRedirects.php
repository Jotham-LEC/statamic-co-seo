<?php

namespace JothamLec\MarketingToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\File\RedirectsStore;
use JothamLec\MarketingToolkit\Redirects\File\SiteRules;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectHit;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Console\RunsInPlease;

/**
 * The `php please mt:export-redirects` command copies the rules in the `mt_redirects` table into the
 * file driver's files (database → files, as statamic/eloquent-driver's `eloquent:export-*` commands
 * go), whatever the driver. Each rule keeps its row's id, and its hits are copied into
 * `mt_redirect_hits`. With `--hits-only`, no file is written: only the hits of the rules already in
 * the files are copied, for a site that exported from a copy of its production database.
 */
final class ExportRedirects extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mt:export-redirects
        {--force : Overwrite rule files that already exist}
        {--hits-only : Write no files; copy the hit counts of the rules already in files into mt_redirect_hits}';

    protected $description = 'Copy the redirect rules in the database into files (content/redirects)';

    public function handle(): int
    {
        if (! Schema::hasTable('mt_redirects') || ! Schema::hasTable('mt_redirect_hits')) {
            $this->components->error('Run `php artisan migrate` first: the mt_redirects and mt_redirect_hits tables are needed.');

            return self::FAILURE;
        }

        return $this->option('hits-only') ? $this->hitsOnly() : $this->export();
    }

    private function export(): int
    {
        $folder = RedirectsStore::folder();
        $rows = Redirect::query()->orderBy('id')->get();
        $bySite = $rows->groupBy(fn (Redirect $redirect) => SiteRules::keyFor($redirect->site));
        $keys = $bySite->keys()->push(SiteRules::EVERY_SITE)->unique()->sort()->values();
        $existing = $keys->filter(fn (string $key) => file_exists("{$folder}/{$key}.yaml"));

        if ($existing->isNotEmpty() && ! $this->option('force')) {
            $this->components->error('These files exist already: '.$existing->map(fn (string $key) => self::relative("{$folder}/{$key}.yaml"))->implode(', ').'. Run with --force to overwrite them.');

            return self::FAILURE;
        }

        $handles = Sites::handles();

        foreach ($keys as $key) {
            // A row of a site whose handle is `all` would land among the rules for every site, so it is left out.
            if ($key === SiteRules::EVERY_SITE && $rows->contains(fn (Redirect $redirect) => $redirect->site === SiteRules::EVERY_SITE)) {
                $this->components->warn('Rules of a site whose handle is `all` can\'t be kept in files, as all.yaml holds the rules for every site. Rename the site, or keep the Eloquent driver.');
            }

            if ($key !== SiteRules::EVERY_SITE && ! in_array($key, $handles, true)) {
                $this->components->warn("{$key}.yaml is for a site this install doesn't have ([{$key}]), so its rules won't apply until there is one.");
            }

            $rules = ($bySite->get($key) ?? collect())
                ->reject(fn (Redirect $redirect) => $redirect->site === SiteRules::EVERY_SITE)
                ->map(fn (Redirect $redirect) => $redirect->toRule())
                ->values()
                ->all();

            (new SiteRules($key, "{$folder}/{$key}.yaml", $rules))->writeFile();
        }

        $copied = DB::transaction(fn () => $this->copyHits($rows->all()));

        Drivers::store()->clear();
        app()->forgetScopedInstances();

        $this->components->info("Wrote {$rows->count()} redirects into ".self::relative($folder).' ('.$keys->map(fn ($key) => "{$key}.yaml")->implode(', ')."), and copied the hits of {$copied}.");
        $this->nextSteps();

        return self::SUCCESS;
    }

    private function hitsOnly(): int
    {
        $ids = collect(Drivers::store()->files())->flatMap(fn (SiteRules $file) => $file->rules)->pluck('id')->filter()->unique();

        if ($ids->isEmpty()) {
            $this->components->warn('No rules with an id are in '.self::relative(RedirectsStore::folder()).', so there are no hits to copy.');

            return self::SUCCESS;
        }

        $rows = Redirect::query()->whereIn('id', $ids->filter(fn (string $id) => ctype_digit($id))->map(fn (string $id) => (int) $id)->values()->all())->get();
        $copied = DB::transaction(fn () => $this->copyHits($rows->all()));

        $this->components->info("Copied the hits of {$copied} redirects into mt_redirect_hits.");

        return self::SUCCESS;
    }

    /**
     * @param  list<Redirect>  $rows
     */
    private function copyHits(array $rows): int
    {
        $copied = 0;

        foreach ($rows as $row) {
            if ($row->hits > 0 || $row->last_hit_at !== null) {
                RedirectHit::merge((string) $row->id, (int) $row->hits, $row->last_hit_at);
                $copied++;
            }
        }

        return $copied;
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->line('Next:');
        $this->line('- Commit '.self::relative(RedirectsStore::folder()).'/ and deploy. From then on the rules are read from the files.');

        if (app(Drivers::class)->configured() !== 'file') {
            $this->line('- Set MT_REDIRECTS_DRIVER=file (or remove it from .env): the driver is `eloquent` now.');
        }

        $this->line('- If you exported from a copy of the production database, run `php please mt:export-redirects --hits-only` once on production after the deploy, to carry over the hits counted since.');
    }

    private static function relative(string $path): string
    {
        return Str::after($path, rtrim(base_path(), '/').'/');
    }
}
