<?php

namespace JothamLec\MarketingToolkit\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Sites;

/**
 * This rule checks that a redirect's target makes sense with its source. The target may use no more of
 * `$1`, `$2`, and so on than the source has `*`, it may put none of them in the domain, and it must not
 * send visitors round in a circle through the other rules.
 */
final readonly class RedirectTarget implements ValidationRule
{
    /** This sets how far a chain of rules is followed when looking for a loop. */
    private const int MAX_HOPS = 10;

    /**
     * @param  string  $source  the rule's source, as typed
     * @param  ?string  $site  the rule's site, or null for a rule for every site
     * @param  ?string  $ignoreId  the rule being edited, which stands aside for its new version
     * @param  ?Collection<int|string, Rule>  $active  the active rules when the caller has
     *                                                 them at hand, or null to read them
     */
    public function __construct(private string $source, private ?string $site = null, private ?string $ignoreId = null, private ?Collection $active = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $target = (string) $value;
        preg_match_all('/\$(\d+)/', $target, $used);

        if ($used[1] !== [] && max(array_map('intval', $used[1])) > substr_count($this->source, '*')) {
            $fail(__('marketing-toolkit::validation.redirect.target_number'));
        } elseif (preg_match('#^https?://[^/]*\$\d#i', $target)) {
            // We refuse this, because a visitor's input would choose the site they are sent to
            // (`https://example.com$1` → example.com.evil.test).
            $fail(__('marketing-toolkit::validation.redirect.target_number_domain'));
        } elseif ($loop = $this->loop($target)) {
            $fail($loop);
        }
    }

    /**
     * Explains why a rule from $source to $target would send visitors round in a circle, or returns null.
     * A target under a wildcard's own source (`/blog/*` to `/blog/new/$1`) is allowed, because the pages
     * there usually exist. A rule for one site is followed through that site's rules, and a rule for every
     * site is followed through each site's rules.
     */
    private function loop(string $target): ?string
    {
        $source = $this->source;

        if (! str_starts_with($target, '/') || $source === '') {
            return null;
        }

        if (Rule::pointsBack($source, $target)) {
            return __('marketing-toolkit::validation.redirect.points_back');
        }

        $source = Rule::normalize($source);

        if (str_contains($source, '*')) {
            return null;
        }

        foreach ($this->site !== null ? [$this->site] : (Sites::multiple() ? Sites::handles() : [null]) as $on) {
            if ($loop = $this->loopOn($source, $target, $on)) {
                return $loop;
            }
        }

        return null;
    }

    /**
     * Follows the rules from the target, as a visitor on $site would be sent on (null means a single site
     * and every rule), and checks whether they come back. Each step looks only at the rules that could
     * match (an exact source and the wildcards). These come from $active when it is given; otherwise they
     * are asked of the repository for that step rather than read from the cached set, which an import
     * would rebuild after every row. The rule being edited stands aside for its new version.
     */
    private function loopOn(string $source, string $target, ?string $site): ?string
    {
        $ignoreId = $this->ignoreId;

        if ($this->active !== null) {
            $rules = $this->active->reject(fn (Rule $rule) => $ignoreId !== null && $rule->id === $ignoreId)
                ->when($site, fn (Collection $rules, string $site) => $rules->filter(fn (Rule $rule) => $rule->site === $site || $rule->site === null));
            $candidates = fn (string $path) => $rules->filter(fn (Rule $rule) => $rule->isWildcard() || Rule::key(Rule::normalize($rule->source)) === Rule::key($path));
        } else {
            $repository = app(RedirectRepository::class);
            $candidates = fn (string $path) => $repository->candidates($path, $site, $ignoreId);
        }

        $path = Rule::normalize($target);
        $seen = [];

        for ($hops = 1; $hops <= self::MAX_HOPS && ! isset($seen[Rule::key($path)]); $hops++) {
            $seen[Rule::key($path)] = true;
            $next = app(Matcher::class)->matchAmong($candidates($path), $path, $site);

            if ($next === null || $next['target'] === null || ! str_starts_with($next['target'], '/')) {
                return null;
            }

            $path = Rule::normalize($next['target']);

            if (Rule::key($path) === Rule::key($source)) {
                return $hops === 1
                    ? __('marketing-toolkit::validation.redirect.loop')
                    : __('marketing-toolkit::validation.redirect.loop_steps', ['steps' => $hops]);
            }
        }

        return null;
    }
}
