<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use Swag\AssistantStarterKit\Command\BenchmarkRequest;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class BenchmarkRequestTest extends CommandSalesChannelTestCase
{
    private const DEFAULT_CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    /** @param array<string, mixed> $options */
    private static function input(array $options): ArrayInput
    {
        return new ArrayInput($options, new InputDefinition([
            new InputOption('term', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('repetitions', null, InputOption::VALUE_REQUIRED, '', '20'),
            new InputOption('card-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('sales-channel', null, InputOption::VALUE_REQUIRED),
            new InputOption('label', null, InputOption::VALUE_REQUIRED, '', 'shop'),
        ]));
    }

    public function testItFallsBackToTheCommittedQueryList(): void
    {
        $request = BenchmarkRequest::fromInput(self::input([]), $this->defaultSalesChannel(self::DEFAULT_CHANNEL));

        // The committed list, so two runs of this command are comparable.
        self::assertSame(BenchmarkRequest::DEFAULT_TERMS, $request->terms);
        self::assertSame(20, $request->repetitions);
        self::assertSame(self::DEFAULT_CHANNEL, $request->salesChannelId);
    }

    public function testExplicitTermsReplaceTheDefaultList(): void
    {
        $request = BenchmarkRequest::fromInput(self::input(['--term' => [
            'jacket',
            'gloves',
        ]]), $this->defaultSalesChannel(self::DEFAULT_CHANNEL));

        self::assertSame(['jacket', 'gloves'], $request->terms);
    }

    public function testCardIdsAreCollected(): void
    {
        $request = BenchmarkRequest::fromInput(self::input(['--card-id' => [
            'fx-001',
            'fx-007',
        ]]), $this->defaultSalesChannel(self::DEFAULT_CHANNEL));

        self::assertSame(['fx-001', 'fx-007'], $request->cardIds);
    }

    public function testRepetitionsBelowOneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BenchmarkRequest::fromInput(self::input([
            '--repetitions' => '0',
        ]), $this->defaultSalesChannel(self::DEFAULT_CHANNEL));
    }

    public function testRepetitionsIsCappedSoAMistypedFlagCannotRunForHours(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BenchmarkRequest::fromInput(self::input([
            '--repetitions' => '100000',
        ]), $this->defaultSalesChannel(self::DEFAULT_CHANNEL));
    }
}
