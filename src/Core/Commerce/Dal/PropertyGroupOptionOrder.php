<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;

/**
 * Reads a Shopware property-option collection into `[group, name]` pairs in the
 * catalogue's own order.
 *
 * Its own class rather than a private method on {@see PropertyGroupOptionReader} because
 * adding the ordering there put that class over mago's class-summed
 * cyclomatic-complexity threshold, and the standing constraint is to split rather than
 * suppress.
 *
 * **Ordering.** By the option group's position, then the option's position within its
 * group, with the names as a deterministic tiebreak. The DAL returns options in
 * association order, which is neither the merchant's order nor a stable one, so the same
 * Trail Jersey variant rendered as *"M · Blue"* on one card and *"Blue · M"* on the next.
 * A shopper says "blue, size M"; the merchant already expressed that order by positioning
 * the groups in the Administration, and this is the one place that order can be honoured
 * for every client at once — the widget, `--ask`, and anything else built on the endpoint.
 *
 * `position` is nullable in Shopware, and null sorts LAST rather than first: an
 * unpositioned group is one nobody ordered, so it has no claim on preceding one somebody
 * did.
 *
 * **An option whose group is not resolved is dropped, never keyed by a guess** — an
 * unloaded `options.group` association yields a null group, and inventing a key there
 * would hand {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} a group name
 * this catalogue does not have. A fabricated key is worse than a missing one, because the
 * constraint would then appear to have been applied.
 */
final class PropertyGroupOptionOrder
{
    /**
     * The sort rank of a group or option Shopware has no `position` for. Ten digits wide so
     * it stays above every real position under the zero-padded string comparison, without
     * assuming a maximum the schema does not state.
     */
    private const UNPOSITIONED = 9_999_999_999;

    private function __construct() {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(?PropertyGroupOptionCollection $options): array
    {
        $ranked = [];

        foreach ($options ?? [] as $option) {
            $group = $option->getGroup()?->getTranslation('name') ?? $option->getGroup()?->getName();
            $name = $option->getTranslation('name') ?? $option->getName();

            if (!\is_string($group) || !\is_string($name) || $group === '' || $name === '') {
                continue;
            }

            $ranked[\sprintf(
                '%010d|%s|%010d|%s',
                $option->getGroup()?->getPosition() ?? self::UNPOSITIONED,
                $group,
                $option->getPosition() ?? self::UNPOSITIONED,
                $name,
            )] = [$group, $name];
        }

        ksort($ranked, \SORT_STRING);

        return array_values($ranked);
    }
}
