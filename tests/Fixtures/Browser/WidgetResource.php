<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Tests\Fixtures\Browser;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WidgetResource extends Resource
{
    protected static ?string $model = Widget::class;

    protected static ?string $slug = 'widgets';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // A rule only the server checks, so the browser lets the form submit.
            TextInput::make('name')->label('Name')->rules(['starts_with:W']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWidgets::route('/'),
            'create' => CreateWidget::route('/create'),
            'edit' => EditWidget::route('/{record}/edit'),
        ];
    }
}
