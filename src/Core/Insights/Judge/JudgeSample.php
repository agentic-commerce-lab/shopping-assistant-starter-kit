<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Judge;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Draws the conversations the judge reads, reproducibly.
 *
 * **Seeded, because a sample nobody can redraw is a finding nobody can check.** The seed is written
 * to the run row, so a surprising night can be judged again over exactly the same conversations —
 * with a different model, or after a change to the request. Without it, "the judge said this once"
 * would be the end of every investigation.
 *
 * **A percentage that rounds to nothing still draws one.** 1 % of 40 conversations is 0.4, and a
 * configured judge that silently never runs reads in the dashboard as "no problems found" — the
 * worst possible failure for a control. Zero percent is the only way to draw nothing, and it is
 * explicit.
 *
 * At 100 % the original order is kept rather than shuffled: a merchant reading a full night's
 * findings should see them in the order the conversations happened.
 */
final class JudgeSample
{
    private function __construct() {}

    /**
     * @param list<ConversationTrace> $traces
     *
     * @return list<ConversationTrace>
     */
    public static function draw(array $traces, int $percent, string $seed): array
    {
        if ($percent <= 0 || $traces === []) {
            return [];
        }

        $wanted = max(1, (int) round((\count($traces) * $percent) / 100));

        if ($wanted >= \count($traces)) {
            return $traces;
        }

        // Ranked into a keyed array rather than sorted by integer key: indexing `$traces[$k]`
        // inside the comparator is a `possibly-null-property-access` under this project's
        // analyzer, and the keyed form says what it means anyway. The id is appended to the hash
        // so two conversations whose md5 collides still order deterministically.
        $ranked = [];

        foreach ($traces as $trace) {
            $ranked[md5($seed . $trace->id) . $trace->id] = $trace;
        }

        ksort($ranked);

        return array_values(\array_slice($ranked, 0, $wanted));
    }
}
