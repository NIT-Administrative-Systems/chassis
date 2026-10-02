<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Relays Sentry browser SDK envelopes through the application's own origin, so ad
 * blockers and strict content security policies don't drop browser error reports.
 *
 * Only envelopes addressed to the application's configured DSN are forwarded, and
 * always to that DSN's host. Envelopes for any other host, project, or key are
 * rejected, so the route cannot be used to relay arbitrary payloads to Sentry.
 *
 * Register the route and point the browser SDK's `tunnel` option at it:
 * ```php
 * Route::post('sentry/tunnel', SentryTunnelController::class)
 *     ->withoutMiddleware([PreventRequestForgery::class])
 *     ->name('sentry.tunnel');
 * ```
 *
 * Override `configuredDsn()` when the browser SDK reports to a different DSN than
 * the one in `sentry.dsn`.
 */
class SentryTunnelController
{
    public function __invoke(Request $request): Response
    {
        $configured = $this->parseDsn($this->configuredDsn());

        $envelope = $request->getContent();
        $header = json_decode(strtok($envelope, "\n") ?: '', true);
        $requested = is_array($header) && is_string($header['dsn'] ?? null)
            ? $this->parseDsn($header['dsn'])
            : null;

        if ($requested === null) {
            return response('Envelope header does not contain a valid DSN.', 422);
        }

        if ($configured === null || $requested !== $configured) {
            return response('Envelope DSN does not match this application.', 403);
        }

        try {
            $upstream = Http::withBody($envelope, 'application/x-sentry-envelope')
                ->post($this->envelopeUrl($configured));
        } catch (ConnectionException) {
            return response('Sentry is unreachable.', 502);
        }

        return response($upstream->body(), $upstream->status())
            ->header('Content-Type', $upstream->header('Content-Type') ?: 'text/plain');
    }

    /**
     * The DSN that envelopes must be addressed to.
     */
    protected function configuredDsn(): ?string
    {
        $dsn = config('sentry.dsn');

        return is_string($dsn) ? $dsn : null;
    }

    /**
     * Reduce a DSN to the parts that identify where an envelope is delivered.
     *
     * @return array{scheme: string, host: string, port: ?int, key: string, prefix: string, project: string}|null
     */
    private function parseDsn(?string $dsn): ?array
    {
        $parts = $dsn === null || $dsn === '' ? false : parse_url($dsn);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            return null;
        }

        $segments = explode('/', trim($parts['path'], '/'));
        $project = array_pop($segments);

        if (! ctype_digit($project) || $project === '0') {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme']),
            'host' => strtolower($parts['host']),
            'port' => $parts['port'] ?? null,
            'key' => $parts['user'],
            'prefix' => $segments === [] ? '' : '/' . implode('/', $segments),
            'project' => $project,
        ];
    }

    /**
     * @param  array{scheme: string, host: string, port: ?int, key: string, prefix: string, project: string}  $dsn
     */
    private function envelopeUrl(array $dsn): string
    {
        $port = $dsn['port'] === null ? '' : ':' . $dsn['port'];

        // The trailing slash is required; Sentry returns a 404 without it.
        return "{$dsn['scheme']}://{$dsn['host']}{$port}{$dsn['prefix']}/api/{$dsn['project']}/envelope/";
    }
}
