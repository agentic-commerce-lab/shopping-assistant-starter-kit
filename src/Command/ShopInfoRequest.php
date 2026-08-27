<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * What {@see ShopInfoCommand} was asked to do, parsed once out of the console input.
 *
 * Split out (cyclomatic-complexity) rather than suppressed, following {@see ProbeRequest}. The split
 * is worth having on its own terms: console options are untyped strings, and pushing the narrowing
 * here leaves the command with a dispatch it is possible to read at a glance.
 */
final readonly class ShopInfoRequest
{
    public const MODE_INDEX = 'index';

    public const MODE_LIST = 'list';

    public const MODE_DELETE = 'delete';

    public const MODE_QUERY = 'query';

    public const MODE_NONE = 'none';

    private function __construct(
        public string $mode,
        public string $salesChannelId,
        public string $path = '',
        public string $name = '',
        public string $question = '',
    ) {}

    public static function fromInput(InputInterface $input, string $defaultSalesChannel): self
    {
        $salesChannelId = self::text($input->getOption('sales-channel')) ?: $defaultSalesChannel;

        $path = self::text($input->getOption('index'));
        if ($path !== '') {
            return new self(self::MODE_INDEX, $salesChannelId, path: $path);
        }

        $name = self::text($input->getOption('delete'));
        if ($name !== '') {
            return new self(self::MODE_DELETE, $salesChannelId, name: $name);
        }

        $question = self::text($input->getOption('query'));
        if ($question !== '') {
            return new self(self::MODE_QUERY, $salesChannelId, question: $question);
        }

        if ($input->getOption('list') === true) {
            return new self(self::MODE_LIST, $salesChannelId);
        }

        return new self(self::MODE_NONE, $salesChannelId);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
