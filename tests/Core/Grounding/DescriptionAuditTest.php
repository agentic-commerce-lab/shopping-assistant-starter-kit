<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit;

/**
 * The open-vocabulary audit, case by case, against the sentences that motivated it.
 *
 * Every row marked `flag` is a real reply from the trace export of 34 conversations, or the same
 * sentence with the enriched description behind it. Every row marked `clean` is a false positive
 * this audit produced on its first pass over those 104 replies — it went from 15 findings with 4
 * true ones to 4 findings all of which are true, and each exclusion below is one of the reasons.
 *
 * **The recall figure belongs here too, because it is not high.** The corpus has twelve replies
 * carrying an invented product fact and this catches four. The clearest miss is
 * *"would typically take much longer to cut than 3 minutes — often 10 minutes or more with standard
 * tools"*: a resistance-time claim, which uses none of the grammar of delivery or certification and
 * so is invisible to {@see \Swag\AssistantStarterKit\Core\Grounding\SuppliedFactClaimExtractor}.
 * Catching that needs a fourth claim family, and this class is deliberately a floor rather than a
 * guarantee — the same honesty {@see \Swag\AssistantStarterKit\Core\Grounding\PassageAudit} applies
 * to its own.
 */
final class DescriptionAuditTest extends TestCase
{
    private const THIN = 'A 90 cm chain in a fabric sleeve, for locking to awkward stands.';

    private const ENRICHED =
        'A 90 cm hardened steel chain in a fabric sleeve, long enough to reach an awkward stand, '
            . 'supplied with two keys and no bracket. No cut-resistance time and no security rating are published for it.';

    private const HELMET =
        'A vented aero road helmet certified to EN 1078, with an in-mould polycarbonate shell. '
            . 'No visor, no mirror and no light are supplied or fitted.';

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function replies(): iterable
    {
        // The four real findings.
        yield 'an invented bracket, against a one-sentence description' => [
            'It can be mounted to your frame using the included bracket - no extra hardware is needed.',
            [self::THIN],
            true,
        ];
        yield 'an invented bracket the enriched description DENIES' => [
            'It can be mounted using the included bracket.',
            [self::ENRICHED],
            true,
        ];
        yield 'an invented rating the enriched description DENIES' => [
            'Both are rated for high-security use.',
            [self::ENRICHED],
            true,
        ];
        yield 'a claim made with nothing handed over at all' => [
            'It comes with a frame mount.',
            [],
            true,
        ];
        yield 'an invented certification beside a real one' => [
            'The helmet is certified to EN 12492 for climbing.',
            [self::HELMET],
            true,
        ];

        // The shop's own sentence, restated.
        yield 'a truthful restatement of the delivery' => [
            'It is supplied with two keys and no bracket.',
            [self::ENRICHED],
            false,
        ];
        yield 'a truthful restatement of the certification' => [
            'The helmet is certified to EN 1078.',
            [self::HELMET],
            false,
        ];

        // The false positives, each with its own reason.
        yield 'a question offers rather than states' => [
            'Would you like me to include it in your cart?',
            [],
            false,
        ];
        yield 'grammar attached to no checkable noun' => [
            'The price includes VAT.',
            [],
            false,
        ];
        yield 'including enumerates a category' => [
            'Handlebar and stem (including bar tape or grips) come next.',
            [],
            false,
        ];
        yield 'the subject is the card, not the product' => [
            'The shop card includes any technical specifications and certifications it has.',
            [],
            false,
        ];
        yield 'German enthaelt is plain containment' => [
            'Prueft, ob der Warenkorb des Kunden Artikel enthält, und leitet ihn zum Checkout weiter.',
            [],
            false,
        ];
    }

    /**
     * @param list<string> $shopProse
     */
    #[DataProvider('replies')]
    public function testWhatIsReportedAndWhatIsNot(string $prose, array $shopProse, bool $expected): void
    {
        $found = (new DescriptionAudit())->unsupportedFactClaims($prose, $shopProse, self::facets());

        self::assertSame($expected, $found !== [], json_encode($found, \JSON_THROW_ON_ERROR));
    }

    /**
     * A claim made only of the shop's own facet values belongs to
     * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedProperties()}, which checks
     * it against the rendered card. Reported here it would be both double-counted and wrong: this
     * was five findings across four conversations, and the reply is restating an attribute the card
     * carries.
     */
    public function testAClaimMadeOnlyOfFacetValuesIsTheOtherAuditsBusiness(): void
    {
        $found = (new DescriptionAudit())->unsupportedFactClaims(
            'It is the one rated for trail and gravel where the road helmet is road only.',
            [],
            self::facets(),
        );

        self::assertSame([], $found);
    }

    /**
     * The counterpart, and the reason the test above checks *every* token rather than any: one word
     * outside the vocabulary makes the claim open-vocabulary again. `trail` is a Terrain value;
     * `helmets` is not, and this reply is one of the corpus's real inventions.
     */
    public function testOneTokenOutsideTheVocabularyKeepsTheClaim(): void
    {
        $found = (new DescriptionAudit())->unsupportedFactClaims(
            'It did return trail-rated helmets that are often used for BMX.',
            [],
            self::facets(),
        );

        self::assertSame(['trail-rated helmets'], $found);
    }

    private static function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Terrain', FacetType::Terms, ['Road', 'Gravel', 'Trail', 'Commuting']),
            new Facet('properties.Material', FacetType::Terms, ['Steel', 'Alloy', 'Carbon', 'Nylon']),
        ]);
    }
}
