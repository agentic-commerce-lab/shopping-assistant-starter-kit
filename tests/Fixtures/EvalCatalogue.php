<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures;

use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogFile;
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;

/**
 * Resolves `ASSISTANT_EVAL_CATALOG` to the catalogue an eval run should use.
 *
 * Its own class rather than a method on one of the three: a class named after one catalogue is the
 * wrong place to decide between them, and this decision is read by
 * {@see \Swag\AssistantStarterKit\Eval\JourneyCatalogue} on the other side of every journey.
 *
 * **An unknown value falls back to small rather than failing.** Inherited from `LargeCatalogFile`, and
 * the reasoning is unchanged: a typo in an environment variable is not worth a red suite, and the
 * expensive mistake is a run that silently used the fifteen-thousand-unit catalogue because someone
 * wrote `fashon`. Note the asymmetry with `JourneyCatalogue`, where an unknown name THROWS — there it
 * is a claim a journey makes about itself, and a claim nobody checks is worthless.
 */
final class EvalCatalogue
{
    public const SMALL = 'small';

    public const LARGE = 'large';

    public const FASHION = 'fashion';

    private const ENV = 'ASSISTANT_EVAL_CATALOG';

    private function __construct() {}

    /** Which catalogue this run uses, by name — the value {@see \Swag\AssistantStarterKit\Eval\JourneyCatalogue::requires()} takes. */
    public static function chosenName(): string
    {
        // `getenv()` returns false when unset, and `(string) false` is '' — which matches no arm below.
        // Casting rather than testing `is_string()` first keeps this class's branch count low and loses
        // nothing: '' and false mean the same here.
        return match (strtolower(trim((string) getenv(self::ENV)))) {
            self::LARGE => self::LARGE,
            self::FASHION => self::FASHION,
            default => self::SMALL,
        };
    }

    /**
     * The path to that catalogue, generating it if it is absent or stale.
     *
     * Generation is a side effect of asking, which is why {@see self::chosenName()} exists separately:
     * a caller that only wants to know which catalogue a run uses — a skip decision, a report header —
     * must not write 5.7 MB to find out.
     */
    public static function chosen(): string
    {
        return match (self::chosenName()) {
            self::LARGE => LargeCatalogFile::path(),
            self::FASHION => FashionCatalogFile::path(),
            default => LargeCatalogFile::smallPath(),
        };
    }
}
