<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

enum FacetType: string
{
    case Terms = 'terms';
    case Range = 'range';
}
