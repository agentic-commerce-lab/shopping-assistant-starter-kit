<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * The eighty-five products this seeder writes, as literals, across four shelf files.
 *
 * **Literals rather than a builder**, matching `FashionSeedTraps::all()` — the one other place in
 * this codebase holding hand-written catalogue data. A helper taking a product's seven fields as
 * arguments reads more compactly and is worse in the two ways that matter: it puts the fields in an
 * order a reader has to remember, and it sits over the parameter budget the rest of this project
 * holds itself to. This is a data table, so it is written as one.
 *
 * `properties` is required, not optional: it is the only thing
 * {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} shows the model besides the name
 * and the variant axes, so a product without it is one the assistant cannot compare. See
 * {@see BikeCatalogue::descriptiveGroups()}.
 *
 * `variants` is a map of group => values, expanded to the full cross product by
 * {@see BikeVariantFamily}. A `stock` of zero means the whole family is out of stock: that is the
 * case ruling R75 and the `variant_stock` journey are about, and a catalogue where everything is
 * available cannot exercise it.
 *
 * ## `description` is authored content, and it asserts things
 *
 * **Read this before reviewing a change to one.** Every description here was written by hand for a
 * fictional product, and since 2026-09-10 they deliberately state specific technical facts, because
 * a one-sentence description is what the assistant invents *into*: measured over 34 real
 * conversations, a shopper asked how long a lock resists a cutter and the shop's entire answer was
 * *"A 90 cm chain in a fabric sleeve, for locking to awkward stands."* The model filled the vacuum
 * with "hardened steel", "rated for high-security use" and an included bracket that does not exist.
 *
 * So each description now names four things, and each of them is a claim somebody has to be willing
 * to stand behind in a demo:
 *
 * | What it states | Example |
 * |---|---|
 * | **Material**, specifically, never contradicting the `Material` property | *"a 90 cm hardened steel chain in a fabric sleeve"* |
 * | **Scope of delivery** — what is in the box, and what is not | *"supplied with two keys and no bracket"* |
 * | **Fit** — what it mounts to, and what it will not fit | *"needs rack eyelets on the seatstays and dropouts"* |
 * | **The honest limit**, including the absence of a rating | *"no cut-resistance time and no security rating are published for it"* |
 *
 * **Two consequences worth being deliberate about.** These descriptions assert a real safety
 * standard by name — the three helmets say *"certified to EN 1078"* — and they assert engineering
 * figures: load ratings, thread patterns, tyre widths, link counts. That is the point rather than an
 * oversight, because the alternative is the model asserting them instead and nothing being able to
 * check it ({@see \Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit} audits a claim against
 * exactly this text). But it means the demo catalogue now makes conformity and specification claims
 * about products that do not exist, and a reviewer should accept that consciously rather than skim
 * it as a string change.
 *
 * The absence clauses are load-bearing in both directions and must not be edited away as negative
 * copy: *"no visor, no mirror and no light are supplied or fitted"* is what refutes the invented
 * *"Road Helmet Aero Mirror"*, and *"no cut-resistance time … is published"* is what a correct
 * refusal is quoting when a shopper asks how long the lock holds.
 *
 * @phpstan-type ProductSpec array{
 *     number: string,
 *     name: string,
 *     description: string,
 *     price: float,
 *     stock: int,
 *     manufacturer: string,
 *     category: string,
 *     properties: array<string, list<string>>,
 *     variants?: array<string, list<string>>,
 * }
 */
final class BikeProducts
{
    private function __construct() {}

    /** @return list<ProductSpec> */
    public static function all(): array
    {
        return [...BikeWear::all(), ...BikeRolling::all(), ...BikeParts::all(), ...BikeKit::all()];
    }
}
