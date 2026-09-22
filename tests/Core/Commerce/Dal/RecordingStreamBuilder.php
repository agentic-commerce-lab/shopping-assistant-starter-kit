<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use Shopware\Core\Content\ProductStream\Service\ProductStreamBuilderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\Filter;

/**
 * A stream builder that answers with the conditions each group was given, and remembers how often it
 * was asked for one.
 *
 * Its own file rather than an anonymous class inside the test, for the reason
 * {@see \Swag\AssistantStarterKit\Tests\Core\Retrieval\RecordingTermGateway} is: a method returning
 * `ProductStreamBuilderInterface` erases the double's own properties, and `$builder->calls` then
 * reads as an access on the interface. How often the database was asked is the whole point of the
 * caching test.
 */
final class RecordingStreamBuilder implements ProductStreamBuilderInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    /**
     * @param array<string, list<Filter>> $byStream   what each group resolves to
     * @param array<string, \Throwable>   $throwsFor  groups that cannot be resolved at all
     */
    public function __construct(
        private readonly array $byStream = [],
        private readonly array $throwsFor = [],
    ) {}

    /**
     * @return list<Filter>
     */
    public function buildFilters(string $id, Context $context): array
    {
        $this->calls[$id] = ($this->calls[$id] ?? 0) + 1;

        $failure = $this->throwsFor[$id] ?? null;

        if ($failure !== null) {
            throw $failure;
        }

        return $this->byStream[$id] ?? [];
    }
}
