<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\CompareProductsTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The description tells the model **when not to** call this tool, not only what it does.
 *
 * ## Measured on the staging shop, 2026-09-02, `google/gemini-3.7-flash`
 *
 * Five prompts, five calls to `compare_products` — including three where nothing was being compared:
 *
 * | prompt | wall clock | compare called | should have |
 * |---|---|---|---|
 * | "Do you have any jerseys?" | 13.9 s | yes | no |
 * | "I am looking for a good jacket for autumn riding" | 23.4 s | yes | no |
 * | "Show me some shorts" | 35.8 s | yes | no |
 * | "What is the difference between the Gravel Helmet and the Road Helmet Aero?" | 13.1 s | yes | **yes** |
 * | "Which is better for winter, the Club Jersey or the Thermal Jersey Long Sleeve?" | 12.7 s | yes | **yes** |
 *
 * With the tool switched off entirely the same first prompt ran in **6.4 s** and the answers were
 * word-for-word equivalent — same facts, same structure, same closing question. The model was not
 * getting anything for the extra 12.6 s, because everything it named (insulated, short sleeve,
 * summer) comes from `properties`, which `search_products` already returns. The one thing this tool
 * adds over that is the shop's own **description** of each product.
 *
 * ## Why a description and not a gate in code
 *
 * A keyword heuristic on the shopper's message ("compare", "difference", "versus") is the obvious
 * alternative and it is worse: it fires on *"what is the difference between the sizes"* and misses
 * *"which of those two would you pick for winter"*. Deciding whether the shopper wants a comparison
 * is the model's job — it just had never been told that not comparing was an option.
 *
 * The capability itself is still governed by toolbox construction (D6): `enableCompareProducts`
 * decides whether the tool exists at all, and no wording here can conjure it. This governs *when a
 * constructed tool is worth calling*, which is a different question.
 */
final class CompareProductsInvocationGuardTest extends TestCase
{
    public function testTheDescriptionSaysWhenNotToCallIt(): void
    {
        $description = self::description();

        self::assertMatchesRegularExpression(
            '/do not call|never call|only when/i',
            $description,
            'the description states what the tool does but never when to leave it alone',
        );
    }

    /**
     * The specific misuse that was measured: enriching an ordinary recommendation. Named explicitly,
     * because "only when relevant" is the kind of instruction a model reads as always relevant.
     */
    public function testItForbidsUsingTheToolToDressUpAnOrdinaryRecommendation(): void
    {
        self::assertMatchesRegularExpression('/recommendation/i', self::description());
    }

    /**
     * The reason, stated rather than implied. A model told *why* a call is pointless declines it more
     * reliably than one handed a bare prohibition — and this reason is checkable: `search_products`
     * returns `options` and `properties` through the same {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}
     * shape this tool uses.
     */
    public function testItSaysTheSearchResultAlreadyCarriesOptionsAndProperties(): void
    {
        $description = self::description();

        self::assertStringContainsString('search_products', $description);
        self::assertMatchesRegularExpression('/already/i', $description);
    }

    /**
     * What must survive: the tool is still for comparing, and the model must still recognise the case
     * it exists for. A guard that suppressed it everywhere would trade seconds for a broken feature.
     */
    public function testItStillDescribesTheComparisonItIsFor(): void
    {
        self::assertMatchesRegularExpression('/compare/i', self::description());
    }

    private static function description(): string
    {
        $attributes = (new \ReflectionClass(CompareProductsTool::class))->getAttributes(AsTool::class);
        $tool = ($attributes[0] ?? null)?->newInstance();

        self::assertInstanceOf(AsTool::class, $tool);

        return $tool->description;
    }
}
