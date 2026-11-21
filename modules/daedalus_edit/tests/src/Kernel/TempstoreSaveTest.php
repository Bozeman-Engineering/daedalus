<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_edit\Controller\Tempstore;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_edit\FormCommitOutcome;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * destination is the canonical URL under the new alias.
 *
 * The route is a POST carrying the session token and the controller answers
 * JSON: the destination on a commit, the commit door's messages when it
 * refuses, the stage and the stored row left as they were.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class TempstoreSaveTest extends FormCommitTestBase {

  /**
   * {@inheritdoc}
   *
   * The path widget needs both modules: path_alias owns the alias entity,
   * path adds the computed `path` field and its widget to nodes. field_ui
   * builds the manage-layout route a template is edited on.
   */
  protected static $modules = ['path_alias', 'path', 'field_ui'];

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
    $response = $this->save($node, $source);

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

    // The answer names the canonical page, recomputed under the new alias,
    // and carries the path-scoped cookie that drops Edit Mode.
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['destination' => '/new-alias'], $this->answer($response), 'The answer is the canonical URL recomputed under the new alias.');
    $cookies = $response->headers->getCookies();
    $this->assertCount(1, $cookies);
    $this->assertSame('navigationMode', $cookies[0]->getName());
    $this->assertSame('none', $cookies[0]->getValue());
    $this->assertSame('/new-alias', $cookies[0]->getPath(), 'The cookie is scoped to the saved host\'s editing path: a node\'s canonical URL under its fresh alias.');
  }

  /**
   * A site served under a sub-path honors a routed destination.
   *
   * The top bar sends the browser's pathname as the destination, which
   * carries the base path when the site is served under one (drupal.org's
   * runner serves it under /web/). The controller resolves that path to a
   * route and answers the route's URL; read with the base path still on it,
   * every destination looks unrouted and the save redirects to the saved
   * entity's own page, which strands a header's or footer's editor on the
   * blueprint instead of the page they left from (SiteFrameBehaviorTest's
   * portal leg, red on drupal.org and green at the web root).
   */
  public function testSaveUnderSubPathHonorsTheDestination(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Framed',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();

    // The node's own edit form: routed, and not its canonical page, so the
    // controller must honor it as given.
    $destination = '/web/node/' . $node->id() . '/edit';
    $request = Request::create(
      'http://localhost/web/daedalus-edit/tempstore/save/node/' . $node->id() . '?destination=' . rawurlencode($destination),
      'POST',
      [],
      [],
      [],
      ['SCRIPT_NAME' => '/web/index.php', 'SCRIPT_FILENAME' => '/var/www/web/index.php'],
    );
    $this->assertSame('/web', $request->getBasePath(), 'The request is served under the /web sub-path.');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    // The answer is a generated URL, and the generator writes the base path
    // from the routing context, which a real request sets as it is routed.
    $this->container->get('router.request_context')->fromRequest($request);
    try {
      $response = Tempstore::create($this->container)->save($request, 'node', $node);
    }
    finally {
      $request_stack->pop();
    }

    $this->assertSame(['destination' => $destination], $this->answer($response), 'The answer honored the routed destination beneath the base path instead of recomputing the canonical page.');
  }

  /**
   * A destination that names no local route answers the saved page.
   *
   * The answer is a URL the controller generated: the node's canonical
   * page, whatever text the request carried.
   */
  #[DataProvider('unroutedDestinations')]
  public function testSavedPageAnswersItsCanonicalUrl(string $destination): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Kept',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();

    $response = $this->save($node, $destination);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['destination' => '/node/' . $node->id()], $this->answer($response), 'The answer is the saved page\'s canonical URL.');
    $this->assertStringNotContainsString('evil', $response->getContent());
    $this->assertStringNotContainsString('alert', $response->getContent());
  }

  /**
   * A destination that names no local route answers the saved template.
   *
   * A display has no canonical page; the answer is its editing surface,
   * core's manage-layout route, whatever text the request carried.
   */
  #[DataProvider('unroutedDestinations')]
  public function testSavedTemplateAnswersItsEditingUrl(string $destination): void {
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $editing_url = $this->container->get('daedalus_ui.ui')->getEditingUrl($display)->toString();
    $this->assertStringContainsString('/display/default/layout', $editing_url, 'The template is edited on the manage-layout route.');

    $response = $this->save($display, $destination);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(['destination' => $editing_url], $this->answer($response), 'The answer is the saved template\'s editing URL.');
    $this->assertStringNotContainsString('evil', $response->getContent());
    $this->assertStringNotContainsString('alert', $response->getContent());
  }

  /**
   * Destinations that are not a local path resolving to a route.
   */
  public static function unroutedDestinations(): array {
    return [
      'a scheme that is not a path' => ['javascript:alert(1)'],
      'a control character after the slash' => ["/\t/evil.example"],
      'a backslash after the slash' => ['/\\evil.example'],
      'two leading slashes' => ['//evil.example'],
      'an absolute URL' => ['https://evil.example/'],
      'a local path no route answers' => ['/no/such/page/evil'],
      'no destination' => [''],
    ];
  }

  /**
   * A routed destination is answered as generated, its query included.
   *
   * A template's Save honors the page the editor named, and the answer is
   * the route's URL as the generator writes it, not the request's text.
   */
  public function testSavedTemplateHonorsRoutedDestination(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Left from',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');

    $response = $this->save($display, '/node/' . $node->id() . '?page=2&x=a b');

    $this->assertSame(['destination' => '/node/' . $node->id() . '?page=2&x=a%20b'], $this->answer($response), 'The answer is the destination route\'s generated URL.');
  }

  /**
   * A page saved elsewhere after staging is refused with the door's message.
   */
  public function testSaveAnswersTheRefusalMessages(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Contended',
      'field_subtitle' => 'Staged from here',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $source = '/node/' . $node->id();

    // Stage a change: the working copy is taken from the stored revision.
    $host = $this->workingHost($node);
    $working = $host->entity();
    assert($working instanceof NodeInterface);
    $working->set('title', 'Staged title');
    $this->container->get('daedalus_blueprint.storage_handler')->persist($host);
    $tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->assertTrue($tempstore->has($node), 'The change is staged.');

    // The page is saved elsewhere: the stored entity moves to a new
    // revision the stage was not taken from.
    $elsewhere = $this->stored($node);
    $elsewhere->setTitle('Saved elsewhere');
    $elsewhere->setNewRevision();
    $elsewhere->save();

    $response = $this->save($node, $source);

    $this->assertSame(409, $response->getStatusCode(), 'A page saved elsewhere answers a conflict.');
    $answer = $this->answer($response);
    $this->assertSame(['reason', 'messages'], array_keys($answer), 'A refusal names its reason and its messages, and no destination.');
    $this->assertSame('moved', $answer['reason']);
    $this->assertCount(1, $answer['messages']);
    $this->assertStringContainsString('was saved elsewhere', $answer['messages'][0]);
    $this->assertSame([], $response->headers->getCookies(), 'A refusal sets no cookie: the editor stays in Edit Mode.');

    // Nothing was saved and the unsaved changes are still staged.
    $this->assertSame('Saved elsewhere', $this->stored($node)->getTitle(), 'The stored row is the save made elsewhere.');
    $this->assertTrue($tempstore->has($node), 'The stage survived the refusal.');
    $this->assertSame('Staged title', $this->working($node)->getTitle(), 'The working copy still holds the unsaved change.');
  }

  /**
   * A working copy that does not validate is refused, one message each.
   */
  public function testSaveAnswersValidationMessages(): void {
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Valid',
      'langcode' => 'en',
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();

    // Stage a title past the field's length, around the spine's own check.
    $host = $this->workingHost($node);
    $working = $host->entity();
    assert($working instanceof NodeInterface);
    $working->set('title', str_repeat('x', 300));
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($working);

    $response = $this->save($node, '/node/' . $node->id());

    $this->assertSame(422, $response->getStatusCode(), 'A working copy that does not validate answers unprocessable.');
    $answer = $this->answer($response);
    $this->assertSame('invalid', $answer['reason']);
    $this->assertNotEmpty($answer['messages']);
    $this->assertArrayNotHasKey('destination', $answer);
    $this->assertSame('Valid', $this->stored($node)->getTitle(), 'Nothing was saved.');
  }

  /**
   * The route takes a POST with the session token, and only that.
   */
  public function testSaveRouteIsTokenedPost(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('daedalus_edit.tempstore.save');
    $this->assertSame(['POST'], $route->getMethods(), 'Save answers POST only.');
    $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'), 'Save requires the session token in the request header.');
    $this->assertSame('access inline editing', $route->getRequirement('_permission'));
    $this->assertSame('entity.update', $route->getRequirement('_entity_access'));
  }

  /**
   * Posts Save for a host the way the top bar does.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The host on the route.
   * @param string $destination
   *   The edited page's path, sent as the destination.
   */
  private function save(EntityInterface $host, string $destination): JsonResponse {
    $type = $host->getEntityTypeId();
    $request = Request::create("/daedalus-edit/tempstore/save/$type/" . $host->id() . '?destination=' . rawurlencode($destination), 'POST');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      return Tempstore::create($this->container)->save($request, $type, $host);
    }
    finally {
      $request_stack->pop();
    }
  }

  /**
   * The decoded JSON answer.
   */
  private function answer(JsonResponse $response): array {
    return json_decode($response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
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
