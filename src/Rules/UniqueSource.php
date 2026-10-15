<?php

namespace JothamLec\MarketingToolkit\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;

/**
 * This rule checks that no other redirect on the same site starts from this address (in any letter case,
 * when matching ignores case).
 */
final readonly class UniqueSource implements ValidationRule
{
    /**
     * @param  ?string  $site  the rule's site, or null for a rule for every site
     * @param  ?string  $ignoreId  the rule being edited, which may keep its own source
     * @param  ?bool  $taken  the answer when the caller already knows it (a CSV import,
     *                        which reads every rule once), or null to look it up
     */
    public function __construct(private ?string $site = null, private ?string $ignoreId = null, private ?bool $taken = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->taken ?? app(RedirectRepository::class)->forSource((string) $value, $this->site, $this->ignoreId) !== null) {
            $fail(__('marketing-toolkit::validation.redirect.source_taken'));
        }
    }
}
