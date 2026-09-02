<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What {@see ProbeCommand} was asked to do, parsed once out of the console input.
 *
 * Split out (cyclomatic-complexity) rather than suppressed. The split is worth having on its own
 * terms: console options are untyped strings, and pushing the narrowing here leaves the command
 * with a dispatch it is possible to read at a glance.
 */
final readonly class ProbeRequest
{
    public const MODE_SEARCH = 'search';

    public const MODE_FACETS = 'facets';

    public const MODE_VARIANT = 'variant';

    public const MODE_ASK = 'ask';

    public const MODE_NONE = 'none';

    /**
     * @param list<VariantSelection> $selections
     */
    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 1: a `final readonly` value object, private constructor, and
    // every call site inside this class uses named arguments. Grouping the mode-specific fields
    // into sub-objects would add three types to express one parsed command line.
    private function __construct(
        public string $mode,
        public string $salesChannelId,
        public string $term = '',
        public string $question = '',
        public string $parentId = '',
        public array $selections = [],
        public int $limit = 10,
        public ProbeShopper $shopper = new ProbeShopper(),
    ) {}

    /**
     * `$default` is resolved lazily on the right-hand side of `?:` below, so a command given an
     * explicit `--sales-channel` never queries the shop and never sees
     * {@see NoDefaultSalesChannelException}.
     */
    public static function fromInput(InputInterface $input, DefaultSalesChannel $default): self
    {
        $salesChannelId = self::text($input->getOption('sales-channel')) ?: $default->id();
        $shopper = ProbeShopper::fromInput($input);

        if ($input->getOption('facets') === true) {
            return new self(self::MODE_FACETS, $salesChannelId, shopper: $shopper);
        }

        $question = self::text($input->getOption('ask'));
        if ($question !== '') {
            return new self(self::MODE_ASK, $salesChannelId, question: $question, shopper: $shopper);
        }

        $parentId = self::text($input->getOption('variant'));
        if ($parentId !== '') {
            return new self(
                self::MODE_VARIANT,
                $salesChannelId,
                parentId: $parentId,
                selections: self::selections($input->getOption('option')),
                shopper: $shopper,
            );
        }

        $term = self::text($input->getOption('search'));
        if ($term !== '') {
            return new self(
                self::MODE_SEARCH,
                $salesChannelId,
                term: $term,
                limit: max(1, (int) self::text($input->getOption('limit'))),
                shopper: $shopper,
            );
        }

        return new self(self::MODE_NONE, $salesChannelId, shopper: $shopper);
    }

    /**
     * Selections are built **without a group** on purpose: the model usually knows "Blue" without
     * knowing it belongs to "Colour", so this exercises the path the model actually takes rather
     * than an easier one.
     *
     * @return list<VariantSelection>
     */
    private static function selections(mixed $raw): array
    {
        $selections = [];

        foreach (\is_array($raw) ? $raw : [] as $value) {
            $option = self::text($value);

            if ($option !== '') {
                $selections[] = new VariantSelection($option);
            }
        }

        return $selections;
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
