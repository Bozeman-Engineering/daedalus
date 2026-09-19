<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The dialect is a total statement against daedalus_stylish's birth defaults.
 *
 * Every DOM op batch and every document runs DRESSED
 * (DomOperationHandler::runTranslated()), so the styling layer's birth
 * subscriber stamps nothing under it: an attribute-less <pb-container>
 * inserted by page_edit wears no variants, one written with width="site"
 * wears exactly container_site (no house default beneath it), and a block
 * the fragment authors wears none even when its type declares defaults.
 * The same adds through the op door (a flat add_section, a flat
 * add_element) are born wearing the declared defaults, which proves the
 * fixtures would have stamped had the batch been undressed. The document
 * path (page_create's applyDocument()) rides the same posture.
 */
#[CoversClass(DomOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DressedDomOperationsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service (the op spine).
   */
  protected object $operations;

  /**
   * The DOM operation handler (the document path).
   */
  protected DomOperationHandler $dom;

  /**
   * The host entity.
   */
  protected object $entity;

  /**
   * The root section's uuid (a container in the tree).
   */
  protected string $rootUuid;

  /**
   * The request options every DOM op batch rides.
   */
  protected array $options;

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
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_stylish',
      // layout_onecol declares {style: onecol_default, width: onecol_wide
      // (a root-only axis), flow: onecol_row (root only)}: three
      // satisfiable section defaults the op door stamps.
      'daedalus_stylish_section_defaults_test',
      // The testimonial block type declares {style: testimonial_default,
      // emphasis: testimonial_emphatic}: a satisfiable block default.
      'daedalus_stylish_guide_test',
      // The unbound site: the variants here carry a literal padding on a
      // site that mints nothing, and the suite's bindings would withhold
      // every one for a reason that is the scale's, not the claim's.
      'daedalus_stylish_unbound_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();
    $this->createBlockContentType('testimonial', 'Testimonial');

    // Every declared default is satisfiable, so a stamp WOULD land on an
    // undressed creation.
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_wide', 'layout:layout_onecol', 'width');
    $this->createVariant('onecol_row', 'layout:layout_onecol', 'flow');
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    // The width the document states: width="site" on the layout's
    // root-only width axis.
    $this->createVariant('container_site', 'layout:layout_onecol', 'width');

    $this->rootUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Dressed host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->dom = $this->container->get('daedalus_blueprint.dom_operation_handler');
    $this->options = [
      'dialect_catalog' => $this->catalog(),
      'conversation' => 'conv-1',
    ];
  }

  /**
   * Creates a published variant.
   */
  protected function createVariant(string $machine_name, string $scope, ?string $axis = NULL): void {
    $variant = DaedalusStylishVariant::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'status' => TRUE,
    ] + ($axis === NULL ? [] : ['axis' => $axis]));
    $variant->setStyles(['' => ['default' => ['padding-top' => '1rem']]]);
    $variant->save();
  }

  /**
   * The test vocabulary: the container's width axis, as daedalus_stylish serves it.
   */
  protected function catalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: ['layout:layout_onecol' => ['width' => []]],
      blockTypes: [],
      conditions: [],
      roster: new MaterialsRoster([
        new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [MaterialRecord::attrSlot('layout:layout_onecol', 'width') => 'site'], scope: Address::fromString('layout:layout_onecol'), applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT]),
      ], new CacheableMetadata()),
    );
  }

  /**
   * Reads a host's style map back.
   */
  protected function styleMap(?object $entity = NULL): array {
    $item = ($entity ?? $this->entity)->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * A page_edit insert wears exactly what it states; the op door is born.
   */
  public function testInsertedElementsWearExactlyWhatTheyState(): void {
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="bare"><pb-block id="quote" type="testimonial" /></pb-container><pb-container id="band" width="site"></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame(['bare', 'quote', 'band'], array_keys($aliases));

    // The attribute-less container wears nothing (no onecol_default,
    // onecol_wide or onecol_row stamped), its block wears nothing (no
    // testimonial_default), and the band wears exactly container_site.
    $map = $this->styleMap();
    $this->assertArrayNotHasKey("container:{$aliases['bare']}", $map);
    $this->assertArrayNotHasKey("block:{$aliases['quote']}", $map);
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $map["container:{$aliases['band']}"] ?? NULL);
    $this->assertSame(["container:{$aliases['band']}"], array_keys($map), 'The fragment wrote exactly one map entry: the stated width.');

    // The op door control: the same adds undressed are born wearing the
    // declared defaults, so the fixtures would have stamped.
    $plain_section = (new PhpUuid())->generate();
    $plain_block = (new PhpUuid())->generate();
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'add_section',
        'uuid' => $plain_section,
        'location' => ['anchor' => $this->entity->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'testimonial',
        'uuid' => $plain_block,
        'location' => ['anchor' => $this->rootUuid, 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $map = $this->styleMap();
    $this->assertSame(
      [
        'variants' => [
          ['variant' => 'onecol_default', 'fragment' => ''],
          ['variant' => 'onecol_wide', 'fragment' => ''],
          ['variant' => 'onecol_row', 'fragment' => ''],
        ],
      ],
      $map["container:$plain_section"] ?? NULL,
    );
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $map["block:$plain_block"] ?? NULL);
  }

  /**
   * The document path (page_create) is the same total statement.
   */
  public function testDocumentWearsExactlyWhatItStates(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Created page',
      'layout_builder__layout' => [],
    ]);
    $entity->save();

    $result = $this->dom->applyDocument($entity, '<pb-container id="bare"><pb-block id="quote" type="testimonial" /></pb-container><pb-container id="band" width="site"></pb-container>', $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame(['bare', 'quote', 'band'], array_keys($aliases));

    $map = $this->styleMap($entity);
    $this->assertSame(["container:{$aliases['band']}"], array_keys($map), 'The document wrote exactly one map entry: the stated width.');
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $map["container:{$aliases['band']}"]);
  }

}
