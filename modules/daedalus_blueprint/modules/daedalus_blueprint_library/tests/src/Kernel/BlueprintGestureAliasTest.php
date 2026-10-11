<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\Materials\BlueprintMaterialsProvider;
use Drupal\layout_builder\Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The blueprint gestures are page-language ops: aliases address the element.
 *
 * Dialect v0.3: the blueprint gestures ride page_edit beside the DOM ops
 * as the blueprint kind of the material gesture family (save_material /
 * update_material, dispatched to the blueprint provider), so "element"
 * takes what the page language spells: a printed uuid or a live
 * data-alias, resolved through the spine's alias map for the batch's
 * conversation. Capturing an element the same batch just inserted by its
 * alias is the composing gesture the brief's MINT line teaches.
 */
#[CoversClass(MaterialGestureOperationHandler::class)]
#[CoversClass(BlueprintMaterialsProvider::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintGestureAliasTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['create section blueprint', 'edit any section blueprint', 'delete any section blueprint'];

  /**
   * An inserted element is captured by its alias in the same batch.
   */
  public function testGesturesResolveAliases(): void {
    ['entity' => $entity, 'root_section' => $root] = $this->createHost();
    $registry = $this->container->get('daedalus_blueprint.operation_handler_registry');
    $this->assertSame([
      'insert',
      'replace',
      'remove',
      'move',
      'set',
      'style',
      'file',
      'detach_blueprint',
      'save_material',
      'update_material',
      'remove_material',
    ], $registry->getPageLanguageOperations(), 'The page language op set is the DOM vocabulary, the file declaration, the blueprint detach and the material gesture family.');

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => '<pb-container id="hero"></pb-container>',
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => 'hero',
        'label' => 'Hero',
        'description' => 'The hero band',
        'offered' => FALSE,
      ],
    ], $this->dialectOptions());
    $this->assertBatchSuccess($result);
    $hero_uuid = $result->getAliases()['hero'];

    $blueprint = $this->loadBlueprint('hero');
    $this->assertSame('container', $this->definitionOf($blueprint)[0]['kind']);
    $this->assertFalse($blueprint->isOffered(), 'The save gesture carries the AI opt-out.');

    // A later turn pushes the same element by alias into the blueprint.
    $update = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => $blueprint->uuid(), 'element' => 'hero', 'offered' => TRUE],
    ], $this->dialectOptions());
    $this->assertBatchSuccess($update);
    $this->assertTrue($this->loadBlueprint('hero')->isOffered(), 'The update gesture flips the AI opt-out.');
    $refused = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => $blueprint->uuid(), 'offered' => 'no'],
    ], $this->dialectOptions());
    $this->assertFalse($refused->isSuccess());
    $this->assertSame('INVALID_PARAMETER', $refused->getErrors()[0]->getCode());

    // A uuid still works, and an alias nobody minted is refused as such.
    $by_uuid = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => $blueprint->uuid(), 'element' => $hero_uuid],
    ], $this->dialectOptions());
    $this->assertBatchSuccess($by_uuid);
    $unknown = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => 'ghost', 'label' => 'Ghost', 'description' => ''],
    ], $this->dialectOptions());
    $this->assertFalse($unknown->isSuccess());
    $this->assertSame('UNKNOWN_REF', $unknown->getErrors()[0]->getCode());
  }

  /**
   * Blueprint="" on a written element lands the provenance stamp at birth.
   *
   * The print carries a placement root's blueprint as an attribute; a
   * rewrite that keeps it (a replace, an insert of the printed band) must
   * land the same stamp place_blueprint writes (a section's third-party
   * settings), so the update verb and the owned-variant wear rule keep
   * targeting the copy. The parser judges the claim against the
   * container-only rule on the real batch door too (a block may claim no
   * blueprint), and set cannot patch provenance after birth.
   */
  public function testProvenanceRidesTheWrite(): void {
    ['entity' => $entity, 'root_section' => $root] = $this->createHost();

    // A container blueprint saved by alias; a block is refused as a root.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => '<pb-container id="hero"></pb-container>',
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
      [
        'op' => 'insert',
        'html' => '<pb-block type="basic" id="quote"/>',
        'at' => ['ref' => $root, 'position' => 'beforeend'],
      ],
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => 'hero',
        'label' => 'Hero',
        'description' => 'The hero band',
      ],
    ], $this->dialectOptions());
    $this->assertBatchSuccess($result);
    $hero = $this->loadBlueprint('hero')->getMachineName();
    $refused = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => 'quote',
        'label' => 'Quote',
        'description' => 'One quoted block',
      ],
    ], $this->dialectOptions());
    $this->assertFalse($refused->isSuccess());
    $this->assertSame('BLUEPRINT_ROOT_NOT_CONTAINER', $refused->getErrors()[0]->getCode());
    $this->assertSame($root, $refused->getErrors()[0]->getContext()['container'], 'The corrective names the block\'s container.');

    // A written band claiming its provenance: the stamp lands on the fresh
    // root, exactly where place_blueprint writes it.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => sprintf('<pb-container id="copy" blueprint="%s"></pb-container>', $hero),
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
    ], $this->dialectOptions(with_roster: TRUE));
    $this->assertBatchSuccess($result);
    $copy = $result->getAliases()['copy'];
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $stamp = NULL;
    foreach ($entity->get('layout_builder__layout') as $item) {
      $section = $item->section;
      if ($section instanceof Section && $adapter->getSectionUuid($section) === $copy) {
        $stamp = $section->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint');
      }
    }
    $this->assertSame($hero, $stamp);

    // The lean tree surfaces the stamp as the node's `blueprint` key, the
    // read surface the printer turns back into blueprint="" (pinned
    // byte-exact in DialectSpecTest), so the copy round-trips as placed.
    $nodes = [];
    $walk = static function (array $list) use (&$walk, &$nodes): void {
      foreach ($list as $node) {
        if (isset($node['uuid'])) {
          $nodes[$node['uuid']] = $node;
        }
        $walk($node['children'] ?? []);
      }
    };
    $walk($this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($entity)));
    $this->assertSame($hero, $nodes[$copy]['blueprint']);

    // A block claiming the blueprint is refused on the batch door with the
    // parser's code; nothing lands.
    $mismatch = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => sprintf('<pb-block type="basic" id="wrong" blueprint="%s"/>', $hero),
        'at' => ['ref' => $root, 'position' => 'beforeend'],
      ],
    ], $this->dialectOptions(with_roster: TRUE));
    $this->assertFalse($mismatch->isSuccess());
    $this->assertSame('BLUEPRINT_MISMATCH', $mismatch->getErrors()[0]->getCode());

    // Provenance is a birth fact: set cannot patch or clear it.
    $patch = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'set', 'ref' => $copy, 'attrs' => ['blueprint' => NULL]],
    ], $this->dialectOptions(with_roster: TRUE));
    $this->assertFalse($patch->isSuccess());
    $this->assertSame('READONLY_ATTR', $patch->getErrors()[0]->getCode());
    $this->assertSame('attrs.blueprint', $patch->getErrors()[0]->getPath());
  }

}
