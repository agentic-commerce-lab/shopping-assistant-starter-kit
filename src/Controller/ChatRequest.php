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
    /**
     * Bounded so a public endpoint cannot be used to push arbitrary text into a paid model.
     *
     * **500, not 2000, since 2026-08-21.** The old bound was set as an abuse ceiling and priced as
     * one — as if a long message were paid for once. It is not: a stored turn is re-sent on every
     * subsequent turn for as long as it stays inside
     * {@see AssistantController::MAX_HISTORY_TURNS}, so one 2000-character message costs its ~500
     * tokens up to eleven times over, and ten of them put ~5000 tokens of shopper prose in front of
     * the model on the last turn alone. Measured against the rest of a turn — the rules block is
     * ~549 tokens and the catalogue vocabulary is capped at ~375 — a single shopper message was
     * allowed to outweigh every instruction the assistant has.
     *
     * 500 characters is still two and a half times the longest realistic product question ("I want a
     * long-sleeve gravel jersey for autumn, I take M at one brand and L at another, budget around
     * 80, and it has to work under a hydration pack" is 205), so this trades nothing a shopper
     * actually needs. What it does cut off is a pasted specification sheet, which this assistant
     * cannot answer from anyway — it answers from tools, not from text the shopper supplies.
     *
     * The client mirrors this in `panel.plugin.js` to refuse before spending a round trip. **The two
     * must stay equal.**
     */
    public const MAX_MESSAGE_LENGTH = 500;

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

        return preg_match(CardIdList::ID_PATTERN, $token) === 1 ? $token : null;
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
