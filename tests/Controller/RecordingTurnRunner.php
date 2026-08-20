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

    /** @var list<ConversationTurn> */
    public array $lastHistory = [];

    public function run(string $message, string $salesChannelId, array $history): TurnResult
    {
        $this->calls++;
        $this->lastHistory = $history;

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

        return new TurnResult(new AssistantTurn('The Trail Jersey in Blue / M.', [$card], 'product_shown'), $trace);
    }
}
