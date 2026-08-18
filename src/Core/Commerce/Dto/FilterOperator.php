<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

enum FilterOperator: string
{
    case Equals = 'equals';
    case Range = 'range';
    case Contains = 'contains';
}
