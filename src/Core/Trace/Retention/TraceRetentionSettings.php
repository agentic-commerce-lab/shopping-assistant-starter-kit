<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Trace\Retention;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * How long a conversation is kept, in days.
 *
 * **Per sales channel, and that is a correction rather than a feature.** This class used to read
 * `getInt(self::KEY)` with no channel and nothing else, on the argument that a scheduled task has no
 * sales-channel context. The argument is true and the conclusion was wrong: Shopware renders every
 * plugin settings page with `<sw-system-config sales-channel-switchable>`, so a merchant can set this
 * field *on one channel*, see it saved, and get no pruning change at all — no error, no log line.
 * Measured on 6.7.13.1 on 2026-09-01: global 30, one channel set to 1, a three-day-old conversation
 * in that channel survived the prune.
 *
 * The dangerous direction is the one that reads as safe. A merchant who sets a channel to **90** days
 * for an audit trail believes the data is being kept, and a global 30 deletes it on day 31 — data
 * loss discovered when somebody asks for a transcript that is already gone.
 *
 * So the windows are resolved the same way the admin form resolves them: one `getInt()` per sales
 * channel, which is Shopware's own inheritance — the channel's own value where it has one, the global
 * value where it does not. A shop that never touches the switcher gets exactly the behaviour it had
 * before, from the same code path every other setting in this plugin already used.
 *
 * There is no "keep forever" value. An unset or nonsensical setting falls back to
 * {@see self::DEFAULT_DAYS} rather than disabling the task, because a merchant who never opens the
 * retention card must still get pruning — this table accumulates what shoppers typed, and the
 * Administration trace view makes it prominent.
 */
final readonly class TraceRetentionSettings
{
    public const DEFAULT_DAYS = 30;

    private const KEY = 'SwagAssistantStarterKit.config.traceRetentionDays';

    /**
     * `$salesChannelRepository` is `sales_channel.repository`, read only for its ids. Untyped by
     * collection like {@see TraceRetentionPruner}'s own repository, so a test double needs no
     * generic gymnastics to stand in for it.
     */
    public function __construct(
        private SystemConfigService $systemConfig,
        private EntityRepository $salesChannelRepository,
    ) {}

    /**
     * The window for one channel, or the shop-wide one when no channel is given.
     *
     * The shop-wide value is still needed on its own: it is what covers conversations whose sales
     * channel has since been deleted, which {@see TraceRetentionPruner} prunes last precisely so no
     * row can outlive the channel that produced it.
     */
    public function retentionDays(?string $salesChannelId = null): int
    {
        $configured = $this->systemConfig->getInt(self::KEY, $salesChannelId);

        return $configured > 0 ? $configured : self::DEFAULT_DAYS;
    }

    /**
     * Every sales channel in the shop with the window that actually applies to it.
     *
     * Inactive channels included. A channel switched off still has conversations in the table, and
     * "we stopped selling through it" is not a reason to keep what its shoppers typed.
     *
     * @return array<string, int> sales channel id => days
     */
    public function windows(): array
    {
        $ids = $this->salesChannelRepository->searchIds(new Criteria(), Context::createDefaultContext())->getIds();

        $windows = [];

        foreach ($ids as $id) {
            if (!\is_string($id)) {
                continue;
            }

            $windows[$id] = $this->retentionDays($id);
        }

        return $windows;
    }
}
