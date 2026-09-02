<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Policy\PolicyDecision;

/**
 * Whether the shopper actually chose the variant an `add_to_cart` call is trying to add.
 *
 * ## The defect this exists for
 *
 * Reported from a live shop on 2026-09-02: *"when I ask for adding items to the basket the agent
 * just adds them, although e.g. the jersey has 3 different variants. I asked for a jersey, it says
 * oh yes we have this variants. Then asking to add in the basket and it just adds one randomly
 * without asking which one specific I want — this could lead to wrong orders."*
 *
 * {@see AddToCartTool} already refused a family PARENT, which is not a sellable unit. This is the
 * neighbouring case it did not cover, and the more dangerous of the two: a concrete variant id is a
 * perfectly valid sellable unit, so the call was accepted and the cart line was real. It was simply
 * the wrong colour, and the shopper found out after ordering.
 *
 * Nothing enforced the question. `search_products` hands the model every variant's id and option
 * values, so which one reached the cart came down to the model reading one sentence of tool
 * description — a request, not a rule, against D6: *capability control is toolbox construction,
 * never a prompt instruction*. Two live runs on 2026-09-02 happened to ask politely, which is a
 * sampled outcome and not a guarantee.
 *
 * ## Why the model cannot fake its way past this
 *
 * The selections are resolved through {@see CommerceGatewayInterface::resolveVariant()}, documented
 * never to guess: it returns null unless the selections narrow to exactly one variant.
 * {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} already trusts that contract
 * completely, and this leans on the same guarantee. So inventing option values does not help — the
 * wrong variant's options resolve to the wrong variant and are refused, and options that narrow to
 * more than one resolve to null. The only way through is to name the unit being added, which is
 * also the sentence the shopper needs to read in the reply.
 *
 * ## Why `parentId`, and not a family-size lookup
 *
 * Shopware sets `parent_id` only on a sellable unit that belongs to a family, so "does this product
 * have variants" is already answered on the card. Counting the family instead would mean reaching
 * for {@see \Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup}, which is deliberately a
 * separate interface from the published gateway one — a merchant's own gateway need not implement
 * it. This is the one tool with write authority, and it must not fail open because an optional
 * interface happened to be absent.
 *
 * (Said without the marker word on purpose: `ApiSymbolsAreDocumentedTest` treats any file
 * mentioning it as a published extension point, and this class is not one.)
 *
 * The cost of the simpler test is that a family of exactly one variant also has to state its
 * options. That is acceptable: the model holds those values verbatim from the tool result, and a
 * reply that names the variant it added is the one a shopper can check.
 *
 * ## Why it is its own class
 *
 * The same reason {@see WholeFamilyResolver} and {@see CartCorrectionNote} are: {@see AddToCartTool}
 * is the write authority and is measured against a cyclomatic-complexity budget summed across every
 * method in the class. Four more branches inside it put it over — which is the gate correctly
 * reporting that the tool had grown a second job.
 */
final class ChosenVariant
{
    private function __construct() {}

    /**
     * Why this add must be refused, or null when the choice is settled.
     *
     * `$card` is what {@see CommerceGatewayInterface::product()} returned for the id being added, so
     * its own id is the identity to compare against: it is the unit that gets priced against the
     * cart limit and rendered beside the confirmation, and a guard that checked anything else could
     * pass while a different variant reached the shopper.
     *
     * @param ?array<array-key, array<array-key, string>|string> $options the option values the
     *        shopper chose, in the raw shape the model sent them
     *
     * @throws ToolArgumentException from {@see VariantSelectionGuard::fromRaw()} when the entries
     *         are not a shape this can read at all. Deliberately not caught: a malformed argument is
     *         the model's own error, and
     *         {@see \Swag\AssistantStarterKit\Core\Agent\MalformedToolArgumentRejection} already
     *         turns it into a correction the model can act on rather than a silent add.
     */
    public static function refusalFor(
        CommerceGatewayInterface $gateway,
        CatalogScope $scope,
        ProductCard $card,
        ?array $options,
    ): ?PolicyDecision {
        if ($card->parentId === null) {
            return null;
        }

        $selections = VariantSelectionGuard::fromRaw($options, 'options');

        if ($selections === []) {
            return PolicyDecision::block(
                'variant_not_chosen',
                'This product has variants and no options were given, so there is no way to tell '
                . 'which one the shopper wants. Ask them which options they want — show the '
                . 'variants — and add the one they name, passing those options in "options".',
            );
        }

        $chosen = $gateway->resolveVariant($card->parentId, $selections, $scope);

        // Two reason codes, not one. A merchant reading a trace has to tell a missing conversation
        // ("the model never asked") from the model contradicting itself ("it named Black/M and
        // added Blue/L") — those are fixed in different places.
        if ($chosen === null || $chosen->id !== $card->id) {
            return PolicyDecision::block(
                'variant_mismatch',
                'Those options do not identify the variant you tried to add: they match a '
                . 'different variant, or more than one. Pass the option values of the exact '
                . 'variant you are adding, copied from its own "options" in the search result — '
                . 'and if the shopper has not chosen yet, ask them instead of picking for them.',
            );
        }

        return null;
    }
}
