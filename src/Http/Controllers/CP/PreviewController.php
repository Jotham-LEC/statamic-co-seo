<?php

namespace JothamLec\MarketingToolkit\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JothamLec\MarketingToolkit\Og\Generator;
use JothamLec\MarketingToolkit\Preview\Draft;
use JothamLec\MarketingToolkit\Preview\MetaPayload;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\Sites;
use Statamic\Contracts\Entries\Entry;
use Statamic\Contracts\Taxonomies\Term;

/**
 * Feeds the `mt_preview` fieldtype. Both actions read the publish form's
 * current values, so the preview follows the editor's typing before the
 * entry is saved, and run them through the same SiteSeo rules the page uses.
 */
final class PreviewController
{
    public function meta(Request $request, MetaPayload $payload): JsonResponse
    {
        $content = Draft::fromRequest($request);

        // The payload is worked out on the content's site, with its brand values, its locale and its address.
        return response()->json(Sites::as($content->locale(), fn () => $payload->forContent($content)));
    }

    /**
     * Returns the generated card for the form as it stands. It is drawn every time
     * and never cached, because each draft differs and only saved pages go public.
     */
    public function card(Request $request, Generator $generator): Response
    {
        $content = Draft::fromRequest($request);

        abort_unless(Features::on('share_cards') && $generator->available(), 404);

        // The card methods work the card out on the page's site, as the public card's route does.
        $png = $content instanceof Term ? $generator->termDraw($content) : $generator->template($content)->image($generator->card($content))->toString();

        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }
}
