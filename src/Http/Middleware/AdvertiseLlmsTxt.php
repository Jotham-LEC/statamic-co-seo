<?php

namespace JothamLec\MarketingToolkit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use JothamLec\MarketingToolkit\Http\Controllers\TextFileController;
use JothamLec\MarketingToolkit\Support\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points agents at /llms.txt from the home page, with a `Link: </llms.txt>; rel="describedby"`
 * header (RFC 8288), whenever the site serves that file: the addon's own, a route of the site's, or
 * a file in public/.
 */
final class AdvertiseLlmsTxt
{
    public const string LINK = '</llms.txt>; rel="describedby"; type="text/plain"';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->getPathInfo() !== '/' || ! $response->isSuccessful() || ! NegotiateMarkdown::isHtml($response) || ! $this->served()) {
            return $response;
        }

        $response->headers->set('Link', self::LINK, false);

        return $response;
    }

    private function served(): bool
    {
        if (Features::on('llms_txt') || is_file(public_path('llms.txt'))) {
            return true;
        }

        // The addon's route answers 404 while llms.txt is off; any other route for the address is the site's.
        $route = Route::getRoutes()->get('GET')['llms.txt'] ?? null;

        return $route !== null && $route->getActionName() !== TextFileController::class.'@llms';
    }
}
