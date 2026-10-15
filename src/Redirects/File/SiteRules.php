<?php

namespace JothamLec\MarketingToolkit\Redirects\File;

use Illuminate\Support\Facades\File;
use JothamLec\MarketingToolkit\Redirects\Rule;
use Statamic\Facades\YAML;

/**
 * One file of the file driver: the rules for one site (`{site}.yaml`), or for every site (`all.yaml`).
 * It is one item of RedirectsStore, as a nav tree is one item of Statamic's store for a site. The file
 * is a YAML list of rules, each with its `id`, `source` and `target` (none for a 410), and `status`,
 * `active` and `automatic` only where they differ from 301, true and false.
 */
final class SiteRules
{
    /** This is the name of the file that holds the rules for every site. */
    public const string EVERY_SITE = 'all';

    /**
     * @param  string  $key  the file's name without `.yaml`: a site handle, or `all`
     * @param  list<Rule>  $rules  in the order the file lists them
     * @param  ?string  $error  why the file couldn't be read, in which case it holds no rules
     */
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public array $rules = [],
        public readonly ?string $error = null,
    ) {}

    /**
     * Returns the file's key for the rules of $site, or for every site when $site is null.
     */
    public static function keyFor(?string $site): string
    {
        return $site ?? self::EVERY_SITE;
    }

    public function id(): string
    {
        return $this->key;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns the handle of the site whose rules these are, or null for every site.
     */
    public function site(): ?string
    {
        return $this->key === self::EVERY_SITE ? null : $this->key;
    }

    /**
     * Reads a file. A file that doesn't parse, or isn't a list, is logged and holds no rules, so a request
     * never fails because of it; the Redirects screen shows the error.
     */
    public static function fromFile(string $key, string $path, string $contents): self
    {
        try {
            $items = trim($contents) === '' ? [] : YAML::parse($contents);
        } catch (\Throwable $exception) {
            $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            logger()->error("Marketing Toolkit could not read the redirect rules in {$path}: {$message}");

            return new self($key, $path, [], $message);
        }

        if (! array_is_list($items)) {
            logger()->error("Marketing Toolkit could not read the redirect rules in {$path}: it is not a list of rules.");

            return new self($key, $path, [], __('marketing-toolkit::cp.redirects.files.not_a_list'));
        }

        $site = $key === self::EVERY_SITE ? null : $key;
        $rules = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_scalar($item['source'] ?? null)) {
                continue;
            }

            $rules[] = new Rule(
                id: is_scalar($item['id'] ?? null) && (string) $item['id'] !== '' ? (string) $item['id'] : null,
                site: $site,
                source: (string) $item['source'],
                target: is_scalar($item['target'] ?? null) && (string) $item['target'] !== '' ? (string) $item['target'] : null,
                status: (int) ($item['status'] ?? 301),
                active: (bool) ($item['active'] ?? true),
                automatic: (bool) ($item['automatic'] ?? false),
            );
        }

        return new self($key, $path, $rules);
    }

    /**
     * Writes the file, sorted by source and then by id so diffs stay small. An emptied file is written
     * as `[]` and kept: Git doesn't keep an empty folder, and a missing folder could bring back the
     * rules still in the database (see Drivers).
     */
    public function writeFile(): void
    {
        $rules = $this->rules;

        usort($rules, fn (Rule $a, Rule $b) => [$a->source, (string) $a->id] <=> [$b->source, (string) $b->id]);
        $this->rules = $rules;

        File::ensureDirectoryExists(dirname($this->path));
        File::put($this->path, $rules === [] ? "[]\n" : YAML::dump(array_map(self::item(...), $rules)));
    }

    /**
     * Returns a rule as the file stores it, leaving out the values that are the defaults.
     *
     * @return array<string, mixed>
     */
    public static function item(Rule $rule): array
    {
        return array_filter([
            'id' => $rule->id,
            'source' => $rule->source,
            'target' => $rule->target,
            'status' => $rule->status === 301 ? null : $rule->status,
            'active' => $rule->active ? null : false,
            'automatic' => $rule->automatic ? true : null,
        ], fn ($value) => $value !== null);
    }
}
