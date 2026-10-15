<?php

namespace JothamLec\MarketingToolkit\Redirects\File;

use Illuminate\Support\Collection;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use Statamic\Facades\Path;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\Store;
use Statamic\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The Stache store of the file driver's rules: one item per YAML file in `redirects.directory`, keyed by
 * the file's name (`all`, or a site handle). It is registered with the Stache, so `stache:clear` and
 * `stache:warm` (which a deploy runs) read the files afresh and flush the compiled rules the matcher keeps.
 *
 * The folder is read from the config each time it is used, not when the store is made, so a site (or a
 * test) can change `redirects.directory` after the app has booted. The cached files are kept under a key
 * that includes the folder, so two folders never share them.
 */
final class RedirectsStore extends Store
{
    public const string KEY = 'mt-redirects';

    /** @var array<string, array<string, SiteRules>> the files read in this process, by folder */
    private array $read = [];

    public function key()
    {
        return self::KEY;
    }

    /**
     * Returns the folder from the config. A folder set from outside (as Statamic's test helpers set each
     * store's) is ignored, since `redirects.directory` is the one place it is set.
     *
     * @param  string|null  $directory
     * @return string|static
     */
    public function directory($directory = null)
    {
        if (func_num_args() > 0) {
            return $this;
        }

        return Str::finish(Path::tidy(self::folder()), '/');
    }

    /**
     * Returns `redirects.directory` without its trailing slash.
     */
    public static function folder(): string
    {
        $directory = config('marketing-toolkit.redirects.directory');

        return rtrim(is_string($directory) && $directory !== '' ? $directory : base_path('content/redirects'), '/');
    }

    /**
     * @return bool
     */
    public function getItemFilter(SplFileInfo $file)
    {
        return $file->getExtension() === 'yaml' && ! str_contains($file->getRelativePathname(), '/');
    }

    /**
     * @param  string  $key
     * @return ?SiteRules
     */
    public function getItem($key)
    {
        return $this->files()[$key] ?? null;
    }

    /**
     * The store has no indexes: the redirect repository reads its files whole.
     *
     * @param  mixed  $keys
     * @param  mixed  $valueIndex
     * @param  mixed  $keyIndex
     * @return Collection<int, mixed>
     */
    public function getItemValues($keys, $valueIndex, $keyIndex)
    {
        return collect();
    }

    /**
     * @return Collection<string, string>
     */
    public function paths()
    {
        return collect($this->files())->map(fn (SiteRules $file) => $file->path());
    }

    /**
     * Returns every file in the folder, by key, in the order of their names.
     *
     * @return array<string, SiteRules>
     */
    public function files(): array
    {
        $this->handleFileChanges();

        $folder = self::folder();

        if (! isset($this->read[$folder])) {
            $files = Stache::cacheStore()->get($this->filesCacheKey());

            if (! is_array($files)) {
                $files = $this->readFiles($folder);
                Stache::cacheStore()->forever($this->filesCacheKey(), $files);
            }

            $this->read[$folder] = $files;
        }

        return $this->read[$folder];
    }

    /**
     * Returns the file for $key, or a new, empty one where there is none yet.
     */
    public function file(string $key): SiteRules
    {
        return $this->files()[$key] ?? new SiteRules($key, self::folder().'/'.$key.'.yaml');
    }

    /**
     * Writes a file and keeps the cached copy current.
     *
     * @param  SiteRules  $item
     * @return void
     */
    public function save($item)
    {
        $item->writeFile();

        $folder = self::folder();
        $files = $this->files();
        $files[$item->key] = $item;
        ksort($files);

        $this->read[$folder] = $files;
        Stache::cacheStore()->forever($this->filesCacheKey(), $files);
    }

    /**
     * @param  SiteRules  $item
     * @return void
     */
    public function delete($item)
    {
        // A file of rules is never deleted: an emptied one is saved as `[]` (see SiteRules::writeFile()).
    }

    /**
     * @return array<string, SiteRules>
     */
    private function readFiles(string $folder): array
    {
        $files = [];

        foreach (glob($folder.'/*.yaml') ?: [] as $path) {
            $key = basename($path, '.yaml');
            $files[$key] = SiteRules::fromFile($key, $path, (string) file_get_contents($path));
        }

        ksort($files);

        return $files;
    }

    /**
     * With Statamic's watcher on (`stache.watcher`, on locally by default), a file that changed since the
     * last request (edited by hand, or by a `git pull`) is read again on the next one, and the compiled
     * rules are flushed. With it off, as in production, a change is read after `stache:clear` or
     * `stache:warm`, as for entries.
     *
     * @return void
     */
    public function handleFileChanges()
    {
        $this->modified = collect();

        if ($this->fileChangesHandled) {
            return;
        }

        $this->fileChangesHandled = true;

        if (! Stache::isWatcherEnabled()) {
            return;
        }

        $now = [];

        foreach (glob(self::folder().'/*.yaml') ?: [] as $path) {
            $now[$path] = filemtime($path).':'.filesize($path);
        }

        $key = 'stache::timestamps::'.self::KEY.'::'.md5(self::folder());
        $before = Stache::cacheStore()->get($key);
        Stache::cacheStore()->forever($key, $now);

        if ($before !== null && $before !== $now) {
            $this->forget();
        }
    }

    /**
     * Forgets the files read, so the next use reads them again, and flushes the compiled rules.
     */
    public function forget(): void
    {
        $this->read = [];
        Stache::cacheStore()->forget($this->filesCacheKey());
        Matcher::flush();
    }

    /**
     * @return void
     */
    public function clear()
    {
        $this->forget();
        Stache::cacheStore()->forget('stache::timestamps::'.self::KEY.'::'.md5(self::folder()));
    }

    /**
     * @return void
     */
    public function warm()
    {
        $this->forget();
        $this->files();
    }

    /**
     * @return void
     */
    public function resetMemoizedState()
    {
        $this->read = [];
        $this->fileChangesHandled = false;
    }

    private function filesCacheKey(): string
    {
        return 'stache::items::'.self::KEY.'::'.md5(self::folder());
    }
}
