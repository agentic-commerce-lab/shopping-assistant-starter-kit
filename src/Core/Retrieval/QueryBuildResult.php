<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

final readonly class QueryBuildResult
{
    /** @param list<string> $droppedFields */
    public function __construct(
        public ProductQuery $query,
        public array $droppedFields,
    ) {}
}
