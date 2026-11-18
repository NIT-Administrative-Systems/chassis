<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Markdown;

use InvalidArgumentException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Northwestern\SysDev\Chassis\Markdown\ResolveRelativeUrls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolveRelativeUrls::class)]
class ResolveRelativeUrlsTest extends TestCase
{
    private const string BASE = 'https://example.edu/support/changelog/v1';

    /**
     * @return array<string, array{string, string}>
     */
    public static function relativeUrls(): array
    {
        return [
            'root-relative' => ['/support/contact', 'https://example.edu/support/contact'],
            'path-relative' => ['v2', 'https://example.edu/support/changelog/v2'],
            'parent directory' => ['../feed.rss', 'https://example.edu/support/feed.rss'],
            'query string' => ['?page=2', 'https://example.edu/support/changelog/v1?page=2'],
            'fragment' => ['#added', 'https://example.edu/support/changelog/v1#added'],
            'protocol-relative' => ['//cdn.example.edu/a.png', 'https://cdn.example.edu/a.png'],
        ];
    }

    #[DataProvider('relativeUrls')]
    public function test_relative_link_urls_resolve_against_the_base(string $url, string $expected): void
    {
        $this->assertSame("<p><a href=\"{$expected}\">link</a></p>\n", $this->render("[link]({$url})"));
    }

    public function test_image_urls_resolve_against_the_base(): void
    {
        $this->assertSame(
            "<p><img src=\"https://example.edu/images/new.png\" alt=\"New\" /></p>\n",
            $this->render('![New](/images/new.png)'),
        );
    }

    public function test_reference_style_links_resolve_against_the_base(): void
    {
        $this->assertSame(
            "<p><a href=\"https://example.edu/support\">Support</a></p>\n",
            $this->render("[Support][help]\n\n[help]: /support"),
        );
    }

    public function test_a_base_with_a_path_keeps_it_for_path_relative_urls(): void
    {
        $html = $this->render('[a](guide) [b](/guide)', baseUrl: 'https://example.edu/app/');

        $this->assertSame(
            "<p><a href=\"https://example.edu/app/guide\">a</a> <a href=\"https://example.edu/guide\">b</a></p>\n",
            $html,
        );
    }

    public function test_urls_with_a_scheme_are_unchanged(): void
    {
        $html = $this->render('[a](https://northwestern.edu/x) [b](mailto:help@example.edu) <https://nu.edu>');

        $this->assertSame(
            '<p><a href="https://northwestern.edu/x">a</a> <a href="mailto:help@example.edu">b</a> '
            . "<a href=\"https://nu.edu\">https://nu.edu</a></p>\n",
            $html,
        );
    }

    public function test_extended_autolinks_are_unchanged(): void
    {
        $environment = $this->environment(self::BASE);
        $environment->addExtension(new AutolinkExtension());

        $this->assertSame(
            "<p>See <a href=\"http://www.northwestern.edu\">www.northwestern.edu</a></p>\n",
            (string) (new MarkdownConverter($environment))->convert('See www.northwestern.edu'),
        );
    }

    public function test_a_url_that_does_not_parse_is_unchanged(): void
    {
        $this->assertSame("<p><a href=\"//%5B::1\">bad</a></p>\n", $this->render('[bad](//[::1)'));
    }

    public function test_the_base_url_must_be_absolute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The base URL [/support] must be absolute.');

        new ResolveRelativeUrls('/support');
    }

    private function render(string $markdown, string $baseUrl = self::BASE): string
    {
        return (string) (new MarkdownConverter($this->environment($baseUrl)))->convert($markdown);
    }

    private function environment(string $baseUrl): Environment
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new ResolveRelativeUrls($baseUrl));

        return $environment;
    }
}
