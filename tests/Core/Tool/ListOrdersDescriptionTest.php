<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\ListOrdersTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The description is a contract with the model, and nothing else checks it.
 *
 * **This test exists because an edit to it silently missed.** The commit that added `withinDays` and
 * `state` said the description named them; it did not — the replacement had been written against an
 * indentation a formatter run had since changed, and no test reads description text, so the suite
 * stayed green over a tool whose filters the model could only infer from parameter names. The eval
 * suite noticed, three paid runs later, when a colloquial phrasing failed 0/3.
 *
 * So the rule the description carries is asserted by substring rather than by intent: intent is what
 * failed. Deliberately loose — it pins the vocabulary a reply depends on, not the prose around it.
 */
final class ListOrdersDescriptionTest extends TestCase
{
    public function testTheModelIsToldAboutEveryParameterItCanPass(): void
    {
        $description = self::description();

        foreach (['withinDays', 'state', 'open', 'in_progress', 'completed', 'cancelled'] as $token) {
            self::assertStringContainsString(
                $token,
                $description,
                sprintf('the model cannot use "%s" if the description never names it', $token),
            );
        }
    }

    public function testTheModelIsToldWhichOrdersCarryADocument(): void
    {
        self::assertStringContainsString('withDocuments', self::description());
    }

    /** The empty case is the one a model invents its way around if nobody names it. */
    public function testTheModelIsToldWhatToSayWhenThereAreNoDocuments(): void
    {
        self::assertStringContainsString('empty', self::description());
    }

    private static function description(): string
    {
        $attributes = (new \ReflectionClass(ListOrdersTool::class))->getAttributes(AsTool::class);
        self::assertCount(1, $attributes);

        $description = $attributes[0]->getArguments()['description'] ?? '';
        self::assertIsString($description);

        return $description;
    }
}
