<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_blueprint_library\EventSubscriber\BlueprintCanvasMode;
use Drupal\daedalus_blueprint_library\Form\BlueprintAddForm;
use Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator;
use Drupal\daedalus_ui\ModeState;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

/**
 * The two doors into direct editing: Open blueprint and Add blueprint.
 *
 * A saved blueprint is a page of its own, edited on its canvas. A linked
 * placement's menu opens that canvas through the mirror annotation
 * `_element.showing` (daedalus_ui's annotator copying the spine's one
 * answer for what a join shows, its portal present only when the actor
 * may update a content blueprint, judged by its type; the "Open
 * blueprint" verb reads nothing else), and the admin listing's Add
 * blueprint form composes one from scratch (the type picked on core's add
 * page, then label, description, scope, subject) and lands on the empty
 * canvas. Both land in Edit Mode: the form queues the cookie for its
 * redirect, and the canvas route itself enters Edit Mode on its first
 * visit (BlueprintCanvasMode), so a plain navigation there, the open verb's
 * included, lands armed. The payload, the form and the canvas's entry are
 * proven here; the browser smoke is BlueprintPlacementBehaviorTest's.
 */
#[CoversClass(BlueprintAddForm::class)]
#[CoversClass(BlueprintCanvasMode::class)]
#[CoversClass(BlueprintElementAnnotator::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintDoorsTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_blueprint_library_test'];

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['create section blueprint', 'edit any section blueprint', 'delete any section blueprint'];

  /**
   * A linked placement's showing carries the canvas url for an updater.
   *
   * A detached placement is a copy with provenance, the page's own: no
   * join, no showing, no door.
   */
  public function testOpenBlueprintAnnotation(): void {
    $blueprint = $this->createBlueprint(['label' => 'Card', 'machine_name' => 'card']);
    ['entity' => $entity, 'root_section' => $root] = $this->createHost();
    $host = $this->host($entity);
    $linked = $this->container->get('uuid')->generate();
    $result = $this->operations()->batchOperations($host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'card',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'place_blueprint',
        'blueprint' => 'card',
        'mode' => BlueprintOperationHandler::MODE_LINKED,
        'uuid' => $linked,
        'location' => ['anchor' => $root, 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $entity->save();

    $annotate = function () use ($entity, $linked): array {
      $host = $this->host($entity);
      $tree = $this->container->get('daedalus_ui.element_annotator')->annotateTree(
        $this->container->get('daedalus_blueprint.node_tree_builder')->build($host),
        $host,
      );
      $this->assertCount(2, $tree);
      [$plain, $card] = $tree;
      $node = array_values(array_filter($this->rootChildren($tree), static fn (array $node): bool => ($node['uuid'] ?? NULL) === $linked))[0];
      return [$plain, $card, $node];
    };
    [$plain, $card, $node] = $annotate();
    $this->assertArrayNotHasKey('showing', $plain['_element'], 'A plain container is no join.');
    $this->assertSame('card', $card['blueprint']);
    $this->assertArrayNotHasKey('showing', $card['_element'], 'A detached placement is the page\'s own copy: no join, no door.');
    $this->assertSame('default', $node['_element']['showing']['shows']);
    $this->assertSame('card', $node['_element']['showing']['blueprint']);
    $this->assertSame($blueprint->toUrl()->toString(), $node['_element']['showing']['portal']['url'], 'A linked content blueprint opens the blueprint\'s canvas (DaedalusUi::getEditingUrl, the canonical route).');

    // Without the grant the portal is absent (honest chrome: the canvas
    // route enforces for real).
    $this->setUpCurrentUser([], [], FALSE);
    [, , $node] = $annotate();
    $this->assertSame('default', $node['_element']['showing']['shows']);
    $this->assertNull($node['_element']['showing']['portal']);

    // A deleted blueprint's name is residue: the node shows nothing.
    $this->setUpCurrentUser([], ['edit any section blueprint'], FALSE);
    $blueprint->delete();
    [, $card, $node] = $annotate();
    $this->assertSame('card', $card['blueprint']);
    $this->assertSame([
      'shows' => 'nothing',
      'document' => NULL,
      'blueprint' => NULL,
      'portal' => NULL,
      'claimable' => FALSE,
    ], $node['_element']['showing']);
  }

  /**
   * The component nodes of a tree's sections, flattened through regions.
   */
  protected function rootChildren(array $tree): array {
    $children = [];
    foreach ($tree as $section) {
      foreach ($section['children'] ?? [] as $child) {
        if (($child['kind'] ?? NULL) === 'region') {
          array_push($children, ...$child['children']);
        }
        else {
          $children[] = $child;
        }
      }
    }
    return $children;
  }

  /**
   * The add form composes a blueprint from four facts and lands on the canvas.
   */
  public function testAddForm(): void {
    // The subject select offers the page-builder bundles: entity_test's
    // display is Layout Builder enabled by the base, the blueprint type's
    // own display never counts.
    $form_object = $this->container->get('entity_type.manager')->getFormObject('blueprint', 'add');
    $form_object->setEntity(Blueprint::create(['type' => 'section']));
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);
    // The built select carries the empty option ahead of the vocabulary.
    $this->assertSame(['', 'entity_test:entity_test'], array_keys($form['subject']['#options']));
    $this->assertSame('Test entity: Entity Test Bundle', $form['subject']['#options']['entity_test:entity_test']);
    $this->assertSame(['block' => 'Block', 'page' => 'Page'], array_map('strval', $form['scope']['#options']));
    $this->assertSame('Save and compose', (string) $form['actions']['submit']['#value']);
    $this->assertArrayNotHasKey('bundles', $form, 'The allow-list, the icon, the AI opt-out and status are the details form\'s.');
    $this->assertArrayNotHasKey('status', $form);

    // The submission: the four values copied onto the entity, saved, and
    // the redirect set (the form's own methods, as FormAPI's submit
    // handlers call them).
    $form_object = $this->container->get('entity_type.manager')->getFormObject('blueprint', 'add');
    $form_object->setEntity(Blueprint::create(['type' => 'section']));
    $form_state = new FormState();
    $form = $this->container->get('form_builder')->buildForm($form_object, $form_state);
    // Values are set after the build: the builder derives them from input.
    $form_state->setValues([
      'label' => 'From scratch',
      'description' => 'Composed on its own canvas.',
      'scope' => 'page',
      'subject' => 'entity_test:entity_test',
    ]);
    $form_object->validateForm($form, $form_state);
    $this->assertSame([], $form_state->getErrors(), 'The four facts validate.');
    $form_object->submitForm($form, $form_state);
    $form_object->save($form, $form_state);
    $blueprint = $form_object->getEntity();
    $this->assertFalse($blueprint->isNew());
    $this->assertSame('from_scratch', $blueprint->getMachineName());
    $this->assertSame('page', $blueprint->getScope());
    $this->assertSame('entity_test', $blueprint->getSubjectType());
    $this->assertSame('entity_test', $blueprint->getSubjectBundle());
    $this->assertSame('user', $blueprint->getOrigin());
    $this->assertTrue($blueprint->isPublished());
    $this->assertTrue($blueprint->get('layout_builder__layout')->isEmpty(), 'Born with an empty document: the canvas is where it is composed.');
    // Lands on the canvas, in Edit Mode: the same queued cookie daedalus_ui
    // sets on a created page's redirect, through its one door.
    $this->assertSame($blueprint->toUrl()->toString(), $form_state->getRedirect()->toString());
    $cookie = $this->container->get(ModeState::class)->takeQueuedModeCookie();
    $this->assertNotNull($cookie, 'The add form queued the mode cookie for its redirect.');
    $this->assertSame('navigationMode', $cookie->getName());
    $this->assertSame('edit', $cookie->getValue());
    $this->assertSame($blueprint->toUrl()->toString(), $cookie->getPath(), 'The cookie is scoped to the new blueprint\'s canvas.');

    // The subject must be one of the site's fieldable bundles; the form's
    // select is convenience over the entity's one rule.
    $form_object = $this->container->get('entity_type.manager')->getFormObject('blueprint', 'add');
    $form_object->setEntity(Blueprint::create(['type' => 'section']));
    $form_state = (new FormState())->setValues([
      'label' => 'Nope',
      'description' => 'No such subject.',
      'scope' => 'block',
      'subject' => 'node:page',
    ]);
    $this->container->get('form_builder')->submitForm($form_object, $form_state);
    $this->assertNotSame([], $form_state->getErrors(), 'An unknown option is refused by the select itself.');

    // A page-builder bundle added later is offered too.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $this->assertEqualsCanonicalizing(
      ['entity_test:entity_test', 'entity_test_rev:entity_test_rev'],
      array_keys($this->container->get('daedalus_blueprint_library.subjects')->options()),
    );
  }

  /**
   * The canvas route enters Edit Mode on its first visit, for an updater.
   */
  public function testCanvasEntersEditModeOnFirstVisit(): void {
    $blueprint = $this->createBlueprint(['label' => 'Card', 'machine_name' => 'card']);
    $other = $this->createBlueprint(['label' => 'Band', 'machine_name' => 'band']);
    $mode_state = $this->container->get(ModeState::class);
    $path = $blueprint->toUrl()->toString();

    // The first visit: this response renders in Edit Mode (the request's
    // cookie bag) and the browser is handed the path-scoped cookie.
    $request = $this->visitCanvas($blueprint);
    $this->assertSame('edit', $request->cookies->get('navigationMode'), 'The first visit renders the canvas in Edit Mode.');
    $this->assertSame('edit', $mode_state->getMode());
    $cookie = $mode_state->takeQueuedModeCookie();
    $this->assertNotNull($cookie, 'The first visit queued the mode cookie for the response.');
    $this->assertSame('edit', $cookie->getValue());
    $this->assertSame($path, $cookie->getPath(), 'The cookie is scoped to the blueprint\'s own canvas.');

    // The second visit honours whatever the browser carries (here nothing):
    // the user can leave Edit Mode on the canvas and stay out.
    $request = $this->visitCanvas($blueprint);
    $this->assertFalse($request->cookies->has('navigationMode'), 'A later visit is left as the browser sent it.');
    $this->assertNull($mode_state->takeQueuedModeCookie(), 'Nothing is queued twice in a session.');

    // Another blueprint's canvas is another first visit.
    $request = $this->visitCanvas($other);
    $this->assertSame('edit', $request->cookies->get('navigationMode'));
    $this->assertSame($other->toUrl()->toString(), $mode_state->takeQueuedModeCookie()?->getPath());

    // A route that is not the canvas, and a sub-request, enter nothing.
    $request = $this->visitCanvas($blueprint, route: 'entity.blueprint.edit_form');
    $this->assertFalse($request->cookies->has('navigationMode'));
    $this->assertNull($mode_state->takeQueuedModeCookie());
    $fresh = $this->createBlueprint(['label' => 'Hero', 'machine_name' => 'hero_content']);
    $request = $this->visitCanvas($fresh, main: FALSE);
    $this->assertFalse($request->cookies->has('navigationMode'));
    $this->assertNull($mode_state->takeQueuedModeCookie());

    // A viewer without update access gets the page as it is: no mode, no
    // cookie (and no session started for them).
    $this->setUpCurrentUser([], [], FALSE);
    $request = $this->visitCanvas($fresh);
    $this->assertFalse($request->cookies->has('navigationMode'), 'A viewer is not put into Edit Mode.');
    $this->assertNull($mode_state->takeQueuedModeCookie());
  }

  /**
   * Hands the subscriber the request event of a visit to a blueprint route.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request as the listeners left it.
   */
  private function visitCanvas(Blueprint $blueprint, string $route = 'entity.blueprint.canonical', bool $main = TRUE): Request {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create($blueprint->toUrl()->toString());
    $request->setSession($request_stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/blueprint/{blueprint}'));
    $request->attributes->set('blueprint', $blueprint);
    $request_stack->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
    $this->container->get('daedalus_blueprint_library.canvas_mode')->onRequest(
      new RequestEvent($this->container->get('http_kernel'), $request, $main ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::SUB_REQUEST),
    );
    return $request;
  }

}
