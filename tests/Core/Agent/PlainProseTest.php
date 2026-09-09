<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\PlainProse;

/**
 * The markdown the prompt has been asking the model not to write since the beginning.
 *
 * Measured over the 34-conversation export of 2026-09-09: **78 of 104 replies** carried markup the
 * prompt bans by name, and the shipped widget renders the reply as text nodes — so the shopper read
 * the asterisks. One hundred and four attempts at instruction is enough evidence; this is the same
 * rule enforced where it can be.
 */
#[CoversClass(PlainProse::class)]
final class PlainProseTest extends TestCase
{
    public function testItRemovesEmphasisAndKeepsTheWords(): void
    {
        self::assertSame(
            'The Chain Lock 90cm is hardened steel.',
            PlainProse::of('The **Chain Lock 90cm** is *hardened steel*.'),
        );
    }

    public function testItRemovesUnderscoreEmphasisWithoutTouchingAnIdentifier(): void
    {
        self::assertSame('a bold word', PlainProse::of('a __bold__ word'));
        self::assertSame('the sales_channel_id field', PlainProse::of('the sales_channel_id field'));
    }

    /**
     * The regression that made {@see PlainProse::PASSES} exist. One real reply carried
     * `**Shopping-Assistent für *diesen spezifischen Shop***`, and a bold pattern that refuses an
     * inner asterisk — the first attempt — left the whole run intact.
     */
    public function testItUnwrapsNestedEmphasis(): void
    {
        self::assertSame(
            'Ich bin ein Shopping-Assistent für diesen spezifischen Shop und suche Produkte.',
            PlainProse::of('Ich bin ein **Shopping-Assistent für *diesen spezifischen Shop*** und suche Produkte.'),
        );
    }

    public function testItRemovesHeadingsAndKeepsTheirText(): void
    {
        self::assertSame(
            "Helmets\nThree of them are in stock.",
            PlainProse::of("### Helmets\nThree of them are in stock."),
        );
    }

    public function testItRemovesCodeFencesAndInlineTicks(): void
    {
        self::assertSame("search\nfound nothing", PlainProse::of("```json\nsearch\n```\nfound `nothing`"));
    }

    /**
     * A table becomes the one list shape the prompt permits, rather than disappearing: the cells are
     * content the model chose to send, and only the pipes are markup.
     */
    public function testATableBecomesAList(): void
    {
        $table = "| Product | Size |\n| --- | --- |\n| Road Helmet Aero | S |\n| Gravel Helmet | M |";

        self::assertSame("- Product · Size\n- Road Helmet Aero · S\n- Gravel Helmet · M", PlainProse::of($table));
    }

    /**
     * **Emoji stays.** The prompt's own sentence bans asterisks, headings, tables and code fences,
     * and emoji is not among them — stripping it would be this class inventing a rule rather than
     * enforcing one.
     */
    public function testItLeavesEmojiAndPermittedListMarkersAlone(): void
    {
        $reply = "Two options 🚲\n- Road Helmet Aero\n- Gravel Helmet";

        self::assertSame($reply, PlainProse::of($reply));
    }

    /**
     * `Toolbox\AgentProcessor` re-invokes the whole processor chain per tool round, so every output
     * processor sees the same text more than once. This class is safe to run again instead of
     * carrying the turn-keyed guard {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor} needs.
     */
    public function testItIsIdempotent(): void
    {
        $once = PlainProse::of("## Locks\n| a | b |\n| --- | --- |\n| **x** | *y* |");

        self::assertSame($once, PlainProse::of($once));
    }

    public function testAReplyThatWasAlreadyPlainIsReturnedUnchanged(): void
    {
        $reply = 'The Gravel Helmet comes in M and L. The shop shows the current price beside it.';

        self::assertSame($reply, PlainProse::of($reply));
    }
}
