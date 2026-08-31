<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\ChatTurnRunnerInterface;
use Swag\AssistantStarterKit\Core\Agent\TurnResult;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Records what the controller asked for, and returns a fixed grounded turn.
 *
 * A recording double rather than a mock: the assertions are about *whether and with what* the
 * controller called the runner, and a counter plus the last argument says that more legibly than
 * expectation syntax.
 */
final class RecordingTurnRunner implements ChatTurnRunnerInterface
{
    public int $calls = 0;

    /** Overridden by tests that need a turn the controller treats as an escalation. */
    public string $outcome = 'product_shown';

    /** @var list<ConversationTurn> */
    public array $lastHistory = [];

    /** The page-context hints the controller passed on, so a test can assert they survived the parse. */
    public ?string $lastViewingProductId = null;

    public ?string $lastBrowsingCategoryId = null;

    /** The storefront's own language, as the controller read it off the request. */
    public ?string $lastStorefrontLocale = null;

    /**
     * Seeds the recorded value, so a test can prove the *next* turn overwrote it with null rather
     * than simply never having touched it.
     *
     * A method rather than the property assignment it wraps: assigning a literal narrows the
     * property's type at the call site, and the analyzer then reports the `assertNull` that follows
     * as an impossible comparison — it cannot see that the controller writes it in between.
     */
    public function seedViewingProductId(?string $value): void
    {
        $this->lastViewingProductId = $value;
    }

    // @mago-expect lint:excessive-parameter-list
    // Dictated by ChatTurnRunnerInterface, which carries the reasoning for the list's length.
    public function run(
        string $message,
        string $salesChannelId,
        array $history,
        ?string $viewingProductId = null,
        ?string $browsingCategoryId = null,
        ?string $storefrontLocale = null,
    ): TurnResult {
        $this->calls++;
        $this->lastHistory = $history;
        $this->lastViewingProductId = $viewingProductId;
        $this->lastBrowsingCategoryId = $browsingCategoryId;
        $this->lastStorefrontLocale = $storefrontLocale;

        $trace = new TraceRecorder();
        $trace->record('guard.check', ['verdict' => 'allow']);
        $trace->record('render', ['stockSource' => 'variant']);

        $card = new ProductCard(
            id: 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2',
            parentId: 'fafafafafafafafafafafafafafafafa',
            name: 'Trail Jersey',
            description: null,
            price: 74.90,
            currency: 'EUR',
            stock: 0,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2',
            imageUrl: null,
            options: ['Colour' => 'Blue', 'Size' => 'M'],
        );

        return new TurnResult(new AssistantTurn('The Trail Jersey in Blue / M.', [$card], $this->outcome), $trace);
    }
}
