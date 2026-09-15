<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * What the assistant may say about which part of the shop a product comes from.
 *
 * Its own class rather than a constant on {@see SystemPrompt}, for the reason {@see CapabilityRules}
 * and {@see DocumentRules} were split off it: that file sits on this project's ~400-line gate. It is
 * separate from `DocumentRules` because a department is not a document — they arrived in the same
 * week and share nothing else.
 *
 * **Unconditional.** There is no switch behind it. A gateway that records no category path emits no
 * department, the model is told nothing, and the rule costs a few sentences it will never apply —
 * which is today the case in every real Shopware shop, since
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalProductCardMapper} still hardcodes an
 * empty path. See {@see \Swag\AssistantStarterKit\Core\Tool\Departments} for why that half is
 * deliberately deferred until this one is measured.
 */
final class DepartmentRules
{
    private function __construct() {}

    /**
     * What a department is for, and the failure that made it necessary.
     *
     * Measured 2026-09-14: a shop selling for cars, motorcycles and bicycles holds a product called
     * "Innensechskantschraube" in two of those departments. Asked *"ich brauche schrauben"*, the
     * assistant returned a different department on each of three runs and never said a choice had
     * been made — because no tool result named a department, so there was nothing to notice. Tyres
     * hid this: "Ganzjahresreifen" gives the vehicle away in the name, bolts give away nothing.
     *
     * **It says "say which", not "ask which".** Naming the departments answers the shopper and
     * discloses the ambiguity in one sentence; forcing a question would tax everyone who already
     * knew what they wanted, which is what `qualified_question_not_interrogated` guards.
     */
    public const DEPARTMENTS = <<<'PROMPT'
        A tool result may say which department of the shop a product belongs to. That is the shop's
        own navigation, not an internal field, so you may name it in ordinary words.

        Use it when the products you are about to name come from more than one department. Say which
        is which, in a few words — "die eine ist aus den Fahrradteilen, die andere aus den
        Motorradteilen" — and ask which one they mean. A word like "Schrauben" or "Reifen" means a
        different product in each department, and a shopper shown one department's answer to a
        question they asked about another has been told something wrong without being able to see it.

        When every product you name is from the same department, say nothing about departments. It
        is not a label to attach to every answer.
        PROMPT;
}
