<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * What the tool tells the model about itself — its `#[AsTool]` description, which is where the
 * guidance lives rather than in the system prompt, because the tool is constructed only when the
 * gateway can read a category tree (D6).
 *
 * Two of these pin corrections rather than intentions: the note first licensed an absence claim and
 * broke a safety journey, and the description first left the model asking permission to chain after
 * a mismatched search.
 */
#[CoversClass(BrowseCategoriesTool::class)]
final class BrowseCategoriesInstructionsTest extends TestCase
{
    /**
     * **The note licenses no absence claim, and its first version did.** It offered "you may say the
     * shop has no department for it", which took the `no_match_not_absence` safety journey to 0 of 3
     * on both archetypes against a documented 2/3–3/3 band — `NoAbsenceClaimInProse` matches on the
     * SUBJECT and deliberately cannot tell "no department for bikes" from "no bikes". This asserts
     * the licence is gone and stays gone.
     */
    public function testTheNoteNeverOffersASentenceAboutWhatTheShopLacks(): void
    {
        $note = BrowseCategoriesTool::NOTE;

        self::assertStringNotContainsString('you may say the shop has no', $note);
        self::assertStringContainsString('name the ones it does have', $note);
        self::assertStringContainsString('settles nothing', $note);
    }

    /**
     * And the same for the description, which is where the model reads its instructions.
     */
    public function testTheDescriptionForbidsTheAbsenceSentenceToo(): void
    {
        $description = self::descriptionOfTool();

        self::assertStringContainsString('Write no sentence about what the shop lacks', $description);
        self::assertStringNotContainsString('you may say the shop has', $description);
    }

    /**
     * The second trigger: a search that came back with the wrong KIND of thing. Measured on staging,
     * the model knew the tool was there and asked permission — so the shopper got a bottle of cleaner
     * and a question instead of an answer.
     */
    public function testTheDescriptionChainsItAfterAMismatchedSearchWithoutAsking(): void
    {
        $description = self::descriptionOfTool();

        self::assertStringContainsString('WITHOUT ASKING FIRST', $description);
        self::assertStringContainsString('is the kind of thing the shopper asked for', $description);
        self::assertStringContainsString('do not name the mismatched product', $description);
    }

    private static function descriptionOfTool(): string
    {
        $attributes = (new \ReflectionClass(BrowseCategoriesTool::class))->getAttributes(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class);

        self::assertNotSame([], $attributes);

        return (string) ($attributes[0]->getArguments()['description'] ?? '');
    }
}
