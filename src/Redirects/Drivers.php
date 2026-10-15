<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JothamLec\MarketingToolkit\Redirects\Eloquent\EloquentRedirectRepository;
use JothamLec\MarketingToolkit\Redirects\File\FileRedirectRepository;
use JothamLec\MarketingToolkit\Redirects\File\RedirectsStore;
use Statamic\Facades\Stache;

/**
 * Chooses where redirect rules are stored, each time the repository is asked for, from
 * `redirects.driver`: `file` (the default) or `eloquent`. It is one instance per request.
 *
 * The safety net: with the file driver, while the `redirects.directory` folder doesn't exist and the
 * `mt_redirects` table has rows, rules are still read from and written to the database. A site that
 * updates from a version that kept them there so changes nothing until it runs
 * `php please mt:export-redirects`, which creates the folder.
 */
final class Drivers
{
    public const array NAMES = ['file', 'eloquent'];

    /** @var array<string, RedirectRepository> */
    private array $repositories = [];

    /** @var array<string, int> the rules still in the database, by folder, once counted in this request */
    private array $inDatabase = [];

    public function repository(): RedirectRepository
    {
        $driver = $this->configured();
        $folder = RedirectsStore::folder();

        if ($driver === 'file' && $this->stillInDatabase($folder) > 0) {
            $driver = 'eloquent';
        }

        return $this->repositories[$driver.'|'.$folder] ??= $driver === 'eloquent'
            ? new EloquentRedirectRepository
            : new FileRedirectRepository(self::store());
    }

    /**
     * Returns the driver the config names.
     */
    public function configured(): string
    {
        $driver = config('marketing-toolkit.redirects.driver') ?? 'file';

        if (! in_array($driver, self::NAMES, true)) {
            throw new InvalidArgumentException('marketing-toolkit.redirects.driver must be "file" or "eloquent", not "'.(is_scalar($driver) ? $driver : get_debug_type($driver)).'".');
        }

        return $driver;
    }

    /**
     * Counts the rules kept in the database while the file driver is configured and its folder doesn't
     * exist, which is when the safety net keeps the site on the database. It is 0 otherwise.
     */
    public function stillInDatabase(?string $folder = null): int
    {
        $folder ??= RedirectsStore::folder();

        if ($this->configured() !== 'file' || is_dir($folder)) {
            return 0;
        }

        // The table is missing until `migrate` has run, and then nothing is in it.
        $count = $this->inDatabase[$folder] ??= (int) rescue(fn () => Redirect::query()->count(), 0, report: false);

        if ($count > 0 && Cache::add('mt:redirects-in-database-logged', true, now()->addDay())) {
            Log::warning("Marketing Toolkit: {$count} redirects are still in the database, so they are read from there. Run `php please mt:export-redirects` to move them into files, or set MT_REDIRECTS_DRIVER=eloquent to keep them there.");
        }

        return $count;
    }

    public static function store(): RedirectsStore
    {
        $store = Stache::store(RedirectsStore::KEY);

        if (! $store instanceof RedirectsStore) {
            Stache::registerStore($store = new RedirectsStore);
        }

        return $store;
    }
}
