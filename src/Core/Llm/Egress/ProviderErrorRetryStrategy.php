<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm\Egress;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Retries a model call the provider gave up on — **including the ones that arrive as HTTP 200**.
 *
 * ## Why the status code is not enough
 *
 * Measured on the staging shop, 2026-09-02. Roughly one turn in twenty came back as the shopper-facing
 * apology, every one of them from the same provider error:
 *
 * ```
 * Symfony\AI\Platform\Exception\RuntimeException
 * Error "504"-- (-): "The operation was aborted".
 * ```
 *
 * The decisive detail is that the **HTTP status of that response is 200**. OpenRouter answers with a
 * normal response whose JSON body carries `{"error":{"code":504,…}}`, so
 * {@see \Symfony\Component\HttpClient\Retry\GenericRetryStrategy} — which decides on status codes —
 * never sees a failure at all. A retry keyed on 502/503/504 would have been dead code.
 *
 * Reproduced outside this plugin with plain curl against the same key and model: 0 of 12 failures
 * without tool definitions, 2 of 12 with them, every failure aborting at 13.3 s ± 0.04 while
 * successful calls ran to 14.5 s. So it is an upstream leg giving up, not our timeout and not our
 * code — which is exactly the shape a retry is for.
 *
 * ## Why retrying is safe here
 *
 * A retry replays **one HTTP request to the provider**, nothing else. Tools that already ran this turn
 * have already run: their results sit in the message bag being re-sent, so a retried call cannot add a
 * second cart line or repeat any other side effect. The only cost is the provider's own bill for a
 * generation that produced nothing the first time.
 *
 * ## What is deliberately NOT retried
 *
 * A 4xx is the shop's own mistake — a bad key, a model name this provider does not serve, a malformed
 * request — and repeating it turns one clear error into three. Same for a body carrying an error below
 * 500: it is an answer, not a failure to answer.
 */
final class ProviderErrorRetryStrategy implements RetryStrategyInterface
{
    /**
     * Long enough for a stalled upstream route to be re-picked, short enough that a shopper waiting on
     * a reply does not notice a second time. The turn is already 10-15 s; this adds under a second.
     */
    private const DELAY_MS = 400;

    public function shouldRetry(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception,
    ): ?bool {
        // A dropped connection or a timeout never carried a body to inspect.
        if ($exception !== null) {
            return true;
        }

        $status = $context->getStatusCode();

        if ($status >= 500) {
            return true;
        }

        if ($status >= 400) {
            return false;
        }

        // Null means "decide once the body has arrived" — the whole reason this class exists, since a
        // 200 is exactly where the provider hides its 504.
        if ($responseContent === null) {
            return null;
        }

        return self::carriesServerError($responseContent);
    }

    public function getDelay(
        AsyncContext $context,
        ?string $responseContent,
        ?TransportExceptionInterface $exception,
    ): int {
        return self::DELAY_MS;
    }

    /**
     * Whether a 2xx body is really a provider failure in disguise.
     *
     * Reads the error object's own code rather than matching on its message: the wording is the
     * provider's to change, the code is the contract. A non-numeric or sub-500 code is left alone.
     */
    private static function carriesServerError(string $body): bool
    {
        $decoded = json_decode($body, true);

        if (!\is_array($decoded) || !isset($decoded['error']) || !\is_array($decoded['error'])) {
            return false;
        }

        $code = $decoded['error']['code'] ?? null;

        return \is_numeric($code) && (int) $code >= 500;
    }
}
