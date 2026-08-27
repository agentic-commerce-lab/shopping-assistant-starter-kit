<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\PeriodClaimExtractor;

/**
 * Finding the periods a reply states, in a form two different phrasings of the same period agree on.
 *
 * ## Why a normalised form and not the words
 *
 * The whole reason this class exists is that a model paraphrases. Measured on the lab shop: the
 * document said "binnen dreissig Tagen" and the reply said "30 Tage"; another said "vierzehn Tagen"
 * and the reply said "14 Tage". A substring check would call both of those inventions, and
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit} already records what that costs — a
 * safety warning that fires on correct behaviour trains people to ignore it. So "thirty days",
 * "dreissig Tagen" and "30 Tage" must all reduce to the same token before anything is compared.
 *
 * German is handled although this suite is otherwise English-first, because the *detector* runs in
 * production: the shipped shop answers in the shopper's language, and a control that only works in
 * English would be absent exactly where these documents live.
 */
final class PeriodClaimExtractorTest extends TestCase
{
    /**
     * @return list<array{string, list<string>}>
     */
    public static function cases(): array
    {
        return [
            ['You have 14 days to return the goods.', ['14 day']],
            ['You have fourteen days to return the goods.', ['14 day']],
            ['Sie haben vierzehn Tage Zeit.', ['14 day']],
            ['Widerruf binnen dreissig Tagen.', ['30 day']],
            ['Widerruf binnen dreißig Tagen.', ['30 day']],
            // The paraphrase pair that must agree, in both languages.
            ['We refund within 30 days.', ['30 day']],
            // A warranty period nobody granted — the case this exists for.
            ['We give a 24 month warranty on frames.', ['24 month']],
            ['Die Gewaehrleistung betraegt zwei Jahre.', ['2 year']],
            // Working days are not days: three working days is not three days.
            ['Delivery takes one to three working days.', ['1 working day', '3 working day']],
            ['Lieferzeit drei bis fuenf Werktage.', ['3 working day', '5 working day']],
            ['Delivery in 1-3 working days.', ['1 working day', '3 working day']],
            ['Payment is due within one month.', ['1 month']],
            ['Wir speichern die Daten zehn Jahre.', ['10 year']],
            // Nothing to find: no number, or a number with no period unit.
            ['We cannot find that in the shop information.', []],
            ['The jersey costs 49.90 euro.', []],
            ['Order number 14 has shipped.', []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testItReducesEveryPhrasingToOneToken(string $text, array $expected): void
    {
        self::assertSame($expected, (new PeriodClaimExtractor())->extract($text));
    }

    public function testTheSamePeriodIsReportedOnceHoweverOftenItIsRepeated(): void
    {
        $claims = (new PeriodClaimExtractor())->extract(
            'You have 14 days. The fourteen days start on delivery. Within fourteen days we refund.',
        );

        self::assertSame(['14 day'], $claims);
    }
}
