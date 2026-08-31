<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool\Factory;

use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\SearchShopInfoTool;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;

/**
 * Contributes {@see SearchShopInfoTool}, on the unprivileged tier.
 *
 * The unprivileged tier is right because shop information is not a catalogue fact: this tool needs
 * somewhere to record what it did and the merchant's settings, and nothing from the gateway.
 * {@see EscalateToolFactory} is the precedent, and {@see ToolContext} explains why that tier exists at
 * all — a tool that could reach a `ProductCard` could hand the model a raw price.
 *
 * The embedder is built per turn from the context's config, because the provider, key and model are
 * all per-channel settings and the vectors in the store are only comparable within one of them.
 *
 * **Also records `retrieve.shopinfo.store`, the evidence spec D6 requires.** `$this->store` is
 * whichever {@see \Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser} handed the container —
 * this factory has no way to ask it which one that was, and no need to: `$availability` answers the
 * same question `PassageStoreChooser` asked. Recording here, not there, is deliberate: the chooser
 * runs on every shop including ones with shop information switched off (its own docblock says so),
 * so it must stay side-effect free, while this method already only runs when the feature is on,
 * after the `embeddingModel === ''` guard above. A shop that always uses the indexed store gets a
 * trace line reading `{store: 'mariadb'}` on every turn; a shop that has fallen back to the portable
 * store gets `{store: 'portable', reason: '...'}`, where the reason is
 * {@see ShopInfoAvailability::fastPathUnmet()}'s sentence — the one a human can act on.
 */
final readonly class SearchShopInfoToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private EmbedderFactory $embedders,
        private PassageStore $store,
        private ShopInfoAvailability $availability,
    ) {}

    public function create(ToolContext $context): ?object
    {
        // Null, not a disabled tool: never constructed means never in the schema the model sees,
        // which is what keeps capability control out of the prompt (D6) — and it is spec R13's off
        // switch. A starter kit offering a tool that always fails is worse than one offering no tool.
        if ($context->config->embeddingModel === '') {
            return null;
        }

        // Recorded here, once per turn, precisely because a MariaDB shop whose operator overlooked
        // the now merely `suggest`-ed `symfony/ai-maria-db-store` package would otherwise experience
        // the fallback only as "it got slower", with nothing anywhere saying why.
        $context->trace->record('retrieve.shopinfo.store', array_filter(
            [
                'store' => $this->availability->nativeVectorStoreUsable() ? 'mariadb' : 'portable',
                'reason' => $this->availability->fastPathUnmet(),
            ],
            static fn(?string $value): bool => $value !== null,
        ));

        return new SearchShopInfoTool(
            $this->embedders->forSalesChannel($context->config->salesChannelId, $context->config->embeddingModel),
            $this->store,
            $context->trace,
            $context->config,
        );
    }
}
