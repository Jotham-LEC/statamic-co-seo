<?php

namespace JothamLec\MarketingToolkit\Http\Middleware;

use Closure;
use DOMDocument;
use DOMElement;
use DOMText;
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
    private const array REMOVE = ['script', 'style', 'noscript', 'template', 'svg', 'form', 'iframe'];

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
            $this->tidy($xpath, $root, $origin);

            $converter = new HtmlConverter(['header_style' => 'atx', 'hard_break' => true, 'strip_tags' => true, 'strip_placeholder_links' => true]);
            $converter->getEnvironment()->addConverter(new TableConverter);
            $body = $converter->convert((string) $document->saveHTML($root));
        }

        // The converter leaves entities as the HTML had them (`&amp;`). Markdown reads them, but an agent
        // reads the text, so they are decoded, except `&lt;` and `&gt;`, which would turn text into tags.
        $body = (string) preg_replace_callback('/&(?!lt;|gt;)(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', fn (array $entity) => html_entity_decode($entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $body);
        // The space HTML kept around inline elements ends up at the ends of lines and after a heading's `#`.
        // Fenced code keeps its spacing.
        $parts = preg_split('/(^```.*?^```$)/ms', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $body = implode('', array_map(fn (string $part, int $index) => $index % 2 === 1 ? $part
            : (string) preg_replace(['/[ \t]+$/m', '/^ (?=\S)/m', '/^(#{1,6}) {2,}/m', '/(?<=\S) {2,}(?=\S)/'], ['', '', '$1 ', ' '], $part), $parts, array_keys($parts)));
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $body));

        return ["---\n".Yaml::dump($front)."---\n\n".$body."\n", $canonical];
    }

    /**
     * Takes out what an agent can't read, keeps the text of controls that hold content, and makes
     * the page's own addresses absolute.
     */
    private function tidy(DOMXPath $xpath, DOMElement $root, string $origin): void
    {
        $query = implode(' | ', [...array_map(fn (string $tag) => './/'.$tag, self::REMOVE), './/*[@hidden]', './/*[@aria-hidden="true"]']);

        foreach (iterator_to_array($xpath->query($query, $root) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // A button's text can be content, such as an accordion's question, so it stays and the button goes.
        foreach (iterator_to_array($xpath->query('.//button', $root) ?: []) as $button) {
            while ($button->firstChild) {
                $button->parentNode?->insertBefore($button->firstChild, $button);
            }

            $button->parentNode?->removeChild($button);
        }

        // HTML reads any run of whitespace as one space, but the converter drops a line break that starts
        // a text node, which would join `</span>\nby` into one word.
        foreach (iterator_to_array($xpath->query('.//text()[not(ancestor::pre) and not(ancestor::code)]', $root) ?: []) as $text) {
            $text->nodeValue = (string) preg_replace('/[ \t\r\n]+/', ' ', (string) $text->nodeValue);
        }

        // A line break in a heading would join the words on either side of it.
        foreach (iterator_to_array($xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]//br', $root) ?: []) as $break) {
            $break->parentNode?->replaceChild($root->ownerDocument->createTextNode(' '), $break);
        }

        // A link with only an icon is named by its aria-label, or else left out, and a link's text loses
        // the space at its ends, which would print as `[ Home ](…)`.
        foreach (iterator_to_array($xpath->query('.//a', $root) ?: []) as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            if ($link->firstChild instanceof DOMText) {
                $link->firstChild->nodeValue = ltrim((string) $link->firstChild->nodeValue);
            }

            if ($link->lastChild instanceof DOMText) {
                $link->lastChild->nodeValue = rtrim((string) $link->lastChild->nodeValue);
            }

            if (trim($link->textContent) !== '' || $xpath->query('.//img', $link)->length > 0) {
                continue;
            }

            $label = trim($link->getAttribute('aria-label'));

            if ($label === '') {
                $link->parentNode?->removeChild($link);
            } else {
                $link->textContent = $label;
            }
        }

        foreach (iterator_to_array($xpath->query('.//*[@href or @src]', $root) ?: []) as $element) {
            foreach (['href', 'src'] as $attribute) {
                if ($element instanceof DOMElement && $element->hasAttribute($attribute)) {
                    $element->setAttribute($attribute, $this->absolute($element->getAttribute($attribute), $origin));
                }
            }
        }
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
