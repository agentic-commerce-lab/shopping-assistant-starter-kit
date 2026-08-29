<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationDefinition;

/**
 * Pins the window this task's review closed: `scope_type` must never be `Required`.
 *
 * A required `StringField` gets a `NotBlank` constraint that Shopware validates *before* it ever
 * builds SQL, and that check fires on a payload that simply omits the key — it does not wait to
 * see whether the column has a default. `DalConversationStore::start()` does not write
 * `scopeType` until Task 5, so a `Required` flag here would have made every `start()` call throw
 * `WriteConstraintViolationException` in the gap between this task and that one. Neither fallback
 * helps against that: the PHP property default on `ConversationEntity` only applies to an entity
 * built directly (never to a DAL write), and the column's `NOT NULL DEFAULT 'guest'` is SQL the
 * validator never reaches.
 *
 * Read through `defineFields()` by reflection rather than `getFields()`, matching
 * {@see \Swag\AssistantStarterKit\Tests\Core\Trace\TraceEventApiExposureTest}: the latter needs a
 * compiled `DefinitionInstanceRegistry`, but flags are attached at definition time and readable
 * without one.
 */
final class ConversationScopeTypeNotRequiredTest extends TestCase
{
    public function testScopeTypeIsNotRequired(): void
    {
        $method = new \ReflectionMethod(ConversationDefinition::class, 'defineFields');
        /** @var FieldCollection $fields */
        $fields = $method->invoke(new ConversationDefinition());

        $field = null;
        foreach ($fields as $candidate) {
            if ($candidate->getPropertyName() !== 'scopeType') {
                continue;
            }

            $field = $candidate;

            break;
        }

        self::assertInstanceOf(StringField::class, $field, 'scope_type is missing from ConversationDefinition');
        self::assertFalse(
            $field->is(Required::class),
            'scope_type must not be Required: DalConversationStore::start() does not write it until '
            . 'Task 5, and a required field throws NotBlank on a payload that omits it. The column\'s '
            . "NOT NULL DEFAULT 'guest' is what covers a write that omits the key.",
        );
    }
}
