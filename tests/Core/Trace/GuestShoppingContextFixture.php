<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;

/**
 * The one guest {@see ShoppingContext} several `Core\Trace` suites need, now that `start()` and
 * `history()` both take one. Those suites are about the transcript and trace mechanics, not
 * scoping — {@see ConversationScopeTest} owns that — so they only ever need this single, reused
 * context.
 *
 * A trait rather than a shared base class or the same three lines copy-pasted into each file: a
 * trait's own methods don't count against the including class for mago's `too-many-methods` budget,
 * and the copy-paste is exactly what `composer quality:dupes` exists to catch. Requires the using
 * class to declare `private const CHANNEL`, which every class below already does.
 */
trait GuestShoppingContextFixture
{
    /**
     * PHP has no way for a trait to require a constant from its using class the way it can require
     * a method (`abstract`) — so mago, analyzing this trait on its own rather than composed into
     * one of the classes above, cannot see that `CHANNEL` will exist. It does, in every one of
     * them, and `GuestShoppingContextFixture.php`'s own docblock exists specifically to keep that
     * true: this is the one place PHP's type system genuinely cannot express the contract, not a
     * loose type this file could tighten instead.
     *
     * @mago-expect analysis:non-existent-class-constant
     * @mago-expect analysis:no-value
     */
    private function guest(): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);
    }
}
