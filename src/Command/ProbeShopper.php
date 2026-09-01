<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Who {@see ProbeCommand} runs as.
 *
 * Split out of {@see ProbeRequest} rather than added to it: the shopper is the one part of the
 * parsed command line that is **not** mode-specific — a B2B price is wrong in `--search` for exactly
 * the reason it is wrong in `--ask` — so every mode carries the same pair, and folding it into one
 * value keeps `ProbeRequest::fromInput()` a dispatch rather than a dispatch plus two extra arguments
 * repeated at five call sites.
 */
final readonly class ProbeShopper
{
    /**
     * The factory option key Commercial's employee decorator reads.
     *
     * A literal, not an import: Shopware Commercial is not a dependency of this plugin and must not
     * become one, so the class that owns this name may not exist at runtime. A shop without
     * Commercial simply never has the option read.
     *
     * @see \Shopware\Commercial\B2B\EmployeeManagement\Domain\Login\SalesChannelContextFactoryDecorator
     */
    private const EMPLOYEE_OPTION = 'employeeId';

    /**
     * Public so `new ProbeShopper()` can serve as {@see ProbeRequest}'s default — a guest, which is
     * what the probe was before `--customer` existed. The `--employee`-needs-`--customer` guard
     * lives in {@see self::fromInput()} rather than here because it is a rule about the *command
     * line*: constructing the pair directly in a test is not a user mistake to protect against.
     */
    public function __construct(
        public ?string $customerId = null,
        public ?string $employeeId = null,
    ) {}

    /**
     * @throws \InvalidArgumentException when an employee is named without its customer
     */
    public static function fromInput(InputInterface $input): self
    {
        $customerId = self::text($input->getOption('customer')) ?: null;
        $employeeId = self::text($input->getOption('employee')) ?: null;

        // Refused rather than degraded. Commercial's employee decorator bails out unless the context
        // already carries a customer, and its fallback sets `customerId` to **null** — so
        // `--employee` alone does not merely skip the employee, it produces a GUEST context. A guest
        // sees list prices and an unrestricted catalogue, so the probe would print a plausible table
        // answering a different question than the one asked. A measurement that looks valid is worse
        // than an error.
        if ($employeeId !== null && $customerId === null) {
            throw new \InvalidArgumentException(
                '--employee needs --customer: without one, Commercial resolves a guest context, and '
                . 'every price and catalogue decision the probe printed would be a guest\'s.',
            );
        }

        return new self($customerId, $employeeId);
    }

    public function isGuest(): bool
    {
        return $this->customerId === null;
    }

    /**
     * The context-factory options that make this a shopper's context rather than a guest's.
     *
     * @return array<string, string>
     */
    public function contextOptions(): array
    {
        $options = [];

        if ($this->customerId !== null) {
            $options[SalesChannelContextService::CUSTOMER_ID] = $this->customerId;
        }

        if ($this->employeeId !== null) {
            $options[self::EMPLOYEE_OPTION] = $this->employeeId;
        }

        return $options;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
