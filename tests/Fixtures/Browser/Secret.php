<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Filament\Pages\Dashboard;

/**
 * A page nobody may open, which page discovery must leave out.
 */
class Secret extends Dashboard
{
    protected static string $routePath = 'secret';

    protected static ?string $title = 'Secret';

    public static function canAccess(): bool
    {
        return false;
    }
}
