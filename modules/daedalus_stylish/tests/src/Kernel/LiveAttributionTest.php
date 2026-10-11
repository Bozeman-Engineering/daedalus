<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests live-attribution demand: style-map keys reaching non-editor markup.
 *
 * The provider (daedalus_stylish.live_attribution) answers daedalus_ui's
 * live-attribution seams: outside edit mode, selectable keys are
 * stamped only where the style map demands them. Delivery through the
 * individual render seams (sections, regions, blocks, fields) lives in
 * daedalus_layout/daedalus_ui and is exercised live; this covers the demand
 * semantics and the entity-root stamp.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LiveAttributionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Edit-mode variant CSS reads the materials roster, whose daedalus_stylish
    // provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Creates a styled entity_test whose map holds the given keys.
   *
   * Key placeholders: "{uuid}" is replaced with the entity's uuid.
   */
  protected function styledEntity(array $map_keys): EntityTest {
    $entity = EntityTest::create(['name' => 'styled']);
    $entity->save();
    $map = [];
    foreach ($map_keys as $key) {
      $map[str_replace('{uuid}', $entity->uuid(), $key)] = ['default' => ['padding' => '2rem']];
    }
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();
    return $entity;
  }

  /**
   * Demand matches map keys exactly and through sub-element extension.
   */
  public function testDemandsKey(): void {
    $entity = $this->styledEntity([
      'entity:{uuid}',
      'field:{uuid}:field_body',
      'block:11111111-1111-1111-1111-111111111111::.quote',
    ]);
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    // Exact matches.
    $this->assertTrue($provider->demandsKey("entity:{$entity->uuid()}", $entity));
    $this->assertTrue($provider->demandsKey("field:{$entity->uuid()}:field_body", $entity));
    // A sub-element entry demands its parent key (the parent is the @scope
    // root the sub-element's CSS targets).
    $this->assertTrue($provider->demandsKey('block:11111111-1111-1111-1111-111111111111', $entity));
    // No demand: undeclared keys, other fields, prefix-similar uuids.
    $this->assertFalse($provider->demandsKey('container:22222222-2222-2222-2222-222222222222', $entity));
    $this->assertFalse($provider->demandsKey("field:{$entity->uuid()}:field_other", $entity));
    $this->assertFalse($provider->demandsKey("entity:{$entity->uuid()}x", $entity));
  }

  /**
   * Prefix demand covers a key family (block wrapper for its field keys).
   */
  public function testDemandsKeyPrefix(): void {
    $entity = $this->styledEntity([
      'block:11111111-1111-1111-1111-111111111111:field_title',
    ]);
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    $this->assertTrue($provider->demandsKeyPrefix('block:11111111-1111-1111-1111-111111111111', $entity));
    $this->assertTrue($provider->demandsKeyPrefix('block:11111111-1111-1111-1111-111111111111:', $entity));
    $this->assertFalse($provider->demandsKeyPrefix('block:33333333-3333-3333-3333-333333333333', $entity));
  }

  /**
   * A styled section demands every section AND block root: the boundaries.
   *
   * Section CSS is scope-limited at nested section and block roots
   * (CssPrinter::CONTAINER_SCOPE_LIMIT), so once the map styles any section,
   * ALL section and block roots must reach live markup — the unstyled ones
   * are the boundaries that stop an outer section's styles at nested layout
   * markup (nested sections stamp their own keys at every depth).
   */
  public function testSectionBoundaryDemand(): void {
    $styled = '44444444-4444-4444-4444-444444444444';
    $nested = '55555555-5555-5555-5555-555555555555';
    $entity = $this->styledEntity(["container:$styled::.daedalus-stylish-layout-container"]);
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    // The styled section's own root, and every other section root.
    $this->assertTrue($provider->demandsKey("container:$styled", $entity));
    $this->assertTrue($provider->demandsKey("container:$nested", $entity));
    // Block roots are boundaries too (and the block stamp seam asks by
    // prefix).
    $this->assertTrue($provider->demandsKey("block:$nested", $entity));
    $this->assertTrue($provider->demandsKeyPrefix("block:$nested", $entity));
    // Non-root keys are not boundaries.
    $this->assertFalse($provider->demandsKey("field:{$entity->uuid()}:field_other", $entity));

    // A root-only section entry demands boundaries too: its variant
    // styles may reach descendants.
    $entity = $this->styledEntity(["container:$styled"]);
    $this->assertTrue($provider->demandsKey("container:$nested", $entity));

    // Without section-keyed styles there are no donuts: block roots are
    // demanded only by their own key family.
    $entity = $this->styledEntity(['entity:{uuid}']);
    $this->assertFalse($provider->demandsKey("block:$nested", $entity));
    $this->assertFalse($provider->demandsKeyPrefix("block:$nested", $entity));
  }

  /**
   * Container conditions demand NO extra stamps.
   *
   * The width-band query root is the page-level container
   * (CssPrinter::PAGE_CONTAINER_RULE), always-present theme markup rather
   * than an entity or container scope root, so a map styling under a container
   * condition demands only its own styled keys, exactly like any other
   * condition.
   */
  public function testContainerConditionsDemandNothingExtra(): void {
    $entity = EntityTest::create(['name' => 'container-styled']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      'block:11111111-1111-1111-1111-111111111111' => ['narrow' => ['padding' => '1rem']],
    ]));
    $entity->save();
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    $this->assertTrue($provider->demandsKey('block:11111111-1111-1111-1111-111111111111', $entity));
    $this->assertFalse($provider->demandsKey("entity:{$entity->uuid()}", $entity));
    $this->assertFalse($provider->demandsKey('container:22222222-2222-2222-2222-222222222222', $entity));
    $this->assertFalse($provider->demandsKey('region:22222222-2222-2222-2222-222222222222:content', $entity));
    $this->assertFalse($provider->demandsKey('block:33333333-3333-3333-3333-333333333333', $entity));

    // No entity-root stamp either: nothing addresses the entity root. The
    // defaults scope class the entity kind's declared part defaults stamp
    // is not a key stamp; only the selectable attribute is the claim.
    $display = EntityViewDisplay::collectRenderDisplay($entity, 'full');
    $build = [];
    daedalus_stylish_entity_view($build, $entity, $display, 'full');
    $this->assertArrayNotHasKey(SelectableKey::ATTRIBUTE, $build['#attributes'] ?? []);
  }

  /**
   * No demand without a map, and none for entities without the field.
   */
  public function testNoDemandWithoutStyles(): void {
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    $unstyled = EntityTest::create(['name' => 'plain']);
    $unstyled->save();
    $this->assertFalse($provider->demandsKey("entity:{$unstyled->uuid()}", $unstyled));

    // block_content has no daedalus_stylish field and no registered root here.
    $bundle = BlockContentType::create(['id' => 'basic', 'label' => 'Basic']);
    $bundle->save();
    $block_content = BlockContent::create(['type' => 'basic', 'info' => 'loose']);
    $block_content->save();
    $this->assertFalse($provider->demandsKey("field:{$block_content->uuid()}:field_body", $block_content));
  }

  /**
   * Nested content resolves its root's map; block-field keys translate.
   */
  public function testBlockContentAnswersFromRoot(): void {
    $component_uuid = '44444444-4444-4444-4444-444444444444';
    $host = $this->styledEntity([
      "block:$component_uuid:field_body",
    ]);
    $bundle = BlockContentType::create(['id' => 'nested', 'label' => 'Nested']);
    $bundle->save();
    $block_content = BlockContent::create(['type' => 'nested', 'info' => 'nested']);
    $block_content->save();
    $this->container->get('daedalus_layout.root_entity_registry')->setRoot($block_content->uuid(), $host);
    $provider = $this->container->get('daedalus_stylish.live_attribution');

    // An inline-block field is stamped with the content uuid where it
    // renders; the map key uses the component uuid. Demand is answered by
    // field name against the root's map.
    $this->assertTrue($provider->demandsKey("field:{$block_content->uuid()}:field_body", $block_content));
    $this->assertFalse($provider->demandsKey("field:{$block_content->uuid()}:field_other", $block_content));
    // The block wrapper's prefix family resolves through the root too.
    $this->assertTrue($provider->demandsKeyPrefix("block:$component_uuid", $block_content));
  }

  /**
   * The daedalus_ui collector answers through the daedalus_stylish provider.
   */
  public function testCollectorIntegration(): void {
    $entity = $this->styledEntity(['entity:{uuid}']);
    $demand = $this->container->get('daedalus_ui.live_attribution_demand');
    $this->assertTrue($demand->demandsKey("entity:{$entity->uuid()}", $entity));
    $this->assertFalse($demand->demandsKey('entity:99999999-9999-9999-9999-999999999999', $entity));
  }

  /**
   * The entity-root key rides #attributes whenever the map demands it.
   */
  public function testEntityRootStamp(): void {
    $entity = $this->styledEntity(['entity:{uuid}']);
    $display = EntityViewDisplay::collectRenderDisplay($entity, 'full');

    // Live render: stamped, with no mode cache variance (the stamp is
    // unconditional, so the build never varies on the mode cookie).
    $build = [];
    daedalus_stylish_entity_view($build, $entity, $display, 'full');
    $this->assertSame("entity:{$entity->uuid()}", $build['#attributes']['data-selectable'] ?? NULL);
    $this->assertNotContains('daedalus_ui_mode', $build['#cache']['contexts'] ?? []);
    $this->assertNotEmpty($build['daedalus_stylish_css'] ?? NULL);

    // Edit mode: same stamp on the same template root. daedalus_ui
    // writes the identical attribute and value on the same build, so both
    // stamps land idempotently on ONE root (no double-root).
    $this->container->get('request_stack')->getCurrentRequest()->query->set('navigationMode', 'edit');
    $build = [];
    daedalus_stylish_entity_view($build, $entity, $display, 'full');
    $this->assertSame("entity:{$entity->uuid()}", $build['#attributes']['data-selectable'] ?? NULL);
    $this->assertNotContains('daedalus_ui_mode', $build['#cache']['contexts'] ?? []);

    // Styled without entity-root keys: no stamp.
    $this->container->get('request_stack')->getCurrentRequest()->query->remove('navigationMode');
    $section_styled = $this->styledEntity(['container:55555555-5555-5555-5555-555555555555']);
    $build = [];
    daedalus_stylish_entity_view($build, $section_styled, $display, 'full');
    $this->assertArrayNotHasKey(SelectableKey::ATTRIBUTE, $build['#attributes'] ?? []);
    $this->assertNotContains('daedalus_ui_mode', $build['#cache']['contexts'] ?? []);
  }

  /**
   * A styled field's key reaches rendered live markup; unstyled fields don't.
   *
   * End-to-end through daedalus_ui_preprocess_field's live branch: the
   * demand provider answers from the map and the key rides the field
   * template's own attributes.
   */
  public function testFieldKeyReachesLiveMarkup(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_live',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_live',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
    $display = EntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->setComponent('field_live', ['type' => 'string'])->save();

    $entity = EntityTest::create(['name' => 'styled', 'field_live' => 'styled text']);
    $entity->save();
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "field:{$entity->uuid()}:field_live" => ['default' => ['padding' => '1rem']],
    ]));
    $entity->save();

    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-selectable="field:' . $entity->uuid() . ':field_live"', $html);

    // An unstyled entity's field carries no key.
    $plain = EntityTest::create(['name' => 'plain', 'field_live' => 'plain text']);
    $plain->save();
    $plain_build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($plain);
    $html = (string) $this->container->get('renderer')->renderInIsolation($plain_build);
    $this->assertStringNotContainsString('data-selectable="field:', $html);
  }

  /**
   * Nested block_content builds depend on their styled root's cache tags.
   */
  public function testNestedFragmentRootTags(): void {
    $host = $this->styledEntity(['entity:{uuid}']);
    $bundle = BlockContentType::create(['id' => 'nested', 'label' => 'Nested']);
    $bundle->save();
    $block_content = BlockContent::create(['type' => 'nested', 'info' => 'nested']);
    $block_content->save();
    $this->container->get('daedalus_layout.root_entity_registry')->setRoot($block_content->uuid(), $host);
    $display = EntityViewDisplay::collectRenderDisplay($block_content, 'full');

    $build = [];
    daedalus_stylish_entity_view($build, $block_content, $display, 'full');
    $this->assertContains("entity_test:{$host->id()}", $build['#cache']['tags'] ?? []);
  }

}
