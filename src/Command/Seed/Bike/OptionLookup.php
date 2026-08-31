<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Turns a `group => values` map into DAL option references, or names everything it could not find.
 *
 * **One class for both kinds of option a product carries.** A variant axis and a descriptive property
 * are different things to the catalogue — one multiplies a product into sellable units, the other
 * describes it — but they are the same thing to the shop: a property group with options, looked up in
 * the ids {@see BikeSeedContext::optionIds()} resolved. {@see ProductReferences} held two copies of
 * this loop once properties arrived, and mago reported it as complexity while jscpd would have
 * reported it as duplication. Both were describing the same missing seam.
 *
 * **Null rather than a shortened list.** A partial result is the failure the seeder's whole
 * resolve-before-you-write posture exists to prevent: the product would be written looking correct
 * while missing exactly the value somebody added it for. Every miss is still recorded before the null
 * comes back, so one pass over the catalogue names all of them — the same reason
 * {@see UnresolvedReferences} collects rather than throws.
 */
final class OptionLookup
{
    private function __construct() {}

    /**
     * @param array<string, list<string>>          $axes      group => values, as the catalogue declares them
     * @param array<string, array<string, string>> $optionIds group => value => id, as the shop has them
     * @param string                               $kind      what to call one of these in an error — "option" for a
     *                                                        variant axis, "property" for a descriptive one, so the
     *                                                        message names which half of the payload is wrong
     *
     * @return ?list<array{id: string}>
     */
    public static function resolve(
        array $axes,
        array $optionIds,
        UnresolvedReferences $unresolved,
        string $kind,
    ): ?array {
        $references = [];
        $resolved = true;

        foreach ($axes as $group => $values) {
            foreach ($values as $value) {
                $id = $optionIds[$group][$value] ?? null;

                if (\is_string($id)) {
                    $references[] = ['id' => $id];

                    continue;
                }

                $unresolved->add(\sprintf('%s "%s = %s"', $kind, $group, $value));
                $resolved = false;
            }
        }

        return $resolved ? $references : null;
    }
}
