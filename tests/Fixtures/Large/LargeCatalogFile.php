<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

/**
 * Puts the generated catalogue where {@see \Swag\AssistantStarterKit\Eval\JourneyRunner} can read it,
 * and decides which catalogue an eval run should use.
 *
 * `var/` is already gitignored, which is spec decision S2's other half: the generator is the
 * reviewable artefact and the file is a build output. Regenerating it must therefore be free, and it
 * is — 6 ms of pure arithmetic over a 12-product JSON, measured.
 *
 * **An unknown value falls back to small rather than failing.** A typo in an environment variable is
 * not worth a red suite, and the alternative — a run that silently used the large catalogue because
 * someone wrote `larg` — is the expensive mistake, not the cheap one.
 */
final class LargeCatalogFile
{
    private const ENV = 'ASSISTANT_EVAL_CATALOG';

    private const LARGE = 'large';

    private function __construct() {}

    /** The catalogue an eval run should use, from the environment. */
    public static function chosen(): string
    {
        // `getenv()` returns false when unset, and `(string) false` is '' — which is not `large`.
        // Casting rather than testing `is_string()` first keeps this class's branch count under the
        // ten this project's lint gate allows, and loses nothing: '' and false mean the same here.
        $requested = strtolower(trim((string) getenv(self::ENV)));

        return self::LARGE === $requested ? self::path() : self::smallPath();
    }

    /**
     * Absolute path to the generated catalogue, written when absent or stale.
     *
     * **Stale, not just absent.** Keying the cache on existence alone means a change to
     * {@see LargeCatalogGenerator} leaves last week's catalogue on disk, and the next eval run
     * measures that one while the report names the new generator — a wrong number that looks exactly
     * like a right one. The stamp beside the file records what produced it; a mismatch rebuilds.
     */
    public static function path(): string
    {
        $path = self::largePath();
        $stamp = self::sourceStamp();

        if (!is_file($path) || self::readStamp($path) !== $stamp) {
            self::write($path, $stamp);
        }

        return $path;
    }

    /**
     * Where the generated catalogue lives, without generating it.
     *
     * {@see self::path()} is the same location plus the guarantee that the file there is current.
     * Split so a caller that only needs to name the file — a cleanup, a `.gitignore` check — does not
     * write 769 KB as a side effect of asking.
     */
    public static function largePath(): string
    {
        return self::repositoryRoot() . '/var/catalog-large.json';
    }

    public static function smallPath(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/catalog.json';
    }

    private static function write(string $path, string $stamp): void
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
            false !== file_put_contents($temporary, (new LargeCatalogGenerator(self::smallPath()))->toJson())
            && rename($temporary, $path)
            && false !== file_put_contents(self::stampPath($path), $stamp);

        if (!$written) {
            throw new \RuntimeException(\sprintf('Unable to write the large catalogue to "%s".', $path));
        }
    }

    /**
     * What produced the file on disk: the generator's own sources, the fixture it copies, and the
     * default seed.
     *
     * Content, not mtime. A `git checkout` rewrites mtimes without changing a byte, and would
     * otherwise force a rebuild on every branch switch while missing the case that matters — an
     * edited generator whose file happens to keep its timestamp.
     */
    private static function sourceStamp(): string
    {
        $sources = [
            __DIR__ . '/LargeCatalogGenerator.php',
            __DIR__ . '/ScaleTrapProducts.php',
            __DIR__ . '/ScaleTrap.php',
            self::smallPath(),
        ];

        $material = '';

        foreach ($sources as $source) {
            $material .= (string) file_get_contents($source);
        }

        return hash('sha256', $material);
    }

    private static function readStamp(string $path): string
    {
        $stampPath = self::stampPath($path);

        return is_file($stampPath) ? (string) file_get_contents($stampPath) : '';
    }

    /**
     * Where the stamp for a catalogue file lives.
     *
     * Public so a test can drop it and force a rebuild. That is not a hole in the design: the stamp
     * is a cache key, and the worst a caller can do by deleting it is spend 6 ms regenerating a file
     * that is byte-identical.
     */
    public static function stampPath(string $path): string
    {
        return $path . '.stamp';
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, levels: 3);
    }
}
