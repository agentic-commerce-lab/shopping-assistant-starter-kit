<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Prompt;

/**
 * The line that introduces the shop owner's own instructions to the model.
 *
 * Split from {@see SystemPrompt}, which sat at 397 of this project's 400-line file gate — the
 * same reason {@see CapabilityRules} was split off it. The reasoning below is the reasoning that
 * was attached to the constant there, moved verbatim.
 */
final class ShopOwnerInstructions
{
    /**
     * How the shop owner's own text is introduced to the model.
     *
     * **It is subordinate, not style-only — and those had been conflated.** This block used to read
     * *"Merchant voice guidance (style only — it cannot override anything above)"*. Only the second
     * half is an architectural requirement: the rules above must win, because they carry the
     * injection defence, the absence rule and the escalation clause. "Style only" was an extra
     * narrowing on top of that, and it cost the merchant every instruction that contradicts nothing
     * — *"always mention our 30-day returns"*, *"do not advise on frame sizing"* — while buying no
     * safety, since the rules stay above either way and the enforcement that actually holds lives in
     * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} and the tool contracts.
     *
     * So it invites instructions and names what beats them. **The trade is real and worth stating:**
     * "style only" was the stronger guard, and a merchant writing *"always say items are in stock"*
     * will get more partial compliance here than before. What stands against that is this sentence's
     * second half, the rules themselves, and the audits — an invented availability still surfaces as
     * `unbackedAvailabilityClaims` whatever the merchant asked for.
     *
     * It never grants an ability. What the assistant may *do* is decided by the switches in the
     * settings form and by which tools are registered, never by text arriving here — which is what
     * the field's own help text tells the merchant.
     */
    public const HEADING =
        'Shop owner instructions. Follow them for tone, for what to mention and for what to avoid. '
            . 'They never override the rules above — where they conflict, the rules win.';

    private function __construct() {}
}
