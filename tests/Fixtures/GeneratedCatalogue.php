<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures;

/**
 * Writes a generated catalogue to `var/` and keeps it current, for any generator.
 *
 * Extracted from `Large\LargeCatalogFile` when a second generated catalogue arrived. Every comment
 * below was written for that one and is unchanged, because the reasoning is unchanged.
 *
 * **Note that no gate would have caught a copy instead.** `.jscpd.json` scans `src` only and ignores
 * `**​/tests/**`, so duplicating this logic would have passed every check. It is shared on the merit:
 * the staleness rule below is subtle, and a silently diverged second copy means one catalogue is stale
 * while the report naming it says otherwise.
 *
 * **Stale, not just absent.** Keying the cache on existence alone means a change to a generator leaves
 * last week's catalogue on disk, and the next eval run measures that one while the report names the new
 * generator — a wrong number that looks exactly like a right one. The stamp beside the file records
 * what produced it; a mismatch rebuilds.
 *
 * **Content, not mtime.** A `git checkout` rewrites mtimes without changing a byte, and would otherwise
 * force a rebuild on every branch switch while missing the case that matters — an edited generator
 * whose file happens to keep its timestamp.
 */
final class GeneratedCatalogue
{
    private function __construct() {}

    /**
     * The path, guaranteed to hold a catalogue current with `$stampSources`.
     *
     * @param list<string>       $stampSources files whose content decides whether `$path` is stale
     * @param callable(): string $produce      the generator's JSON, called only when a write is needed
     */
    public static function ensure(string $path, array $stampSources, callable $produce): string
    {
        $stamp = self::stampOf($stampSources);

        if (!is_file($path) || self::readStamp($path) !== $stamp) {
            self::write($path, $stamp, $produce);
        }

        return $path;
    }

    /**
     * Where the stamp for a catalogue file lives.
     *
     * Public so a test can drop it and force a rebuild. That is not a hole in the design: the stamp is
     * a cache key, and the worst a caller can do by deleting it is spend a few milliseconds
     * regenerating a file that is byte-identical.
     */
    public static function stampPath(string $path): string
    {
        return $path . '.stamp';
    }

    /** @param callable(): string $produce */
    private static function write(string $path, string $stamp, callable $produce): void
    {
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        // Written to a sibling and renamed, so a reader never sees a half-written catalogue. `rename`
        // is atomic within a filesystem, and both paths are in `var/` by construction.
        //
        // The three writes share one guard rather than carrying one each: this class's cyclomatic
        // complexity is summed across its methods against a threshold of ten, and which of the three
        // failed does not change what the caller can do about it. The path is in the message.
        $temporary = $path . '.tmp';
        $written =
            false !== file_put_contents($temporary, $produce())
            && rename($temporary, $path)
            && false !== file_put_contents(self::stampPath($path), $stamp);

        if (!$written) {
            throw new \RuntimeException(\sprintf('Unable to write the generated catalogue to "%s".', $path));
        }
    }

    /**
     * What produced the file on disk: the content of every source that decides its shape.
     *
     * @param list<string> $stampSources
     */
    private static function stampOf(array $stampSources): string
    {
        $material = '';

        foreach ($stampSources as $source) {
            $material .= (string) file_get_contents($source);
        }

        return hash('sha256', $material);
    }

    private static function readStamp(string $path): string
    {
        $stampPath = self::stampPath($path);

        return is_file($stampPath) ? (string) file_get_contents($stampPath) : '';
    }
}
