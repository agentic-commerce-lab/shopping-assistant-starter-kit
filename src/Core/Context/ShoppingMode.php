<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * Who the assistant is talking to, at the coarsest useful grain.
 *
 * Two cases, not four. The spec's `commercial` and `commercial_unavailable` modes need a Commercial
 * bridge that cannot be built or verified without a B2B licence, and an enum case nothing can
 * produce is a branch nothing can test. They are added with the bridge.
 *
 * The backed values are what `swag_assistant_conversation.scope_type` stores, so renaming a case is
 * a migration, not a refactor.
 */
enum ShoppingMode: string
{
    case Guest = 'guest';
    case Customer = 'customer';
}
