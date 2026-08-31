<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Prompt;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Prompt\SystemPrompt;

/**
 * Its own file rather than more methods on {@see SystemPromptTest}, the same way
 * {@see SystemPromptViewingTest} and {@see SystemPromptMatchReasonsTest} are — mago's
 * too-many-methods threshold is per class.
 *
 * **The rule these pin.** The prompt used to close with a flat `Answer in English.`, so a German
 * shopper in a German storefront was answered in English beside German buttons, German card labels
 * and German warnings. Replacing it with the sales channel's language would have fixed that case
 * and broken the one that matters more: a shopper writing German into an English-configured shop
 * is still a shopper writing German, and the reply follows the person, not the settings screen.
 *
 * So the channel's language survives only as the fallback for the case where the shopper's language
 * genuinely cannot be told — a first message that is a size, a colour or a product name.
 */
final class SystemPromptLanguageTest extends TestCase
{
    public function testTheReplyFollowsTheShoppersOwnLanguage(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('Answer in the language the shopper writes in', $prompt);
    }

    /**
     * The flat order is gone, not merely joined by a second sentence: two instructions about the
     * reply's language, one of them absolute, is worse than either alone.
     */
    public function testThePromptNoLongerOrdersEnglishOutright(): void
    {
        self::assertStringNotContainsString('Answer in English.', SystemPrompt::build(new AssistantConfig()));
    }

    /**
     * Stickiness is a separate rule from detection, and it is the one that makes the feature usable:
     * a shopper who answers "XL" to a German question has written a message with no language in it,
     * and a model detecting per message would flip to English mid-conversation.
     */
    public function testThePromptKeepsOneLanguageForTheWholeConversation(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig());

        self::assertStringContainsString('stay in it for the whole conversation', $prompt);
    }

    public function testTheConfiguredFallbackLanguageIsTheOneTheModelIsToldToUse(): void
    {
        $prompt = SystemPrompt::build(new AssistantConfig(defaultReplyLanguage: 'German'));

        self::assertStringContainsString('answer in German', $prompt);
        self::assertStringNotContainsString('answer in English', $prompt);
    }

    public function testTheFallbackIsEnglishWhenTheChannelResolvedNothing(): void
    {
        self::assertStringContainsString('answer in English', SystemPrompt::build(new AssistantConfig()));
    }

    /**
     * The language rule stays where `Answer in English.` was — between the rules and the catalogue
     * vocabulary — for the reason {@see SystemPrompt::CLOSING}'s own placement was chosen: anything
     * after the vocabulary is read as commentary on the vocabulary.
     */
    public function testTheLanguageRuleStillPrecedesTheVocabularyAndTheVoice(): void
    {
        $prompt = SystemPrompt::build(
            new AssistantConfig(agentVoice: 'Be terse.'),
            'Words this shop uses. Size: M, L.',
        );

        $language = strpos($prompt, 'Answer in the language the shopper writes in');
        $vocabulary = strpos($prompt, 'Words this shop uses. Size: M, L.');
        $voice = strpos($prompt, 'Be terse.');

        self::assertIsInt($language);
        self::assertIsInt($vocabulary);
        self::assertIsInt($voice);
        self::assertGreaterThan($language, $vocabulary);
        self::assertGreaterThan($vocabulary, $voice);
    }
}
