<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How the access token on an OAuth request was obtained, judged from its client.
 */
enum OAuthGrantType: string implements HasLabel
{
    case PersonalAccess = 'personal_access';

    /** Authorization code, including tokens later refreshed or obtained by device code. */
    case AuthorizationCode = 'authorization_code';

    case ClientCredentials = 'client_credentials';

    public function getLabel(): string
    {
        return match ($this) {
            self::PersonalAccess => 'Personal access token',
            self::AuthorizationCode => 'Authorization code',
            self::ClientCredentials => 'Client credentials',
        };
    }
}
