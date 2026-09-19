<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests placement-restriction enforcement at the blueprint spine.
 *
 * The provider is daedalus_blueprint_restriction_test, which mirrors
 * layout_builder_restrictions' filter-alter contract exactly (the
 * list/browse gate; per-region denial derived from
 * section_storage->getSection(delta)->getLayoutId()), so these tests prove
 * the spine hands providers the coordinates they expect: the ROOT display
 * governs every depth, and a nested section's governing layout is resolved
 * through the scoped storage at local delta 0.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[CoversClass(PlacementRestrictions::class)]
#[Group('daedalus_blueprint')]
class PlacementRestrictionsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The daedalus_layout UUID of the root section.
   */
  protected string $rootSectionUuid;

  /**
   * The host entity holding the root section.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_layout',
      'daedalus_blueprint_restriction_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');

    $this->rootSectionUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Sets a restriction-provider state key.
   */
  protected function restrict(string $key, mixed $value): void {
    \Drupal::state()->set("daedalus_blueprint_restriction_test.$key", $value);
  }

  /**
   * Asserts a failed result carrying exactly one error with the given code.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError
   *   The error, for further assertions.
   */
  protected function assertSingleError(object $result, string $code): object {
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors, print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame($code, $errors[0]->getCode());
    return $errors[0];
  }

  /**
   * Nests a twocol section inside the root section, returning its UUID.
   */
  protected function nestTwocolSectionNode(): string {
    $uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      $uuid,
      'layout_twocol_section',
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    return $uuid;
  }

  /**
   * Tests a display-wide inline block denial on add_element.
   */
  public function testInlineBlockPlacementDenied(): void {
    $this->restrict('denied_blocks', ['inline_block:basic']);

    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['block_type' => 'basic'],
    );
    $error = $this->assertSingleError($result, 'RESTRICTED_BLOCK_TYPE');
    $this->assertNotContains('basic', $error->getContext()['allowed_block_types']);

    // Lifting the restriction lifts the refusal.
    $this->restrict('denied_blocks', []);
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['block_type' => 'basic'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Tests a display-wide plugin block denial on add_element.
   */
  public function testPluginBlockPlacementDenied(): void {
    $this->restrict('denied_blocks', ['system_powered_by_block']);

    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['plugin_id' => 'system_powered_by_block'],
    );
    $this->assertSingleError($result, 'RESTRICTED_PLUGIN_ID');

    $this->restrict('denied_blocks', []);
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['plugin_id' => 'system_powered_by_block'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Tests the container plugin is never placeable as a block.
   *
   * Lb_plus hides its nested-section container from every plugin listing;
   * the spine answer inherits that, so sections can only enter through the
   * section grammar.
   */
  public function testContainerPluginRefusedAsBlock(): void {
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['plugin_id' => 'daedalus_layout_section'],
    );
    $this->assertSingleError($result, 'RESTRICTED_PLUGIN_ID');
  }

  /**
   * Returns the layout id of a root section by its daedalus_layout UUID.
   */
  protected function sectionLayout(string $uuid): ?string {
    foreach ($this->entity->get('layout_builder__layout')->getSections() as $section) {
      if ($section->getThirdPartySetting('daedalus_layout', 'uuid') === $uuid) {
        return $section->getLayoutId();
      }
    }
    return NULL;
  }

  /**
   * Tests implicit sections resolve through the default-layout ladder.
   *
   * Add_section carries no layout choice, so the spine must mint one it
   * would itself allow: the display's default_section setting when its
   * layout is allowed, otherwise the first allowed layout, and a structured
   * refusal when nothing is allowed — never a hardcoded layout id.
   */
  public function testAddSectionDefaultLayoutLadder(): void {
    $root_location = ['anchor' => $this->entity->uuid(), 'placement' => 'inside'];

    // No configured default and a restricted display: the section is born
    // with the first ALLOWED layout.
    $this->restrict('allowed_layouts', ['layout_twocol_section']);
    $uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->entity, $root_location, $uuid);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('layout_twocol_section', $this->sectionLayout($uuid));

    // A configured default_section wins while its layout is allowed.
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->setThirdPartySetting('daedalus_layout', 'default_section', ['layout_plugin' => 'layout_onecol'])->save();
    $this->restrict('allowed_layouts', ['layout_twocol_section', 'layout_onecol']);
    $uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->entity, $root_location, $uuid);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('layout_onecol', $this->sectionLayout($uuid));

    // A stale configured default pointing at a now-restricted layout loses
    // to the first allowed layout instead of failing the add.
    $this->restrict('allowed_layouts', ['layout_twocol_section']);
    $uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->entity, $root_location, $uuid);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('layout_twocol_section', $this->sectionLayout($uuid));

    // Nothing allowed at all: a structured refusal, no invented layout.
    $this->restrict('allowed_layouts', []);
    $result = $this->operations->addSection($this->entity, $root_location);
    $this->assertSingleError($result, 'NO_ALLOWED_LAYOUTS');
  }

  /**
   * Tests an explicit birth layout enforces allowed layouts at any depth.
   */
  public function testExplicitLayoutRestricted(): void {
    $nested_uuid = $this->nestTwocolSectionNode();

    $this->restrict('allowed_layouts', ['layout_onecol', 'layout_twocol_section']);

    // A top-level add naming a disallowed layout.
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'after'],
      NULL,
      'layout_threecol_section',
    );
    $this->assertSingleError($result, 'RESTRICTED_LAYOUT');

    // Nested too: the same root-display answer governs every depth.
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $nested_uuid, 'placement' => 'inside'],
      NULL,
      'layout_threecol_section',
    );
    $this->assertSingleError($result, 'RESTRICTED_LAYOUT');

    // An allowed layout passes.
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $nested_uuid, 'placement' => 'inside'],
      NULL,
      'layout_onecol',
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Tests per-region denial governs a NESTED destination's own layout.
   *
   * The by_region contract: providers read
   * section_storage->getSection(delta)->getLayoutId(). For a nested section
   * the spine passes the scoped storage at local delta 0, so the governing
   * layout is the nested section's own — while the restriction config itself
   * lives on the ROOT display.
   */
  public function testByRegionDenialAtDepth(): void {
    $nested_uuid = $this->nestTwocolSectionNode();

    $this->restrict('denied_by_region', [
      'layout_twocol_section' => ['first' => ['inline_block:basic']],
    ]);

    // Denied in the nested twocol's 'first' region.
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $nested_uuid, 'placement' => 'inside', 'region' => 'first'],
      ['block_type' => 'basic'],
    );
    $this->assertSingleError($result, 'RESTRICTED_BLOCK_TYPE');

    // Allowed in its 'second' region: the denial is region-precise.
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $nested_uuid, 'placement' => 'inside', 'region' => 'second'],
      ['block_type' => 'basic'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // Allowed in the root onecol section: the denial is layout-precise.
    $result = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['block_type' => 'basic'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Tests move_element enforces the destination BEFORE mutating anything.
   */
  public function testMoveDestinationRestricted(): void {
    $add = $this->operations->addElement(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ['block_type' => 'basic', 'uuid' => 'b6a3f547-ec02-4a45-9c88-3d2f6b7e9d41'],
    );
    $this->assertTrue($add->isSuccess(), print_r($add->toArray()['errors'] ?? [], TRUE));
    $nested_uuid = $this->nestTwocolSectionNode();

    $this->restrict('denied_by_region', [
      'layout_twocol_section' => ['first' => ['inline_block:basic']],
    ]);

    $result = $this->operations->moveElement(
      $this->entity,
      'b6a3f547-ec02-4a45-9c88-3d2f6b7e9d41',
      ['anchor' => $nested_uuid, 'placement' => 'inside', 'region' => 'first'],
    );
    $this->assertSingleError($result, 'RESTRICTED_BLOCK_TYPE');

    // Nothing moved: the block still sits in the root section.
    $root_section = $this->entity->get('layout_builder__layout')->getSections()[0];
    $this->assertArrayHasKey('b6a3f547-ec02-4a45-9c88-3d2f6b7e9d41', $root_section->getComponentsByRegion('content'));

    // The sibling region is a valid destination.
    $result = $this->operations->moveElement(
      $this->entity,
      'b6a3f547-ec02-4a45-9c88-3d2f6b7e9d41',
      ['anchor' => $nested_uuid, 'placement' => 'inside', 'region' => 'second'],
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Tests the AI schema projects the same filtered answer.
   */
  public function testSchemaProjectsSameAnswer(): void {
    $this->createBlockContentType('other', 'Other');
    $this->restrict('denied_blocks', ['inline_block:basic']);
    $this->restrict('allowed_layouts', ['layout_onecol']);

    // One call only: the schema caches per display coordinates, and the
    // state-driven test provider (unlike a real provider's display config)
    // carries no cache tags.
    $schema = $this->container->get('daedalus_blueprint.schema_builder')->getSchema('entity_test', 'entity_test', 'full');

    $block_types = $schema['layout_builder__layout']['available_block_types'];
    $this->assertArrayNotHasKey('basic', $block_types);
    $this->assertArrayHasKey('other', $block_types);

    $layout_ids = array_column($schema['layout_builder__layout']['available_layouts'], 'id');
    $this->assertSame(['layout_onecol'], $layout_ids);
  }

  /**
   * Tests whole-layout writes enforce restrictions at every depth.
   *
   * Restructure_layout reaches the layout deserializer without the
   * structural validator, so the deserializer itself must refuse restricted
   * layouts, block types, and plugin blocks — including on nested section
   * nodes.
   */
  public function testRestructureLayoutEnforced(): void {
    $this->restrict('allowed_layouts', ['layout_onecol', 'layout_twocol_section']);
    $this->restrict('denied_blocks', ['inline_block:basic', 'system_powered_by_block']);

    // Restricted layout on a NESTED section node.
    $result = $this->operations->restructureLayout($this->entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              ['layout_id' => 'layout_threecol_section', 'regions' => []],
            ],
          ],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('RESTRICTED_LAYOUT', $result->getErrors()[0]->getCode());

    // Restricted inline block type.
    $result = $this->operations->restructureLayout($this->entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              ['block_type' => 'basic', 'block_fields' => []],
            ],
          ],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('RESTRICTED_BLOCK_TYPE', $result->getErrors()[0]->getCode());

    // Restricted plugin block.
    $result = $this->operations->restructureLayout($this->entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              ['plugin_id' => 'system_powered_by_block'],
            ],
          ],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('RESTRICTED_PLUGIN_ID', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests the structural validator (create_entity path) enforcement.
   */
  public function testValidatorEnforced(): void {
    $this->restrict('allowed_layouts', ['layout_onecol']);
    $this->restrict('denied_blocks', ['inline_block:basic']);

    // Restricted layout, nested node included (the validator recurses).
    $result = $this->operations->createEntity([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Restricted'],
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              ['layout_id' => 'layout_twocol_section', 'regions' => []],
              ['block_type' => 'basic', 'block_fields' => []],
              ['plugin_id' => 'no_such_plugin_at_all'],
            ],
          ],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $codes = array_map(fn ($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('RESTRICTED_LAYOUT', $codes);
    $this->assertContains('RESTRICTED_BLOCK_TYPE', $codes);
    $this->assertContains('INVALID_PLUGIN_ID', $codes);
  }

}
