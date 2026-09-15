<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\BoundedProperties;
use Swag\AssistantStarterKit\Core\Tool\PropertiesWithheld;

/**
 * The cap may cut a property list; it may not do so without saying how much.
 *
 * **The live failure, 2026-09-14.** A brake pad with fifteen vehicle models and twelve model years
 * reached the model as four of each, with nothing to mark the difference. Asked whether it fits a
 * Harley FLHRXS from 2008 it answered "Ja, der Bremsbelag passt" — unhedged, about a brake part,
 * for a motorcycle first built in 2021.
 */
final class PropertiesWithheldTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function values(int $count, string $prefix = 'v'): array
    {
        return array_map(static fn(int $i): string => $prefix . $i, range(1, $count));
    }

    /**
     * The common case, and the reason the key is optional: nothing was cut, so nothing is said.
     */
    public function testAProductWithinTheCapCarriesNoKey(): void
    {
        self::assertSame(
            [],
            PropertiesWithheld::keyFor([
                'Material' => ['Alu', 'Stahl'],
                'Farbe' => ['schwarz'],
            ]),
        );
    }

    /**
     * The measured failure, as a test.
     */
    public function testALongGroupReportsWhatTheModelCannotSee(): void
    {
        self::assertSame(
            ['propertiesWithheld' => ['Fahrzeugmodell' => 11, 'Baujahr' => 8]],
            PropertiesWithheld::keyFor([
                'Fahrzeugmodell' => $this->values(15, 'm'),
                'Fahrzeugmarke' => ['Harley Davidson'],
                'Baujahr' => $this->values(12, 'y'),
            ]),
        );
    }

    /**
     * A group past the group cap reports ALL of its values, because none of them arrived. Silence
     * there would be the same defect one level up: the model cannot ask about a group it was never
     * shown, and would read the groups it has as the product's whole attribute set.
     */
    public function testAGroupDroppedWholeReportsEveryValue(): void
    {
        $properties = [];

        foreach (range(1, 8) as $i) {
            $properties['Gruppe' . $i] = ['a', 'b'];
        }

        self::assertSame(
            ['propertiesWithheld' => ['Gruppe7' => 2, 'Gruppe8' => 2]],
            PropertiesWithheld::keyFor($properties),
        );
    }

    /**
     * The counts are a diff against the real cap rather than a second copy of its limits, so this
     * holds whatever {@see BoundedProperties} decides — the pair cannot drift apart.
     */
    public function testTheCountIsAlwaysTheDifferenceFromWhatWasShown(): void
    {
        $properties = [
            'Lang' => $this->values(9),
            'Kurz' => ['nur eins'],
        ];

        $shown = BoundedProperties::of($properties);
        $withheld = PropertiesWithheld::keyFor($properties)['propertiesWithheld'] ?? [];

        foreach ($properties as $group => $values) {
            self::assertSame(
                \count($values),
                \count($shown[$group] ?? []) + ($withheld[$group] ?? 0),
                $group . ': shown plus withheld must be the whole list',
            );
        }
    }

    /**
     * An empty map is not a truncated one.
     */
    public function testAProductWithoutPropertiesCarriesNoKey(): void
    {
        self::assertSame([], PropertiesWithheld::keyFor([]));
    }
}
