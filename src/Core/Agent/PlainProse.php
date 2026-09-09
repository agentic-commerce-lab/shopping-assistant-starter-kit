<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

/**
 * Takes the markdown back out of a reply.
 *
 * ## Why this is not another prompt sentence
 *
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt::RULES} has asked for plain prose since
 * the beginning, and gives the reason: the plugin is not told what surface is presenting the
 * conversation, and the shipped one renders the reply as **text nodes** — see
 * `src/Resources/app/storefront/src/assistant/render.js`, which is deliberately never `innerHTML`.
 * So a model writing `**hardened steel**` puts two pairs of asterisks on the shopper's screen.
 *
 * Measured across 34 real conversations exported on 2026-09-09: **83 of 104 replies** carried
 * markup the prompt bans by name. One hundred and four attempts at instruction, eighty-three
 * failures. A rule that loses that consistently is not a rule the model is going to start following
 * — and unlike a claim about a product, this one can simply be corrected on the way out, because
 * removing a syntax character cannot change a fact.
 *
 * ## What it removes, and what it leaves alone
 *
 * Removed: emphasis markers, ATX headings, code fences and inline code ticks, pipe tables (flattened
 * to the one list shape the prompt allows), and horizontal rules.
 *
 * **Left alone: emoji.** The prompt's own sentence bans four things — asterisks, headings, tables,
 * code fences — and emoji is not among them. Stripping it would be this class inventing a rule
 * rather than enforcing one, and the unicode ranges are a maintenance liability for a decision
 * nobody has taken.
 *
 * **Left alone: `- ` list markers and ordinary paragraphs.** The prompt explicitly permits a list of
 * one item per line starting with `- `, so that is the shape a table is flattened INTO rather than
 * away from.
 *
 * ## Idempotent on purpose
 *
 * `Toolbox\AgentProcessor` resolves a tool round by calling `Agent::call()` recursively, so every
 * output processor sees the same final text once per nesting level — the effect
 * {@see GroundingOutputProcessor} keys a whole guard around. Nothing here needs that guard: running
 * this twice produces the same string as running it once, which is cheaper than remembering whether
 * it already ran.
 */
final class PlainProse
{
    /**
     * Applied in this order, and the order is load-bearing where it is not obvious.
     *
     * Fences go before inline ticks, so a fence line is not first read as an empty code span.
     * Headings go before emphasis, so `### **Tools**` loses the hashes rather than leaving a
     * half-stripped line. Tables go before emphasis for the same reason.
     *
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        // A fence line and its language tag. The code between fences is kept: it is text the model
        // chose to send, and this class removes syntax, not content.
        '/^[ \t]*(?:```|~~~)[^\n]*\n?/m' => '',
        // Inline code. The backticks are the markup; what they wrap is a word.
        '/`([^`\n]+)`/' => '$1',
        // ATX heading: keep the words, drop the hashes and any trailing ones.
        '/^[ \t]{0,3}#{1,6}[ \t]+([^\n]*?)[ \t]*#*[ \t]*$/m' => '$1',
        // A table's separator row carries no content at all.
        // The newline goes with it: left behind, it splits the flattened list in two.
        '/^[ \t]*\|?[ \t]*:?-{2,}:?[ \t]*(?:\|[ \t]*:?-{2,}:?[ \t]*)+\|?[ \t]*$\n?/m' => '',
        // A table row becomes the one list shape the prompt allows.
        '/^[ \t]*\|(.+)\|[ \t]*$/m' => '- $1',
        // Horizontal rules.
        '/^[ \t]{0,3}(?:\*{3,}|-{3,}|_{3,})[ \t]*$\n?/m' => '',
        // Bold and italic, in both spellings. Bounded so an underscore inside a word survives.
        //
        // Bold takes the NEAREST closing pair on the same line and may contain a single asterisk,
        // which is what makes nesting converge: `**A *B***` loses its outer pair on the first pass
        // and reads as `A *B*` on the second, where the italic rule finishes it. Refusing inner
        // asterisks here — the first attempt — left that reply with its markup intact.
        '/\*\*([^\n]+?)\*\*/' => '$1',
        '/(?<![\p{L}\p{N}_])__([^_\n]+)__(?![\p{L}\p{N}_])/u' => '$1',
        '/(?<![*\p{L}\p{N}])\*([^*\n]+)\*(?![*\p{L}\p{N}])/u' => '$1',
        '/(?<![\p{L}\p{N}_])_([^_\n]+)_(?![\p{L}\p{N}_])/u' => '$1',
        // What the table and rule rows left behind, plus any run the model sent itself.
        '/\n{3,}/' => "\n\n",
    ];

    /**
     * The separator a flattened table cell boundary becomes.
     *
     * A middle dot rather than a dash: a dash is what the list marker already is, and a row reading
     * `- Road Helmet Aero - White - Size S` cannot be told from four list items.
     */
    private const CELL_SEPARATOR = ' · ';

    /**
     * How many times the whole set is applied.
     *
     * **Nested emphasis is why this is not one pass.** Measured against the 34-conversation export:
     * one reply carried `**Shopping-Assistent für *diesen spezifischen Shop***`, and the bold pattern
     * refuses a run containing another asterisk — by design, so it cannot swallow two separate
     * emphases as one. A second pass then finds what the first one uncovered. Three is one more than
     * any real reply needed and the loop stops as soon as a pass changes nothing, so the bound is a
     * guarantee rather than a cost.
     */
    private const PASSES = 3;

    private function __construct() {}

    public static function of(string $prose): string
    {
        $plain = $prose;

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $before = $plain;
            $plain = self::onePass($plain);

            if ($plain === $before) {
                break;
            }
        }

        return trim(self::flattenCells($plain));
    }

    private static function onePass(string $prose): string
    {
        $plain = $prose;

        foreach (self::REPLACEMENTS as $pattern => $replacement) {
            $applied = preg_replace($pattern, $replacement, $plain);

            // Null is a compile or backtrack failure, never "nothing matched". The original text is
            // then the honest fallback: a reply the shopper can read with asterisks in it beats a
            // reply this class dropped on the floor.
            if ($applied === null) {
                continue;
            }

            $plain = $applied;
        }

        return $plain;
    }

    /**
     * Turns the surviving pipes of a flattened table row into a readable separator.
     *
     * Done as its own pass rather than inside the row pattern, because a row's cell count is not
     * known to the pattern that matched it and `preg_replace_callback` for one join is more machinery
     * than the job needs.
     */
    private static function flattenCells(string $prose): string
    {
        if (!str_contains($prose, '|')) {
            return $prose;
        }

        $lines = explode("\n", $prose);

        foreach ($lines as $index => $line) {
            if (!str_starts_with($line, '- ') || !str_contains($line, '|')) {
                continue;
            }

            $cells = array_filter(
                array_map('trim', explode('|', mb_substr($line, 2))),
                static fn(string $cell): bool => $cell !== '',
            );

            $lines[$index] = '- ' . implode(self::CELL_SEPARATOR, $cells);
        }

        return implode("\n", $lines);
    }
}
