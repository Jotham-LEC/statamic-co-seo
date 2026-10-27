<?php

namespace JothamLec\MarketingToolkit\Http\Middleware;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use JothamLec\MarketingToolkit\Settings;
use JothamLec\MarketingToolkit\Support\Features;
use JothamLec\MarketingToolkit\Support\StatamicRoutes;
use JothamLec\MarketingToolkit\TextFiles\TextFiles;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Answers a request that prefers Markdown (`Accept: text/markdown`) with the page's main content as
 * Markdown, for AI agents, and marks every HTML page `Vary: Accept`, since its address now has two
 * forms. It is global middleware, outside the routes and so outside Statamic's static cache, whose
 * key ignores Accept: the cache keeps storing and serving HTML, with HTML's headers, and the page is
 * converted on its way out, so a browser never gets a Markdown copy the cache kept.
 */
final class NegotiateMarkdown
{
    /** Elements that carry no text an agent can read, or only controls. */
    private const array REMOVE = ['script', 'style', 'noscript', 'template', 'svg', 'form', 'button', 'iframe'];

    public function __construct(private Settings $settings, private TextFiles $textFiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::isHtml($response) || StatamicRoutes::owns($request->getPathInfo()) || str_starts_with(ltrim($request->getPathInfo(), '/'), 'livewire')) {
            return $response;
        }

        $response->setVary('Accept', false);

        if (! $this->wantsMarkdown($request) || $response->getStatusCode() !== 200) {
            return $response;
        }

        $html = $response->getContent();

        if (! is_string($html) || trim($html) === '') {
            return $response;
        }

        [$markdown, $canonical] = $this->convert($html, $request);

        $response->setContent($markdown);
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set('x-markdown-tokens', (string) (int) ceil(strlen($markdown) / 4));
        $response->headers->set('Link', '<'.$canonical.'>; rel="canonical"', false);
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');

        if ($this->settings->bool('content_signals', true)) {
            $response->headers->set('Content-Signal', $this->textFiles->contentSignal());
        }

        return $response;
    }

    /**
     * A missing Content-Type counts as HTML, since a Laravel response that returns a string has none
     * until it is sent.
     */
    public static function isHtml(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $type = $response->headers->get('Content-Type');

        return $type === null || str_starts_with(strtolower($type), 'text/html');
    }

    private function wantsMarkdown(Request $request): bool
    {
        if (! Features::on('markdown_negotiation') || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        // Sorted by quality, so Markdown wins only when it is ranked above HTML, or HTML isn't asked for.
        $types = $request->getAcceptableContentTypes();
        $markdown = array_search('text/markdown', $types, true);
        $html = array_search('text/html', $types, true);

        return $markdown !== false && ($html === false || $markdown < $html);
    }

    /**
     * Converts the page's `<main>` (or, without one, its `<body>`) to Markdown, under front matter
     * with its title, description and canonical address.
     *
     * @return array{string, string} the Markdown, and the canonical address
     */
    private function convert(string $html, Request $request): array
    {
        $document = new DOMDocument;
        // The XML declaration makes libxml read the page as UTF-8.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($document);
        $origin = $request->getSchemeAndHttpHost();

        $canonical = $this->absolute($this->attribute($xpath, '//link[@rel="canonical"]', 'href') ?? '', $origin) ?: $request->url();
        $front = array_filter([
            'title' => $this->text($xpath, '//title'),
            'description' => $this->attribute($xpath, '//meta[@name="description"]', 'content'),
            'url' => $canonical,
        ], fn (?string $value) => filled($value));

        $root = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        $body = '';

        if ($root instanceof DOMElement) {
            $query = implode(' | ', [...array_map(fn (string $tag) => './/'.$tag, self::REMOVE), './/*[@hidden]', './/*[@aria-hidden="true"]']);

            foreach (iterator_to_array($xpath->query($query, $root) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }

            foreach (iterator_to_array($xpath->query('.//*[@href or @src]', $root) ?: []) as $element) {
                foreach (['href', 'src'] as $attribute) {
                    if ($element instanceof DOMElement && $element->hasAttribute($attribute)) {
                        $element->setAttribute($attribute, $this->absolute($element->getAttribute($attribute), $origin));
                    }
                }
            }

            $converter = new HtmlConverter(['header_style' => 'atx', 'strip_tags' => true, 'strip_placeholder_links' => true]);
            $converter->getEnvironment()->addConverter(new TableConverter);
            $body = $converter->convert((string) $document->saveHTML($root));
        }

        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $body));

        return ["---\n".Yaml::dump($front)."---\n\n".$body."\n", $canonical];
    }

    /** Makes a root-relative address (`/about`) absolute, and leaves every other address as it is. */
    private function absolute(string $url, string $origin): string
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $origin.$url : $url;
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        $text = trim((string) $xpath->query($query)->item(0)?->textContent);

        return $text === '' ? null : $text;
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)->item(0);
        $value = $node instanceof DOMElement ? trim($node->getAttribute($attribute)) : '';

        return $value === '' ? null : $value;
    }
}
