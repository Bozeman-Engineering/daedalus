<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_edit\Plugin\Sidebar\EntityFormSidebar;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the entity-form route (MultipleEntityFormController) end to end.
 *
 * The daedalus_edit.entity_form route is the entity-form half of the form fetch
 * spine: leaves without a declared form endpoint ride it, and the panel's
 * dumb door (icon, boot restore) fetches the top-level form through it. Its
 * contract has two halves this test pins through the real HTTP kernel:
 * - Access: the route is gated on the 'access inline editing' permission (the
 *   inline editing surface's one permission) AND the entity's own update
 *   access: the form is a widget host over the entity's staged values, so
 *   fetching it is an update-shaped read, and the surface permission alone
 *   must not open another user's entity.
 * - The tempstore-entity form build: the controller builds the entity's
 *   DEFAULT form around the TEMPSTORE version of the entity (stage, don't
 *   save: an open form must show the staged values, not the saved ones),
 *   runs it through the inline-editing form alter (the Update submit
 *   pipeline), and answers an ajaxReturnForm fetch with the panel's open
 *   contract — an append of the wrapped form into the EntityFormSidebar
 *   plugin's form-cache mount.
 *
 * Two hosts walk the door: a node (the page road) and a content blueprint
 * (its canvas enters Edit Mode on first visit and the panel's boot restore
 * fetches the blueprint's own form through the same route).
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class EntityFormRouteTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must be
   * listed explicitly or the container fails to compile. The blueprint entity
   * type lives in daedalus_blueprint_library; its icon field references
   * media and its scope is a list_string, so the media stack and options
   * ride along for the blueprint schema.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'image',
    'media',
    'options',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
    'daedalus_blueprint_library',
  ];

  /**
   * The node whose form the route builds.
   */
  protected Node $node;

  /**
   * The content blueprint whose form the route builds.
   */
  protected Blueprint $blueprint;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // system: date formats (the node form's meta area formats the changed
    // date). user: the anonymous role — the kernel-handled requests below
    // authenticate as anonymous (no session rides them), so the access
    // assertions grant and withhold the permission on that role.
    // daedalus_blueprint_library: the blueprint types (the per-type
    // permissions the blueprint's update access runs on) and the type's
    // Layout Builder display with its layout field.
    $this->installConfig(['system', 'user', 'daedalus_blueprint', 'daedalus_blueprint_library']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'Saved title']);
    $this->node->save();
    $this->blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Saved label',
      'description' => 'A quote beside a portrait.',
      'scope' => 'block',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
    ]);
    $this->blueprint->save();
  }

  /**
   * Builds the entity-form request the client-side fetch sends.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity whose form the fetch asks for.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   A POST to the route with the fetch payload (viewMode + ajaxReturnForm)
   *   and the edit-mode query parameter endpoint requests carry (the
   *   navigationMode cookie is path-scoped to the edited page).
   */
  protected function entityFormRequest(ContentEntityInterface $entity): Request {
    return Request::create(
      sprintf('/daedalus-edit/entity-form/%s/%s?navigationMode=edit', $entity->getEntityTypeId(), $entity->id()),
      'POST',
      ['viewMode' => 'default', 'ajaxReturnForm' => '1'],
    );
  }

  /**
   * The route is permission-gated and builds the tempstore entity's form.
   */
  public function testEntityFormRoute(): void {
    $http_kernel = $this->container->get('http_kernel');

    // Access: without 'access inline editing' the route is forbidden.
    $response = $http_kernel->handle($this->entityFormRequest($this->node));
    $this->assertSame(403, $response->getStatusCode(), 'The entity-form route denies a user without the inline editing permission.');

    // Stage a changed entity in tempstore: the form must be built around
    // THIS version, not the saved one.
    $staged = clone $this->node;
    $staged->setTitle('Staged title');
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    // The surface permission alone is not enough: the entity's update access
    // gates the form too.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access inline editing']);
    $response = $http_kernel->handle($this->entityFormRequest($this->node));
    $this->assertSame(403, $response->getStatusCode(), 'The entity-form route denies a user who may not update the entity.');

    // Node update access needs 'access content' beside the edit permission.
    // The access handler memoizes per entity and operation within the kernel,
    // so the denial above is reset before the grant is read.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access content', 'edit any page content']);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $response = $http_kernel->handle($this->entityFormRequest($this->node));
    $this->assertSame(200, $response->getStatusCode(), 'The permission plus update access on the entity opens the route.');

    $html = $this->assertOpenContract($response, $this->node);

    // The title widget is seeded from the TEMPSTORE entity — the staged
    // value, not the saved one.
    $this->assertStringContainsString('value="Staged title"', $html, 'The title widget is seeded from the tempstore entity.');
    $this->assertStringNotContainsString('value="Saved title"', $html, 'The saved value does not leak into the staged form.');
  }

  /**
   * A content blueprint is a host of the door: its default form builds.
   *
   * The blueprint's canvas enters Edit Mode on first visit and the panel's
   * boot restore fetches the top-level form through this route, so the
   * blueprint type carries a `default` form (its details form) the
   * controller can build, and the inline alter turns it into the panel's
   * widget host over the staged blueprint.
   */
  public function testBlueprintEntityFormRoute(): void {
    $http_kernel = $this->container->get('http_kernel');

    // The surface permission alone is not enough for a blueprint either:
    // its type's update permission gates the form.
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['access inline editing']);
    $response = $http_kernel->handle($this->entityFormRequest($this->blueprint));
    $this->assertSame(403, $response->getStatusCode(), 'The entity-form route denies a user who may not update the blueprint.');

    // Stage a changed blueprint in tempstore: the form must be built around
    // THIS version, not the saved one.
    $staged = clone $this->blueprint;
    $staged->set('label', 'Staged label');
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, ['edit any section blueprint']);
    $this->container->get('entity_type.manager')->getAccessControlHandler('blueprint')->resetCache();
    $response = $http_kernel->handle($this->entityFormRequest($this->blueprint));
    $this->assertSame(200, $response->getStatusCode(), 'The permission plus update access on the blueprint opens the route: the blueprint type carries a default form.');

    $html = $this->assertOpenContract($response, $this->blueprint);

    // The label widget is seeded from the TEMPSTORE blueprint.
    $this->assertStringContainsString('value="Staged label"', $html, 'The label widget is seeded from the tempstore blueprint.');
    $this->assertStringNotContainsString('value="Saved label"', $html, 'The saved value does not leak into the staged form.');
  }

  /**
   * Asserts the panel's open contract on an ajaxReturnForm answer.
   *
   * ONE append of the wrapped form into the EntityFormSidebar plugin's
   * form-cache mount (the client-side subject model takes it from there),
   * the wrapper carrying the cache-lookup identity, and the inline-editing
   * alter having run (the request declared edit mode): the save submit
   * became the hidden Update button of the tempstore pipeline, and no
   * hidden transport inputs ride the form (a commit names its field on the
   * request).
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The route's answer.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity the form was fetched for.
   *
   * @return string
   *   The appended form's markup, for the leg's own widget assertions.
   */
  protected function assertOpenContract(Response $response, ContentEntityInterface $entity): string {
    $this->assertInstanceOf(AjaxResponse::class, $response);
    $appends = array_values(array_filter(
      $response->getCommands(),
      fn (array $command) => $command['command'] === 'insert' && $command['method'] === 'append',
    ));
    $this->assertCount(1, $appends, 'The response carries exactly one append command.');
    $this->assertSame('#' . EntityFormSidebar::FORM_CACHE_ID, $appends[0]['selector'], 'The form is appended into the form-cache mount.');

    $html = (string) $appends[0]['data'];
    $this->assertStringContainsString(
      sprintf('data-daedalus-edit-form-id="%s::%s"', $entity->getEntityTypeId(), $entity->id()),
      $html,
      'The wrapper carries the form-cache lookup identity.',
    );
    $this->assertStringContainsString('daedalus-edit-update-button', $html, 'The submit is the inline-editing Update button.');
    $this->assertStringNotContainsString('empty-field', $html, 'No hidden transport inputs ride the form: a commit names its field on the request.');
    return $html;
  }

}
