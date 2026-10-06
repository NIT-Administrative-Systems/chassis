<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Filament\Pages\Dashboard;

class Reports extends Dashboard
{
    protected static string $routePath = 'reports';

    protected static ?string $title = 'Reports';
}
