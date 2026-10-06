<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Formatting;

use Northwestern\SysDev\Chassis\Formatting\TitleCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TitleCase::class)]
class TitleCaseTest extends TestCase
{
    #[DataProvider('names')]
    public function test_it_applies_chicago_headline_style(string $text, string $expected): void
    {
        $this->assertSame($expected, TitleCase::of($text));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'plain words' => ['personal access token', 'Personal Access Token'],
            'small words stay lowercase' => ['applications with access to your account', 'Applications with Access to Your Account'],
            'a small word first or last is capitalized' => ['the token to look for', 'The Token to Look For'],
            'prepositions of any length' => ['sign-ins from the last day without errors', 'Sign-Ins from the Last Day without Errors'],
            'a verb\'s particle is capitalized' => ['sign in with email', 'Sign In with Email'],
            'already capitalized small words are lowered' => ['Date And Time', 'Date and Time'],
            'acronyms and inner capitals are kept' => ['revoke MCP client for myHR via OAuth', 'Revoke MCP Client for myHR via OAuth'],
            'NetID' => ['sign out of NetID', 'Sign Out of NetID'],
            'hyphenated words' => ['sign-in records', 'Sign-In Records'],
            'placeholders are kept' => ['delete selected :label', 'Delete Selected :label'],
            'extra spaces are kept' => ['audit  log', 'Audit  Log'],
        ];
    }
}
