<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

/**
 * The default answer: this shop has no bundle association.
 *
 * **The safe direction, and that is why it is the default rather than the other way round.** Naming
 * a field the DAL does not know fails the entire product read, so a builder constructed without a
 * capability check must ask for nothing bundle-shaped. A shop that does have Commercial then loses
 * bundle contents until {@see DalBundleSupport} is wired — a missing answer, not a broken one.
 */
final readonly class BundlesUnavailable implements BundleSupport
{
    public function isAvailable(): bool
    {
        return false;
    }
}
