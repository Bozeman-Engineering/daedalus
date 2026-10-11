<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_edit\PageTitleEditing;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the outside-rendered-label rule behind page-title inline editing.
 *
 * The entity's label field presents AS the root row (the element model's
 * derived edit target), but the label usually renders OUTSIDE the entity
 * as the theme's page-title element. PageTitleEditing decides when the
 * page title stands in for the label as its one in-place leaf: exactly
 * when the model names it AND the entity render itself does not produce
 * the field on the canonical page, so the title element is the label's
 * ONE canvas render (duplicate leaf identities would make the form-swap
 * and markup replacement ambiguous). The rule answers from proven render
 * truths only:
 * a node's non-configurable title (template_preprocess_node pulls it out of
 * page content) and a Layout Builder display's root fields (core strips
 * every display-configurable one); everything else stays inert. The same rule gates every delivery half, attribution
 * (preprocessPageTitle), the replacement render (titleRender) and the
 * mutation-response subscriber (onMutationResponse) that puts that render
 * on the op spine's contract under the label's key, so they agree by
 * construction.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class PageTitleEditingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must be
   * listed explicitly or the container fails to compile. daedalus_layout registers
   * the Layout Builder root-existence feeder the LB branch agrees with.
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
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
  ];

  /**
   * The service under test.
   */
  protected PageTitleEditing $pageTitleEditing;

  /**
   * The node whose label the page title may stand in for.
   */
  protected Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    // The whole-entity render behind the mutation response prints the
    // node's created date through the system date formats.
    $this->installConfig(['system']);
    $this->installEntitySchema('block_content');
    // The page-title attribution is stamped for permitted users only.
    $this->setUpCurrentUser([], ['access inline editing']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'Hello there']);
    $this->pageTitleEditing = $this->container->get('daedalus_edit.page_title_editing');
  }

  /**
   * The bundle's render display, fresh from storage.
   */
  protected function display(): EntityViewDisplay {
    return EntityViewDisplay::collectRenderDisplay($this->node, 'default');
  }

  /**
   * The proven render truths decide when the page title IS the label.
   */
  public function testOutsideLabelField(): void {
    // A node's title is not display configurable and never renders inside
    // the entity on its canonical page (template_preprocess_node moves it
    // to the `label` variable, printed only on non-page views): the page
    // title is the label's one render, plain display or not.
    $this->assertSame('title', $this->pageTitleEditing->outsideLabelField($this->node));

    // The same holds on a Layout Builder display (the site shape that
    // motivated the rule).
    $display = $this->display();
    $display->enableLayoutBuilder()->save();
    $this->assertSame('title', $this->pageTitleEditing->outsideLabelField($this->node));

    // A root without an edit target has nothing for the page title to
    // stand in for: block_content's label key (info) is declared
    // element: false, so the model names no label for the root at all.
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => FALSE])->save();
    $block = BlockContent::create(['type' => 'basic', 'info' => 'Admin label']);
    $this->assertNull($this->pageTitleEditing->outsideLabelField($block));
  }

  /**
   * The titleRender() method delivers the H1 only for the outside label.
   */
  public function testTitleRender(): void {
    // The update round-trip's replacement render: the same rule, keyed on
    // the field the update targets.
    $render = $this->pageTitleEditing->titleRender($this->node, 'title');
    $this->assertSame('page_title', $render['#type']);
    $this->assertSame('Hello there', $render['#title']);

    // Any other field updates inside the entity render: no title render.
    $this->assertNull($this->pageTitleEditing->titleRender($this->node, 'body'));
  }

  /**
   * A label mutation's response carries the attributed H1 under the label key.
   *
   * The whole round through the response builder, under the edit-mode
   * response convention every spine road establishes (the mode on the
   * request, the entity on the route match): the renderer's whole-entity
   * fallback (the label is outside the entity, so its key is missing from
   * the render) gives way to the page-title render alone, keyed exactly as
   * the canvas H1 is, so the client swaps the H1 and nothing else.
   */
  public function testMutationResponseCarriesThePageTitle(): void {
    $this->node->save();
    $this->node->setTitle('Renamed page');
    $label_key = SelectableKey::field($this->node->uuid(), 'title');
    $result = BlueprintResult::success($this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->node))
      ->withTargets([['uuid' => $this->node->uuid(), 'field_name' => 'title']]);

    $request = $this->mutationRequest($this->node);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $response = $this->container->get('daedalus_blueprint_api.mutation_response_builder')
        ->build($request, $this->node, $result, NULL, [], 'full');
    }
    finally {
      $request_stack->pop();
      $this->container->get('daedalus_ui.ui')->clearDerivedHost();
    }
    $payload = Json::decode($response->getContent());

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['warnings'], 'The render succeeded and the H1 was attributed.');
    $this->assertSame([$label_key], array_keys($payload['markup']), 'A label-only mutation swaps the H1 alone: the whole-entity fallback is dropped.');
    $h1 = $payload['markup'][$label_key];
    $this->assertStringStartsWith('<h1', $h1);
    $this->assertStringContainsString('data-selectable="' . $label_key . '"', $h1);
    $this->assertStringContainsString('class="daedalus-edit-field-value"', $h1);
    $this->assertStringContainsString(
      sprintf('data-daedalus-edit-id="node::page::%s::title::0::%s::string::string::value"', $this->node->id(), $this->node->language()->getId()),
      $h1,
    );
    $this->assertStringContainsString('Renamed page', $h1);
  }

  /**
   * The label key is claimed, never merged, and other keys are untouched.
   *
   * The renderer can find the label INSIDE the entity too (a theme printing
   * the label under a non-full view mode, or on a route where core's page
   * test fails) and deliver that inner render under the H1's key; the
   * subscriber overwrites it with the H1. When the label is one target
   * among several, the other keys (and the whole-entity fallback, which
   * may be standing in for one of them) stay.
   */
  public function testMutationResponseClaimsTheLabelKey(): void {
    $this->node->save();
    $uuid = $this->node->uuid();
    $label_key = SelectableKey::field($uuid, 'title');
    $body_key = SelectableKey::field($uuid, 'body');
    $entity_key = SelectableKey::entity($uuid);
    $targets = [
      ['uuid' => $uuid, 'field_name' => 'title'],
      ['uuid' => $uuid, 'field_name' => 'body'],
    ];

    // The double-key case: an inner label render under the H1's key.
    $payload = $this->dispatch($targets, [
      $label_key => '<div data-selectable="' . $label_key . '"><h2>Hello there</h2></div>',
      $body_key => '<div data-selectable="' . $body_key . '">Body</div>',
    ]);
    $this->assertSame([$label_key, $body_key], array_keys($payload['markup']));
    $this->assertStringStartsWith('<h1', $payload['markup'][$label_key]);
    $this->assertStringNotContainsString('<h2', $payload['markup'][$label_key]);
    $this->assertSame('<div data-selectable="' . $body_key . '">Body</div>', $payload['markup'][$body_key]);
    $this->assertSame([], $payload['warnings']);

    // The fallback case: the whole entity stands in for the body; it stays.
    $payload = $this->dispatch($targets, [$entity_key => '<article>whole</article>']);
    $this->assertSame([$entity_key, $label_key], array_keys($payload['markup']));
    $this->assertSame('<article>whole</article>', $payload['markup'][$entity_key]);
    $this->assertStringStartsWith('<h1', $payload['markup'][$label_key]);

    // A mutation that never touched the label leaves the payload alone,
    // and so does a failed one.
    $untouched = [$body_key => '<div data-selectable="' . $body_key . '">Body</div>'];
    $payload = $this->dispatch([['uuid' => $uuid, 'field_name' => 'body']], $untouched);
    $this->assertSame($untouched, $payload['markup']);
    $payload = $this->dispatch($targets, $untouched, success: FALSE);
    $this->assertSame($untouched, $payload['markup']);
  }

  /**
   * A render that came back unattributed is reported, not swapped in.
   *
   * Outside the edit-mode response convention the page title renders as a
   * live H1 with no leaf identity; delivering it would swap an inert
   * element over the canvas leaf, so the payload keeps its markup and says
   * why the title was left alone.
   */
  public function testMutationResponseReportsAnUnattributedTitle(): void {
    $this->node->save();
    $uuid = $this->node->uuid();
    $label_key = SelectableKey::field($uuid, 'title');
    $entity_key = SelectableKey::entity($uuid);
    $markup = [$entity_key => '<article>whole</article>'];

    $payload = $this->dispatch([['uuid' => $uuid, 'field_name' => 'title']], $markup, edit_mode: FALSE);
    $this->assertSame($markup, $payload['markup']);
    $this->assertCount(1, $payload['warnings']);
    $this->assertSame(PageTitleEditing::WARNING_UNATTRIBUTED, $payload['warnings'][0]['code']);
    $this->assertSame('markup', $payload['warnings'][0]['path']);
  }

  /**
   * Dispatches a mutation-response event for the node and returns the payload.
   *
   * @param array $targets
   *   The result's targets.
   * @param array $markup
   *   The payload's markup channel as the renderer left it.
   * @param bool $success
   *   Whether the result succeeded.
   * @param bool $edit_mode
   *   Whether the request carries the edit mode.
   *
   * @return array
   *   The payload after every subscriber ran.
   */
  protected function dispatch(array $targets, array $markup, bool $success = TRUE, bool $edit_mode = TRUE): array {
    $result = $success
      ? BlueprintResult::success($this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->node))->withTargets($targets)
      : BlueprintResult::semanticFailure([new BlueprintError('operations[0]', 'CONSTRAINT_VIOLATION', 'Rejected.')]);
    $request = $this->mutationRequest($this->node, $edit_mode);
    $event = new BlueprintMutationResponseEvent($request, $this->node, $result, [
      'success' => $success,
      'langcode' => NULL,
      'warnings' => [],
      'markup' => $markup,
    ]);
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $this->container->get('event_dispatcher')->dispatch($event);
    }
    finally {
      $request_stack->pop();
      $this->container->get('daedalus_ui.ui')->clearDerivedHost();
    }
    return $event->getPayload();
  }

  /**
   * A request shaped like the mutate endpoint's, the way the client sends it.
   *
   * The navigationMode cookie is path-scoped to the edited page, so every
   * spine road puts the mode on the query and the entity on the route
   * match (the endpoint's route parameter; daedalus_ai's MutatedTurn forces
   * the same shape); attribution derives the main entity from there.
   */
  protected function mutationRequest(Node $node, bool $edit_mode = TRUE): Request {
    $uri = '/daedalus-blueprint/node/' . $node->id() . ($edit_mode ? '?navigationMode=edit' : '');
    $request = Request::create($uri, 'POST');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'daedalus_blueprint_api.mutate');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/daedalus-blueprint/{entity_type}/{entity}'));
    $request->attributes->set('entity_type', 'node');
    $request->attributes->set('entity', $node);
    $request->attributes->set('_raw_variables', new InputBag(['entity_type' => 'node', 'entity' => $node->id()]));
    return $request;
  }

}
