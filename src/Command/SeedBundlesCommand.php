<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Swag\AssistantStarterKit\Command\Seed\Bundle\BundleCatalogue;
use Swag\AssistantStarterKit\Command\Seed\Bundle\BundleSeedPlan;
use Swag\AssistantStarterKit\Command\Seed\Bundle\BundleSeedRun;
use Swag\AssistantStarterKit\Command\Seed\Bundle\DalBundleMemberReader;
use Swag\AssistantStarterKit\Core\Commerce\Dal\BundleSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes this shop's product bundles, so the assistant's bundle behaviour is reproducible.
 *
 * **This replaces a loose script, and the reason is the ids.** The four staging bundles were first
 * built by hand from a standalone kernel on 2026-09-10, using `Uuid::randomHex()` — so every run
 * produced four new bundles and left every trace, note and screenshot pointing at ids that no longer
 * existed. Here every id comes from {@see BundleSeedPlan::idFor()}, derived from the product number,
 * so **a re-run updates rather than doubles** and an id stays quotable. Same guarantee, and the same
 * mechanism, as `--update` on {@see SeedBikeCatalogueCommand}.
 *
 * **It writes through the DAL because it cannot use the API.** Every `/api/product-bundle` route is
 * gated on Commercial's Routes licence (`PRODUCT_BUNDLE-1159113`), so a seeder that went through the
 * Admin API would work only on a fully licensed shop. {@see BundleSeedPlan} therefore mirrors
 * `ProductBundlePayloadHydrator`'s own rules; that class's docblock says which and why.
 *
 * **`--dry-run` first**, for the reason its sibling gives: this writes into a shop with existing
 * content, so the plan is worth reading before it runs. Everything is resolved against the live shop
 * and reported — and the check that matters most only exists here. Commercial hides a bundle
 * containing a variant family **completely and silently**, so {@see SingleVariantMembers} refuses
 * such a bundle up front rather than letting a 404 be discovered later.
 *
 * It requires the bundle association to exist at all: without Commercial installed there is no
 * `bundleItems` field and nothing to write, which {@see BundleSupport} reports as a sentence rather
 * than a DAL exception.
 */
#[AsCommand(
    name: 'swag:assistant:seed-bundles',
    description: 'Write this shop\'s product bundles through the DAL. Re-runnable; ids are derived, so it updates.',
)]
final class SeedBundlesCommand extends Command
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly DalBundleMemberReader $reader,
        private readonly BundleSupport $bundles,
        private readonly DefaultSalesChannel $defaultSalesChannel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'sales-channel',
            null,
            InputOption::VALUE_REQUIRED,
            'Sales channel the bundles are visible in. Defaults to the shop\'s only active Storefront channel.',
        )->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and report the plan without writing.');
    }

    /**
     * @throws \Doctrine\DBAL\Exception propagated from {@see DalBundleMemberReader}: a shop this
     *         cannot be read from is a shop nothing should be written to, and a DBAL failure
     *         diagnoses itself better than any message this could wrap it in
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->bundles->isAvailable()) {
            $io->error(
                'This shop has no bundle support: Shopware Commercial is not installed, or its '
                . 'PRODUCT_BUNDLE feature is off, so `product.bundleItems` does not exist. Nothing was written.',
            );

            return self::FAILURE;
        }

        try {
            $salesChannelId = $this->salesChannelId($input);
        } catch (NoDefaultSalesChannelException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $plan = BundleSeedRun::plan(
            BundleCatalogue::all(),
            $this->reader->members(BundleCatalogue::memberNumbers()),
            $this->reader->categories(array_column(BundleCatalogue::all(), 'category')),
            $this->reader->tax(),
            $salesChannelId,
        );

        $io->table(['number', 'name', 'items', 'discount', 'status'], $plan['rows']);

        $refused = \count($plan['rows']) - \count($plan['payloads']);

        if ($refused > 0) {
            // Refusing the whole run rather than writing the healthy ones: a partially seeded set is
            // the state hardest to reason about later, and every problem reported above is one a
            // catalogue or a shop fixes in a minute. See BundleSeedRun.
            $io->error(sprintf(
                '%d of %d bundles cannot be written. Nothing was written.',
                $refused,
                \count($plan['rows']),
            ));

            return self::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->success(sprintf('%d bundles resolved. Nothing written (--dry-run).', \count($plan['payloads'])));

            return self::SUCCESS;
        }

        $this->productRepository->upsert($plan['payloads'], Context::createDefaultContext());

        $io->success(sprintf(
            '%d bundles written. Commercial derives their stock and price; re-running updates them in place.',
            \count($plan['payloads']),
        ));

        return self::SUCCESS;
    }

    private function salesChannelId(InputInterface $input): string
    {
        $given = $input->getOption('sales-channel');

        return \is_string($given) && $given !== '' ? $given : $this->defaultSalesChannel->id();
    }
}
