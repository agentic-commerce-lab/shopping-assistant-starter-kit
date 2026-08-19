<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit;

use Shopware\Core\Framework\Plugin;

/**
 * Plugin base class.
 *
 * Deliberately empty. There is no install/update/activate hook to write because the plugin's
 * two custom entities are created by Shopware's own `SchemaUpdater` when the plugin is installed
 * or updated, not by a migration this class would have to trigger.
 *
 * Not `final`: Shopware instantiates plugin classes itself and the base class already declares
 * its constructor `final`, so the extension point that matters is closed either way.
 */
class SwagAssistantStarterKit extends Plugin {}
