<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval\Assertion;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Eval\Assertion\AmbiguityNotResolvedSilently;
use Swag\AssistantStarterKit\Eval\Assertion\CompetingGroups;
use Swag\AssistantStarterKit\Eval\Assertion\GroupVocabulary;

/**
 * The two ways a turn may legitimately handle an ambiguous word, and the ways it may not.
 *
 * **The middle case is why this file exists.** The first version of this assertion counted any
 * question as a pass, and `ambiguous_fasteners` then went green while the assistant showed three
 * motorcycle bolts to someone who had not said what they ride. The reply asked *"Wofür genau möchten
 * Sie die Schrauben verwenden oder welche Größe benötigen Sie?"* — a real question, about the wrong
 * thing. These cases pin the difference so it cannot be loosened again by accident.
 */
#[CoversClass(AmbiguityNotResolvedSilently::class)]
#[CoversClass(CompetingGroups::class)]
#[CoversClass(GroupVocabulary::class)]
final class AmbiguityNotResolvedSilentlyTest extends TestCase
{
    private const GROUPS = [
        'groups' => [
            'Motorrad- und Rollerteile' => ['motorrad', 'roller'],
            'Fahrradteile' => ['fahrrad', 'rad'],
        ],
        'axis' => ['fahrzeug'],
    ];

    private function card(string $world, string $name): ProductCard
    {
        return new ProductCard(
            id: 'hj-' . md5($world . $name),
            parentId: null,
            name: $name,
            description: null,
            price: 1.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/x',
            imageUrl: null,
            categoryPath: [$world, 'Befestigungsmaterial'],
        );
    }

    /**
     * @param list<ProductCard> $cards
     */
    private function evaluate(string $prose, array $cards): bool
    {
        return (new AmbiguityNotResolvedSilently())->evaluate(
            new AssistantTurn($prose, $cards, 'answered'),
            new TraceRecorder(),
            self::GROUPS,
        )->passed;
    }

    /**
     * This used to pass, on the reasoning that two worlds side by side disclose the ambiguity.
     * They do not: the shopper is never told which world a card belongs to — `CardPayload` does not
     * send `categoryPath` and `card.js` does not render it — so these two cards read as two screws.
     */
    public function testCardsFromTwoWorldsDiscloseNothingTheShopperCanSee(): void
    {
        self::assertFalse($this->evaluate('Hier sind ein paar Schrauben.', [
            $this->card('Motorrad- und Rollerteile', 'Zylinderschraube'),
            $this->card('Fahrradteile', 'Hutmutter'),
        ]));
    }

    public function testNamingTwoReadingsInProseAlsoCounts(): void
    {
        self::assertTrue($this->evaluate('Wir haben Schrauben fürs Motorrad und fürs Fahrrad.', [$this->card(
            'Motorrad- und Rollerteile',
            'Zylinderschraube',
        )]));
    }

    /**
     * The generic form resolves it just as well, so a reply need not enumerate the catalogue.
     */
    public function testAskingForTheVehicleCounts(): void
    {
        self::assertTrue($this->evaluate('Für welches Fahrzeug brauchen Sie die Schrauben?', [$this->card(
            'Motorrad- und Rollerteile',
            'Zylinderschraube',
        )]));
    }

    /**
     * The measured failure, verbatim: a question that cannot settle which reading was meant.
     */
    public function testAQuestionAboutSizeDoesNotResolveTheAmbiguity(): void
    {
        self::assertFalse($this->evaluate('Wofür genau möchten Sie die Schrauben verwenden oder welche Größe benötigen Sie?', [
            $this->card('Motorrad- und Rollerteile', 'Zylinderschraube'),
            $this->card('Motorrad- und Rollerteile', 'Nummernschildschraube'),
        ]));
    }

    /**
     * German compounds are the common phrasing, so the match is bounded on the left only —
     * "Fahrradschrauben" must count, and "Motorrad" must not be read as the bicycle word "rad".
     */
    public function testCompoundsCountAndMotorradIsNotRad(): void
    {
        self::assertTrue($this->evaluate('Wir führen Motorradschrauben und Fahrradschrauben.', [$this->card(
            'Motorrad- und Rollerteile',
            'Zylinderschraube',
        )]));

        self::assertFalse($this->evaluate('Wir führen Motorradschrauben in mehreren Längen.', [$this->card(
            'Motorrad- und Rollerteile',
            'Zylinderschraube',
        )]));
    }

    /**
     * A different failure that used to read as this one — see the branch it now has of its own.
     */
    public function testAnEmptyTurnSaysSoRatherThanBlamingTheAmbiguity(): void
    {
        $result = (new AmbiguityNotResolvedSilently())->evaluate(
            new AssistantTurn('Leider nichts gefunden.', [], 'answered'),
            new TraceRecorder(),
            self::GROUPS,
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('renders_at_least', $result->detail);
    }

    public function testAJourneyThatNamedNoGroupsFailsLoudly(): void
    {
        $result = (new AmbiguityNotResolvedSilently())->evaluate(
            new AssistantTurn('egal', [], 'answered'),
            new TraceRecorder(),
            [],
        );

        self::assertFalse($result->passed);
        self::assertStringContainsString('no competing groups', $result->detail);
    }

    /**
     * It measures a behaviour nothing has been built to produce, so it takes the 2-of-3 leniency
     * rather than putting a permanent red safety line in the suite.
     */
    public function testItIsNotASafetyAssertion(): void
    {
        self::assertFalse((new AmbiguityNotResolvedSilently())->isSafety());
    }
}
