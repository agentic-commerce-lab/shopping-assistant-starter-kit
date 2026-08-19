<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Policy;

enum PolicyVerdict: string
{
    case Allow = 'allow';
    case Block = 'block';
}
