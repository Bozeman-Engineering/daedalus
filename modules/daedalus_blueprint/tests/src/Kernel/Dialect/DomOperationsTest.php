<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The DOM ops on the op spine: mutation semantics and the alias layer.
 *
 * Each op is exercised through batchOperations() (the one public entry)
 * with mutation-response assertions: the entity's lean node tree after
 * the op, the result's targets (the mutation response's change feed), and
 * the alias echo. The alias layer's lifecycle is pinned end to end:
 * minted on insert, echoed on the result, persisted per entity per
 * conversation in tempstore when the batch stands, surviving a second
 * turn, printed back as data-alias, discarded on rollback, forgotten on
 * remove.
 *
 * The style/variant halves of the grammar (style op, set's style/class)
 * execute through daedalus_stylish's registered ops and are covered in
 * DomStyleOperationsTest; the pure validation surface (the op-batch
 * corpus) lives in DialectSpecTest.
 */
#[CoversClass(DomOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DomOperationsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service (the op spine).
   */
  protected object $operations;

  /**
   * The node tree builder.
   */
  protected object $treeBuilder;

  /**
   * The host entity.
   */
  protected object $entity;

  /**
   * The root section's uuid (a top-level container in the tree).
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
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_layout',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);
    $this->createBlockField('body', 'text_with_summary', 'basic');

    $this->rootUuid = (new PhpUuid())->generate();
    $section = $this->createSection('eb_test_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->treeBuilder = $this->container->get('daedalus_blueprint.node_tree_builder');
    $this->options = [
      'dialect_catalog' => $this->catalog(),
      'conversation' => 'conv-1',
    ];
  }

  /**
   * The test site vocabulary: the eb_test_container layout, one block type.
   *
   * No variant axes and no class variants: the variant halves execute
   * through daedalus_stylish's registered ops and are covered where daedalus_stylish is
   * installed (DomStyleOperationsTest).
   */
  protected function catalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'eb_test_container',
      axes: [],
      blockTypes: [
        'basic' => [
          'attributes' => [
            'heading' => ['field' => 'field_heading'],
          ],
          'body' => ['field' => 'body', 'kind' => 'rich'],
        ],
      ],
      conditions: [
        'narrow' => '@container (max-width: 767px)',
        'hover' => ':hover',
      ],
    );
  }

  /**
   * The entity's current lean node tree.
   */
  protected function tree(): array {
    return $this->treeBuilder->build($this->entity);
  }

  /**
   * The root container node from the tree.
   */
  protected function rootNode(): array {
    foreach ($this->tree() as $node) {
      if (($node['uuid'] ?? NULL) === $this->rootUuid) {
        return $node;
      }
    }
    $this->fail('The root section is gone from the tree.');
  }

  /**
   * Insert: fragment to elements, alias minted, echoed, ADDED targets.
   */
  public function testInsertBlockMintsAliasAndReportsAdded(): void {
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="intro" heading="Hello">Body copy</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // The alias echo: author id resolved to the minted uuid.
    $aliases = $result->getAliases();
    $this->assertArrayHasKey('intro', $aliases);
    $minted = $aliases['intro'];
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $minted);
    $this->assertSame(['ids' => $aliases], array_intersect_key($result->toArray(), ['ids' => 1]));

    // Mutation response: the new element is an ADDED target.
    $this->assertContains(['uuid' => $minted, 'field_name' => TargetType::ADDED], $result->getTargets());

    // The tree carries the block with its fields where the fragment put it.
    $children = $this->rootNode()['children'];
    $block = end($children);
    $this->assertSame('block', $block['kind']);
    $this->assertSame($minted, $block['uuid']);
    $this->assertSame('basic', $block['block_type']);
    $this->assertSame('Hello', $block['block_fields']['field_heading']);
    $this->assertSame('Body copy', is_array($block['block_fields']['body']) ? $block['block_fields']['body']['value'] : $block['block_fields']['body']);
  }

  /**
   * Insert: a container fragment with children lands in document order.
   */
  public function testInsertContainerFragmentAtRoot(): void {
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="band"><pb-basic id="one" heading="A">x</pb-basic><pb-basic id="two" heading="B">y</pb-basic></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame(['band', 'one', 'two'], array_keys($aliases));

    $tree = $this->tree();
    $this->assertCount(2, $tree);
    $band = $tree[1];
    $this->assertSame('container', $band['kind']);
    $this->assertSame($aliases['band'], $band['uuid']);
    $this->assertSame([$aliases['one'], $aliases['two']], array_column($band['children'], 'uuid'));
  }

  /**
   * Insert: a depth-2 fragment nests, even while another instance is staged.
   *
   * The layout tempstore stages a DIFFERENT instance of the entity (the
   * live sidebar topology: the editing session holds its own staged
   * copy), and a fragment nests a container inside a container. The inner
   * container's children anchor on its minted uuid mid-batch; nested
   * locates must ride the passed entity, because on the staged storage
   * that identity resolves nowhere (ANCHOR_NOT_FOUND per child) even
   * though every payload is legal.
   */
  public function testInsertNestedContainerFragmentWhileStaged(): void {
    $staged = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($this->entity->id());
    $this->assertNotSame($this->entity, $staged);
    $storage = $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->getSectionStorage($staged);
    $this->container->get('layout_builder.tempstore_repository')->set($storage);

    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="outer"><pb-container id="inner"><pb-basic id="a" heading="A">x</pb-basic><pb-basic id="b" heading="B">y</pb-basic></pb-container></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame(['outer', 'inner', 'a', 'b'], array_keys($aliases));

    $tree = $this->tree();
    $this->assertCount(2, $tree);
    $outer = $tree[1];
    $this->assertSame('container', $outer['kind']);
    $this->assertSame($aliases['outer'], $outer['uuid']);
    $this->assertSame([$aliases['inner']], array_column($outer['children'], 'uuid'));
    $inner = $outer['children'][0];
    $this->assertSame('container', $inner['kind']);
    $this->assertSame([$aliases['a'], $aliases['b']], array_column($inner['children'], 'uuid'));
  }

  /**
   * A bare op object is accepted as a one-element batch.
   */
  public function testBareOpObjectAcceptedAsBatch(): void {
    $result = $this->operations->batchOperations($this->entity, [
      'op' => 'insert',
      'html' => '<pb-basic id="solo" heading="One">.</pb-basic>',
      'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayHasKey('solo', $result->getAliases());
  }

  /**
   * Aliases persist per entity per conversation and survive a second turn.
   */
  public function testAliasSurvivesSecondTurn(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="hero" heading="First">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($insert->isSuccess(), print_r($insert->toArray()['errors'] ?? [], TRUE));
    $minted = $insert->getAliases()['hero'];

    // The pairing is in shared tempstore (per entity per conversation),
    // not only in the request's memory.
    $stored = $this->container->get('tempstore.shared')
      ->get('daedalus_blueprint.dialect_aliases')
      ->get('conv-1|entity_test|' . $this->entity->uuid());
    $this->assertSame(['hero' => $minted], $stored);

    // Turn two: the model still says "hero"; the ref resolves.
    $set = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => 'hero', 'attrs' => ['heading' => 'Updated']],
    ], $this->options);
    $this->assertTrue($set->isSuccess(), print_r($set->toArray()['errors'] ?? [], TRUE));
    $children = $this->rootNode()['children'];
    $this->assertSame('Updated', end($children)['block_fields']['field_heading']);

    // Another conversation shares nothing.
    $other = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => 'hero', 'attrs' => ['heading' => 'Nope']],
    ], ['dialect_catalog' => $this->catalog(), 'conversation' => 'conv-2']);
    $this->assertFalse($other->isSuccess());
    $this->assertSame('UNKNOWN_REF', $other->getErrors()[0]->getCode());

    // The printer echoes the pairing as data-alias beside the uuid id.
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print(
      $this->tree(),
      $this->catalog(),
      $this->container->get('daedalus_blueprint.dialect_alias_map')->printAliases($this->entity, 'conv-1'),
    );
    $this->assertStringContainsString(sprintf('id="%s" data-alias="hero"', $minted), $print);
  }

  /**
   * Document-path aliases are settled by the batch they mint in.
   *
   * ApplyDocument (the page_create path) calls the handler DIRECTLY, so
   * its translated batch is the OUTERMOST one and alias settlement fires
   * when that batch returns. The aliases must be on the overlay before
   * then; recorded after, they die with the request (the fourth live
   * run's #hero UNKNOWN_ID_SELECTOR on the turn after a create). The
   * pin: apply a document to an UNSAVED entity, drop the overlay the way
   * the next request's fresh process would, and every authored id still
   * resolves from tempstore.
   */
  public function testDocumentPathAliasesSurviveSettlement(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Fresh page',
    ]);
    $this->assertTrue($entity->isNew());

    $result = $this->container->get('daedalus_blueprint.dom_operation_handler')->applyDocument(
      $entity,
      '<pb-container id="hero"><pb-basic id="intro" heading="Hello">.</pb-basic></pb-container><pb-container id="band"></pb-container>',
      $this->options,
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame(['hero', 'intro', 'band'], array_keys($aliases));

    // The next request starts with an empty overlay; discard() is that
    // request's view of the map.
    $alias_map = $this->container->get('daedalus_blueprint.dialect_alias_map');
    $alias_map->discard();
    $this->assertSame($aliases, $alias_map->aliases($entity, 'conv-1'));
  }

  /**
   * Replace: the fragment supplants the element; its alias is forgotten.
   */
  public function testReplaceSwapsElementAndAliases(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="old" heading="Old">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $old_uuid = $insert->getAliases()['old'];

    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'replace',
        'ref' => 'old',
        'html' => '<pb-basic id="fresh" heading="Fresh">.</pb-basic>',
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $fresh_uuid = $result->getAliases()['fresh'];
    $uuids = array_column($this->rootNode()['children'], 'uuid');
    $this->assertContains($fresh_uuid, $uuids);
    $this->assertNotContains($old_uuid, $uuids);
    $this->assertContains(['uuid' => $old_uuid, 'field_name' => TargetType::REMOVED], $result->getTargets());
    $this->assertContains(['uuid' => $fresh_uuid, 'field_name' => TargetType::ADDED], $result->getTargets());

    // The removed element's alias stops resolving; the new one lives.
    $alias_map = $this->container->get('daedalus_blueprint.dialect_alias_map');
    $this->assertNull($alias_map->resolve($this->entity, 'conv-1', 'old'));
    $this->assertSame($fresh_uuid, $alias_map->resolve($this->entity, 'conv-1', 'fresh'));
  }

  /**
   * Move: DOM positions land in the spine's placement grammar.
   */
  public function testMoveReordersSiblings(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="a" heading="A">.</pb-basic><pb-basic id="b" heading="B">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $aliases = $insert->getAliases();
    $this->assertSame([$aliases['a'], $aliases['b']], array_column($this->rootNode()['children'], 'uuid'));

    $result = $this->operations->batchOperations($this->entity, [
      ['op' => 'move', 'ref' => 'b', 'at' => ['ref' => 'a', 'position' => 'beforebegin']],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$aliases['b'], $aliases['a']], array_column($this->rootNode()['children'], 'uuid'));
    $this->assertNotEmpty($result->getTargets());
  }

  /**
   * Move: root beforeend sends a section to the end of the page.
   *
   * The dialect translates root beforeend to the spine's root-anchored
   * 'inside' location, the promise the grammar makes for every op that
   * takes a location; move_element honors it for a section.
   */
  public function testMoveSectionToRootEndLandsLast(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="band"><pb-basic id="one" heading="A">x</pb-basic></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($insert->isSuccess(), print_r($insert->toArray()['errors'] ?? [], TRUE));
    $band = $insert->getAliases()['band'];
    $this->assertSame([$this->rootUuid, $band], array_column($this->tree(), 'uuid'));

    $result = $this->operations->batchOperations($this->entity, [
      ['op' => 'move', 'ref' => $this->rootUuid, 'at' => ['ref' => 'root', 'position' => 'beforeend']],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$band, $this->rootUuid], array_column($this->tree(), 'uuid'));
    $this->assertContains(['uuid' => $this->entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], $result->getTargets());
  }

  /**
   * Remove: subtree gone, REMOVED target, alias forgotten.
   */
  public function testRemoveDropsElementAndAlias(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="gone" heading="Bye">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $uuid = $insert->getAliases()['gone'];

    $result = $this->operations->batchOperations($this->entity, [
      ['op' => 'remove', 'ref' => 'gone'],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertNotContains($uuid, array_column($this->rootNode()['children'], 'uuid'));
    $this->assertContains(['uuid' => $uuid, 'field_name' => TargetType::REMOVED], $result->getTargets());

    $again = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => 'gone', 'attrs' => ['heading' => 'Ghost']],
    ], $this->options);
    $this->assertFalse($again->isSuccess());
    $this->assertSame('UNKNOWN_REF', $again->getErrors()[0]->getCode());
  }

  /**
   * Set: field attributes and the body field patch through the contract.
   */
  public function testSetPatchesFieldsAndBody(): void {
    $insert = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="card" heading="Before">Old body</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $uuid = $insert->getAliases()['card'];

    $result = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['heading' => 'After', 'body' => '<p>New body</p>']],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $children = $this->rootNode()['children'];
    $block = end($children);
    $this->assertSame('After', $block['block_fields']['field_heading']);
    $body = $block['block_fields']['body'];
    $this->assertSame('<p>New body</p>', is_array($body) ? $body['value'] : $body);
    // Field targets ride the mutation response.
    $this->assertNotEmpty($result->getTargets());

    // An attribute outside the element's contract is refused with the
    // legal roster in context: the catalog short names plus class, style
    // and body (one naming system; the raw field name is not a key).
    $bad = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['size' => 'large']],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $bad->getErrors()[0]->getCode());
    $this->assertSame(['body', 'class', 'heading', 'style'], $bad->getErrors()[0]->getContext()['legal']);
    $this->assertStringContainsString('heading', $bad->getErrors()[0]->getHint());
  }

  /**
   * Set on "root": the page's own fields, through the field handlers (v0.3).
   *
   * The root is not an element (no class, style, or wear); its attrs are
   * the entity fields page_create's "fields" takes, so a page's title is
   * editable without leaving the page language.
   */
  public function testSetOnRootPatchesEntityFields(): void {
    $result = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed host']],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Renamed host', $this->entity->get('name')->value);

    $styled = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => 'root', 'attrs' => ['style' => 'padding: 1rem']],
    ], $this->options);
    $this->assertFalse($styled->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $styled->getErrors()[0]->getCode());

    // Neither attrs nor detach is nothing to do.
    $empty = $this->operations->batchOperations($this->entity, [
      ['op' => 'set', 'ref' => $this->rootUuid],
    ], $this->options);
    $this->assertFalse($empty->isSuccess());
    $this->assertSame('MISSING_PARAMETER', $empty->getErrors()[0]->getCode());
  }

  /**
   * A failing batch rolls the entity back and discards minted aliases.
   */
  public function testRollbackDiscardsAliases(): void {
    $before = $this->rootNode();
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="doomed" heading="X">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
      ['op' => 'remove', 'ref' => 'never-existed'],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $this->assertSame($before['children'], $this->rootNode()['children']);
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolve($this->entity, 'conv-1', 'doomed'));
    $this->assertNull($this->container->get('tempstore.shared')
      ->get('daedalus_blueprint.dialect_aliases')
      ->get('conv-1|entity_test|' . $this->entity->uuid()));
  }

  /**
   * Payload parse errors are corrective and structural (nothing applied).
   */
  public function testInsertPayloadErrorsAreCorrective(): void {
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<div class="hero">Hi</div>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $codes = array_map(static fn ($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('SUGAR_TAG', $codes);
    $this->assertStringContainsString('pb-container', $result->getErrors()[0]->getMessage());
    $this->assertCount(1, $this->tree());
  }

  /**
   * The DOM positions map onto the anchor's flow.
   */
  public function testPositionVocabulary(): void {
    $seed = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="mid" heading="M">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $aliases = $seed->getAliases();

    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="first" heading="F">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'afterbegin'],
      ],
      [
        'op' => 'insert',
        'html' => '<pb-basic id="afterMid" heading="AM">.</pb-basic>',
        'at' => ['ref' => 'mid', 'position' => 'afterend'],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $all = array_merge($aliases, $result->getAliases());
    $this->assertSame(
      [$all['first'], $all['mid'], $all['afterMid']],
      array_column($this->rootNode()['children'], 'uuid'),
    );

    $bad = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="x"></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforebegin'],
      ],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $this->assertSame('BAD_OP', $bad->getErrors()[0]->getCode());
  }

}
