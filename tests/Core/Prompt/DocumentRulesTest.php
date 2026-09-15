<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * The sentence that keeps the first stage honest.
 *
 * A document reaches the model as a TITLE and nothing else, so every claim about its contents would
 * be invented — and the titles invite exactly that: "Sicherheitsdatenblatt" reads like an answer to
 * "is this safe?", and it is not one. The prompt already refuses to let the model say what a product
 * is rated or certified for unless the shop's own words make the claim; this extends the same line
 * to a file the shop attached but nothing in this process has opened.
 *
 * Pinned the way {@see CapabilityRulesTest} pins its own rules: a prompt rule that quietly
 * disappears in an edit is a control nobody notices losing.
 */
#[CoversClass(SystemPrompt::class)]
final class DocumentRulesTest extends TestCase
{
    private function prompt(): string
    {
        return SystemPrompt::build(new AssistantConfig());
    }

    public function testItMayNameADocumentThatExists(): void
    {
        self::assertMatchesRegularExpression('/datasheet|document/i', $this->prompt());
    }

    public function testItIsToldItHasNotReadThem(): void
    {
        self::assertMatchesRegularExpression('/NOT read/i', $this->prompt());
    }

    /**
     * The trap the titles set: a file called "Safety data sheet" is not evidence about safety.
     */
    public function testItIsToldATitleIsNotEvidence(): void
    {
        self::assertMatchesRegularExpression('/title as evidence/i', $this->prompt());
    }

    public function testItIsToldTheShopRendersTheLink(): void
    {
        self::assertMatchesRegularExpression('/never write one yourself/i', $this->prompt());
    }
}
