<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\ProbeRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Who the probe runs as.
 *
 * The shopper is cross-cutting rather than mode-specific: a B2B price is wrong in `--search` for
 * exactly the reason it is wrong in `--ask`, so every mode carries the same two ids.
 */
final class ProbeRequestShopperTest extends TestCase
{
    private const DEFAULT_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    /** @param array<string, mixed> $options */
    private static function input(array $options): ArrayInput
    {
        return new ArrayInput($options, new InputDefinition([
            new InputOption('search', null, InputOption::VALUE_REQUIRED),
            new InputOption('ask', null, InputOption::VALUE_REQUIRED),
            new InputOption('facets', null, InputOption::VALUE_NONE),
            new InputOption('variant', null, InputOption::VALUE_REQUIRED),
            new InputOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, '', '10'),
            new InputOption('sales-channel', null, InputOption::VALUE_REQUIRED, '', self::DEFAULT_CHANNEL),
            new InputOption('customer', null, InputOption::VALUE_REQUIRED),
            new InputOption('employee', null, InputOption::VALUE_REQUIRED),
        ]));
    }

    public function testAGuestProbeCarriesNoShopper(): void
    {
        $request = ProbeRequest::fromInput(self::input(['--search' => 'jersey']), self::DEFAULT_CHANNEL);

        self::assertNull($request->shopper->customerId);
        self::assertNull($request->shopper->employeeId);
    }

    public function testCustomerReachesEveryMode(): void
    {
        $customerId = '01a05cfa1b017514b5d846dc0810327b';

        foreach ([
            ['--search' => 'jersey'],
            ['--facets' => true],
            ['--ask' => 'what does a jersey cost'],
            ['--variant' => '01a0000000007000b000000000000000', '--option' => ['Blue']],
        ] as $options) {
            $request = ProbeRequest::fromInput(
                self::input($options + ['--customer' => $customerId]),
                self::DEFAULT_CHANNEL,
            );

            self::assertSame($customerId, $request->shopper->customerId);
        }
    }

    public function testEmployeeIsCarriedAlongsideItsCustomer(): void
    {
        $request = ProbeRequest::fromInput(self::input([
            '--search' => 'jersey',
            '--customer' => '01a05cfa1b017514b5d846dc0810327b',
            '--employee' => '01a05d07bc20733bb8ac8bb67e1b32aa',
        ]), self::DEFAULT_CHANNEL);

        self::assertSame('01a05cfa1b017514b5d846dc0810327b', $request->shopper->customerId);
        self::assertSame('01a05d07bc20733bb8ac8bb67e1b32aa', $request->shopper->employeeId);
    }

    /**
     * Commercial's employee decorator bails out unless the context already has a customer, and its
     * fallback sets `customerId` to **null** — so `--employee` alone does not merely skip the
     * employee, it produces a guest context. A probe that answered as a guest while the operator
     * believed they were measuring an employee would make every number it printed a lie, so this
     * is refused rather than silently degraded.
     */
    public function testEmployeeWithoutCustomerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProbeRequest::fromInput(self::input([
            '--search' => 'jersey',
            '--employee' => '01a05d07bc20733bb8ac8bb67e1b32aa',
        ]), self::DEFAULT_CHANNEL);
    }
}
