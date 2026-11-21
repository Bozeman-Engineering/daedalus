<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_edit\Form\ConfirmDiscardChangesForm;
use Drupal\daedalus_edit\Plugin\Sidebar\EntityFormSidebar;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
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
   * The route's form is the panel's widget host whatever mode is named.
   *
   * The controller of this route marks the form; the mode on the request is
   * the client's to set and decides nothing. With no mode, and with another
   * mode, the answer is still the open contract: the form's submit is the
   * Update button of the form transport, never the entity form's own save.
   */
  public function testFormIsTheWidgetHostInAnyMode(): void {
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, [
      'access inline editing',
      'access content',
      'edit any page content',
    ]);
    $http_kernel = $this->container->get('http_kernel');
    foreach (['', '?navigationMode=none', '?navigationMode=preview'] as $query) {
      $request = Request::create(
        sprintf('/daedalus-edit/entity-form/node/%s%s', $this->node->id(), $query),
        'POST',
        ['viewMode' => 'default', 'ajaxReturnForm' => '1'],
      );
      $response = $http_kernel->handle($request);
      $this->assertSame(200, $response->getStatusCode(), "The route answers with the query '$query'.");
      $this->assertOpenContract($response, $this->node);
    }
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
   * A route's entity access is judged on the saved entity.
   *
   * In Edit Mode the entity param converter answers a typed entity
   * parameter (the page itself, its stock edit form) with the unsaved
   * copy, for someone who may update the saved entity. The copy's own
   * values are unsaved edits like any other: a staged author or published
   * state neither opens a route to someone the saved entity refuses nor
   * closes it to someone the saved entity admits. (This endpoint's own
   * parameter is typed by a path placeholder, which core converts without
   * the swap; its access was always the saved entity's.)
   */
  public function testRouteAccessIsJudgedOnTheSavedEntity(): void {
    $permissions = [
      'access content',
      'edit own page content',
      'access inline editing',
      'use daedalus ui edit mode',
    ];
    // The first account created is the super user; neither actor is it.
    $this->createUser();
    $author = $this->createUser($permissions);
    $other = $this->createUser($permissions);
    $node = Node::create([
      'type' => 'page',
      'title' => 'Saved title',
      'uid' => $author->id(),
      'status' => 0,
    ]);
    $node->save();

    // The unsaved copy is published and names the other account as author.
    $staged = clone $node;
    $staged->setTitle('Staged title');
    $staged->setOwnerId($other->id());
    $staged->setPublished();
    $this->container->get('daedalus_tempstore.entity_tempstore_repository')->set($staged);

    // The request declares Edit Mode, as the page and its endpoints do.
    $request = Request::create('/node/' . $node->id(), 'GET', ['navigationMode' => 'edit']);
    $request->setSession($this->container->get('request_stack')->getSession());
    $this->container->get('request_stack')->push($request);
    $access_manager = $this->container->get('access_manager');
    $access_handler = $this->container->get('entity_type.manager')->getAccessControlHandler('node');
    $parameters = ['node' => $node->id()];

    $this->setCurrentUser($other);
    $this->assertFalse($access_manager->checkNamedRoute('entity.node.canonical', $parameters, $other), 'An unpublished page stays closed to a viewer whatever its unsaved copy says.');
    $this->assertFalse($access_manager->checkNamedRoute('entity.node.edit_form', $parameters, $other), 'The author an unsaved copy names is not the author.');

    $access_handler->resetCache();
    $this->setCurrentUser($author);
    $converted = $this->container->get('paramconverter_manager')->convert([
      'node' => $node->id(),
      '_route_object' => $this->container->get('router.route_provider')->getRouteByName('entity.node.canonical'),
    ]);
    $this->assertSame('Staged title', $converted['node']->label(), 'The route parameter is the unsaved copy for someone who may update the saved entity.');
    $this->assertTrue($access_manager->checkNamedRoute('entity.node.edit_form', $parameters, $author), 'The saved author keeps the route whatever the unsaved copy says.');
  }

  /**
   * A translation of the host finds the stage the host's writers share.
   *
   * The spine stages the entity as storage loads it. The form endpoint and
   * the Discard form are handed the entity in the language of their URL,
   * and address the same unsaved copy: the form shows that translation's
   * staged values, the discard ends the stage.
   */
  public function testTranslatedRouteFindsTheStage(): void {
    $this->enableModules(['language']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('kernel')->rebuildContainer();
    $this->container = $this->container->get('kernel')->getContainer();
    user_role_grant_permissions(RoleInterface::ANONYMOUS_ID, [
      'access content',
      'edit any page content',
      'access inline editing',
    ]);

    $node = Node::load($this->node->id());
    $node->addTranslation('fr', ['title' => 'Titre enregistré']);
    $node->save();
    $repository = $this->container->get('daedalus_tempstore.entity_tempstore_repository');

    // The writer's road: the default-language object, both languages
    // edited on the one working copy.
    $staged = clone Node::load($node->id());
    $staged->setTitle('Staged title');
    $staged->getTranslation('fr')->setTitle('Titre en attente');
    $repository->set($staged);

    $request = Request::create(
      sprintf('/fr/daedalus-edit/entity-form/node/%s?navigationMode=edit', $node->id()),
      'POST',
      ['viewMode' => 'default', 'ajaxReturnForm' => '1'],
    );
    $response = $this->container->get('http_kernel')->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $html = $this->assertOpenContract($response, $node);
    $this->assertStringContainsString('value="Titre en attente"', $html, 'The form on a translated URL is seeded from the staged translation.');
    $this->assertStringNotContainsString('value="Titre enregistré"', $html, 'The saved translation does not stand in for the staged one.');
    $this->assertStringNotContainsString('value="Staged title"', $html, 'The form is the translation the URL names.');

    // Discard, handed the translation its URL upcasts.
    $french = Node::load($node->id())->getTranslation('fr');
    $form_state = (new FormState())->addBuildInfo('args', ['node', $french]);
    $this->container->get('form_builder')->submitForm(ConfirmDiscardChangesForm::class, $form_state);
    $this->assertFalse($repository->has(Node::load($node->id())), 'A discard on a translated URL ends the stage.');
  }

  /**
   * Asserts the panel's open contract on an ajaxReturnForm answer.
   *
   * ONE append of the wrapped form into the EntityFormSidebar plugin's
   * form-cache mount (the client-side subject model takes it from there),
   * the wrapper carrying the cache-lookup identity, and the inline-editing
   * alter having run (the route's own form, in any mode): the save submit
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
