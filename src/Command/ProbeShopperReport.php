<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints who the probe actually resolved as, and refuses to measure when that is not who was asked
 * for.
 *
 * This exists because **both** Commercial context decorators degrade silently. An employee whose
 * membership is not active makes the employee decorator fall back to `customerId => null`, i.e. a
 * guest; an organisation with no catalogue row for the current sales channel simply adds no
 * extension. Neither is an error, and both change every number that follows. Measured 2026-09-01
 * against a Commercial shop: `--employee` naming a valid-looking membership produced a full price
 * table that was a guest's, with nothing on screen to say so.
 */
final readonly class ProbeShopperReport
{
    /**
     * The context extension Commercial's employee decorator attaches its resolved membership under.
     *
     * A literal for the same reason as {@see ProbeShopper}'s option key: Commercial is not a
     * dependency and the owning class may not exist at runtime.
     */
    private const EMPLOYEE_EXTENSION = 'b2bEmployee';

    /**
     * The context extension the Advanced Product Catalogue decorator adds once an organisation
     * resolved to a catalogue **in this sales channel**.
     */
    private const ADVANCED_CATALOG_EXTENSION = 'advancedCatalog';

    public function __construct(
        private ProbeShopper $shopper,
    ) {}

    /**
     * @return bool false when the resolved context is not the one that was asked for
     */
    public function write(SymfonyStyle $io, SalesChannelContext $context): bool
    {
        $resolvedCustomerId = $context->getCustomer()?->getId();

        if (!$this->shopper->isGuest() && $resolvedCustomerId === null) {
            $io->error(\sprintf(
                'Asked to run as customer %s, but the context came back with no customer. Either the '
                . 'id does not exist in this sales channel, or --employee named a membership that is '
                . 'not active — Commercial answers that by dropping the customer, not by erroring.',
                (string) $this->shopper->customerId,
            ));

            return false;
        }

        if ($this->shopper->isGuest()) {
            $io->writeln('<comment>Shopper: guest</comment>');

            return true;
        }

        if (
            $this->shopper->employeeId !== null
            && !$context->getExtension(self::EMPLOYEE_EXTENSION) instanceof Entity
        ) {
            $io->error(\sprintf(
                'Asked to run as employee %s, but no "%s" extension is on the context. Either '
                . 'Shopware Commercial is not installed, or Employee Management is off.',
                $this->shopper->employeeId,
                self::EMPLOYEE_EXTENSION,
            ));

            return false;
        }

        $io->writeln(\sprintf(
            '<info>Shopper:</info> customer %s%s',
            (string) $resolvedCustomerId,
            $this->shopper->employeeId !== null ? ' · employee ' . $this->shopper->employeeId : '',
        ));
        $io->writeln(self::describeCatalogue($context));

        return true;
    }

    /**
     * What catalogue scope, if any, is in force.
     *
     * Read off the `advancedCatalog` context extension rather than off the employee's
     * `organizationId`, because that extension is *the* thing Commercial's criteria subscriber
     * consults: it is present only when an organisation resolved **and** a catalogue exists for it in
     * this sales channel. Reading the employee instead was wrong in both directions — measured
     * 2026-09-01, it reported "no organisation" for a membership whose catalogue was demonstrably
     * filtering search, and it would equally have claimed a restriction for an organisation with no
     * catalogue row.
     *
     * Naming the absence matters as much as the presence: an employee with no organisation, or an
     * organisation with no catalogue for this channel, is unrestricted. That is a valid
     * configuration and the single easiest thing to mistake for "Advanced Product Catalogues do not
     * work".
     */
    private static function describeCatalogue(SalesChannelContext $context): string
    {
        $catalogue = $context->getExtension(self::ADVANCED_CATALOG_EXTENSION);

        if (!$catalogue instanceof ArrayStruct) {
            return (
                '<comment>No Advanced Product Catalogue in force: ' . 'every released category is searchable.</comment>'
            );
        }

        $organisationId = $catalogue->get('organizationId');
        $catalogueId = $catalogue->get('catalogId');

        return \sprintf(
            '<info>Advanced Product Catalogue:</info> %s (organisation %s) — only its released '
            . 'categories are searchable.',
            \is_string($catalogueId) ? $catalogueId : '?',
            \is_string($organisationId) ? $organisationId : '?',
        );
    }
}
