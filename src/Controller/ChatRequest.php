<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * A validated chat request.
 *
 * Split out of {@see AssistantController} (cyclomatic-complexity) rather than suppressed, and the
 * seam is the right one anyway: **this endpoint is public**, so parsing untrusted input is its own
 * concern with its own rules, and the controller is left with the ordering it exists to enforce.
 */
final readonly class ChatRequest
{
    /** Bounded so a public endpoint cannot be used to push arbitrary text into a paid model. */
    public const MAX_MESSAGE_LENGTH = 2000;

    private function __construct(
        public ?string $message,
        // A bearer credential for someone else's conversation transcript, so it must not appear in a
        // stack trace. Same treatment ConversationStore's $token and LlmSettings::$apiKey have.
        #[\SensitiveParameter]
        public ?string $token,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $payload = self::payload($request);

        $raw = $request->isMethod('GET') ? $request->query->get('token') : $payload['token'] ?? null;

        return new self(message: self::message($payload['message'] ?? null), token: self::token($raw));
    }

    /**
     * Null rather than an empty string when unusable, so the controller's check reads as
     * "there is no message" rather than "the message is falsy".
     *
     * An oversized message is **rejected, not truncated**: truncating sends the model half a
     * question, which it will then answer confidently.
     */
    private static function message(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $message = trim($value);

        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return null;
        }

        return $message;
    }

    /**
     * A conversation token is a 32-character hex id.
     *
     * Validating the shape keeps a malformed value out of a repository lookup. A well-formed but
     * unknown token is deliberately *not* rejected here — it yields an empty history, which is what
     * a shopper with a stale `sessionStorage` entry should get instead of an error.
     */
    private static function token(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $token = trim($value);

        return preg_match('/^[0-9a-f]{32}$/', $token) === 1 ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), associative: true);

        if (!\is_array($decoded)) {
            return [];
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
