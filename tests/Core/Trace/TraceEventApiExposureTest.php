<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationDefinition;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventDefinition;

/**
 * The API boundary for the two trace entities, asserted rather than documented.
 *
 * **Read this before changing either definition.** Shopware fields are API-readable by *default*:
 * `Field::__construct()` adds `new ApiAware(AdminApiSource::class)` to every field, and `setFlags()`
 * puts it back if the flag list is cleared. Declaring no flag therefore does **not** close a field.
 * `ConversationDefinition` claimed the opposite in its docblock until 2026-08-21, and `transcript`
 * was readable over `/api/` for as long as that was believed.
 *
 * Two things this file locks, both of which are silent if broken:
 *
 * 1. **`transcript` carries no `ApiAware`.** It is closed by an explicit
 *    `removeFlag(ApiAware::class)`; delete that call and the verbatim conversation record goes back
 *    on the admin API with nothing to say so.
 * 2. **Nothing is readable over `/store-api/`.** `ApiAware`'s constructor parameter is named
 *    `$protectedSources` but builds an *allow*-list, so a bare `new ApiAware()` opens the store API
 *    as well — a one-word mistake that would put shopper conversations on a surface a browser can
 *    reach, and that nothing else in the suite would catch.
 *
 * The exposed-field cases pass on the framework default rather than on anything this plugin does.
 * They are still worth asserting: they are what fails if someone "helpfully" adds a bare
 * `new ApiAware()` or strips a flag the Administration needs.
 *
 * Scoped to **declared** fields: `EntityDefinition::defaultFields()` appends `createdAt`/`updatedAt`
 * and they carry no conversation content.
 *
 * Read through `defineFields()` by reflection rather than `getFields()`, because the latter needs a
 * compiled `DefinitionInstanceRegistry`; flags are attached at definition time and readable without
 * one.
 */
final class TraceEventApiExposureTest extends TestCase
{
    public function testTheTranscriptIsNeverReadableOverAnyApi(): void
    {
        $field = self::field(new ConversationDefinition(), 'transcript');

        self::assertNotNull($field, 'transcript disappeared from ConversationDefinition');
        self::assertNull(
            $field->getFlag(ApiAware::class),
            'transcript must never be ApiAware. It is closed by removeFlag(ApiAware::class); fields are '
            . 'admin-readable by default, so deleting that call silently reopens it.',
        );
    }

    public function testTheDefaultForANewFieldIsAdminReadableSoClosingIsAlwaysExplicit(): void
    {
        // Pins the framework behaviour this whole file depends on. If a Shopware upgrade ever makes
        // fields closed by default, this fails and the removeFlag call above becomes redundant
        // rather than load-bearing — which is a thing the next reader must be told, not discover.
        $field = new StringField('probe', 'probe');
        $flag = $field->getFlag(ApiAware::class);

        self::assertInstanceOf(ApiAware::class, $flag, 'Shopware fields are ApiAware by default');
        self::assertTrue($flag->isSourceAllowed(AdminApiSource::class));
        self::assertFalse($flag->isSourceAllowed(SalesChannelApiSource::class));
    }

    /**
     * @return list<array{0: class-string<EntityDefinition>, 1: string}>
     */
    public static function exposedFields(): array
    {
        return [
            [ConversationDefinition::class, 'salesChannelId'],
            [ConversationDefinition::class, 'locale'],
            [ConversationDefinition::class, 'turnCount'],
            [ConversationDefinition::class, 'outcome'],
            [ConversationDefinition::class, 'totalMs'],
            [ConversationDefinition::class, 'events'],
            [TraceEventDefinition::class,   'conversationId'],
            [TraceEventDefinition::class,   'seq'],
            [TraceEventDefinition::class,   'stage'],
            [TraceEventDefinition::class,   'payload'],
            [TraceEventDefinition::class,   'elapsedMs'],
        ];
    }

    /**
     * @param class-string<EntityDefinition> $definitionClass
     */
    #[DataProvider('exposedFields')]
    public function testAnExposedFieldIsAdminOnly(string $definitionClass, string $propertyName): void
    {
        $field = self::field(new $definitionClass(), $propertyName);
        self::assertNotNull($field, $propertyName . ' is missing from ' . $definitionClass);

        $flag = $field->getFlag(ApiAware::class);
        self::assertInstanceOf(
            ApiAware::class,
            $flag,
            $propertyName . ' must be ApiAware for the Administration to read it',
        );

        self::assertTrue($flag->isSourceAllowed(AdminApiSource::class), $propertyName . ' must be readable over /api/');
        self::assertFalse(
            $flag->isSourceAllowed(SalesChannelApiSource::class),
            $propertyName
            . ' must NOT be readable over /store-api/ — use new ApiAware(AdminApiSource::class), never new ApiAware().',
        );
    }

    private static function field(EntityDefinition $definition, string $propertyName): ?Field
    {
        $method = new \ReflectionMethod($definition, 'defineFields');
        /** @var FieldCollection $fields */
        $fields = $method->invoke($definition);

        foreach ($fields as $field) {
            if ($field->getPropertyName() === $propertyName) {
                return $field;
            }
        }

        return null;
    }
}
