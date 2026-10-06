<?php

declare(strict_types=1);

use Northwestern\SysDev\Chassis\Testing\Browser\FilamentPages;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\Reports;
use Northwestern\SysDev\Chassis\Tests\Fixtures\Browser\WidgetResource;

beforeEach(fn () => $this->signIn());

it('lists the pages the person can open, without the ones that need a record', function () {
    expect(FilamentPages::in('admin'))->toBe([
        '/admin',
        '/admin/reports',
        '/admin/widgets',
        '/admin/widgets/create',
    ]);
});

it('leaves out the classes it is told to', function () {
    expect(FilamentPages::in('admin', except: [WidgetResource::class, Reports::class]))->toBe(['/admin']);
});
