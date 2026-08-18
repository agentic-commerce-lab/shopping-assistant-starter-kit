<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

enum StockSource: string
{
    case Variant = 'variant';
    case Parent = 'parent';
}
