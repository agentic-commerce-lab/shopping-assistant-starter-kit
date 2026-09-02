<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Swag\AssistantStarterKit\Core\Trace\Sink\TraceLogChannel;
use Swag\AssistantStarterKit\PluginLifecycle\AssistantTableRemoval;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * There is no install, update or activate hook to write: the plugin's custom entities are created by
 * Shopware's own `SchemaUpdater` and its migrations run themselves.
 *
 * **Uninstall is the exception, and it had been missing.** Shopware delegates table removal to this
 * class — `PluginLifecycleService::uninstallPlugin()` removes the migration records, the plugin's
 * `system_config` and its assets, then calls `uninstall()`, commented in its own source as *"plugin->
 * uninstall() will remove the tables etc of the plugin"*. With this class empty, nothing was ever
 * dropped: `swag_assistant_conversation.transcript` — everything shoppers had typed — survived an
 * uninstall a merchant performed specifically to remove it.
 *
 * The intent was on record and only half implemented. Every migration's `updateDestructive()`
 * declines to drop these tables and says why — *"removal is a deliberate uninstall decision and not a
 * migration side effect"* — which is correct for an update, and left the uninstall decision unwritten.
 * {@see AssistantTableRemoval} is that decision.
 *
 * Not `final`: Shopware instantiates plugin classes itself and the base class already declares its
 * constructor `final`, so the extension point that matters is closed either way.
 */
class SwagAssistantStarterKit extends Plugin
{
    /**
     * Adds the plugin's own Monolog channel, and nothing else.
     *
     * `parent::build()` is where Shopware loads `services.xml`, registers the migration path and
     * wires the plugin's filesystems, so it runs first and unconditionally.
     *
     * **Why a plugin writes `monolog` config at all.** The `logTraces` setting promised a line per
     * reply in the shop's log and delivered nothing in production — the reasoning, and the
     * measurement, are on {@see TraceLogChannel}. Prepending rather than appending leaves a
     * merchant's own `config/packages/monolog.yaml` the last word, which is the right order: this is
     * a default the plugin brings, not a policy it imposes.
     *
     * The guard is not defensive padding. `prependExtensionConfig()` throws
     * `LogicException: Container extension "monolog" is not registered` when the bundle is absent,
     * and a shop that chose to run without MonologBundle must not be unable to install this plugin
     * over a log line.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        if (!$container->hasExtension('monolog')) {
            return;
        }

        $container->prependExtensionConfig('monolog', TraceLogChannel::monologConfig());
    }

    /**
     * **`keepUserData()` is honoured in both directions**, and the second one matters as much as the
     * first: a merchant who leaves it ticked has asked for the trace history to stay, and a plugin
     * that dropped the tables anyway would destroy exactly what they said to keep.
     *
     * @throws \Doctrine\DBAL\Exception propagated deliberately. An uninstall that cannot drop a table
     *     must fail loudly rather than report a clean removal over data still in the database.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);

        // Not an assertion: this runs inside Shopware's uninstall, and a container that cannot hand
        // over a connection is a reason to leave the tables standing rather than to abort an
        // uninstall halfway through. The merchant can drop them by hand; they cannot undo a
        // half-uninstalled plugin as easily.
        if (!$connection instanceof Connection) {
            return;
        }

        AssistantTableRemoval::drop($connection);
    }
}
