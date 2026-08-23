<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm;

use Symfony\AI\Platform\PlatformInterface;

/**
 * Builds the Symfony AI platform one turn talks to. Decorate or replace the service to use another.
 *
 * This exists because {@see PlatformFactory::create()} is static and pins the generic
 * OpenAI-compatible bridge: Symfony AI ships bridges for dozens of providers and this plugin could
 * reach none of them without being edited. The settings arrive per call because they are per sales
 * channel — one shop can point two channels at two different models.
 *
 * @api
 */
interface LlmPlatformInterface
{
    public function of(LlmSettings $settings): PlatformInterface;
}
