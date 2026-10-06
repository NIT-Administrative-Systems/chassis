<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Northwestern\SysDev\Chassis\Markdown\ShiftHeadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShiftHeadings::class)]
class ShiftHeadingsTest extends TestCase
{
    public function test_the_shallowest_heading_moves_to_the_chosen_level_and_the_rest_follow(): void
    {
        $html = $this->render("### 1.4.0\n\n#### Added\n\n##### Details", topLevel: 2);

        $this->assertSame("<h2>1.4.0</h2>\n<h3>Added</h3>\n<h4>Details</h4>\n", $html);
    }

    public function test_headings_can_move_deeper(): void
    {
        $html = $this->render("# Changelog\n\n## 1.4.0", topLevel: 3);

        $this->assertSame("<h3>Changelog</h3>\n<h4>1.4.0</h4>\n", $html);
    }

    public function test_levels_stay_within_one_to_six(): void
    {
        $html = $this->render("# Changelog\n\n#### Added\n\n###### Details", topLevel: 4);

        $this->assertSame("<h4>Changelog</h4>\n<h6>Added</h6>\n<h6>Details</h6>\n", $html);
    }

    public function test_a_document_without_headings_is_unchanged(): void
    {
        $this->assertSame("<p>Nothing to shift.</p>\n", $this->render('Nothing to shift.', topLevel: 2));
    }

    /**
     * @param  int<1, 6>  $topLevel
     */
    private function render(string $markdown, int $topLevel): string
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new ShiftHeadings($topLevel));

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
