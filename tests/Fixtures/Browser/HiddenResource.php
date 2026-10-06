<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Filament\Resources\Resource;

/**
 * A resource nobody may use, which page discovery must leave out.
 */
class HiddenResource extends Resource
{
    protected static ?string $model = Widget::class;

    protected static ?string $slug = 'hidden';

    public static function canViewAny(): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWidgets::route('/'),
        ];
    }
}
