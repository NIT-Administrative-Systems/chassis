<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Markdown;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\ExtensionInterface;

/**
 * A CommonMark extension that resolves a document's relative link and image URLs against a base
 * URL, as a browser would on the page at that URL. Markdown rendered away from the site, such as
 * an RSS feed's items, then links back to it: "/support" becomes "https://example.edu/support".
 * URLs with a scheme, such as https: and mailto: ones, and URLs that don't parse are unchanged.
 *
 * ```php
 * Str::markdown($changelog, extensions: [new ResolveRelativeUrls(route('changelog.show', $entry))]);
 * ```
 */
final readonly class ResolveRelativeUrls implements ExtensionInterface
{
    private Uri $baseUri;

    /**
     * @param  string  $baseUrl  An absolute URL, such as the page the Markdown is published on
     *
     * @throws InvalidArgumentException When the base URL has no scheme or host
     */
    public function __construct(string $baseUrl)
    {
        $this->baseUri = new Uri($baseUrl);

        if ($this->baseUri->getScheme() === '' || $this->baseUri->getHost() === '') {
            throw new InvalidArgumentException("The base URL [{$baseUrl}] must be absolute.");
        }
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if ($node instanceof AbstractWebResource) {
                    $node->setUrl($this->resolve($node->getUrl()));
                }
            }
        });
    }

    private function resolve(string $url): string
    {
        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $url)) {
            return $url;
        }

        try {
            return (string) UriResolver::resolve($this->baseUri, new Uri($url));
        } catch (InvalidArgumentException) {
            // A typo in one link shouldn't fail the whole document.
            return $url;
        }
    }
}
