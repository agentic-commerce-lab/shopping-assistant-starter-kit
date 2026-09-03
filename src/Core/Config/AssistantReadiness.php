<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Config;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Whether this shop can answer a shopper at all.
 *
 * ## The defect this exists for
 *
 * Measured on 2026-09-03. The settings page's status card read its state from `assistantEnabled`
 * alone — `running() { return this.value !== false; }` — and that setting defaults to on, because a
 * shop that has never opened the form must not read as switched off. So on a shop with no model
 * configured the card showed a green dot, **"Running"**, and *"Shoppers can chat with the assistant,
 * and each reply spends model credit on your account"*, while `/assistant/chat` answered 503 and the
 * storefront rendered no orb at all. Three false statements, and the worst audience for them is a
 * fresh install: the merchant opens the settings for the first time, is told the assistant is live
 * and spending money, and nothing works.
 *
 * ## Why the server has to answer it
 *
 * The obvious fix — have the card read the model fields sitting in the same form — is wrong in the
 * other direction. Environment variables beat stored config and appear in no form field, so a shop
 * configured through `ASSISTANT_LLM_*` has three empty boxes and answers perfectly. Since
 * {@see EnvironmentValue} that includes anyone who put the key in `.env.local`. Only
 * {@see SystemConfigLlmSettings::isConfigured()} knows, and it runs here.
 *
 * ## Shop-wide, and why that is the honest scope
 *
 * A `config.xml` component is rendered through `sw-form-field-renderer`, which hands it the field's
 * own value and nothing else — not the sales channel the switcher above is pointing at. The sibling
 * {@see \Swag\AssistantStarterKit\Resources} preview component declined to reach into
 * `sw-system-config`'s internals for the same reason, and this declines too.
 *
 * So the question asked is *"is there any scope in which this assistant can answer?"*: the global
 * values, or any sales channel's own. That is exact for the two cases that matter — nothing
 * configured anywhere, and configured globally — and it is right rather than merely convenient for
 * the third: a shop that configured only one channel would read as unconfigured if this looked at
 * the global scope alone. What it cannot see is a *particular* channel being unconfigured while
 * another is fine; that shop reads as running, which is the lesser wrong answer of the two available.
 *
 * Channel inheritance does most of the work: `isConfigured($channelId)` is already true for a
 * channel with no override once the global values are set, so the global check below only decides a
 * shop that has no sales channels at all.
 */
final readonly class AssistantReadiness
{
    /**
     * `$salesChannelRepository` is `sales_channel.repository`, read only for its ids. Left untyped by
     * collection, the same call {@see \Swag\AssistantStarterKit\Core\Trace\Retention\TraceRetentionSettings}
     * made for the same repository: a generic here buys nothing and costs every test double a cast.
     */
    public function __construct(
        private SystemConfigLlmSettings $llmSettings,
        private EntityRepository $salesChannelRepository,
    ) {}

    /**
     * Whether a model is configured anywhere in this shop.
     */
    public function isConfiguredAnywhere(): bool
    {
        if ($this->llmSettings->isConfigured()) {
            return true;
        }

        $ids = $this->salesChannelRepository->searchIds(new Criteria(), Context::createDefaultContext())->getIds();

        foreach ($ids as $id) {
            if (\is_string($id) && $this->llmSettings->isConfigured($id)) {
                return true;
            }
        }

        return false;
    }
}
