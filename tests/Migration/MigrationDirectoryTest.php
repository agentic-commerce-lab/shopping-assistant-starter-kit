<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Every file directly under `src/Migration/` must be a migration Shopware can instantiate.
 *
 * ## The defect this exists for
 *
 * Measured 2026-08-27 against the local shop, and invisible to every other test in this suite:
 *
 *     $ bin/console plugin:update SwagAssistantStarterKit
 *     Cannot instantiate abstract class Swag\AssistantStarterKit\Migration\RenameSystemConfigKey
 *
 * `MigrationCollection::loadMigrationSteps()` runs `scandir()` over the plugin's migration directory,
 * keeps every file whose class `is_subclass_of(MigrationStep::class)`, and calls `new` on it. An
 * abstract base class passes that check and fatals on construction — so ONE shared helper in the wrong
 * directory stops **every** migration in the plugin from running.
 *
 * The consequence was not cosmetic. The two migrations that extend that helper are
 * `Migration1788048000InvertKillSwitch` and `Migration1788134400MergeExcludedIntoBlockedCategories`,
 * so on any shop the kill-switch rename could never apply: `system_config` kept `killSwitch` while the
 * code read `assistantEnabled`, and a merchant who had deliberately switched the assistant OFF would
 * find it ON after updating, with the stored value silently ignored.
 *
 * `scandir()` is not recursive, and a directory entry fails the `.php` extension check, so a
 * subdirectory is the fix: shared base classes live in `Migration/Support/`.
 *
 * This test is the cheap check that keeps it fixed. Nothing else in the suite constructs a migration
 * the way Shopware does.
 */
final class MigrationDirectoryTest extends TestCase
{
    public function testEveryClassInTheMigrationDirectoryCanBeInstantiated(): void
    {
        foreach (self::migrationFiles() as $file => $className) {
            self::assertTrue(class_exists($className), \sprintf('%s does not declare %s', $file, $className));

            $reflection = new \ReflectionClass($className);

            self::assertFalse($reflection->isAbstract(), \sprintf(
                '%s is abstract and sits directly in src/Migration/, so Shopware\'s MigrationCollection '
                . 'will try to construct it and every migration in the plugin will fail. Move shared base '
                . 'classes into src/Migration/Support/.',
                $className,
            ));

            self::assertTrue($reflection->isInstantiable(), $className . ' is not instantiable');
        }
    }

    /** Shopware only runs a file it recognises as a MigrationStep, so anything else here is dead weight. */
    public function testEveryClassInTheMigrationDirectoryIsAMigrationStep(): void
    {
        foreach (self::migrationFiles() as $className) {
            self::assertTrue(
                is_subclass_of($className, MigrationStep::class),
                $className . ' sits in src/Migration/ but is not a MigrationStep',
            );
        }
    }

    /** And it must be able to report its own timestamp, which is how Shopware orders them. */
    public function testEveryMigrationReportsACreationTimestamp(): void
    {
        foreach (self::migrationFiles() as $className) {
            // Through reflection rather than `new $className()`: the analyzer cannot resolve a concrete
            // class from a variable class-string, and the point of this test is that Shopware's own
            // reflection-driven construction succeeds.
            $migration = (new \ReflectionClass($className))->newInstance();

            self::assertInstanceOf(MigrationStep::class, $migration);
            self::assertGreaterThan(0, $migration->getCreationTimestamp(), $className);
        }
    }

    /** @return array<string, class-string> file name => fully qualified class name */
    private static function migrationFiles(): array
    {
        $directory = \dirname(__DIR__, levels: 2) . '/src/Migration';
        $found = [];

        foreach ((array) scandir($directory) as $entry) {
            if (!\is_string($entry) || !str_ends_with($entry, '.php')) {
                continue;
            }

            /** @var class-string $className */
            $className = 'Swag\\AssistantStarterKit\\Migration\\' . pathinfo($entry, \PATHINFO_FILENAME);
            $found[$entry] = $className;
        }

        self::assertNotSame([], $found, 'no migrations found — the path is wrong');

        return $found;
    }
}
