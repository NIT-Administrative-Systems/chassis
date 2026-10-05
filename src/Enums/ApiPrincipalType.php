<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who an authenticated OAuth request acts for.
 */
enum ApiPrincipalType: string implements HasLabel
{
    /** A person, through a personal access token or an application they authorized. */
    case User = 'user';

    /** A client acting for itself, through the client credentials grant. */
    case Client = 'client';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Client => 'Client',
        };
    }
}
