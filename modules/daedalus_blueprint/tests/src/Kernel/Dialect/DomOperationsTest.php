<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint_test\TestFileDeclarationHandler;
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
      'file',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
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
    TestFileDeclarationHandler::$declared = [];
    TestFileDeclarationHandler::$uuids = [];
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
    return $this->treeBuilder->build($this->host($this->entity));
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
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $storage = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($staged)->sectionStorage();
    $this->container->get('layout_builder.tempstore_repository')->set($storage);

    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $insert = $this->operations->batchOperations($this->host($this->entity), [
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
    $set = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'hero', 'attrs' => ['heading' => 'Updated']],
    ], $this->options);
    $this->assertTrue($set->isSuccess(), print_r($set->toArray()['errors'] ?? [], TRUE));
    $children = $this->rootNode()['children'];
    $this->assertSame('Updated', end($children)['block_fields']['field_heading']);

    // Another conversation shares nothing.
    $other = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'hero', 'attrs' => ['heading' => 'Nope']],
    ], ['dialect_catalog' => $this->catalog(), 'conversation' => 'conv-2']);
    $this->assertFalse($other->isSuccess());
    $this->assertSame('UNKNOWN_REF', $other->getErrors()[0]->getCode());

    // The printer echoes the pairing as data-alias beside the uuid id.
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print(
      $this->tree(),
      $this->catalog(),
      $this->container->get('daedalus_blueprint.dialect_alias_map')->printAliases($this->host($this->entity), 'conv-1'),
    )->text;
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
      $this->host($entity),
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
    $this->assertSame($aliases, $alias_map->aliases($this->host($entity), 'conv-1'));
  }

  /**
   * Replace: the fragment supplants the element; its alias is forgotten.
   */
  public function testReplaceSwapsElementAndAliases(): void {
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="old" heading="Old">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $old_uuid = $insert->getAliases()['old'];

    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $this->assertNull($alias_map->resolve($this->host($this->entity), 'conv-1', 'old'));
    $this->assertSame($fresh_uuid, $alias_map->resolve($this->host($this->entity), 'conv-1', 'fresh'));
  }

  /**
   * Move: DOM positions land in the spine's placement grammar.
   */
  public function testMoveReordersSiblings(): void {
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="a" heading="A">.</pb-basic><pb-basic id="b" heading="B">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $aliases = $insert->getAliases();
    $this->assertSame([$aliases['a'], $aliases['b']], array_column($this->rootNode()['children'], 'uuid'));

    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-container id="band"><pb-basic id="one" heading="A">x</pb-basic></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($insert->isSuccess(), print_r($insert->toArray()['errors'] ?? [], TRUE));
    $band = $insert->getAliases()['band'];
    $this->assertSame([$this->rootUuid, $band], array_column($this->tree(), 'uuid'));

    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="gone" heading="Bye">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $uuid = $insert->getAliases()['gone'];

    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'remove', 'ref' => 'gone'],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertNotContains($uuid, array_column($this->rootNode()['children'], 'uuid'));
    $this->assertContains(['uuid' => $uuid, 'field_name' => TargetType::REMOVED], $result->getTargets());

    $again = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'gone', 'attrs' => ['heading' => 'Ghost']],
    ], $this->options);
    $this->assertFalse($again->isSuccess());
    $this->assertSame('UNKNOWN_REF', $again->getErrors()[0]->getCode());
  }

  /**
   * Set: field attributes and the body field patch through the contract.
   */
  public function testSetPatchesFieldsAndBody(): void {
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="card" heading="Before">Old body</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $uuid = $insert->getAliases()['card'];

    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $bad = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $uuid, 'attrs' => ['size' => 'large']],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $bad->getErrors()[0]->getCode());
    $this->assertSame(['body', 'class', 'heading', 'style'], $bad->getErrors()[0]->getContext()['legal']);
    $this->assertStringContainsString('heading', $bad->getErrors()[0]->getHint());
  }

  /**
   * Set {name} on a container renames the section; null clears the name.
   *
   * The dialect's name attribute is the element-naming spine's renamable
   * slot: set translates it to update_element {label}, the same op the
   * editor's tree rename takes, so the tree node reads the new word and
   * the page print carries it back as name="...". A block has no name
   * attribute (its name is derived from its content), so set refuses it
   * with the block's legal roster; the container's roster names it.
   */
  public function testSetNameRenamesContainer(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['name' => 'Welcome']],
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Welcome', $this->rootNode()['label']);
    $this->assertNotEmpty($result->getTargets());
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print($this->tree(), $this->options['dialect_catalog'])->text;
    $this->assertStringContainsString(sprintf('<pb-container id="%s" name="Welcome">', $this->rootUuid), $print);

    $cleared = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['name' => NULL]],
    ], $this->options);
    $this->assertTrue($cleared->isSuccess(), print_r($cleared->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey('label', $this->rootNode(), 'A cleared name leaves the section reading by its kind.');

    $bad = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $this->rootUuid, 'attrs' => ['title' => 'Welcome']],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $bad->getErrors()[0]->getCode());
    $this->assertSame(['class', 'name', 'style'], $bad->getErrors()[0]->getContext()['legal']);

    $insert = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'insert', 'html' => '<pb-basic id="card" heading="A">x</pb-basic>', 'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend']],
    ], $this->options);
    $block = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => $insert->getAliases()['card'], 'attrs' => ['name' => 'Card']],
    ], $this->options);
    $this->assertFalse($block->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $block->getErrors()[0]->getCode());
    $this->assertSame(['body', 'class', 'heading', 'style'], $block->getErrors()[0]->getContext()['legal']);
  }

  /**
   * Insert: a named container is born with its name in the label slot.
   *
   * The fragment's name="" rides add_section as layout_settings.label, the
   * section's one label slot, so the born band reads by its name in the
   * tree and prints it back, at every depth.
   */
  public function testInsertNamedContainer(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-container id="band" name="Pricing"><pb-container id="inner" name="Plans"></pb-container><pb-basic id="one" heading="A">x</pb-basic></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforeend'],
      ],
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();

    $tree = $this->tree();
    $this->assertCount(2, $tree);
    $this->assertArrayNotHasKey('label', $tree[0], 'The unnamed root band stays unnamed.');
    $band = $tree[1];
    $this->assertSame('Pricing', $band['label']);
    $this->assertSame('Plans', $band['children'][0]['label']);
    $this->assertSame($aliases['one'], $band['children'][1]['uuid']);

    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print($tree, $this->options['dialect_catalog'])->text;
    $this->assertStringContainsString(sprintf('<pb-container id="%s" name="Pricing">', $aliases['band']), $print);
    $this->assertStringContainsString(sprintf('  <pb-container id="%s" name="Plans">', $aliases['inner']), $print);
  }

  /**
   * Set on "root": the page's own fields, through the field handlers (v0.3).
   *
   * The root is not an element (no class, style, or wear); its attrs are
   * the entity fields page_create's "fields" takes, so a page's title is
   * editable without leaving the page language.
   */
  public function testSetOnRootPatchesEntityFields(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed host']],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame('Renamed host', $this->entity->get('name')->value);

    $styled = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'set', 'ref' => 'root', 'attrs' => ['style' => 'padding: 1rem']],
    ], $this->options);
    $this->assertFalse($styled->isSuccess());
    $this->assertSame('ILLEGAL_ATTR', $styled->getErrors()[0]->getCode());

    // Neither attrs nor detach is nothing to do.
    $empty = $this->operations->batchOperations($this->host($this->entity), [
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
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="doomed" heading="X">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
      ['op' => 'remove', 'ref' => 'never-existed'],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $this->assertSame($before['children'], $this->rootNode()['children']);
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolve($this->host($this->entity), 'conv-1', 'doomed'));
    $this->assertNull($this->container->get('tempstore.shared')
      ->get('daedalus_blueprint.dialect_aliases')
      ->get('conv-1|entity_test|' . $this->entity->uuid()));
  }

  /**
   * Payload parse errors are corrective and structural (nothing applied).
   */
  public function testInsertPayloadErrorsAreCorrective(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
   * A file op reaches the seam and its name settles with the batch.
   *
   * The declaration is not a mutation (no target, the tree untouched);
   * its effect is the name the conversation holds, recorded on the
   * overlay by the seam and persisted by the outermost batch's
   * settlement, so the next request resolves it and the same batch's
   * later ops could already.
   */
  public function testFileOpDeclaresAndSettlesWithTheBatch(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'file',
        'name' => 'logo.svg',
        'type' => 'image/svg+xml',
        'content' => ' <svg xmlns="http://www.w3.org/2000/svg"/> ',
      ],
      [
        'op' => 'insert',
        'html' => '<pb-basic id="intro" heading="Hi">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([['conv-1', 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>']], TestFileDeclarationHandler::$declared, 'The seam receives the batch\'s conversation and the trimmed declaration.');
    $this->assertSame(['intro'], array_keys($result->getAliases()), 'A declared file is no element alias.');
    $this->assertCount(1, $this->rootNode()['children']);
    $uuid = TestFileDeclarationHandler::$uuids['logo.svg'];
    $this->assertSame($uuid, $this->container->get('daedalus_blueprint.dialect_alias_map')->resolveFile('conv-1', 'logo.svg'));
    // The next request reads it from tempstore: the batch settled it.
    $this->assertSame($uuid, (new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('conv-1', 'logo.svg'));
  }

  /**
   * A refused body is a structural error naming the declaration.
   */
  public function testFileOpRefusalNamesTheDeclaration(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="intro" heading="Hi">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
      [
        'op' => 'file',
        'name' => 'bad.svg',
        'type' => 'image/svg+xml',
        'content' => '<svg>' . TestFileDeclarationHandler::REFUSE . '</svg>',
      ],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $error = $result->getErrors()[0];
    $this->assertSame('FILE_REJECTED', $error->getCode());
    $this->assertSame(1, $error->getOperationIndex());
    $this->assertStringContainsString('The file "bad.svg" was refused: The body was refused by the test seam. The declared type image/svg+xml was noted.', $error->getMessage());
    $this->assertSame(['The body was refused by the test seam.', 'The declared type image/svg+xml was noted.'], $error->getContext()['reasons']);
    // The whole batch rolled back: the insert before it did not land.
    $this->assertSame([], $this->rootNode()['children']);
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolve($this->host($this->entity), 'conv-1', 'intro'));

    // The envelope: a declaration missing a part is refused before the seam.
    $missing = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'file', 'name' => 'logo.svg', 'type' => 'image/svg+xml'],
    ], $this->options);
    $this->assertSame('MISSING_PARAMETER', $missing->getErrors()[0]->getCode());
    $this->assertSame('content', $missing->getErrors()[0]->getPath());
    $path = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'file', 'name' => 'icons/logo.svg', 'type' => 'image/svg+xml', 'content' => '<svg/>'],
    ], $this->options);
    $this->assertStringContainsString('bare filename', $path->getErrors()[0]->getMessage());
    $this->assertCount(1, TestFileDeclarationHandler::$declared, 'Only the refused declaration reached the seam.');
  }

  /**
   * A batch that fails after a declaration leaves no name behind.
   */
  public function testRollbackDropsTheDeclaredName(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'file', 'name' => 'logo.svg', 'type' => 'image/svg+xml', 'content' => '<svg/>'],
      ['op' => 'remove', 'ref' => 'never-existed'],
    ], $this->options);

    $this->assertFalse($result->isSuccess());
    $this->assertCount(1, TestFileDeclarationHandler::$declared, 'The seam ran (the declaration preceded the failure).');
    $alias_map = $this->container->get('daedalus_blueprint.dialect_alias_map');
    $this->assertNull($alias_map->resolveFile('conv-1', 'logo.svg'), 'Discarded with the overlay.');
    $this->assertNull($this->container->get('tempstore.shared')->get('daedalus_blueprint.dialect_aliases')->get('conv-1|file'));
  }

  /**
   * Without a conversation or a seam the declaration answers in words.
   */
  public function testFileOpWithoutConversationOrSeam(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
      ['op' => 'file', 'name' => 'logo.svg', 'type' => 'image/svg+xml', 'content' => '<svg/>'],
    ], ['dialect_catalog' => $this->catalog()]);
    $this->assertSame('CONVERSATION_MISSING', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('"logo.svg"', $result->getErrors()[0]->getMessage());
    $this->assertSame([], TestFileDeclarationHandler::$declared);

    // A handler nobody handed a seam (a site without daedalus_ai).
    $bare = new DomOperationHandler(
      $this->container->get('daedalus_blueprint.dialect_parser'),
      $this->container->get('daedalus_blueprint.dialect_alias_map'),
      $this->treeBuilder,
      $this->container->get('uuid'),
      fn () => $this->operations,
      $this->container->get('daedalus_blueprint.document_instantiator'),
    );
    $this->assertContains('file', $bare->getPageLanguageOperations());
    $result = $bare->apply($this->host($this->entity), [
      'op' => 'file',
      'name' => 'logo.svg',
      'type' => 'image/svg+xml',
      'content' => '<svg/>',
    ], $this->options);
    $this->assertSame('FILE_STORE_MISSING', $result->getErrors()[0]->getCode());
    $this->assertSame([], TestFileDeclarationHandler::$declared);
  }

  /**
   * A document's <pb-file> declarations land before its batch.
   *
   * Whatever the source order, the declaration is saved and named before
   * the translated batch runs, so file="name" anywhere in the document
   * resolves; the settlement persists the name with the element aliases.
   * A refused body joins the grammar errors, addressed by line, and the
   * judged batch lands nothing.
   */
  public function testDocumentDeclaresFilesBeforeTheBatch(): void {
    $handler = $this->container->get('daedalus_blueprint.dom_operation_handler');
    $entity = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Fresh page']);
    $result = $handler->applyDocument(
      $this->host($entity),
      "<pb-container id=\"hero\"><pb-basic id=\"intro\" heading=\"Hello\">.</pb-basic></pb-container>\n<pb-file name=\"logo.svg\" type=\"image/svg+xml\"><svg xmlns=\"http://www.w3.org/2000/svg\"/></pb-file>",
      $this->options,
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([['conv-1', 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>']], TestFileDeclarationHandler::$declared);
    $this->assertSame(['hero', 'intro'], array_keys($result->getAliases()));
    $alias_map = $this->container->get('daedalus_blueprint.dialect_alias_map');
    $alias_map->discard();
    $this->assertSame(TestFileDeclarationHandler::$uuids['logo.svg'], $alias_map->resolveFile('conv-1', 'logo.svg'), 'Settled with the document\'s batch.');

    TestFileDeclarationHandler::$declared = [];
    $doomed = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Doomed page']);
    $result = $handler->applyDocument(
      $this->host($doomed),
      "<pb-container id=\"hero\"></pb-container>\n<pb-file name=\"bad.svg\" type=\"image/svg+xml\"><svg>" . TestFileDeclarationHandler::REFUSE . "</svg></pb-file>",
      $this->options,
    );
    $this->assertFalse($result->isSuccess());
    $codes = array_map(static fn ($error) => $error->getCode(), $result->getErrors());
    $this->assertSame(['FILE_REJECTED'], $codes);
    $this->assertSame('layout', $result->getErrors()[0]->getPath());
    $this->assertStringContainsString('The file "bad.svg" was refused: The body was refused by the test seam. The declared type image/svg+xml was noted. (line 2)', $result->getErrors()[0]->getMessage());
    $this->assertNull($alias_map->resolveFile('conv-1', 'bad.svg'));
    $this->assertNull($alias_map->resolve($this->host($doomed), 'conv-1', 'hero'), 'A judged batch lands nothing.');
  }

  /**
   * The DOM positions map onto the anchor's flow.
   */
  public function testPositionVocabulary(): void {
    $seed = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="mid" heading="M">.</pb-basic>',
        'at' => ['ref' => $this->rootUuid, 'position' => 'beforeend'],
      ],
    ], $this->options);
    $aliases = $seed->getAliases();

    $result = $this->operations->batchOperations($this->host($this->entity), [
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

    $bad = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-container id="x"></pb-container>',
        'at' => ['ref' => 'root', 'position' => 'beforebegin'],
      ],
    ], $this->options);
    $this->assertFalse($bad->isSuccess());
    $this->assertSame('BAD_OP', $bad->getErrors()[0]->getCode());
  }

  /**
   * Appends a section on a non-container layout to the page.
   *
   * A page-language site refuses to create one through the spine
   * (RESTRICTED_LAYOUT), so the fixture is built on the storage, the way
   * an adopted site already holds them. The components land in the named
   * regions as inline blocks of the test block type.
   *
   * @param string $layout_id
   *   The layout plugin id.
   * @param array<string, string> $blocks
   *   Region name => heading, one block per entry.
   *
   * @return array{0: string, 1: array<string, string>}
   *   The section's uuid and the block uuids by region.
   */
  protected function appendLayoutSection(string $layout_id, array $blocks = []): array {
    $section_uuid = (new PhpUuid())->generate();
    $components = [];
    $uuids = [];
    foreach ($blocks as $region => $heading) {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type' => 'basic',
        'reusable' => FALSE,
        'field_heading' => $heading,
      ]);
      $uuids[$region] = (new PhpUuid())->generate();
      $components[] = [
        'uuid' => $uuids[$region],
        'region' => $region,
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('basic', $block, $heading),
      ];
    }
    $section = $this->createSection($layout_id, $components, [], ['daedalus_layout' => ['uuid' => $section_uuid]]);
    $this->entity->get('layout_builder__layout')->appendSection($section);
    $this->entity->save();
    return [$section_uuid, $uuids];
  }

  /**
   * An insert op: the fragment and its at, the region when one is named.
   */
  protected function insertOp(string $html, string $ref, string $position, ?string $region = NULL): array {
    $at = ['ref' => $ref, 'position' => $position];
    if ($region !== NULL) {
      $at['region'] = $region;
    }
    return ['op' => 'insert', 'html' => $html, 'at' => $at];
  }

  /**
   * A move op: the element and its at, the region when one is named.
   */
  protected function moveOp(string $ref, string $anchor, string $position, ?string $region = NULL): array {
    $at = ['ref' => $anchor, 'position' => $position];
    if ($region !== NULL) {
      $at['region'] = $region;
    }
    return ['op' => 'move', 'ref' => $ref, 'at' => $at];
  }

  /**
   * The element node with the given uuid, at any depth of the tree.
   */
  protected function findNode(string $uuid, ?array $nodes = NULL): array {
    $search = static function (array $nodes) use (&$search, $uuid): ?array {
      foreach ($nodes as $node) {
        if (($node['uuid'] ?? NULL) === $uuid) {
          return $node;
        }
        $found = $search($node['children'] ?? []);
        if ($found !== NULL) {
          return $found;
        }
      }
      return NULL;
    };
    return $search($nodes ?? $this->tree()) ?? $this->fail("No element $uuid in the tree.");
  }

  /**
   * The uuids a section's region holds, from the tree, in order.
   */
  protected function regionUuids(string $section_uuid, string $region): array {
    foreach ($this->findNode($section_uuid)['children'] as $child) {
      if (($child['kind'] ?? '') === 'region' && $child['region'] === $region) {
        return array_column($child['children'], 'uuid');
      }
    }
    $this->fail("No region $region on $section_uuid.");
  }

  /**
   * Insert into a named region: beforeend appends there, afterbegin leads.
   *
   * A region is an address on `at`, never an element: a section on a
   * multi-region layout prints its regions (data-regions) and `at.region`
   * reaches one, the empty one included. afterbegin on an empty region
   * lands inside it, a fragment's siblings follow the first into the same
   * region, and a container lands in a region as a nested section.
   */
  public function testInsertIntoNamedRegion(): void {
    [$twocol, $blocks] = $this->appendLayoutSection('layout_twocol_section', ['first' => 'Left']);
    $left = $blocks['first'];
    $this->assertSame([$left], $this->regionUuids($twocol, 'first'));
    $this->assertSame([], $this->regionUuids($twocol, 'second'));

    // Afterbegin on the EMPTY region: inside it, the sibling following.
    $result = $this->operations->batchOperations($this->host($this->entity), [
      [
        'op' => 'insert',
        'html' => '<pb-basic id="r1" heading="R1">.</pb-basic><pb-basic id="r2" heading="R2">.</pb-basic>',
        'at' => ['ref' => $twocol, 'position' => 'afterbegin', 'region' => 'second'],
      ],
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame([$aliases['r1'], $aliases['r2']], $this->regionUuids($twocol, 'second'));
    $this->assertSame([$left], $this->regionUuids($twocol, 'first'), 'The other region is untouched.');
    $this->assertContains(['uuid' => $aliases['r1'], 'field_name' => TargetType::ADDED], $result->getTargets());

    // Beforeend appends inside the named region; afterbegin leads a filled
    // one, before the region's own first child rather than the section's.
    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-basic id="r3" heading="R3">.</pb-basic>', $twocol, 'beforeend', 'second'),
      $this->insertOp('<pb-basic id="lead" heading="Lead">.</pb-basic>', $twocol, 'afterbegin', 'first'),
      $this->insertOp('<pb-basic id="r0" heading="R0">.</pb-basic>', $twocol, 'afterbegin', 'second'),
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases += $result->getAliases();
    $this->assertSame([$aliases['r0'], $aliases['r1'], $aliases['r2'], $aliases['r3']], $this->regionUuids($twocol, 'second'));
    $this->assertSame([$aliases['lead'], $left], $this->regionUuids($twocol, 'first'));

    // A container lands in a region as a nested section, its children in it.
    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-container id="col"><pb-basic id="in" heading="In">.</pb-basic></pb-container>', $twocol, 'beforeend', 'first'),
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases += $result->getAliases();
    $this->assertSame([$aliases['lead'], $left, $aliases['col']], $this->regionUuids($twocol, 'first'));
    $col = $this->findNode($aliases['col']);
    $this->assertSame('container', $col['kind']);
    $this->assertSame([$aliases['in']], array_column($col['children'], 'uuid'));

    // The print spells the address the op took: the section's regions and
    // each child's region.
    $print = $this->container->get('daedalus_blueprint.dialect_printer')->print($this->tree(), $this->options['dialect_catalog'])->text;
    $this->assertStringContainsString(sprintf('id="%s" data-layout="layout_twocol_section" data-regions="first second"', $twocol), $print);
    $this->assertStringContainsString(sprintf('id="%s" data-region="second"', $aliases['r0']), $print);
  }

  /**
   * Move into a named region, the empty one included.
   */
  public function testMoveIntoNamedRegion(): void {
    [$twocol, $blocks] = $this->appendLayoutSection('layout_twocol_section', ['first' => 'Left']);
    $left = $blocks['first'];
    $insert = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-basic id="a" heading="A">.</pb-basic><pb-basic id="b" heading="B">.</pb-basic>', $this->rootUuid, 'beforeend'),
    ], $this->options);
    $this->assertTrue($insert->isSuccess(), print_r($insert->toArray()['errors'] ?? [], TRUE));
    $aliases = $insert->getAliases();

    // From the root container into the empty region, then ahead of it.
    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->moveOp('a', $twocol, 'beforeend', 'second'),
      $this->moveOp('b', $twocol, 'afterbegin', 'second'),
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$aliases['b'], $aliases['a']], $this->regionUuids($twocol, 'second'));
    $this->assertSame([], array_column($this->rootNode()['children'], 'uuid'));
    $this->assertNotEmpty($result->getTargets());

    // Between the regions of one section: the first empties, then refills
    // through afterbegin on an empty region.
    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->moveOp($left, $twocol, 'beforeend', 'second'),
      $this->moveOp('a', $twocol, 'afterbegin', 'first'),
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$aliases['a']], $this->regionUuids($twocol, 'first'));
    $this->assertSame([$aliases['b'], $left], $this->regionUuids($twocol, 'second'));
  }

  /**
   * An insert or move INTO a multi-region section names its region.
   *
   * The spine's default would land it in the first region silently (how
   * "put it in the right column" lands in the left one), so the door
   * refuses afterbegin/beforeend on such a section without a region and
   * lists them. Beside the section no region is needed: the flow is the
   * one the section lives in.
   */
  public function testRegionRequiredInsideMultiRegionSection(): void {
    [$twocol, $blocks] = $this->appendLayoutSection('layout_twocol_section', ['first' => 'Left']);
    $before = $this->tree();

    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $twocol, 'beforeend'),
    ], $this->options);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $error = $result->getErrors()[0];
    $this->assertSame('REGION_REQUIRED', $error->getCode());
    $this->assertSame('at.region', $error->getPath());
    $this->assertSame(['first', 'second'], $error->getContext()['regions']);
    $this->assertStringContainsString('first, second', $error->getMessage());
    $this->assertStringContainsString('region: "first"', $error->getHint());

    $move = $this->operations->batchOperations($this->host($this->entity), [
      $this->moveOp($blocks['first'], $twocol, 'afterbegin'),
    ], $this->options);
    $this->assertFalse($move->isSuccess());
    $this->assertSame('REGION_REQUIRED', $move->getErrors()[0]->getCode());
    $this->assertSame('at.region', $move->getErrors()[0]->getPath());
    $this->assertSame($before, $this->tree(), 'Nothing landed.');

    $beside = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-container id="after"></pb-container>', $twocol, 'afterend'),
    ], $this->options);
    $this->assertTrue($beside->isSuccess(), print_r($beside->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$this->rootUuid, $twocol, $beside->getAliases()['after']], array_column($this->tree(), 'uuid'));
  }

  /**
   * A region anywhere it means nothing is refused: root, beside, no regions.
   */
  public function testRegionWhereNoneBelongs(): void {
    [$twocol, $blocks] = $this->appendLayoutSection('layout_twocol_section', ['first' => 'Left']);
    $left = $blocks['first'];
    $before = $this->tree();

    $cases = [
      'root' => $this->insertOp('<pb-container id="x"></pb-container>', 'root', 'beforeend', 'first'),
      'beside a block' => $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $left, 'afterend', 'first'),
      'beside the section' => $this->insertOp('<pb-container id="x"></pb-container>', $twocol, 'beforebegin', 'first'),
      'a container layout' => $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $this->rootUuid, 'beforeend', 'content'),
      'a move' => $this->moveOp($left, $this->rootUuid, 'afterbegin', 'content'),
    ];
    foreach ($cases as $label => $op) {
      $result = $this->operations->batchOperations($this->host($this->entity), [$op], $this->options);
      $this->assertFalse($result->isSuccess(), $label);
      $this->assertSame('structural', $result->getPhase(), $label);
      $error = $result->getErrors()[0];
      $this->assertSame('REGION_NOT_APPLICABLE', $error->getCode(), $label);
      $this->assertSame('at.region', $error->getPath(), $label);
      $this->assertSame($op['at']['region'], $error->getContext()['region'], $label);
    }
    $this->assertStringContainsString('The page has no regions', $this->operations->batchOperations($this->host($this->entity), [$cases['root']], $this->options)->getErrors()[0]->getMessage());
    $this->assertStringContainsString(sprintf('beside #%s', $left), $this->operations->batchOperations($this->host($this->entity), [$cases['beside a block']], $this->options)->getErrors()[0]->getMessage());
    $this->assertStringContainsString(sprintf('#%s has no regions', $this->rootUuid), $this->operations->batchOperations($this->host($this->entity), [$cases['a container layout']], $this->options)->getErrors()[0]->getMessage());

    // The envelope: a region that is not a name.
    $shape = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $twocol, 'beforeend', ''),
    ], $this->options);
    $this->assertSame('BAD_OP', $shape->getErrors()[0]->getCode());
    $this->assertSame('at.region', $shape->getErrors()[0]->getPath());
    $this->assertSame($before, $this->tree(), 'Nothing landed.');
  }

  /**
   * A region the layout lacks is the spine's refusal, addressed at at.region.
   *
   * The door spells the address; the spine owns the name's validity
   * (INVALID_REGION with the layout's regions), for a block, a container
   * (add_section) and a move alike, afterbegin included (an unknown
   * region has no first child, so the inside location rides through).
   */
  public function testUnknownRegionComesThroughFromTheSpine(): void {
    [$twocol, $blocks] = $this->appendLayoutSection('layout_twocol_section', ['first' => 'Left']);
    $before = $this->tree();

    $cases = [
      'a block, beforeend' => $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $twocol, 'beforeend', 'third'),
      'a block, afterbegin' => $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $twocol, 'afterbegin', 'third'),
      'a container' => $this->insertOp('<pb-container id="x"></pb-container>', $twocol, 'beforeend', 'third'),
      'a move' => $this->moveOp($blocks['first'], $twocol, 'afterbegin', 'third'),
    ];
    foreach ($cases as $label => $op) {
      $result = $this->operations->batchOperations($this->host($this->entity), [$op], $this->options);
      $this->assertFalse($result->isSuccess(), $label);
      $error = $result->getErrors()[0];
      $this->assertSame('INVALID_REGION', $error->getCode(), $label);
      $this->assertSame('at.region', $error->getPath(), $label);
      $this->assertSame(['first', 'second'], $error->getContext()['available'], $label);
      $this->assertStringContainsString("Region 'third' does not exist on layout 'layout_twocol_section'", $error->getMessage(), $label);
    }
    $this->assertSame($before, $this->tree(), 'Nothing landed.');
    $this->assertNull($this->container->get('daedalus_blueprint.dialect_alias_map')->resolve($this->host($this->entity), 'conv-1', 'x'));
  }

  /**
   * A single-region layout needs no region and takes its one.
   *
   * Core's layout_onecol (what enabling Layout Builder seeds) carries one
   * region node, content: afterbegin/beforeend inside it need no region
   * (nothing is silently defaulted), and naming the region is accepted.
   */
  public function testSingleRegionSectionNeedsNoRegion(): void {
    [$onecol] = $this->appendLayoutSection('layout_onecol');

    $result = $this->operations->batchOperations($this->host($this->entity), [
      $this->insertOp('<pb-basic id="x" heading="X">.</pb-basic>', $onecol, 'beforeend'),
      $this->insertOp('<pb-basic id="y" heading="Y">.</pb-basic>', $onecol, 'afterbegin'),
      $this->insertOp('<pb-basic id="z" heading="Z">.</pb-basic>', $onecol, 'beforeend', 'content'),
    ], $this->options);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $aliases = $result->getAliases();
    $this->assertSame([$aliases['y'], $aliases['x'], $aliases['z']], $this->regionUuids($onecol, 'content'));
  }

}
