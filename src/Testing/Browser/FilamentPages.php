<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Testing\Browser;

use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use LogicException;

/**
 * Finds the pages of a Filament panel that the signed-in person can open, so a test can check
 * every page and a page can't be added without being checked.
 *
 * Each resource contributes its index and create pages, when it has them and the person may
 * use them; each standalone page, cluster and dashboard contributes itself. Pages whose URL
 * needs a parameter, such as record pages and nested resources, are left out: list those
 * explicitly, with records made for the test.
 */
final class FilamentPages
{
    /**
     * The paths, with their query strings, of the panel's pages the authenticated user can open.
     *
     * @param  list<class-string>  $except  Resource or page classes to leave out
     * @return list<string>
     */
    public static function in(string $panelId, array $except = []): array
    {
        $panel = Filament::getPanel($panelId);

        if ($panel->hasTenancy()) {
            throw new LogicException("The [{$panelId}] panel uses tenancy, which FilamentPages doesn't support.");
        }

        $previous = Filament::getCurrentPanel();
        Filament::setCurrentPanel($panel);

        try {
            $urls = [];

            foreach ($panel->getResources() as $resource) {
                if (is_subclass_of($resource, Resource::class) && ! in_array($resource, $except, true)) {
                    array_push($urls, ...self::resourceUrls($resource, $panelId));
                }
            }

            foreach ($panel->getPages() as $page) {
                if (! in_array($page, $except, true) && $page::canAccess()) {
                    $urls[] = self::url(static fn (): mixed => $page::getUrl(panel: $panelId));
                }
            }
        } finally {
            Filament::setCurrentPanel($previous);
        }

        $paths = array_values(array_unique(array_filter($urls, is_string(...))));
        sort($paths);

        return $paths;
    }

    /**
     * @param  class-string<resource>  $resource
     * @return list<string|null>
     */
    private static function resourceUrls(string $resource, string $panelId): array
    {
        $urls = [];

        if ($resource::hasPage('index') && $resource::canViewAny()) {
            $urls[] = self::url(static fn (): mixed => $resource::getUrl('index', panel: $panelId));
        }

        if ($resource::hasPage('create') && $resource::canCreate()) {
            $urls[] = self::url(static fn (): mixed => $resource::getUrl('create', panel: $panelId));
        }

        return $urls;
    }

    /**
     * @param  callable(): mixed  $url
     */
    private static function url(callable $url): ?string
    {
        try {
            $absolute = $url();
        } catch (UrlGenerationException) {
            return null;
        }

        if (! is_string($absolute)) {
            return null;
        }

        $path = parse_url($absolute, PHP_URL_PATH);
        $query = parse_url($absolute, PHP_URL_QUERY);

        return (is_string($path) && $path !== '' ? $path : '/') . (is_string($query) ? '?' . $query : '');
    }
}
