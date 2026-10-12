<?php

namespace JothamLec\MarketingToolkit\Http\Controllers;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Og\Shape;
use JothamLec\MarketingToolkit\SiteSeo;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Fields;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Structures\Page;

/**
 * Serves /og.png (home) and /og/{path}.png, the generated share card of the
 * published entry (or the taxonomy term) at that path on the domain, and its other shapes at /og.1x1.png,
 * /og/{path}.1x1.png and the same with `.4x3`, when the entry's template draws them. The meta tags only point here when the entry has no uploaded share
 * image, but the card is served either way so that editors can preview it.
 *
 * In a Live Preview the page's card address carries the preview's `token`. When that token
 * previews the entry at the path, the card is drawn from the form's unsaved values, a draft
 * included, and neither cached nor kept by the browser. Any other token is ignored.
 */
final class OgImageController
{
    public function __invoke(Request $request, Generator $generator, SiteSeo $seo, ?string $path = null): Response
    {
        throw_unless(Features::on('share_cards') && $generator->available(), NotFoundHttpException::class);

        $path = trim((string) $path, '/');
        $shape = Shape::Landscape;
        $entry = null;

        // A path that ends in a shape's suffix is that shape of the page without it, unless only
        // the whole path is a page (a slug of its own that ends in `.1x1`).
        if (preg_match('/^(.+)\.(1x1|4x3)$/', $path, $match)) {
            $entry = $this->entry($request, $match[1]);
            $shape = $entry ? Shape::fromSuffix($match[2]) : $shape;
        }

        return $this->card($request, $generator, $seo, $entry ?? $this->entry($request, $path), $shape);
    }

    /**
     * Serves the home page's card in another shape, at /og.1x1.png or /og.4x3.png.
     */
    public function home(Request $request, Generator $generator, SiteSeo $seo, string $shape): Response
    {
        throw_unless(Features::on('share_cards') && $generator->available(), NotFoundHttpException::class);

        return $this->card($request, $generator, $seo, $this->entry($request, ''), Shape::fromSuffix($shape));
    }

    private function card(Request $request, Generator $generator, SiteSeo $seo, EntryContract|TermContract|null $entry, Shape $shape): Response
    {
        $previewed = $entry ? $this->previewed($request, $entry) : null;
        // A protected page is refused, because its card would show its title and text to anyone.
        throw_unless($entry && ($previewed || $entry->status() === 'published') && ! $seo->isProtected($entry), NotFoundHttpException::class);
        $term = $entry instanceof TermContract;
        throw_unless($term ? $generator->termDraws($entry, $shape) : $generator->draws($entry, $shape), NotFoundHttpException::class);

        if ($previewed) {
            $png = $previewed instanceof TermContract ? $generator->termDraw($previewed, $shape) : $generator->draw($previewed, $shape);

            return new Response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
        }

        return new Response($term ? $generator->termPng($entry, $shape) : $generator->png($entry, $shape), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.(int) config('marketing-toolkit.og.max_age'),
        ]);
    }

    /**
     * Returns the entry as a Live Preview holds it, when the request's token previews this
     * entry. The card routes are outside Statamic's web middleware, which would otherwise put
     * the previewed entry in place.
     */
    private function previewed(Request $request, EntryContract|TermContract $entry): EntryContract|TermContract|null
    {
        if (! Fields::isPreviewed($entry)) {
            return null;
        }

        $item = LivePreview::item($request->statamicToken());

        return $item instanceof EntryContract || $item instanceof TermContract ? $item : null;
    }

    /**
     * Finds the entry, or else the taxonomy term, whose page is at $path on this
     * domain. For example, /og/fr/a-propos.png is the card of /fr/a-propos, on the
     * site under /fr/. A domain that no site is on (a local copy) is read as the
     * current site's.
     */
    private function entry(Request $request, string $path): EntryContract|TermContract|null
    {
        $url = $request->getSchemeAndHttpHost().'/'.$path;
        $site = Site::findByUrl($url);
        $uri = '/'.trim($site ? $site->relativePath($url) : '/'.$path, '/');
        $handle = ($site ?? Site::current())->handle();

        $entry = Entry::findByUri($uri, $handle);
        $entry = $entry instanceof Page ? $entry->entry() : $entry;

        return $entry ?? ($uri === '/' ? null : Term::findByUri($uri, $handle));
    }
}
