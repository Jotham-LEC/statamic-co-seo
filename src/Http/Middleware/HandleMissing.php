<?php

namespace JothamLec\MarketingToolkit\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JothamLec\MarketingToolkit\NotFound\Recorder;
use JothamLec\MarketingToolkit\Redirects\Matcher;
use JothamLec\MarketingToolkit\Redirects\RedirectRepository;
use JothamLec\MarketingToolkit\Redirects\Rule;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use JothamLec\MarketingToolkit\Support\StatamicRoutes;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the site answers a request with a 404, this middleware sends the visitor on if a redirect rule
 * matches, and otherwise counts the request in the 404 log. A page that exists always wins over a rule,
 * so a page that comes back takes its address back. The bookkeeping (hit counts and the log) runs in
 * terminate(), after the response has gone out. We don't use Laravel's defer(), because it runs only
 * where the app has Laravel 11's InvokeDeferredCallbacks middleware, which a site that keeps the older
 * app/Http/Kernel.php may lack, and the log would then stay empty.
 */
final class HandleMissing
{
    public function __construct(private Matcher $matcher, private Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Features::on('redirects') && ! Features::on('not_found')) {
            return $next($request);
        }

        $response = $next($request);

        if ($response->getStatusCode() !== 404 || ! in_array($request->getMethod(), ['GET', 'HEAD'], true) || StatamicRoutes::owns($request->getPathInfo())) {
            return $response;
        }

        // A cached copy would be served without coming through here, so a redirect added later would
        // never apply, and the log would count only one visit.
        $response->headers->set('X-Statamic-Uncacheable', 'true');

        $site = Site::current();
        // The path is taken as requested, from the domain's root, because the matcher removes the site's folder itself.
        $path = '/'.trim($request->decodedPath(), '/');
        // The lookup reads the rules (from the cache, or else from their files or table). If the table is
        // missing (`migrate` hasn't run yet) or the lookup fails, the error is reported and the address
        // answers its 404 as before, rather than a 500.
        $rule = Features::on('redirects') ? rescue(fn () => $this->matcher->match($path, (string) $request->getQueryString(), $site->handle()), null) : null;

        // A rule back to the requested address would loop, so we treat the address as simply missing.
        if ($rule && $rule['target'] !== null && str_starts_with($rule['target'], '/') && Rule::normalize(self::fromRoot($rule['target'], $site)) === $path) {
            $rule = null;
        }

        if ($rule === null) {
            $request->attributes->set('mt.record_missing', $this->recorder->shouldRecord($request));

            return $response;
        }

        $request->attributes->set('mt.redirect_hit', $rule['id']);

        if ($rule['status'] === 410 || $rule['target'] === null) {
            // We return the site's own error page with a 410 status, which says the page is gone for good.
            return $response->setStatusCode(410);
        }

        // We build the response by hand, because Laravel's redirect() would trim a trailing slash the site wants.
        return new RedirectResponse(self::location($rule['target'], $site), $rule['status']);
    }

    /**
     * Returns a target path as it would be requested, from the domain's root. Targets are paths within the
     * site, as sources are (see Matcher), so `/qui-sommes-nous` on a site at example.com/fr/ becomes
     * `/fr/qui-sommes-nous`. A target that already starts with the site's folder was typed from the
     * domain's root, as it had to be before, so it is kept as it is.
     */
    private static function fromRoot(string $target, SiteObject $site): string
    {
        $folder = Sites::folder($site->handle());

        return $folder !== '' && preg_match('#^'.preg_quote($folder, '#').'([/?\#]|$)#', $target) ? $target : $folder.$target;
    }

    /**
     * Returns where a rule sends the visitor. Another site's address is used as typed. A path is placed on
     * the site's own address, never on the request's Host header, which a client can set to anything. A
     * site whose URL is relative (`url: '/'`) has no address other than the request's, so it gets the path
     * alone, which the browser resolves on the host it asked.
     */
    private static function location(string $target, SiteObject $site): string
    {
        if (! str_starts_with($target, '/')) {
            return $target;
        }

        // This is the site's address without its folder, which leaves the domain and the app's install folder.
        $root = rtrim($site->absoluteUrl(), '/');
        $root = substr($root, 0, strlen($root) - strlen(Sites::folder($site->handle())));
        $location = $root.self::fromRoot($target, $site);

        if (preg_match('#^https?://#i', $site->url())) {
            return $location;
        }

        // We never return `//host`, because a browser would read a path that starts that way as another site.
        return '/'.ltrim((string) preg_replace('#^https?://[^/]*#i', '', $location), '/');
    }

    public function terminate(Request $request, Response $response): void
    {
        // This bookkeeping runs after the response has gone out, so a failure is reported rather than thrown.
        // The count goes to the database whichever driver keeps the rules: a visit never writes a file.
        if ($id = $request->attributes->get('mt.redirect_hit')) {
            rescue(fn () => app(RedirectRepository::class)->recordHit((string) $id));
        }

        if ($request->attributes->get('mt.record_missing')) {
            rescue(fn () => $this->recorder->record($request));
        }
    }
}
