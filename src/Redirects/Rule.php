<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use JothamLec\MarketingToolkit\Rules\RedirectTarget;
use JothamLec\MarketingToolkit\Rules\UniqueSource;
use JothamLec\MarketingToolkit\Support\Sites;

/**
 * A redirect rule, whichever driver stores it (see RedirectRepository). Requests for `source` go to
 * `target` with `status` (301 or 302), or they are answered with 410 Gone. A `*` in the source matches
 * anything, and the target receives what it matched as $1, $2, and so on. Rules apply only to addresses
 * that the site would otherwise answer with a 404. On a multi-site install, a rule names the site it
 * applies on, or names none to apply on every site, and a site's own rule wins over a rule for every
 * site from the same address.
 *
 * The id is a string: a UUID for a rule made in files, or the row's number for one in the database
 * (or exported from it). A rule without an id hasn't been saved, or was typed into a file without one.
 */
final class Rule
{
    public const array STATUSES = [301, 302, 410];

    /** This is the longest source allowed, as MySQL's unique index on site and source must stay under 3072 bytes. */
    public const int MAX_SOURCE = 736;

    public function __construct(
        public ?string $id = null,
        public ?string $site = null,
        public string $source = '/',
        public ?string $target = null,
        public int $status = 301,
        public bool $active = true,
        public bool $automatic = false,
        public int $hits = 0,
        public ?Carbon $lastHitAt = null,
    ) {}

    /**
     * Makes a rule from the fields a form, a CSV row or a file holds (`site`, `source`, `target`, `status`,
     * `active`, `automatic`, and `id`, `hits`, `last_hit_at` where known).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function make(array $attributes = []): self
    {
        return (new self)->fill($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            match ($key) {
                'id' => $this->id = $value === null || $value === '' ? null : (string) $value,
                'site' => $this->site = $value === null || $value === '' ? null : (string) $value,
                'source' => $this->source = (string) $value,
                'target' => $this->target = $value === null || $value === '' ? null : (string) $value,
                'status' => $this->status = (int) $value,
                'active' => $this->active = (bool) $value,
                'automatic' => $this->automatic = (bool) $value,
                'hits' => $this->hits = (int) $value,
                'last_hit_at', 'lastHitAt' => $this->lastHitAt = $value === null ? null : Carbon::parse($value),
                default => null,
            };
        }

        return $this;
    }

    /**
     * Puts the fields in the form rules are stored in, as a save does: the source and target tidied,
     * no target for a 410, and no site rather than an empty one.
     */
    public function normalized(): self
    {
        $this->site = $this->site === '' ? null : $this->site;
        $this->source = self::normalize($this->source);
        $this->target = $this->status === 410 ? null : self::normalizeTarget($this->target);

        return $this;
    }

    public function isWildcard(): bool
    {
        return str_contains($this->source, '*');
    }

    /**
     * Determines whether the signed-in user may manage this rule: a rule of a site they may work on, or a
     * rule for every site only if they may work on every site.
     */
    public function isAccessible(): bool
    {
        return $this->site === null ? Sites::accessesAll() : in_array($this->site, Sites::accessible(), true);
    }

    /**
     * @return array{id: ?string, site: ?string, source: string, target: ?string, status: int, active: bool, automatic: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site,
            'source' => $this->source,
            'target' => $this->target,
            'status' => $this->status,
            'active' => $this->active,
            'automatic' => $this->automatic,
        ];
    }

    /**
     * Returns a site path in the form that rules store and compare. The path is decoded (so `/caf%C3%A9` and
     * `/café` are one address, as they are when requests are matched), has a leading slash, has no trailing
     * slash (except for the home page), and has no query string or fragment.
     */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        $path = substr($path, 0, strcspn($path, '?#'));

