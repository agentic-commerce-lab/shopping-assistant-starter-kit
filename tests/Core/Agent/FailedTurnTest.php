<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantTurn;
use Swag\AssistantStarterKit\Core\Agent\FailedTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What the trace says when the turn died inside the agent.
 *
 * **Until 2026-09-02 it said nothing.** `ShopwareChatTurnRunner` caught `AgentExceptionInterface`,
 * swapped in a fixed apology and returned — recording no stage and logging no line. Measured on a
 * fresh 6.7.13.1 shop: one turn in sixteen came back `outcome: error`, and its persisted trace
 * stopped dead after `tool.call` with nothing to say why. The docblock on that catch claimed "the
 * trace is what says where the turn stopped", and the trace was the one thing that did not.
 *
 * The shopper-facing half was already right and is untouched: a model or network error is not
 * something to explain to a customer.
 */
#[CoversClass(FailedTurn::class)]
final class FailedTurnTest extends TestCase
{
    public function testItRecordsAStageNamingTheExceptionClass(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \RuntimeException('upstream said no'));

        $payload = $trace->payload(FailedTurn::STAGE);

        self::assertIsArray($payload);
        self::assertSame(\RuntimeException::class, $payload['exception']);
        self::assertSame('upstream said no', $payload['message']);
    }

    /**
     * **`turn.end` must stay absent.** Ruling R40 makes a missing `turn.end` mean "the turn never
     * completed", and {@see \Swag\AssistantStarterKit\Eval\Assertion\CartContains} fails loudly on
     * it. Synthesising one here to make the outcome tidy would take that detection away from every
     * eval journey — the failure would look like a completed turn that happened to end badly.
     */
    public function testItDoesNotSynthesiseATurnEnd(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \RuntimeException('upstream said no'));

        self::assertNull($trace->payload('turn.end'));
        self::assertSame([FailedTurn::STAGE], $trace->stages());
    }

    /**
     * The cause is the useful half of a wrapped transport error, so it is named — per AGENTS.md,
     * log the exception with its `getPrevious()` rather than just the message.
     */
    public function testItNamesTheCauseOfAWrappedException(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \LogicException('wrapper', 0, new \DomainException('the real cause')));

        $payload = $trace->payload(FailedTurn::STAGE);

        self::assertIsArray($payload);
        self::assertSame(\DomainException::class, $payload['causedBy']);
    }

    public function testAnUnwrappedExceptionHasNoCause(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \RuntimeException('alone'));

        $payload = $trace->payload(FailedTurn::STAGE);

        self::assertIsArray($payload);
        self::assertArrayNotHasKey('causedBy', $payload);
    }

    /**
     * **Bounded and single-line.** A provider that answers an error with a page of HTML would
     * otherwise put that page into a database column the Administration renders, and the trace is
     * read as a timeline rather than as a log file.
     */
    public function testItBoundsTheMessageAndFlattensIt(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \RuntimeException("line one\nline two" . str_repeat('x', 500)));

        $payload = $trace->payload(FailedTurn::STAGE);

        self::assertIsArray($payload);
        $message = $payload['message'];
        self::assertIsString($message);
        self::assertLessThanOrEqual(FailedTurn::MAX_MESSAGE_LENGTH, mb_strlen($message));
        self::assertStringNotContainsString("\n", $message);
        self::assertStringContainsString('line one line two', $message);
    }

    /**
     * An exception whose message is empty must still produce a readable stage: the class is the
     * diagnosis in that case, and an empty string would read as "no information recorded".
     */
    public function testAnEmptyMessageIsReplacedBySomethingReadable(): void
    {
        $trace = new TraceRecorder();

        FailedTurn::record($trace, new \RuntimeException(''));

        $payload = $trace->payload(FailedTurn::STAGE);

        self::assertIsArray($payload);
        self::assertSame('(no message)', $payload['message']);
    }

    /**
     * **The catch used to be `AgentExceptionInterface` only, and that was a 500 in front of a
     * shopper.** Measured on 2026-09-02: pointing the model at a URL that answers HTML rather than
     * JSON threw `Symfony\Component\HttpClient\Exception\JsonException` — an HTTP-client
     * exception, not an agent one — which escaped the catch and took the storefront's chat endpoint
     * to `HTTP 500`. Any provider incident page, proxy error or gateway timeout does the same.
     */
    public function testATransportExceptionDegradesRatherThanEscaping(): void
    {
        $trace = new TraceRecorder();

        $turn = FailedTurn::orDegrade($trace, 'English', static function (): AssistantTurn {
            throw new \JsonException('Syntax error for "https://example.test/v1/chat/completions".');
        });

        self::assertSame(FailedTurn::OUTCOME, $turn->outcome);
        self::assertSame([], $turn->cards);

        $payload = $trace->payload(FailedTurn::STAGE);
        self::assertIsArray($payload);
        self::assertSame(\JsonException::class, $payload['exception']);
    }

    /**
     * A bug inside a contributed tool is degraded too, and the reasoning is worth stating: a shopper
     * must not see a stack trace because someone's plugin has a type error, and the trace now names
     * the class so it is diagnosable. Nothing is hidden from development — the eval harness drives
     * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner} directly and never passes
     * through here, so a broken tool still fails a journey loudly.
     */
    public function testAnErrorFromAToolIsDegradedAndNamed(): void
    {
        $trace = new TraceRecorder();

        $turn = FailedTurn::orDegrade($trace, 'English', static function (): AssistantTurn {
            throw new \TypeError('Argument #3 must be of type BlocklistFilter');
        });

        self::assertSame(FailedTurn::OUTCOME, $turn->outcome);

        $payload = $trace->payload(FailedTurn::STAGE);
        self::assertIsArray($payload);
        self::assertSame(\TypeError::class, $payload['exception']);
    }

    public function testASucceedingTurnIsReturnedUntouchedAndRecordsNothing(): void
    {
        $trace = new TraceRecorder();
        $expected = new AssistantTurn('here you go', [], 'product_shown');

        $turn = FailedTurn::orDegrade($trace, 'English', static fn(): AssistantTurn => $expected);

        self::assertSame($expected, $turn);
        self::assertSame([], $trace->stages());
    }

    /**
     * The apology is the shopper's, so it follows the conversation's language — see
     * {@see \Swag\AssistantStarterKit\Core\Agent\FailedTurnMessage}.
     */
    public function testTheShopperIsApologisedToInTheirOwnLanguage(): void
    {
        $turn = FailedTurn::orDegrade(new TraceRecorder(), 'German', static function (): AssistantTurn {
            throw new \RuntimeException('nope');
        });

        self::assertStringContainsString('konnte', $turn->prose);
    }
}
