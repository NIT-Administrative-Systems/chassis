<?php

declare(strict_types=1);

namespace Northwestern\SysDev\Chassis\Console\Commands\Migrate\Steps;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Northwestern\SysDev\Chassis\Console\Commands\Migrate\MigrationContext;

/**
 * Remove stale coverage exclusions from phpunit.xml after the delete step.
 *
 * The migration deletes many source files that consuming apps typically list
 * under `<source><exclude>` for coverage (enums, boilerplate, value objects).
 * After extraction those paths either no longer exist or reference empty
 * directories — leaving them in the config is dead weight.
 *
 * Only removes entries that point at paths that are gone or empty. Any exclude
 * pointing to a file or directory still present on disk is left untouched, so
 * app-specific exclusions are preserved.
 */
class CleanPhpunitExclusionsStep extends AbstractMigrationStep
{
    /**
     * Candidate phpunit config files, in preference order.
     *
     * @var list<string>
     */
    private const array CONFIG_PATHS = [
        'phpunit.xml',
        'phpunit.xml.dist',
    ];

    public function label(): string
    {
        return 'Cleaning stale phpunit.xml coverage exclusions...';
    }

    public function run(MigrationContext $context): void
    {
        $relativePath = $this->locateConfig();

        if ($relativePath === null) {
            return;
        }

        $absolutePath = base_path($relativePath);
        $xml = File::get($absolutePath);

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        if (! @$dom->loadXML($xml)) {
            return;
        }

        $removed = $this->pruneStaleExclusions($dom);

        if ($removed === []) {
            return;
        }

        $output = $dom->saveXML();

        if ($output === false) {
            return;
        }

        if (! $context->isDryRun) {
            File::put($absolutePath, $output);
        }

        $this->markFileModified($context);

        foreach ($removed as $path) {
            $this->success($context, "{$relativePath} removed exclusion: {$path}");
        }
    }

    private function locateConfig(): ?string
    {
        foreach (self::CONFIG_PATHS as $path) {
            if (File::exists(base_path($path))) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Walk `<source><exclude>` elements and remove `<file>` / `<directory>`
     * nodes whose paths no longer exist or point at empty directories.
     *
     * @return list<string> Paths of removed entries (for logging).
     */
    private function pruneStaleExclusions(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//source/exclude/file | //source/exclude/directory');

        if ($nodes === false) {
            return [];
        }

        $removed = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $path = trim($node->textContent);
            if ($path === '') {
                continue;
            }
            if (! $this->isStale($path)) {
                continue;
            }

            $parent = $node->parentNode;

            if ($parent === null) {
                continue;
            }

            // Drop the preceding whitespace text node if present, so we don't
            // leave blank lines behind.
            $previous = $node->previousSibling;
            if ($previous !== null && $previous->nodeType === XML_TEXT_NODE && trim($previous->nodeValue ?? '') === '') {
                $parent->removeChild($previous);
            }

            $parent->removeChild($node);
            $removed[] = $path;
        }

        return $removed;
    }

    /**
     * A path is stale if it no longer exists, or is an empty directory.
     */
    private function isStale(string $relativePath): bool
    {
        $absolute = base_path($relativePath);

        if (! File::exists($absolute)) {
            return true;
        }

        if (File::isDirectory($absolute)) {
            return File::allFiles($absolute) === [] && File::directories($absolute) === [];
        }

        return false;
    }
}
