<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The tool for the question eight of 34 conversations asked and none got answered.
 *
 * *"Do you sell bikes?"* ended in a bottle of cleaning fluid every time, because `bike` is a token
 * in *Bike Wash 1L* and a non-empty search result skips the orientation an empty one gets. And
 * *"What do you sell?"* was answered five times with no tool call, from the facet vocabulary.
 */
#[CoversClass(BrowseCategoriesTool::class)]
final class BrowseCategoriesToolTest extends TestCase
{
    /**
     * The bike catalogue's own top level, which is the whole point: there is no Bikes department,
     * and that absence is a fact the shop itself states.
     */
    private function tool(TraceRecorder $trace = new TraceRecorder()): BrowseCategoriesTool
    {
        $reader = new class implements CategoryTreeReader {
            public function categories(?string $parentId, CatalogScope $scope): array
            {
                return match ($parentId) {
                    null => [
                        self::node('acc', 'Accessories', hasChildren: true),
                        self::node('app', 'Apparel', hasChildren: true),
                        self::node('bag', 'Bags'),
                        // Stocked nowhere: an empty aisle is not an offer.
                        self::node('cle', 'Clearance', hasProducts: false),
                    ],
                    'acc' => [self::node('loc', 'Locks'), self::node('rac', 'Racks')],
                    'app' => [self::node('jac', 'Jackets')],
                    default => [],
                };
            }

            private static function node(
                string $id,
                string $name,
                bool $hasProducts = true,
                bool $hasChildren = false,
            ): CategoryNode {
                return new CategoryNode($id, $name, [$name], $hasProducts, $hasChildren);
            }
        };

        return new BrowseCategoriesTool($reader, $trace);
    }

    public function testItReturnsTheShopsOwnDepartmentsWithTheirSections(): void
    {
        $reply = $this->tool()();

        self::assertSame(
            [
                ['name' => 'Accessories', 'sections' => ['Locks', 'Racks']],
                ['name' => 'Apparel', 'sections' => ['Jackets']],
                ['name' => 'Bags'],
            ],
            $reply['departments'],
        );
    }

    /**
     * Offering someone an empty aisle is the disappointment twice — {@see \Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation}'s
     * rule, applied to the same tree.
     */
    public function testAnEmptyDepartmentIsNotOffered(): void
    {
        $names = array_column($this->tool()()['departments'], 'name');

        self::assertNotContains('Clearance', $names);
    }

    /**
     * **No ids and no counts.** `CategoryNode` refuses a product count by design, and an id in the
     * reply is something the model could quote at a shopper.
     */
    public function testTheReplyCarriesNamesOnly(): void
    {
        $json = json_encode($this->tool()(), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('"acc"', $json);
        self::assertStringNotContainsString('hasProducts', $json);
        self::assertDoesNotMatchRegularExpression('/"(count|total|products)":\s*\d/', $json);
    }

    /**
     * The distinction the whole tool rests on: the tree supports "no department for that", never
     * "the shop does not sell that".
     */
    public function testTheNoteLicensesTheDepartmentClaimAndForbidsTheAssortmentOne(): void
    {
        $note = $this->tool()()['note'];

        self::assertStringContainsString('no department for it', $note);
        self::assertStringContainsString('Never say the shop does not sell it', $note);
    }

    public function testItRecordsWhatItHandedOver(): void
    {
        $trace = new TraceRecorder();
        $this->tool($trace)();

        $event = $trace->events()[0] ?? null;

        self::assertNotNull($event);
        self::assertSame('categories.browsed', $event->stage);
        self::assertSame(3, $event->payload['departments'] ?? null);
        self::assertFalse($event->payload['truncated'] ?? null);
    }

    /**
     * A gateway that cannot describe its tree never gets this tool — the factory returns null, so
     * the model never sees it (D6). Asserted on the factory rather than here.
     */
    public function testAShortenedListSaysSo(): void
    {
        $reader = new class implements CategoryTreeReader {
            public function categories(?string $parentId, CatalogScope $scope): array
            {
                if ($parentId !== null) {
                    return [];
                }

                $nodes = [];

                for ($i = 0; $i < 14; ++$i) {
                    $nodes[] = new CategoryNode('d' . $i, 'Dept ' . $i, ['Dept ' . $i], true, false);
                }

                return $nodes;
            }
        };

        $reply = (new BrowseCategoriesTool($reader, new TraceRecorder()))();

        self::assertCount(12, $reply['departments']);
        self::assertStringContainsString('do not describe this as its full range', $reply['shortened'] ?? '');
    }
}
