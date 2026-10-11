<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\Materials\CanvasOffer;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsDelivery;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\BlueprintTypeInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\SectionListInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the fit: what a blueprint needs of a host, and who reads it.
 *
 * A blueprint's requirements derive from its layout (the subject's fields
 * it places, the block types, plugins and layouts, the variants it wears)
 * plus its allow-list; a blueprint asking for `body` fits node pages and
 * not a bundle without the field, an allow-list excludes every bundle it
 * does not name, a host offers a blueprint at the scope asked for when it
 * has that scope, a root and fits (CanvasOffer: block scope for the Add
 * panel, page scope for the page library), the page-load delivery payload
 * for a canvas host carries only the blueprints it offers at block scope
 * and the mid-session delta resolved for a canvas removes a touched one
 * it does not offer (both unfiltered without a host), a frame-only type's
 * blueprint (the site chrome) fits the site frame alone so no page canvas
 * is offered it, and place_blueprint refuses BLUEPRINT_DOES_NOT_FIT naming
 * the missing requirement before anything is placed.
 */
#[CoversClass(BlueprintRequirements::class)]
#[CoversClass(CanvasOffer::class)]
#[CoversClass(MaterialsDelivery::class)]
#[CoversClass(MaterialsChangeSet::class)]
#[CoversClass(BlueprintOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintFitsTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    // The second subject: node pages with a body, Layout Builder enabled
    // with overrides so core derives field blocks for the bundle; the base
    // class's entity_test bundle has no body.
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Body',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
  }

  /**
   * The fit judgement.
   */
  protected function requirements(): BlueprintRequirements {
    return $this->container->get('daedalus_blueprint.blueprint_requirements');
  }

  /**
   * The page-load delivery.
   */
  protected function delivery(): MaterialsDelivery {
    return $this->container->get('daedalus_blueprint.materials_delivery');
  }

  /**
   * A node page host with one empty root section.
   */
  protected function createNodeHost(): HostInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'A page',
      'layout_builder__layout' => [$this->section()],
    ]);
    $node->save();
    return $this->host(Node::load($node->id()));
  }

  /**
   * A onecol container holding children in its content region.
   */
  protected function containerOf(array $children): array {
    return [
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'children' => [['kind' => 'region', 'region' => 'content', 'children' => $children]],
    ];
  }

  /**
   * A saved block blueprint for node pages placing the body field.
   */
  protected function createBodyBlueprint(): Blueprint {
    return $this->createBlueprintFromTree([
      'label' => 'Byline',
      'machine_name' => 'byline',
      'description' => 'The page body under a heading.',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ], [$this->containerOf([['kind' => 'block', 'field' => 'body']])]);
  }

  /**
   * The blueprint ids a delivery payload carries, sorted.
   *
   * @return string[]
   *   The ids.
   */
  protected function deliveredBlueprints(?HostInterface $host): array {
    $ids = [];
    foreach ($this->delivery()->payload($host)['records'] as $record) {
      if ($record['kind'] === MaterialKind::Blueprint->value) {
        $ids[] = $record['id'];
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * The sections of a host's saved document.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections.
   */
  protected function savedSections(FieldableEntityInterface $entity): array {
    $fresh = $this->container->get('entity_type.manager')->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    $list = $fresh->get('layout_builder__layout');
    $this->assertInstanceOf(SectionListInterface::class, $list);
    return $list->getSections();
  }

  /**
   * The requirements derive from the tree, one walk, every kind present.
   */
  public function testDeriveReadsTheLayout(): void {
    $layout = [
      [
        'kind' => 'container',
        'layout_id' => 'layout_twocol_section',
        'styles' => [
          '' => [
            'variants' => [
              ['variant' => 'card'],
              ['variant' => 'distributed', 'fragment' => 'x'],
              ['variant' => 'via', 'via' => 'card'],
            ],
          ],
        ],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [
              ['kind' => 'block', 'field' => 'body', 'styles' => ['' => ['variants' => ['quiet']]]],
              ['kind' => 'block', 'block_type' => 'basic', 'block_fields' => []],
            ],
          ],
          [
            'kind' => 'region',
            'region' => 'second',
            'children' => [
              ['kind' => 'block', 'plugin_id' => 'system_powered_by_block'],
              $this->containerOf([
                ['kind' => 'block', 'block_type' => 'basic'],
                ['kind' => 'block', 'field' => 'body'],
              ]),
            ],
          ],
        ],
      ],
      $this->containerOf([['kind' => 'block', 'block_type' => 'text', 'styles' => ['' => ['variants' => ['card']]]]]),
    ];
    $this->assertSame([
      BlueprintRequirements::FIELDS => ['body'],
      BlueprintRequirements::BLOCK_TYPES => ['basic', 'text'],
      BlueprintRequirements::PLUGINS => ['system_powered_by_block'],
      BlueprintRequirements::LAYOUTS => ['layout_twocol_section', 'layout_onecol'],
      BlueprintRequirements::VARIANTS => ['card', 'quiet'],
      BlueprintRequirements::BUNDLES => ['node:page'],
    ], BlueprintRequirements::derive($layout, ['node:page', 'node:page']), 'Ids are unique in first-seen order; derived wearings (a fragment, a via) need nothing; the allow-list rides de-duplicated.');
    $this->assertSame(array_fill_keys(BlueprintRequirements::KINDS, []), BlueprintRequirements::derive([]), 'An empty canvas needs nothing, every kind present.');
  }

  /**
   * A blueprint asking for body fits node pages and not a bundle without it.
   */
  public function testFieldsAreJudgedAgainstTheSubject(): void {
    $byline = $this->createBodyBlueprint();
    $entry = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint('byline');
    $this->assertSame([], $entry['bundles'], 'The entry carries the (empty) allow-list.');
    $requires = BlueprintRequirements::derive($entry['layout'], $entry['bundles']);
    $this->assertSame(['body'], $requires[BlueprintRequirements::FIELDS]);
    $this->assertSame(['layout_onecol'], $requires[BlueprintRequirements::LAYOUTS]);

    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Blueprint, 'byline');
    $this->assertSame($requires, $record->requires, 'The record carries what the entry derives to.');
    $this->assertArrayNotHasKey('requires', $record->toArray(), 'Server-side only: the client never judges a fit.');

    $node_host = $this->createNodeHost();
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);
    $this->assertTrue($this->requirements()->fits($requires, $node_host));
    $this->assertSame([], $this->requirements()->missing($requires, $node_host));
    $this->assertFalse($this->requirements()->fits($requires, $test_host));
    $missing = $this->requirements()->missing($requires, $test_host);
    $this->assertSame([BlueprintRequirements::FIELDS => ['body']], $missing);
    $this->assertSame('the field body (not placeable on entity_test:entity_test)', BlueprintRequirements::describe($missing, $test_host));

    // The site facts: a layout, a plugin or a block type the site lacks is
    // missing on every host; the subject is judged for fields only.
    $missing = $this->requirements()->missing([
      BlueprintRequirements::LAYOUTS => ['layout_onecol', 'layout_nope'],
      BlueprintRequirements::PLUGINS => ['system_powered_by_block', 'nope_block'],
      BlueprintRequirements::BLOCK_TYPES => ['basic', 'nope_type'],
    ], $node_host);
    $this->assertSame([
      BlueprintRequirements::BLOCK_TYPES => ['nope_type'],
      BlueprintRequirements::PLUGINS => ['nope_block'],
      BlueprintRequirements::LAYOUTS => ['layout_nope'],
    ], $missing);
    $this->assertSame('the block type nope_type (not on this site) and the block plugin nope_block (not on this site) and the layout layout_nope (not on this site)', BlueprintRequirements::describe($missing, $node_host));
    $this->assertInstanceOf(Blueprint::class, $byline);
  }

  /**
   * The allow-list excludes every bundle it does not name.
   */
  public function testTheAllowListExcludes(): void {
    $this->createBlueprint([
      'label' => 'Pages only',
      'machine_name' => 'pages_only',
      'description' => 'Any host could hold it; the author allows pages.',
      'bundles' => ['node:page'],
    ]);
    $entry = $this->container->get('daedalus_blueprint_library.discovery')->getBlueprint('pages_only');
    $this->assertSame(['node:page'], $entry['bundles']);
    $requires = BlueprintRequirements::derive($entry['layout'], $entry['bundles']);
    $this->assertSame([], $requires[BlueprintRequirements::FIELDS], 'An empty container needs no field.');

    $node_host = $this->createNodeHost();
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);
    $this->assertTrue($this->requirements()->fits($requires, $node_host));
    $missing = $this->requirements()->missing($requires, $test_host);
    $this->assertSame([BlueprintRequirements::BUNDLES => ['node:page']], $missing);
    $this->assertSame('it is for node:page only', BlueprintRequirements::describe($missing, $test_host));

    // Without the allow-list the same blueprint fits both.
    $this->assertTrue($this->requirements()->fits(BlueprintRequirements::derive($entry['layout']), $test_host));
  }

  /**
   * The delivery payload for a canvas host carries only the fitting ids.
   */
  public function testTheDeliveryPayloadIsTheCanvasHosts(): void {
    $this->createBodyBlueprint();
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'An empty band any host can hold.',
    ]);
    $this->createBlueprint([
      'label' => 'Pages only',
      'machine_name' => 'pages_only',
      'description' => 'Allowed on pages.',
      'bundles' => ['node:page'],
    ]);
    $node_host = $this->createNodeHost();
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);

    $this->assertSame(['band', 'byline', 'pages_only'], $this->deliveredBlueprints(NULL), 'Without a canvas nothing is filtered.');
    $this->assertSame(['band', 'byline', 'pages_only'], $this->deliveredBlueprints($node_host));
    $this->assertSame(['band'], $this->deliveredBlueprints($test_host), 'The body blueprint and the allow-listed one do not fit entity_test.');

    // The filtered payload is the same contract: {records}, the record
    // shape, roster order, a list (no holes where a record was dropped).
    $payload = $this->delivery()->payload($test_host);
    $this->assertSame(['records'], array_keys($payload));
    $this->assertSame(array_keys($payload['records']), range(0, count($payload['records']) - 1));
    $this->assertSame($payload, json_decode($this->delivery()->envelope($test_host), TRUE));
    $all = $this->delivery()->payload();
    $this->assertCount(count($all['records']) - 2, $payload['records']);
    foreach ($payload['records'] as $record) {
      $this->assertContains($record, $all['records'], 'A kept record is the unfiltered record.');
    }

    // The render that attaches a filtered envelope depends on the roster
    // and on what the fit judged.
    $tags = $this->delivery()->cacheMetadata($test_host)->getCacheTags();
    $this->assertContains(BlueprintDiscovery::CACHE_TAG, $tags);
    $this->assertContains('config:block_content_type_list', $tags);
    $this->assertNotContains('config:block_content_type_list', $this->delivery()->cacheMetadata()->getCacheTags(), 'An unfiltered delivery carries the roster\'s metadata alone.');
  }

  /**
   * A canvas offers a block blueprint with a root that fits; nothing else.
   *
   * The one judgement both halves of the delivery apply: the page-load
   * payload for a canvas host carries no page-scope and no rootless
   * blueprint beside the ones that do not fit, and the delta resolved for
   * a canvas lists a touched blueprint the canvas does not offer under
   * remove while a touched one it offers is an upsert; with no canvas the
   * delta upserts every touched id the roster carries. Other kinds are
   * offered on every canvas.
   */
  public function testTheCanvasOffersScopeRootAndFit(): void {
    $this->createBodyBlueprint();
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'An empty band any host can hold.',
    ]);
    $this->createBlueprint([
      'label' => 'Pages only',
      'machine_name' => 'pages_only',
      'description' => 'Allowed on pages.',
      'bundles' => ['node:page'],
    ]);
    $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A page document, the page library\'s.',
      'scope' => 'page',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ]);
    $this->createBlueprint([
      'label' => 'Uncomposed',
      'machine_name' => 'uncomposed',
      'description' => 'Composed from scratch, no root yet.',
    ], []);
    $node_host = $this->createNodeHost();
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(MaterialRecord::SCOPE_PAGE, $roster->get(MaterialKind::Blueprint, 'whole_page')->blueprintScope);
    $this->assertSame([], $roster->get(MaterialKind::Blueprint, 'uncomposed')->tree, 'The empty blueprint is in the roster with no root.');

    $offer = $this->container->get('daedalus_blueprint.canvas_offer');
    $this->assertInstanceOf(CanvasOffer::class, $offer);
    $this->assertTrue($offer->offered($roster->get(MaterialKind::Blueprint, 'byline'), $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertTrue($offer->offered($roster->get(MaterialKind::Blueprint, 'band'), $test_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertFalse($offer->offered($roster->get(MaterialKind::Blueprint, 'byline'), $test_host, MaterialRecord::SCOPE_BLOCK), 'The body field is not placeable on entity_test.');
    $this->assertFalse($offer->offered($roster->get(MaterialKind::Blueprint, 'pages_only'), $test_host, MaterialRecord::SCOPE_BLOCK), 'The allow-list excludes entity_test.');
    $this->assertFalse($offer->offered($roster->get(MaterialKind::Blueprint, 'whole_page'), $node_host, MaterialRecord::SCOPE_BLOCK), 'A page-scope blueprint is never offered on a canvas: it is the page library\'s.');
    $this->assertFalse($offer->offered($roster->get(MaterialKind::Blueprint, 'uncomposed'), $node_host, MaterialRecord::SCOPE_BLOCK), 'A blueprint with no root places nothing and is not offered.');
    $this->assertTrue($offer->offered(new MaterialRecord(MaterialKind::Swatch, 'lime', 'Lime'), $test_host, MaterialRecord::SCOPE_BLOCK), 'Every other kind is offered everywhere.');
    $this->assertSame($this->requirements()->cacheMetadata()->getCacheTags(), $offer->cacheMetadata()->getCacheTags());

    // The page-load half: the payload is what the canvas offers.
    $this->assertSame(['band', 'byline', 'pages_only', 'uncomposed', 'whole_page'], $this->deliveredBlueprints(NULL), 'Without a canvas nothing is filtered.');
    $this->assertSame(['band', 'byline', 'pages_only'], $this->deliveredBlueprints($node_host), 'No page-scope and no rootless blueprint.');
    $this->assertSame(['band'], $this->deliveredBlueprints($test_host));

    // The mid-session half: a touched blueprint the canvas does not offer
    // is a remove, exactly as one the roster lacks; a touched one it
    // offers is the upsert it always was.
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $this->assertInstanceOf(MaterialsChangeSet::class, $change_set);
    $change_set->flush();
    $change_set->touch(MaterialKind::Blueprint, 'byline', 'band', 'pages_only', 'whole_page', 'uncomposed', 'gone');
    $ids = static fn (array $records): array => array_column($records, 'id');

    $delta = $change_set->delta($test_host);
    $this->assertSame(['band'], $ids($delta['upsert']));
    $this->assertSame(['byline', 'pages_only', 'whole_page', 'uncomposed', 'gone'], $ids($delta['remove']), 'Not fitting, not fitting, page scope, no root, not in the roster: all removes, in touch order.');
    $this->assertSame(['kind' => 'blueprint', 'id' => 'byline'], $delta['remove'][0]);

    $delta = $change_set->delta($node_host);
    $this->assertSame(['byline', 'band', 'pages_only'], $ids($delta['upsert']));
    $this->assertSame(['whole_page', 'uncomposed', 'gone'], $ids($delta['remove']));

    $delta = $change_set->delta();
    $this->assertSame(['byline', 'band', 'pages_only', 'whole_page', 'uncomposed'], $ids($delta['upsert']), 'With no canvas every touched id the roster carries is an upsert.');
    $this->assertSame(['gone'], $ids($delta['remove']));

    // flush() is delta() for the canvas, then the drain.
    $this->assertSame($change_set->delta($test_host), $change_set->flush($test_host));
    $this->assertTrue($change_set->isEmpty());
  }

  /**
   * The same records asked for page scope answer the page library's offer.
   *
   * One judgement, two questions: the Add panel asks what a canvas can
   * place (block scope) and the page library asks what a page can start as
   * (page scope). Asked for page scope, the offer keeps the page-scope
   * blueprints that have a root and fit the host, and nothing of block
   * scope; the Add panel's delivery still asks for block scope, so a
   * page-scope blueprint never reaches a canvas payload. Other kinds are
   * offered at either scope, and a scope no blueprint has is refused.
   */
  public function testTheOfferAnswersTheScopeAsked(): void {
    $this->createBodyBlueprint();
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'An empty band any host can hold.',
    ]);
    $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A page document needing nothing of its host.',
      'scope' => 'page',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ]);
    $this->createBlueprintFromTree([
      'label' => 'Article page',
      'machine_name' => 'article_page',
      'description' => 'A page document placing the body.',
      'scope' => 'page',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ], [$this->containerOf([['kind' => 'block', 'field' => 'body']]), $this->containerOf([])]);
    $this->createBlueprint([
      'label' => 'Uncomposed page',
      'machine_name' => 'uncomposed_page',
      'description' => 'A page blueprint composed from scratch, no root yet.',
      'scope' => 'page',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
    ], []);
    $node_host = $this->createNodeHost();
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);

    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(MaterialRecord::SCOPE_PAGE, $roster->get(MaterialKind::Blueprint, 'article_page')->blueprintScope);
    $this->assertCount(2, $roster->get(MaterialKind::Blueprint, 'article_page')->tree, 'A page-scope record carries its whole document.');
    $this->assertSame([], $roster->get(MaterialKind::Blueprint, 'uncomposed_page')->tree);
    $offer = $this->container->get('daedalus_blueprint.canvas_offer');
    $offered = static fn (string $id, HostInterface $host, string $scope): bool => $offer->offered($roster->get(MaterialKind::Blueprint, $id), $host, $scope);

    // Page scope on a node page's host: the rooted, fitting page documents.
    $this->assertTrue($offered('whole_page', $node_host, MaterialRecord::SCOPE_PAGE));
    $this->assertTrue($offered('article_page', $node_host, MaterialRecord::SCOPE_PAGE));
    $this->assertFalse($offered('uncomposed_page', $node_host, MaterialRecord::SCOPE_PAGE), 'A page blueprint with no root starts nothing and is not offered.');
    $this->assertFalse($offered('byline', $node_host, MaterialRecord::SCOPE_PAGE), 'A block blueprint is never a start: it is the Add panel\'s.');
    $this->assertFalse($offered('band', $node_host, MaterialRecord::SCOPE_PAGE));

    // The fit is judged against the host asked for, at either scope.
    $this->assertTrue($offered('whole_page', $test_host, MaterialRecord::SCOPE_PAGE), 'A page document needing nothing fits any host.');
    $this->assertFalse($offered('article_page', $test_host, MaterialRecord::SCOPE_PAGE), 'The body field is not placeable on entity_test.');

    // Block scope on the same host and records: the Add panel's answer.
    $this->assertTrue($offered('byline', $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertTrue($offered('band', $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertFalse($offered('whole_page', $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertFalse($offered('article_page', $node_host, MaterialRecord::SCOPE_BLOCK));

    // Other kinds ride at either scope; a scope no blueprint has is refused.
    $this->assertTrue($offer->offered(new MaterialRecord(MaterialKind::Swatch, 'lime', 'Lime'), $node_host, MaterialRecord::SCOPE_PAGE));
    try {
      $offer->offered($roster->get(MaterialKind::Blueprint, 'band'), $node_host, 'section');
      $this->fail('A scope no blueprint has must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertSame('Unknown blueprint scope "section" asked of the offer; one of block, page.', $e->getMessage());
    }

    // The Add panel's delivery asks for block scope: a page-scope blueprint
    // never reaches a canvas payload, whatever it would start.
    $this->assertSame(['article_page', 'band', 'byline', 'uncomposed_page', 'whole_page'], $this->deliveredBlueprints(NULL));
    $this->assertSame(['band', 'byline'], $this->deliveredBlueprints($node_host));
  }

  /**
   * A frame-only type's blueprint fits the site frame and nothing else.
   *
   * The site chrome (the library's `site_chrome` type, frame_only TRUE)
   * is the frame's material: discovery serves the type's rule beside the
   * type for both sources, derive() turns it into the FRAME kind (present
   * in the map only then), missing() names `frame` on a page host and
   * nothing on a FrameHost, describe() spells it "the site frame", and
   * the one offer judgement drops the chrome from a page canvas's
   * delivery payload and its mid-session delta while a frame canvas keeps
   * it. A `section` blueprint is untouched by the rule.
   */
  public function testTheSiteChromeIsTheFramesMaterial(): void {
    $this->createBlueprint([
      'label' => 'Header',
      'machine_name' => 'header',
      'description' => 'The site header, shown by the frame on every page.',
      'type' => BlueprintInterface::TYPE_SITE_CHROME,
    ]);
    $this->createBlueprint([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'An empty band any host can hold.',
    ]);
    $discovery = $this->container->get('daedalus_blueprint_library.discovery');
    $header = $discovery->getBlueprint('header');
    $band = $discovery->getBlueprint('band');
    $this->assertSame(BlueprintInterface::TYPE_SITE_CHROME, $header['type']);
    $this->assertTrue($header['frame_only'], 'Discovery serves the type\'s rule beside the type.');
    $this->assertFalse($band['frame_only']);
    $this->assertTrue($this->container->get('entity_type.manager')->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->load(BlueprintInterface::TYPE_SITE_CHROME)->isFrameOnly());
    $this->assertFalse($this->container->get('entity_type.manager')->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->load(BlueprintInterface::TYPE_SECTION)->isFrameOnly());

    $requires = BlueprintRequirements::derive($header['layout'], $header['bundles'], $header['frame_only']);
    $this->assertSame([FrameInterface::ENTITY_TYPE_ID], $requires[BlueprintRequirements::FRAME]);
    $this->assertArrayNotHasKey(BlueprintRequirements::FRAME, BlueprintRequirements::derive($band['layout'], $band['bundles'], $band['frame_only']), 'The kind is present only where it binds.');
    $this->assertArrayNotHasKey(BlueprintRequirements::FRAME, BlueprintRequirements::derive([]));
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame($requires, $roster->get(MaterialKind::Blueprint, 'header')->requires, 'The record carries the frame kind.');

    $node_host = $this->createNodeHost();
    $frame_host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(Frame::create(['id' => 'stark']));
    $this->assertInstanceOf(FrameHost::class, $frame_host);
    $missing = $this->requirements()->missing($requires, $node_host);
    $this->assertSame([BlueprintRequirements::FRAME => [FrameInterface::ENTITY_TYPE_ID]], $missing);
    $this->assertSame('the site frame', BlueprintRequirements::describe($missing, $node_host));
    $this->assertFalse($this->requirements()->fits($requires, $node_host));
    $this->assertSame([], $this->requirements()->missing($requires, $frame_host), 'The frame satisfies the kind.');
    $this->assertTrue($this->requirements()->fits($requires, $frame_host));

    // The one offer judgement: a page canvas is never offered the chrome,
    // the frame canvas is; the section blueprint is offered on both.
    $offer = $this->container->get('daedalus_blueprint.canvas_offer');
    $this->assertFalse($offer->offered($roster->get(MaterialKind::Blueprint, 'header'), $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertTrue($offer->offered($roster->get(MaterialKind::Blueprint, 'header'), $frame_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertTrue($offer->offered($roster->get(MaterialKind::Blueprint, 'band'), $node_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertTrue($offer->offered($roster->get(MaterialKind::Blueprint, 'band'), $frame_host, MaterialRecord::SCOPE_BLOCK));
    $this->assertSame(['band', 'header'], $this->deliveredBlueprints(NULL), 'Without a canvas nothing is filtered.');
    $this->assertSame(['band'], $this->deliveredBlueprints($node_host), 'The page canvas\'s Add panel never lists the chrome.');
    $this->assertSame(['band', 'header'], $this->deliveredBlueprints($frame_host), 'The frame canvas\'s does.');
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $change_set->flush();
    $change_set->touch(MaterialKind::Blueprint, 'header', 'band');
    $delta = $change_set->delta($node_host);
    $this->assertSame(['band'], array_column($delta['upsert'], 'id'));
    $this->assertSame(['header'], array_column($delta['remove'], 'id'), 'A touched chrome blueprint is a remove on a page canvas.');
    $delta = $change_set->delta($frame_host);
    $this->assertSame(['header', 'band'], array_column($delta['upsert'], 'id'));
    $this->assertSame([], $delta['remove']);
    $change_set->flush();
  }

  /**
   * Place_blueprint refuses a blueprint that does not fit, before placing.
   */
  public function testPlaceBlueprintRefusesWhatDoesNotFit(): void {
    $this->createBodyBlueprint();
    $this->createBlueprint([
      'label' => 'Pages only',
      'machine_name' => 'pages_only',
      'description' => 'Allowed on pages.',
      'bundles' => ['node:page'],
    ]);
    ['entity' => $entity] = $this->createHost();
    $test_host = $this->host($entity);

    $result = $this->operations()->batchOperations($test_host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'byline',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('BLUEPRINT_DOES_NOT_FIT', $errors[0]->getCode());
    $this->assertSame('blueprint', $errors[0]->getPath());
    $this->assertSame('Blueprint "byline" does not fit this entity_test:entity_test: it needs the field body (not placeable on entity_test:entity_test).', $errors[0]->getMessage());
    $context = $errors[0]->getContext();
    $this->assertSame('byline', $context['blueprint']);
    $this->assertNull($context['provider']);
    $this->assertSame('entity_test:entity_test', $context['subject']);
    $this->assertSame([BlueprintRequirements::FIELDS => ['body']], $context['missing']);
    $this->assertCount(1, $this->savedSections($entity), 'Nothing was placed.');

    $result = $this->operations()->batchOperations($test_host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'pages_only',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('BLUEPRINT_DOES_NOT_FIT', $result->getErrors()[0]->getCode());
    $this->assertSame([BlueprintRequirements::BUNDLES => ['node:page']], $result->getErrors()[0]->getContext()['missing']);
    $this->assertStringContainsString('allow-list', $result->getErrors()[0]->getHint());

    // On a host it fits, the same op places it: the field block lands as
    // the node page's own derivative.
    $node_host = $this->createNodeHost();
    $node = $node_host->entity();
    $result = $this->operations()->batchOperations($node_host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'byline',
        'location' => ['anchor' => $node_host->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $this->container->get('daedalus_blueprint.storage_handler')->commit($node_host);
    $sections = $this->savedSections($node);
    $this->assertCount(2, $sections);
    $this->assertSame('byline', $sections[1]->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
    $plugin_ids = array_map(static fn ($component): string => $component->getPluginId(), array_values($sections[1]->getComponents()));
    $this->assertSame(['field_block:node:page:body'], $plugin_ids);
  }

}
