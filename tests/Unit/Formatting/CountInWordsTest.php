<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Unit\Formatting;

use Northwestern\SysDev\Chassis\Formatting\CountInWords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountInWords::class)]
class CountInWordsTest extends TestCase
{
    public function test_it_spells_out_one_through_nine_and_pluralizes(): void
    {
        $this->assertSame('one minute', CountInWords::of(1, 'minute'));
        $this->assertSame('five seconds', CountInWords::of(5, 'second'));
        $this->assertSame('nine days', CountInWords::of(9, 'day'));
        $this->assertSame('10 days', CountInWords::of(10, 'day'));
        $this->assertSame('30 seconds', CountInWords::of(30, 'second'));
    }
}
