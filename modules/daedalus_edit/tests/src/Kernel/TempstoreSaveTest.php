<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\daedalus_edit\Controller\Tempstore;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_edit\FormCommitOutcome;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the top bar's Save as an adapter over the commit door.
 *
 * The regression this pins: a URL alias changed in the Page form and staged
 * through the form transport was dropped at Save, because the controller
 * copied fields from the staged entity onto a fresh load and skipped
 * computed fields and entity keys (`path`, `status`, `uid`), exactly the
 * advanced-pane fields the form path exists for. The controller now hands
 * the working copy to EntityStorageHandler::commit() as it is: the alias
 * and the published flag land in the database, the stage is gone, the
 * redirect is the canonical URL under the new alias.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class TempstoreSaveTest extends FormCommitTestBase {

  /**
   * {@inheritdoc}
   *
   * The path widget needs both modules: path_alias owns the alias entity,
   * path adds the computed `path` field and its widget to nodes.
   */
  protected static $modules = ['path_alias', 'path'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');

    // The path widget validates the alias's source path through the router
    // (its PathAlias entity's ValidPath constraint) and derives that source
    // from the node's canonical URL, so the routes must exist in the
    // router table.
    $this->container->get('router.builder')->rebuild();

    // The advanced panes on the plain form: the alias widget and the
    // published checkbox.
    $display = EntityFormDisplay::load('node.plain.default');
    $display
      ->setComponent('path', ['type' => 'path'])
      ->setComponent('status', ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]])
      ->save();

    // The path widget is on the form only for a user who may create
    // aliases, and it validates the alias's source through the
    // access-checked path validator (viewing the node needs `access
    // content`); the published flag is editable only by a node
    // administrator (the spine checks field edit access on every write);
    // and the op door judges update access on the host before any field
    // (`administer nodes` is not node edit access).
    $this->setUpCurrentUser([], [
      'access inline editing',
      'use daedalus ui edit mode',
      'access content',
      'edit any plain content',
      'create url aliases',
      'administer nodes',
    ]);
  }

  /**
   * Save commits the staged alias and published flag, and ends the stage.
   */
  public function testSaveCommitsTheWorkingCopy(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Aliased',
      'field_subtitle' => 'Sub',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
      'path' => ['alias' => '/old-alias'],
    ]);
    $node->save();
    $source = '/node/' . $node->id();
    $this->assertSame('/old-alias', $this->aliasOf($source), 'The fixture node has its alias row.');
    $revisions_before = $this->revisionCount($node);

    // Stage the alias through the form transport: the path widget's own
    // submit, the one named field reaching the spine.
    [$form_object, $form_state] = $this->nodeFormState($this->working($node), [
      'path' => [['alias' => '/new-alias']],
      FormCommit::FIELD_INPUT => 'path',
    ]);
    $this->submitAndAnswer($form_object, $form_state, $this->formRequest($node, '/daedalus-edit/entity-form/node/' . $node->id()));
    $this->assertFalse($form_state->hasAnyErrors(), 'The path widget accepted the alias. ' . print_r(array_map('strval', $form_state->getErrors()), TRUE));
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame('/new-alias', $this->working($node)->get('path')->alias, 'The working copy holds the new alias.');
    $this->assertSame('/old-alias', $this->aliasOf($source), 'Staging never saves: the alias row is unchanged.');

    // Stage the published flag through the same transport: an explicit
    // NULL is how a programmatic submit unchecks a checkbox (the value
    // callback reads an unset input as unchecked), and the boolean widget
    // handles its values itself, so there is no delta in the path.
    [$form_object, $form_state] = $this->nodeFormState($this->working($node), [
      'status' => ['value' => NULL],
      FormCommit::FIELD_INPUT => 'status',
    ]);
    $this->submitAndAnswer($form_object, $form_state, $this->formRequest($node, '/daedalus-edit/entity-form/node/' . $node->id()));
    $this->assertFalse($form_state->hasAnyErrors(), 'The published checkbox accepted the flag. ' . print_r(array_map('strval', $form_state->getErrors()), TRUE));
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $diagnostics = print_r([
      'result' => $outcome->result->toArray(),
      'persisted' => $outcome->persisted,
      'form_value' => $form_state->getValue('status'),
      'form_entity_published' => $form_state->getFormObject()->getEntity()->isPublished(),
    ], TRUE);
    $this->assertTrue($outcome->result->isSuccess(), $diagnostics);
    $this->assertSame('tempstore', $outcome->persisted, $diagnostics);
    $this->assertFalse($this->working($node)->isPublished(), 'The working copy is unpublished. ' . $diagnostics);
    $this->assertTrue($this->stored($node)->isPublished(), 'Staging never saves: the stored row is published.');
    $tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->assertTrue($tempstore->has($node), 'Both commits live in one stage.');

    // Save through the controller, with the request the top bar sends: the
    // one host on the route ({entity_type}/{entity}) and the edited page as
    // the destination.
    $request = Request::create('/daedalus-edit/tempstore/save/node/' . $node->id(), 'GET', ['destination' => $source]);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $response = Tempstore::create($this->container)->save($request, 'node', $node);
    }
    finally {
      $request_stack->pop();
    }

    // The stored row is the working copy: the alias (and its path_alias
    // row) and the published flag landed, no field filtered.
    $stored = $this->stored($node);
    $this->assertSame('/new-alias', $stored->get('path')->alias, 'Save committed the staged alias.');
    $this->assertSame('/new-alias', $this->aliasOf($source), 'The path_alias row carries the new alias.');
    $this->assertFalse($stored->isPublished(), 'Save committed the staged published flag.');
    $this->assertSame($revisions_before + 1, $this->revisionCount($node), 'The door applied the bundle revision policy: one new revision.');

    // The stage is gone: the next load answers the row.
    $this->assertFalse($tempstore->has($stored), 'The stage died with the save.');
    $this->assertFalse($this->working($node)->isPublished(), 'The working copy is now the stored row.');

    // The redirect is the canonical page, recomputed under the new alias,
    // with the path-scoped cookie that drops Edit Mode.
    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('/new-alias', $response->getTargetUrl(), 'The redirect recomputed the canonical URL under the new alias.');
    $cookies = $response->headers->getCookies();
    $this->assertCount(1, $cookies);
    $this->assertSame('navigationMode', $cookies[0]->getName());
    $this->assertSame('none', $cookies[0]->getValue());
    $this->assertSame('/new-alias', $cookies[0]->getPath(), 'The cookie is scoped to the saved host\'s editing path: a node\'s canonical URL under its fresh alias.');
  }

  /**
   * The alias stored for a system path, or NULL when none.
   */
  private function aliasOf(string $source): ?string {
    $row = $this->container->get('path_alias.repository')->lookupBySystemPath($source, 'en');
    return $row['alias'] ?? NULL;
  }

  /**
   * The number of revisions the node has in storage.
   */
  private function revisionCount(NodeInterface $node): int {
    return count($this->container->get('entity_type.manager')->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', $node->id())
      ->execute());
  }

}
