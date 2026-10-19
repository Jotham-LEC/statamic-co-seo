<?php

namespace JothamLec\MarketingToolkit\Reports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;
use Statamic\View\Cascade;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders a page in this process, the way Statamic's front end would answer a
 * visit, but without an HTTP request. It makes a fresh request object, points
 * Statamic's cascade at it, takes the content's own response (or the site's
 * router's, when a route of the site's own answers the address), and then puts
 * everything back.
 *
 * It switches `noindex_outside_production` off while rendering, so a report run
 * on a local or staging copy sees the robots tags that production would print.
 *
 * The page renders as a visitor sees it, because a report started from the
 * control panel runs while someone is signed in, and a page could show them more
 * (such as an `{{ if logged_in }}` block or the toolbar) than a visitor would get.
 */
class Renderer
{
    /**
     * A page that throws is stored with a generic message and the exception's
     * class, not its text, because the report is shown to anyone who may view
     * reports, and a query exception's text holds SQL and connection details.
     * The full error goes to the log.
     */
    public function render(Entry|Term $content): RenderedPage
    {
        $request = Request::create((string) $content->absoluteUrl(), 'GET', server: ['HTTP_USER_AGENT' => 'jotham-lec/statamic-marketing-toolkit report']);
        $previous = app('request');
        $cascade = app(Cascade::class);
        $noindex = config('marketing-toolkit.robots.noindex_outside_production');

        app()->instance('request', $request);
        $cascade->withRequest($request);
        config(['marketing-toolkit.robots.noindex_outside_production' => false]);

        // Outside a web request (the console or a queue worker), no middleware shares the
        // validation errors that views expect, so this shares an empty bag, as ShareErrorsFromSession does.
        View::share('errors', View::shared('errors') ?? new ViewErrorBag);

        $signedIn = $this->signOut();

        try {
            $response = $this->siteRoute($request) ? $this->throughRouter($request) : $content->toResponse($request);

            return new RenderedPage($response->getStatusCode(), (string) $response->getContent());
        } catch (HttpExceptionInterface $e) {
            return new RenderedPage($e->getStatusCode());
        } catch (Throwable $e) {
            report($e);

            return new RenderedPage(500, error: 'marketing-toolkit::reports.messages.render_failed', exception: class_basename($e));
        } finally {
            app()->instance('request', $previous);
            $cascade->withRequest($previous);
            config(['marketing-toolkit.robots.noindex_outside_production' => $noindex]);
            $this->signBackIn($signedIn);
        }
    }

    /**
     * Whether one of the site's own routes answers this address, ahead of
     * Statamic's catch-all: a page a controller draws from an entry, such as
     * /case-studies/{slug}. The entry has a route but no template, so its own
     * response would throw, though visitors see the page.
     */
    private function siteRoute(Request $request): bool
    {
        try {
            return ! str_contains(app(Router::class)->getRoutes()->match($request)->uri(), '{segments');
        } catch (HttpExceptionInterface) {
            return false;
        }
    }

    /**
     * Answers the request as the site's router would answer a visit, with the
     * route's middleware, on a session kept in memory so a render saves no
     * session. An exception becomes the error page the visitor would get,
     * reported as any other.
     */
    private function throughRouter(Request $request): Response
    {
        $driver = config('session.driver');
        config(['session.driver' => 'array']);

        try {
            return app(Router::class)->dispatch($request);
        } finally {
            config(['session.driver' => $driver]);
        }
    }

    /**
     * Signs everyone out for as long as the render lasts. Forgetting a guard's
     * user isn't enough, because a session guard reads the user back from the
     * session: the guards are rebuilt on an empty session instead, and the
     * real session and the users signed in are put back afterwards.
     *
     * @return array{session: mixed, users: array<string, Authenticatable>} what signBackIn() restores
     */
    private function signOut(): array
    {
        $guards = array_unique(array_filter([config('auth.defaults.guard'), config('statamic.users.guards.web'), config('statamic.users.guards.cp')]));
        $users = [];

        foreach ($guards as $name) {
            if ($user = Auth::guard($name)->user()) {
                $users[$name] = $user;
            }
        }

        $session = app()->bound('session.store') ? app('session.store') : null;
        app()->instance('session.store', new Store('mt-report', new ArraySessionHandler(1)));
        Auth::forgetGuards();

        return ['session' => $session, 'users' => $users];
    }

    /**
     * @param  array{session: mixed, users: array<string, Authenticatable>}  $signedIn
     */
    private function signBackIn(array $signedIn): void
    {
        $signedIn['session'] === null ? app()->forgetInstance('session.store') : app()->instance('session.store', $signedIn['session']);
        Auth::forgetGuards();

        foreach ($signedIn['users'] as $name => $user) {
            Auth::guard($name)->setUser($user);
        }
    }
}
