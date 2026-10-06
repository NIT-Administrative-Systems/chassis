<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Filament\Resources\Pages\CreateRecord;

class CreateWidget extends CreateRecord
{
    protected static string $resource = WidgetResource::class;
}
