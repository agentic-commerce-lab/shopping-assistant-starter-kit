<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;

/**
 * Guarded reads off a DAL entity whose shape this plugin is not allowed to name.
 *
 * Split from {@see DalBundleItems} because they are two jobs revised for different reasons — that
 * class knows what a bundle item *is*, this one knows how to get a value off an entity without
 * trusting that it is there — and because the complexity gate is right that one class doing both is
 * doing too much. Same seam the directory already uses between {@see DalApplicablePrice} and its
 * callers.
 *
 * **Why guards at all.** `Entity::get()` throws on a property the entity does not have, and the
 * entities this reads are hydrated by Shopware Commercial, which is not a dependency here: the
 * concrete class cannot be type-hinted, so the only honest assumption is the `has()`/`get()`
 * contract core documents. A partially hydrated entity must degrade to a missing value rather than
 * end a shopper's turn (rulings R48, R49).
 */
final readonly class EntityValue
{
    /**
     * The property's value, or null when the entity does not carry it.
     */
    public static function of(Entity $entity, string $property): mixed
    {
        return $entity->has($property) ? $entity->get($property) : null;
    }

    /**
     * A translated string field, resolved through inheritance the way
     * {@see DalProductCardMapper} resolves a card's own name.
     *
     * `translated` is where the DAL puts a field after resolving parent inheritance, and a variant
     * normally has no name of its own — so the own-value getter alone returns null and the caller
     * would drop the row. The own value stays as the fallback for an entity loaded with no
     * translations at all.
     */
    public static function inheritedString(Entity $entity, string $property): ?string
    {
        foreach ([$entity->getTranslation($property), self::of($entity, $property)] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
