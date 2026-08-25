<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Every distinct option value across one family's variants, bounded per group.
 *
 * Split out of {@see TruncatedFamilies} rather than suppressed with a pragma: that class is measured
 * against a cyclomatic-complexity budget summed across all of its methods, and it went over. The seam
 * is a real one — deciding what a summary says and collecting the values it says it with fail for
 * different reasons and are worth reading apart.
 */
final class FamilyOptionValues
{
    /**
     * How many values of one option group may be listed.
     *
     * Fifty, borrowing `DalCommerceGateway::FACET_VALUE_LIMIT`'s existing judgement about "enough to
     * be useful, bounded enough to send". Without a cap a thirty-variant problem becomes a
     * three-thousand-variant one, and the reply would be the thing bloating the context it was added
     * to inform.
     */
    public const MAX_VALUES = 50;

    private function __construct() {}

    /**
     * In the order the catalogue presented them, so a size run reads as a size run.
     *
     * @param list<ProductCard> $members
     *
     * @return array{options: array<string, list<string>>, truncated: bool}
     */
    public static function of(array $members): array
    {
        $options = [];
        $truncated = false;

        foreach ($members as $member) {
            foreach ($member->options as $group => $value) {
                $known = $options[$group] ?? [];

                if (\in_array($value, $known, strict: true)) {
                    continue;
                }

                if (\count($known) >= self::MAX_VALUES) {
                    $truncated = true;

                    continue;
                }

                $options[$group] = [...$known, $value];
            }
        }

        return ['options' => $options, 'truncated' => $truncated];
    }
}
