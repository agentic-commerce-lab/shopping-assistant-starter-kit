<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

/**
 * Whether this shop's `product` entity carries Shopware Commercial's bundle association.
 *
 * A capability port rather than a version or plugin-name check, for the reason
 * {@see DalVectorSupport} gives about `VEC_DISTANCE_COSINE`: the question that matters is not
 * "which edition is installed" but "does the field this criteria is about to name exist here".
 * Asking the definition answers that directly and keeps working whatever Commercial is called or
 * numbered next.
 */
interface BundleSupport
{
    public function isAvailable(): bool;
}
