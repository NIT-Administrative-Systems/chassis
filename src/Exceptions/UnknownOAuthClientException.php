<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Exceptions;

use Northwestern\SysDev\Chassis\Http\Middleware\DetectUnknownOAuthClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A person reached Passport's authorization screen from a client the server doesn't know:
 * deleted, revoked, or lost when the database was rebuilt. {@see DetectUnknownOAuthClient}
 * throws it; render it as a page that tells the person what to do.
 */
class UnknownOAuthClientException extends HttpException
{
    public function __construct()
    {
        parent::__construct(400, 'The application that sent you here is no longer registered.');
    }
}
