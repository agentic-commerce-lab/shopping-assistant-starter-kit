<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * What the model may say about a document the merchant attached to a product.
 *
 * Split out of {@see SystemPrompt} the same way {@see CapabilityRules} and
 * {@see ShopOwnerInstructions} were, and for the same immediate reason: that file sits on this
 * project's ~400-line gate, and this block put it over. The boundary earns its keep beyond the
 * threshold, though — a reader asking "what is the assistant allowed to say about a datasheet?" has
 * one file to read, and the second stage of this feature, which will let it actually read one, has
 * an obvious place to change.
 *
 * **Unconditional, unlike every block in {@see CapabilityRules}.** There is no switch behind it. A
 * product either carries documents or it does not, and a shop that attaches none never puts the word
 * in front of a model — but the rule has to be there for the shop that does, because the failure it
 * prevents needs no configuration to happen.
 *
 * ## The failure it prevents
 *
 * The model is handed document TITLES and nothing else — see
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}, which withholds even the URL. A
 * title is an invitation: *"Sicherheitsdatenblatt"* reads like an answer to "is this safe to use
 * indoors?", and it is not one. Measured 2026-09-11 against the shop this was built for, the
 * documents are overwhelmingly safety data sheets — regulatory papers full of hazard statements —
 * and the prompt already refuses to let the model claim what a product is rated or certified for
 * unless the shop's own words make the claim. This extends that line to a file the shop attached
 * but nothing in this process has opened.
 *
 * ## Where it is appended, and why not inside the rules block
 *
 * {@see SystemPrompt::build()} appends it AFTER the escalation clause rather than inside
 * {@see SystemPrompt::RULES}. That clause opens with "If asked about any of those" and its
 * antecedent is the paragraph `RULES` ends on — the dangling-pronoun defect that split those two
 * constants apart in the first place. Nothing here refers backwards, so it is safe anywhere after
 * that pair, and it goes directly after them so it is read before the brevity and language rules
 * rather than among them.
 */
final class DocumentRules
{
    private function __construct() {}

    public const ATTACHED_DOCUMENTS = <<<'PROMPT'
        A tool result may list documents a product has — a datasheet, a manual, a safety data sheet.
        You may say that such a document exists and name it. The shop puts the link beside the
        product; you never write one yourself.

        You have NOT read any of those documents. Only their titles were given to you. So never say
        what one contains, never answer a question from it, and never treat its title as evidence
        about the product: a file called "Safety data sheet" tells you a document exists and nothing
        about whether the product is safe for anything. When the shopper asks what is in it, say the
        document is there for them to read and that you cannot read it for them.
        PROMPT;
}
