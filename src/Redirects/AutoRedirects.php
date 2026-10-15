<?php

namespace JothamLec\MarketingToolkit\Redirects;

use Illuminate\Support\Facades\Cache;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Facades\User;

/**
 * This class creates the 301 redirects that Statamic content needs when its address changes, and it keeps them
 * free of chains and loops. A rule that points to the old address is repointed at the new one, and a rule
 * from the new address is dropped, because that page is now live. On a multi-site install, this all happens
 * among the rules of the content's own site; on a single site, it happens among the rules for every site.
 */
class AutoRedirects
{
    /**
     * @param  ?string  $site  the handle of the site the content is on
     */
    public function create(string $from, string $to, ?string $site = null): void
    {
        $from = Rule::normalize($from);
        $to = Rule::normalize($to);

        if ($from === $to || ! Features::on('automatic_redirects')) {
            return;
        }

        $this->write($from, $to, $from, $to, live: $to, site: Sites::scope($site));
    }

    /**
     * Redirects everything under an address that moved with it, such as when a mount page takes its
     * collection's entries along. A single wildcard rule covers them all.
     */
    public function createForPrefix(string $from, string $to, ?string $site = null): void
    {
        $from = Rule::normalize($from);
        $to = Rule::normalize($to);

        if ($from === $to || $from === '/' || ! Features::on('automatic_redirects')) {
            return;
        }

        $this->write($from.'/*', $to.'/$1', $from, $to, live: $to.'/*', site: Sites::scope($site));
    }

    /**
     * @param  string  $live  the source of a rule the move makes wrong, which is the new address or everything under it
     * @param  ?string  $site  the site whose rules change, or null for the rules for every site
     */
    private function write(string $source, string $target, string $from, string $to, string $live, ?string $site): void
    {
        $repository = app(RedirectRepository::class);

        // One batch: one transaction in the database, or one write of the site's file.
        $repository->batch(function () use ($repository, $source, $target, $from, $to, $live, $site) {
            $rules = $repository->onSite($site);

            // The new address is live again, so we delete any rule that would send visitors away from it.
            $rules->filter(fn (Rule $rule) => $rule->source === $live)->each(fn (Rule $rule) => $repository->delete($rule));

            // Rules that point to the old address, or under it, follow it to the new one, unless that
            // would send a rule back to its own address (such as `/x/*` to `/x/$1`).
            $rules->filter(fn (Rule $rule) => $rule->source !== $live && ($rule->target === $from || str_starts_with((string) $rule->target, $from.'/')))
                ->each(function (Rule $rule) use ($repository, $from, $to) {
                    $rule->target = $to.substr((string) $rule->target, strlen($from));
                    Rule::pointsBack($rule->source, $rule->target) ? $repository->delete($rule) : $repository->save($rule);
                });

            // We create or update the site's rule from the old address, in any letter case when matching ignores case.
            $repository->save(($repository->forSource($source, $site) ?? new Rule)->fill([
                'site' => $site, 'source' => $source, 'target' => $target, 'status' => 301, 'active' => true, 'automatic' => true,
            ]));
        });
    }

    /**
     * Stores the editor's answer from the save dialog for the content that is saved next. When there is
     * no answer, such as for a save from code or an import, the redirect is made.
     */
    public function remember(string $id, bool $create): void
    {
        Cache::put($this->choiceKey($id), $create, now()->addMinutes(10));
    }

    public function wanted(string $id): bool
    {
        return Cache::pull($this->choiceKey($id), true) !== false;
    }

    private function choiceKey(string $id): string
    {
        return 'mt:redirect-choice:'.(User::current()?->id() ?? 'guest').':'.$id;
    }
}
