<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The rules that make an answer advice rather than a datasheet.
 *
 * **Written against a measured answer.** Asked for a helmet after a conversation about trail riding,
 * the assistant returned all five helmets it found, one line each, listing every property it had —
 * including "made of polystyrene" on three of them, which is true, identical across all three, and
 * useless for choosing. It closed with three questions at once and no recommendation.
 *
 * Nothing in that reply broke a rule. The prompt says at length what the model may not claim and
 * nothing about how to advise, so a datasheet was a compliant answer. These three rules are the
 * missing half.
 *
 * Its own class rather than more methods on {@see SystemPromptTest}, which is at mago's method
 * ceiling.
 */
final class SystemPromptAdviceTest extends TestCase
{
    /**
     * Context ranks, it does not filter — and the reason is in the shop's data, not in politeness.
     * `sk-101 Trail Helmet` is the most trail-specific helmet in the catalogue and carried no
     * attributes at all until recently, so any narrowing on `Terrain=Trail` would have dropped exactly
     * the right answer. The model must not treat missing attributes as evidence of unsuitability.
     */
    public function testItTellsTheModelToRankOnContextRatherThanNarrowByIt(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('what to put first', $prompt);
        self::assertStringContainsString('not to decide what to leave out', $prompt);
    }

    public function testItAsksForOneRecommendationWithAReasonBeforeAlternatives(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('Lead with one recommendation', $prompt);
        self::assertStringContainsString('at most two alternatives', $prompt);
    }

    /**
     * The polystyrene rule. A property shared by every candidate cannot move a decision, and naming it
     * on each of them buries the one that can.
     */
    public function testItForbidsNamingAPropertyThatDoesNotTellTheCandidatesApart(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('only when it tells the products apart', $prompt);
    }

    public function testItLimitsTheModelToOneQuestionAtATime(): void
    {
        self::assertStringContainsString('one question at a time', SystemPrompt::build(new AssistantConfig()));
    }

    /**
     * The advice rules must sit inside the rules block, before the language clause that closes it —
     * an instruction appended after the closing paragraph reads as an afterthought, which is the same
     * reasoning `SystemPrompt::CLOSING` already documents for itself.
     */
    public function testTheAdviceRulesSitInsideTheRulesBlock(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        $advice = strpos($prompt, 'Lead with one recommendation');
        $closing = strpos($prompt, 'Answer in the language the shopper writes in');

        self::assertIsInt($advice);
        self::assertIsInt($closing);
        self::assertLessThan($closing, $advice);
    }

    /**
     * The two-alternative limit must not override an explicit request for everything.
     *
     * **Measured live 2026-09-01.** Asked "Show me every helmet you have", the assistant named three of
     * the six it found. That is the recommendation rule doing exactly what it was told and being wrong
     * about it: a shopper who asks for the full range is not asking to be curated, and a limit that
     * silently hides half a catalogue is worse than the datasheet the rule was written to replace.
     */
    public function testTheAlternativeLimitYieldsToAnExplicitRequestForEverything(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        // Asserted on phrases that do not straddle the heredoc's line wraps — "that limit does not
        // apply" is split across two lines in the source and would never match as one string.
        self::assertStringContainsString('asks to see all of them', $prompt);
        self::assertStringContainsString('not asking to be curated', $prompt);
    }

    /**
     * A constraint nothing satisfies is an answer, not a reason to keep searching.
     *
     * **Measured live 2026-09-01.** *"Ich suche Handschuhe für den Winter, aber nichts über 35 Euro"*
     * exhausted the tool-call budget and returned the degraded "could not finish" reply. The shop's
     * winter gloves cost €39, so the search with the ceiling found nothing — and the model kept trying
     * other wordings instead of saying so.
     *
     * The budget on that shop was set to 5 against a shipped default of 20, which is the larger half of
     * the cause. But a model that answers after two fruitless attempts is right at any budget, and one
     * that keeps rewording is wasting a limit that exists to stop runaway loops.
     */
    public function testItTellsTheModelToAnswerRatherThanKeepSearchingWhenAConstraintCannotBeMet(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('nothing meets a limit the shopper set', $prompt);
        self::assertStringContainsString('do not keep trying different wordings', $prompt);
    }
}