        return '/'.trim(rawurldecode($path), '/');
    }

    /**
     * Tidies a target as typed. Another site's address is kept as it is. A path on this site loses its
     * trailing slash and keeps its query and fragment, still encoded, because it goes into the Location
     * header as it is.
     */
    public static function normalizeTarget(?string $target): ?string
    {
        $target = trim((string) $target);

        if ($target === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $target)) {
            return $target;
        }

        $end = strcspn($target, '?#');

        return '/'.trim(substr($target, 0, $end), '/').substr($target, $end);
    }

    /**
     * Determines whether rules match an address in any letter case. This happens when
     * `redirects.case_sensitive` is off, for a site whose old addresses worked in any case (Wix or IIS).
     */
    public static function ignoresCase(): bool
    {
        return ! config('marketing-toolkit.redirects.case_sensitive');
    }

    /**
     * Returns a normalised path in the form that rules compare. It is case-folded when matching ignores case
     * (so `/CAFÉ` and `/café` are one address), and is otherwise left as it is. A path that isn't valid
     * UTF-8 is left alone.
     */
    public static function key(string $path): string
    {
        return self::ignoresCase() && mb_check_encoding($path, 'UTF-8') ? mb_convert_case($path, MB_CASE_FOLD_SIMPLE, 'UTF-8') : $path;
    }

    /**
     * Determines whether a rule sends every address it matches back to that same address, such as `/a` to
     * `/a`, or `/x/*` to `/x/$1`. Letter case counts even when matching ignores it, because `/About` to
     * `/about` is how an old address in capitals reaches the page, and if that page is missing, the 404 isn't
     * redirected again.
     */
    public static function pointsBack(string $source, ?string $target): bool
    {
        $back = 0;
        $same = preg_replace_callback('/\*/', fn () => '$'.++$back, self::normalize($source));

        return $target !== null && str_starts_with($target, '/') && self::normalize($target) === $same;
    }

    /**
     * Checks a redirect's fields from a CSV row. The form checks them with the same rules() and messages()
     * (see Http\Requests\SaveRedirect). $taken says whether another rule already starts from the source.
     * $active holds the active rules when the caller has them at hand (such as an import, which reads them
     * once rather than for every row); otherwise they are looked up. $sites lists the handles a rule may
     * name (the CP passes the user's own), and every site's handle is allowed when it is not given.
     *
     * @param  array<string, mixed>  $data
     * @param  ?Collection<int|string, self>  $active
     * @param  ?list<string>  $sites
     */
    public static function validator(array $data, ?string $ignoreId = null, ?bool $taken = null, ?Collection $active = null, ?array $sites = null): ValidatorContract
    {
        $site = is_string($data['site'] ?? null) && $data['site'] !== '' ? $data['site'] : null;

        return Validator::make($data, self::rules((string) ($data['source'] ?? ''), $site, $ignoreId, $taken, $active, $sites), self::messages());
    }

    /**
     * Returns the checks that a redirect's fields must pass, from the form or a CSV row. A rule may name no
     * site (and so apply on every site) only when every site is among $sites.
     *
     * @param  ?Collection<int|string, self>  $active
     * @param  ?list<string>  $sites  the handles a rule may name, or null for every site's handle
     * @return array<string, mixed>
     */
    public static function rules(string $source, ?string $site, ?string $ignoreId = null, ?bool $taken = null, ?Collection $active = null, ?array $sites = null): array
    {
        $sites ??= Sites::handles();

        // This runs before the other checks, because a regex on text that isn't UTF-8 fails, and Postgres refuses it.
        $utf8 = function (string $attribute, mixed $value, Closure $fail) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail(__('marketing-toolkit::validation.redirect.encoding'));
            }
        };

        // A site whose handle is `all` can't have rules in files, as all.yaml holds the rules for every site.
        $notAll = function (string $attribute, mixed $value, Closure $fail) {
            if ($value === File\SiteRules::EVERY_SITE && app(RedirectRepository::class)->driver() === 'file') {
                $fail(__('marketing-toolkit::cp.redirects.files.all_handle'));
            }
        };

        return [
            'site' => [ValidationRule::requiredIf(Sites::multiple() && array_diff(Sites::handles(), $sites) !== []), 'nullable', 'string', ValidationRule::in($sites), $notAll],
            'source' => [
                // We reject control characters, because they would go into the Location header, where a line break
                // starts a new header.
                'required', 'string', 'bail', $utf8, 'max:'.self::MAX_SOURCE, 'starts_with:/', 'not_regex:/[?#]/', 'regex:/^[^\x00-\x1F\x7F]*$/',
                new UniqueSource($site, $ignoreId, $taken),
            ],
            'target' => [
                'nullable', 'required_unless:status,410', 'string', 'bail', $utf8, 'max:2048', 'regex:#^(/|https?://)#i', 'not_regex:/[\x00-\x1F\x7F]/',
                new RedirectTarget($source, $site, $ignoreId, $active),
            ],
            'status' => ['required', ValidationRule::in(self::STATUSES)],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'source.required' => __('marketing-toolkit::validation.redirect.source_required'),
            'source.starts_with' => __('marketing-toolkit::validation.redirect.source_starts_with'),
            'source.not_regex' => __('marketing-toolkit::validation.redirect.source_query'),
            'source.regex' => __('marketing-toolkit::validation.redirect.control_characters'),
            'target.required_unless' => __('marketing-toolkit::validation.redirect.target_required'),
            'target.regex' => __('marketing-toolkit::validation.redirect.target_format'),
            'target.not_regex' => __('marketing-toolkit::validation.redirect.control_characters'),
            'status.in' => __('marketing-toolkit::validation.redirect.status'),
            'site.in' => __('marketing-toolkit::validation.redirect.site'),
            'site.required' => __('marketing-toolkit::validation.redirect.site_required'),
        ];
    }
}
