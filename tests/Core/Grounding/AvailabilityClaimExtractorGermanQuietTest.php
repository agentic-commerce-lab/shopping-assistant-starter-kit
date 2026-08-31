<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\AvailabilityClaimExtractor;

/**
 * The German half of what the extractor must stay QUIET about, split from
 * {@see AvailabilityClaimExtractorGermanTest} the way {@see AvailabilityClaimExtractorQuietTest} is
 * split from {@see AvailabilityClaimExtractorTest} — mago's too-many-methods threshold is per class,
 * and the two halves are genuinely different questions anyway.
 *
 * **Ruling R85 is why this half is the larger risk.** A safety assertion that fires on correct
 * behaviour trains people to ignore it, and that is unaffordable on this one. Every case here is a
 * sentence the assistant is supposed to write.
 */
final class AvailabilityClaimExtractorGermanQuietTest extends TestCase
{
    private function extractor(): AvailabilityClaimExtractor
    {
        return new AvailabilityClaimExtractor();
    }

    /**
     * **Measured against the running local shop on 2026-08-31, and it is the reason this file grew.**
     * Asked *"Habt ihr das Trail Jersey in Blau, Groesse M?"*, the shop replied *"Ich habe das Trail
     * Jersey in Blau in der Größe M gefunden."* — which is exactly the sentence the system prompt
     * asks for: it reports the search and says nothing about stock, beside a card reporting 0. The
     * newly added German patterns flagged it anyway, on `ich habe das`.
     *
     * German builds the perfect tense with the same auxiliary the claim uses — *haben* — and puts
     * the participle at the END of the clause. So `ich habe X` is a claim or a report depending on a
     * word that can be twenty characters further on, and the 30-character trailing window could not
     * see it. Ruling R85 in its purest form: a safety check that fires on correct behaviour trains
     * people to ignore it, and this one would have fired on nearly every German turn.
     */
    public function testReportingWhatWasFoundIsNotAClaimEvenThoughGermanSharesTheAuxiliary(): void
    {
        self::assertSame([], $this->extractor()->extract('Ich habe das Trail Jersey in Blau in der Größe M gefunden.'));
        self::assertSame([], $this->extractor()->extract('Wir haben das Trail Jersey in Blau für dich herausgesucht.'));
        self::assertSame([], $this->extractor()->extract('Ich habe dir ein paar Taschen in dem Preisbereich gesucht.'));
    }

    /**
     * The other half of the same problem: German also puts its NEGATION at the end of the clause,
     * so a window measured in characters misses *"führen wir leider nicht"* on any sentence long
     * enough to name the product it is about — which is every real one.
     */
    public function testATrailingNegationSurvivesAWholeClauseBetweenItAndTheVerb(): void
    {
        self::assertSame([], $this->extractor()->extract('Wir führen das Trail Jersey in Blau leider nicht.'));
        self::assertSame([], $this->extractor()->extract('Wir haben das Trail Jersey in dieser Größe nicht mehr.'));
    }

    public function testALeadingNegationInGermanIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Das Trail Jersey ist in Blau nicht verfügbar.'));
    }

    /**
     * The trailing-negation case. Without a backward window this reads as a plain assertion that
     * the shop carries the item, which is the opposite of what the sentence says.
     */
    public function testATrailingNegationInGermanIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Wir führen das leider nicht.'));
        self::assertSame([], $this->extractor()->extract('Wir haben es nicht in Größe M.'));
        self::assertSame([], $this->extractor()->extract('Ja, wir haben so etwas, aber kein Blau in M.'));
    }

    public function testASoldOutStatementIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Das Trail Jersey in Blau, Größe M ist ausverkauft.'));
    }

    /** A question about adding something is not an assertion that it can be had. */
    public function testAnOfferToAddToTheCartIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Möchtest du es in den Warenkorb legen?'));
    }

    /** Pointing at the card is the deferral the prompt asks for, not a claim. */
    public function testDeferringToTheCardIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Die Verfügbarkeit siehst du auf der Karte unten.'));
    }

    /**
     * **The regression running both language sets creates.** English negations are matched inside
     * lowercased prose, and `no` is a substring of the extremely common German words `noch` and
     * `nochmal`. Matched loosely, an honest German claim would be silently negated by a word in the
     * sentence before it — a false negative in the one detector that must not have any, produced by
     * the very change meant to make it work in German.
     */
    public function testAGermanWordMerelyContainingAnEnglishNegationDoesNotNegate(): void
    {
        $prose = 'Ich habe noch einmal gesucht. Ja, wir haben das Trail Jersey in Blau.';

        self::assertNotSame([], $this->extractor()->extract($prose));
    }
}
