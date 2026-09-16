<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * The insights get their own ACL key rather than riding on `swag_assistant_conversation:read`.
 *
 * A run carries only counts; a finding quotes a shopper. A shop may want merchandising staff
 * reading the numbers while raw conversations stay with whoever administers the plugin, and one
 * right for both would force that choice the permissive way.
 *
 * Asserted against the file's text because the Administration is JavaScript and there is no PHP
 * seam to read a privilege mapping through. Thin, but it catches the two mistakes that actually
 * happen: a key that does not match the entities, and an entity privilege left out after a rename.
 */
final class InsightsAclTest extends TestCase
{
    private const ACL =
        __DIR__ . '/../../src/Resources/app/administration/src/module/swag-assistant-insights/acl/index.js';

    public function testTheInsightsHaveTheirOwnAclKey(): void
    {
        self::assertStringContainsString("key: 'swag_assistant_insight'", self::acl());
    }

    public function testBothEntitiesAreReadable(): void
    {
        self::assertStringContainsString('swag_assistant_insight_run:read', self::acl());
        self::assertStringContainsString('swag_assistant_insight_finding:read', self::acl());
    }

    public function testTheConversationRightIsADependencyRatherThanABundledPrivilege(): void
    {
        // A finding links into the trace detail. Declaring the dependency shows an administrator
        // why that second right is needed; bundling it would grant it without saying so.
        $acl = self::acl();

        self::assertMatchesRegularExpression("/dependencies:\s*\[\s*'swag_assistant_conversation',/", $acl);
        self::assertStringNotContainsString("'swag_assistant_conversation:read',", $acl);
    }

    public function testTheModuleIsRegisteredInTheAdministrationEntryPoint(): void
    {
        // An ACL file nothing imports grants nothing. This is the one line that makes it load.
        $main = file_get_contents(__DIR__ . '/../../src/Resources/app/administration/src/main.js');

        self::assertIsString($main);
        self::assertStringContainsString("import './module/swag-assistant-insights';", $main);
    }

    private static function acl(): string
    {
        $acl = file_get_contents(self::ACL);

        self::assertIsString($acl, 'the insights ACL file must exist');

        return $acl;
    }
}
