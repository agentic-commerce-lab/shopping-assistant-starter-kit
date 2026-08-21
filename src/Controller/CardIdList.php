<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * The card ids a client is asking to have re-rendered, from `?ids=a2a2…,a5a5…`.
 *
 * Its own class rather than another static on {@see ChatRequest}: that class parses *the chat
 * request*, and folding a second request shape into it pushed its complexity past the gate — which
 * was the gate correctly reporting that one class had grown two jobs.
 *
 * The rules are the same ones a conversation token follows, because the reason is the same: this
 * endpoint is **public**, so a malformed value must never reach a repository lookup. Anything that
 * does not fit the shape is dropped rather than failing the whole request — one bad entry must not
 * cost a shopper the cards that were fine.
 */
final readonly class CardIdList
{
    /**
     * A Shopware id: 32 lowercase hex characters. Shared with {@see ChatRequest} because a
     * conversation token has the same shape, and one definition beats two that can drift.
     */
    public const ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * How many ids one request may carry. Bounds catalogue lookups on a public endpoint, which
     * {@see AssistantCardController} performs one per id.
     *
     * **Correction, 2026-08-21.** This used to be justified as *"a turn renders at most a shortlist,
     * so a request for more ids than this did not come from one of our own transcripts"* — which was
     * simply wrong, and the widget paid for it. A transcript holds up to
     * {@see AssistantController::MAX_HISTORY_TURNS} turns, re-hydration needs the *union* of their
     * cards, and measured live that was 15 distinct products after ten turns. The excess was
     * truncated here, silently, and the three newest cards vanished from the panel on the next page
     * load while the older ones stayed.
     *
     * The cap itself was never the defect — asking past it was. `transport.js` now batches its
     * request at exactly this number, so the bound still holds per request and nothing is dropped.
     * **The two constants must stay equal.** Raising this one without raising the client's is
     * harmless; lowering it silently loses cards again, which is why the end-to-end suite asserts a
     * 15-card transcript re-hydrates whole.
     */
    public const MAX_IDS = 12;

    /**
     * Capped, de-duplicated, order-preserving — the order is the order the turn rendered them in,
     * which is the order a shopper saw.
     *
     * @return list<string>
     */
    public static function fromRequest(Request $request): array
    {
        $raw = $request->query->get('ids');

        return \is_string($raw) ? self::parse($raw) : [];
    }

    /**
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $candidate) {
            $id = trim($candidate);

            if (!self::isId($id) || \in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;

            if (\count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        return $ids;
    }

    private static function isId(string $value): bool
    {
        return preg_match(self::ID_PATTERN, $value) === 1;
    }
}
