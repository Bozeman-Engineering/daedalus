<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint_stencil\Materials\StencilMaterialsProvider;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;
use Drupal\layout_builder\Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The stencil gestures are page-language ops: aliases address the element.
 *
 * Dialect v0.3: the stencil gestures ride page_edit beside the DOM
 * ops as the stencil kind of the material gesture family (save_material /
 * update_material, dispatched to the stencil provider), so "element"
 * takes what the page language spells: a printed uuid or a live
 * data-alias, resolved through the spine's alias map for the batch's
 * conversation. Capturing an element the same batch just inserted by its
 * alias is the composing gesture the brief's MINT line teaches.
 */
#[CoversClass(MaterialGestureOperationHandler::class)]
#[CoversClass(StencilMaterialsProvider::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilGestureAliasTest extends StencilKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['save stencils'];

  /**
   * The page-language request options: the site vocabulary and the thread.
   *
   * @param bool $with_roster
   *   TRUE to carry the live materials roster (the stencils saved so far),
   *   rebuilt from storage, so a fragment can spell stencil="" against it.
   */
  protected function options(bool $with_roster = FALSE): array {
    $roster = NULL;
    if ($with_roster) {
      $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
      $builder->reset();
      $roster = $builder->build();
    }
    return [
      'dialect_catalog' => new DialectCatalog(
        containerLayoutId: 'layout_onecol',
        axes: [],
        blockTypes: [],
        conditions: [],
        roster: $roster,
      ),
      'conversation' => 'conv-1',
    ];
  }

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
      'save_material',
      'update_material',
      'remove_material',
    ], $registry->getPageLanguageOperations(), 'The page language op set is the DOM vocabulary, the file declaration and the material gesture family.');

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'insert',
        'html' => '<pb-container id="hero"></pb-container>',
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => 'hero',
        'label' => 'Hero',
        'description' => 'The hero band',
        'offered' => FALSE,
      ],
    ], $this->options());
    $this->assertBatchSuccess($result);
    $hero_uuid = $result->getAliases()['hero'];

    $stencils = $this->container->get('entity_type.manager')->getStorage('stencil')->loadByProperties(['label' => 'Hero']);
    $this->assertCount(1, $stencils);
    $stencil = reset($stencils);
    $this->assertSame('container', $stencil->getElement()['kind']);
    $this->assertFalse($stencil->isOffered(), 'The save gesture carries the AI opt-out.');

    // A later turn pushes the same element by alias into the stencil.
    $update = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => $stencil->uuid(), 'element' => 'hero', 'offered' => TRUE],
    ], $this->options());
    $this->assertBatchSuccess($update);
    $this->assertTrue($this->container->get('entity_type.manager')->getStorage('stencil')->loadUnchanged($stencil->id())->isOffered(), 'The update gesture flips the AI opt-out.');
    $refused = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => $stencil->uuid(), 'offered' => 'no'],
    ], $this->options());
    $this->assertFalse($refused->isSuccess());
    $this->assertSame('INVALID_PARAMETER', $refused->getErrors()[0]->getCode());

    // A uuid still works, and an alias nobody minted is refused as such.
    $by_uuid = $this->operations()->batchOperations($entity, [
      ['op' => 'update_material', 'kind' => 'stencil', 'id' => $stencil->uuid(), 'element' => $hero_uuid],
    ], $this->options());
    $this->assertBatchSuccess($by_uuid);
    $unknown = $this->operations()->batchOperations($entity, [
      ['op' => 'save_material', 'kind' => 'stencil', 'element' => 'ghost', 'label' => 'Ghost', 'description' => ''],
    ], $this->options());
    $this->assertFalse($unknown->isSuccess());
    $this->assertSame('UNKNOWN_REF', $unknown->getErrors()[0]->getCode());
  }

  /**
   * Stencil="" on a written element lands the provenance stamp at birth.
   *
   * The print carries a placement root's stencil as an attribute; a
   * rewrite that keeps it (a replace, an insert of the printed band) must
   * land the same stamp instantiate_stencil writes (a section's
   * third-party settings, a block's additional data), so the update verb
   * and the owned-variant wear rule keep targeting the copy. The parser
   * judges the claim against the stencil's root kind on the real batch
   * door too, and set cannot patch provenance after birth.
   */
  public function testProvenanceRidesTheWrite(): void {
    ['entity' => $entity, 'root_section' => $root] = $this->createHost();

    // One container-rooted and one block-rooted stencil, saved by alias.
    $result = $this->operations()->batchOperations($entity, [
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
        'kind' => 'stencil',
        'element' => 'hero',
        'label' => 'Hero',
        'description' => 'The hero band',
      ],
      [
        'op' => 'save_material',
        'kind' => 'stencil',
        'element' => 'quote',
        'label' => 'Quote',
        'description' => 'One quoted block',
      ],
    ], $this->options());
    $this->assertBatchSuccess($result);
    $storage = $this->container->get('entity_type.manager')->getStorage('stencil');
    $saved = $storage->loadByProperties(['label' => 'Hero']);
    $hero = reset($saved)->getMachineName();
    $saved = $storage->loadByProperties(['label' => 'Quote']);
    $quote = reset($saved)->getMachineName();

    // A written band and block claiming their provenance: both stamps land
    // on the fresh roots, exactly where instantiate_stencil writes them.
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'insert',
        'html' => sprintf('<pb-container id="copy" stencil="%s"></pb-container>', $hero),
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
      [
        'op' => 'insert',
        'html' => sprintf('<pb-block type="basic" id="copy_quote" stencil="%s"/>', $quote),
        'at' => ['ref' => $root, 'position' => 'beforeend'],
      ],
    ], $this->options(with_roster: TRUE));
    $this->assertBatchSuccess($result);
    $copy = $result->getAliases()['copy'];
    $copy_quote = $result->getAliases()['copy_quote'];
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $stamps = [];
    foreach ($entity->get('layout_builder__layout') as $item) {
      $section = $item->section;
      if ($section instanceof Section && $adapter->getSectionUuid($section) === $copy) {
        $stamps['copy'] = $section->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil');
      }
      foreach ($section instanceof Section ? $section->getComponents() : [] as $component) {
        if ($component->getUuid() === $copy_quote) {
          $stamps['copy_quote'] = $component->get(LayoutSerializer::STENCIL_PROVENANCE_KEY);
        }
      }
    }
    ksort($stamps);
    $this->assertSame(['copy' => $hero, 'copy_quote' => $quote], $stamps);

    // The lean tree surfaces both stamps as the nodes' `stencil` key, the
    // read surface the printer turns back into stencil="" (pinned
    // byte-exact in DialectSpecTest), so the copies round-trip as placed.
    $nodes = [];
    $walk = static function (array $list) use (&$walk, &$nodes): void {
      foreach ($list as $node) {
        if (isset($node['uuid'])) {
          $nodes[$node['uuid']] = $node;
        }
        $walk($node['children'] ?? []);
      }
    };
    $walk($this->container->get('daedalus_blueprint.node_tree_builder')->build($entity));
    $this->assertSame($hero, $nodes[$copy]['stencil']);
    $this->assertSame($quote, $nodes[$copy_quote]['stencil']);

    // A block claiming the container-rooted stencil is refused on the
    // batch door with the parser's code; nothing lands.
    $mismatch = $this->operations()->batchOperations($entity, [
      [
        'op' => 'insert',
        'html' => sprintf('<pb-block type="basic" id="wrong" stencil="%s"/>', $hero),
        'at' => ['ref' => $root, 'position' => 'beforeend'],
      ],
    ], $this->options(with_roster: TRUE));
    $this->assertFalse($mismatch->isSuccess());
    $this->assertSame('STENCIL_MISMATCH', $mismatch->getErrors()[0]->getCode());

    // Provenance is a birth fact: set cannot patch or clear it.
    $patch = $this->operations()->batchOperations($entity, [
      ['op' => 'set', 'ref' => $copy, 'attrs' => ['stencil' => NULL]],
    ], $this->options(with_roster: TRUE));
    $this->assertFalse($patch->isSuccess());
    $this->assertSame('READONLY_ATTR', $patch->getErrors()[0]->getCode());
    $this->assertSame('attrs.stencil', $patch->getErrors()[0]->getPath());
  }

}
