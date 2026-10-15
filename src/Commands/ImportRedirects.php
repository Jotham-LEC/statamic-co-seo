<?php

namespace JothamLec\MarketingToolkit\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JothamLec\MarketingToolkit\Redirects\Drivers;
use JothamLec\MarketingToolkit\Redirects\File\SiteRules;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\Redirect;
use JothamLec\MarketingToolkit\Redirects\RedirectHit;
use JothamLec\MarketingToolkit\Redirects\Rule;
use Statamic\Console\RunsInPlease;

/**
 * The `php please mt:import-redirects` command copies the file driver's rules into the `mt_redirects`
 * table (files → database, as statamic/eloquent-driver's `eloquent:import-*` commands go), for a site
 * that moves to the Eloquent driver. Each rule gets a new row id, and takes its hits from
 * `mt_redirect_hits`. The files are left in place.
 */
final class ImportRedirects extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:mt:import-redirects
        {--force : Import even though mt_redirects has rows, updating the rule from the same address on the same site}';

    protected $description = 'Copy the redirect rules in files (content/redirects) into the database';

    public function handle(): int
    {
        if (! Schema::hasTable('mt_redirects') || ! Schema::hasTable('mt_redirect_hits')) {
            $this->components->error('Run `php artisan migrate` first: the mt_redirects and mt_redirect_hits tables are needed.');

            return self::FAILURE;
        }

        if (Redirect::query()->exists() && ! $this->option('force')) {
            $this->components->error('mt_redirects has rows already. Run with --force to add the rules from the files to them, updating the rule from the same address on the same site.');

            return self::FAILURE;
        }

        $files = Drivers::store()->files();

        foreach ($files as $file) {
            if ($file->error !== null) {
                $this->components->error("{$file->path()} can't be read, so nothing was imported: {$file->error}");

                return self::FAILURE;
            }
        }

        $counts = Matcher::flushAfter(fn () => DB::transaction(fn () => $this->import($files)));

        $this->components->info("Imported {$counts['created']} redirects and updated {$counts['updated']}.".($counts['skipped'] ? " {$counts['skipped']} were left out, as an earlier rule in the same file starts from the same address." : ''));
        $this->newLine();
        $this->line('Next: set MT_REDIRECTS_DRIVER=eloquent to read the rules from the database. The files are left in place; delete them once you no longer need them.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, SiteRules>  $files
     * @return array{created: int, updated: int, skipped: int}
     */
    private function import(array $files): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($files as $file) {
            $seen = [];

            foreach ($file->rules as $rule) {
                $rule = (clone $rule)->normalized();
                $key = Rule::key($rule->source);

                if (isset($seen[$key])) {
                    $counts['skipped']++;

                    continue;
                }

                $seen[$key] = true;
                $redirect = Redirect::forSource($rule->source, site: $rule->site);
                $counts[$redirect ? 'updated' : 'created']++;
                $redirect ??= new Redirect;
                $redirect->fillFromRule($rule);

                $hits = $rule->id === null ? null : RedirectHit::query()->where('redirect_id', $rule->id)->first();

                if ($hits) {
                    $redirect->hits = max((int) $redirect->hits, $hits->hits);
                    $redirect->last_hit_at = $hits->last_hit_at;
                }

                $redirect->save();
            }
        }

        return $counts;
    }
}
