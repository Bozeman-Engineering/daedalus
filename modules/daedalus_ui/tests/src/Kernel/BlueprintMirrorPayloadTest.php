<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\daedalus_blueprint_api\Render\MutationRenderScope;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\daedalus_ui\EventSubscriber\BlueprintElementAnnotations;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Snapshot of the blueprint mirror's delivered envelope.
 *
 * The mirror envelope is the client's boot payload; every JS consumer
 * projects from it, so its shape is a public contract. This test pins the
 * whole payload: the identity keys beside the blueprint, and — the lean
 * node tree contract — the `_tree` the spine's NodeTreeBuilder distributes
 * over the annotated blueprint: kind-discriminated container/region/block
 * nodes, one child axis, no region/delta residue (no regions map, no
 * section_index, no view_mode/block_revision_id/label_display), the
 * classic layout_builder__layout shape still riding beside it for
 * consumers not yet ported. `_element` annotations ride the tree's nodes
 * (their content is locked by BlueprintElementAnnotatorTest, so the
 * snapshot strips them and asserts presence).
 */
#[CoversClass(BlueprintMirror::class)]
#[CoversClass(BlueprintElementAnnotations::class)]
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class BlueprintMirrorPayloadTest extends BlueprintKernelTestBase {

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
      'navigation',
      'twig_events',
      'daedalus_blueprint_api',
      'file',
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

    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('body', 'text_with_summary', 'text_section');
  }

  /**
   * The envelope carries identity, the blueprint, and the lean node tree.
   */
  public function testEnvelopeSnapshot(): void {
    $entity = $this->createTestEntity(['name' => 'Mirror page']);
    $root_uuid = 'aaaaaaaa-5555-4444-8888-000000000000';
    $block_uuid = 'aaaaaaaa-5555-4444-8888-000000000001';
    $nested_uuid = 'aaaaaaaa-5555-4444-8888-000000000002';
    $inner_uuid = 'aaaaaaaa-5555-4444-8888-000000000003';

    $inner = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Inner',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Inner</p>', 'format' => 'plain_text'],
    ]);
    $inner->save();
    $nested = $this->createSection('eb_test_container', [
      [
        'uuid' => $inner_uuid,
        'region' => 'content',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $inner, 'Inner'),
      ],
    ], ['label' => 'Nested band']);
    $nested->setThirdPartySetting('daedalus_layout', 'uuid', $nested_uuid);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'First',
      'reusable' => FALSE,
      'body' => ['value' => '<p>First</p>', 'format' => 'plain_text'],
    ]);
    $block->save();
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => $block_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'First'),
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $root_uuid);
    $section->appendComponent(SectionBlock::createComponent($nested, 'content', 1));

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $envelope = $this->container->get('daedalus_ui.blueprint_mirror')->envelope($entity, 'full');
    $this->assertIsString($envelope);
    $payload = Json::decode($envelope);

    // The identity keys beside the blueprint.
    $this->assertSame(
      ['entityType', 'entityId', 'uuid', 'label', 'langcode', 'viewMode', 'blueprint'],
      array_keys($payload),
    );
    $this->assertSame('entity_test', $payload['entityType']);
    $this->assertSame($entity->uuid(), $payload['uuid']);
    $this->assertSame('Mirror page', $payload['label']);
    $this->assertSame('full', $payload['viewMode']);

    $blueprint = $payload['blueprint'];
    // The classic layout shape still rides for consumers not yet ported.
    $this->assertArrayHasKey('layout_builder__layout', $blueprint);

    // `_element` annotations ride the delivered tree's owning nodes — the
    // tree is built over the ANNOTATED sections, so mirror consumers read
    // model facts and structure from one shape.
    $tree = $blueprint['_tree'];
    $this->assertArrayHasKey('_element', $tree[0]);
    $this->assertArrayHasKey('_element', $tree[0]['children'][0]);
    $this->assertArrayHasKey('_element', $tree[0]['children'][1]);

    // The lean node tree, pinned whole (annotations stripped — their
    // content is BlueprintElementAnnotatorTest's contract).
    $this->assertSame([
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'uuid' => $root_uuid,
        'children' => [
          [
            'kind' => 'block',
            'uuid' => $block_uuid,
            'block_type' => 'text_section',
            'label' => 'First',
            'block_fields' => [
              'info' => 'First',
              'body' => [
                'value' => '<p>First</p>',
                'format' => 'plain_text',
                'summary' => '',
              ],
            ],
            'label_rendered' => TRUE,
          ],
          [
            'kind' => 'container',
            'layout_id' => 'eb_test_container',
            'uuid' => $nested_uuid,
            'label' => 'Nested band',
            'children' => [
              [
                'kind' => 'block',
                'uuid' => $inner_uuid,
                'block_type' => 'text_section',
                'label' => 'Inner',
                'block_fields' => [
                  'info' => 'Inner',
                  'body' => [
                    'value' => '<p>Inner</p>',
                    'format' => 'plain_text',
                    'summary' => '',
                  ],
                ],
                'label_rendered' => TRUE,
              ],
            ],
          ],
        ],
      ],
    ], $this->stripAnnotations($tree));
  }

  /**
   * The materials roster rides the same render as the mirror.
   *
   * Attach() delivers both envelopes as JSON strings under
   * daedalusUi (blueprint, materials): the page-load half of the
   * materials delivery contract, {records} in the delta's record shape,
   * with the roster's own cache metadata merged into the render so a
   * material write invalidates the page that boots the store.
   */
  public function testAttachDeliversMaterialsBesideTheMirror(): void {
    $this->enableModules(['daedalus_blueprint_materials_test']);
    $entity = $this->createTestEntity(['name' => 'Mirror page']);

    $build = ['#cache' => ['tags' => ['entity_test:1']]];
    $this->container->get('daedalus_ui.blueprint_mirror')->attach($build, $entity, 'full');

    $settings = $build['#attached']['drupalSettings']['daedalusUi'];
    $this->assertSame(['blueprint', 'materials'], array_keys($settings));
    $this->assertIsString($settings['materials']);
    $materials = Json::decode($settings['materials']);
    $this->assertSame(['records'], array_keys($materials));
    $this->assertSame(
      $this->container->get('daedalus_blueprint.materials_delivery')->payload()['records'],
      $materials['records'],
    );
    $this->assertContains('lime_green', array_column($materials['records'], 'id'));
    $this->assertSame(
      [
        'kind',
        'id',
        'label',
        'description',
        'value',
        'group',
        'spellings',
        'scope',
        'applies',
        'owned_by',
        'published',
        'weight',
        'icon',
        'root_kind',
        'origin',
        'updatable',
        'design_system',
        'offered',
      ],
      array_keys($materials['records'][0]),
    );

    // The render carries the roster's invalidation beside its own.
    $this->assertContains('entity_test:1', $build['#cache']['tags']);
    $this->assertContains('materials_test_list', $build['#cache']['tags']);
    $this->assertContains('user.permissions', $build['#cache']['contexts']);
  }

  /**
   * A mutation response delivers the blueprint once: in the body.
   *
   * The response's whole-entity render runs the boot seam's entity view
   * build in edit mode, so attach() runs inside it. Under
   * daedalus_blueprint_api's mutation-render flag it delivers the materials
   * roster only: the settings command among the response's asset commands
   * carries `daedalusUi.materials` and no `daedalusUi.blueprint`,
   * while `payload['blueprint']` is present. The flag never outlives the
   * render.
   */
  public function testMutationResponseDeliversNoSettingsEnvelope(): void {
    $entity = $this->createTestEntity(['name' => 'Mirror page']);

    // Edit mode on the entity's own route, the way the boot render sees a
    // page: the mode cookie, the route entity, and the page state that lets
    // the attachments processor diff assets into commands.
    $request = Request::create('/daedalus-blueprint/entity_test/' . $entity->id(), 'POST', [], ['navigationMode' => 'edit']);
    $request->attributes->set('ajax_page_state', ['libraries' => 'core/drupal', 'theme' => 'stark', 'theme_token' => '']);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.entity_test.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/entity_test/{entity_test}'));
    $request->attributes->set('entity_test', $entity);
    $request->attributes->set('_raw_variables', new InputBag(['entity_test' => $entity->id()]));
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $response = $this->container->get('daedalus_blueprint_api.mutation_response_builder')
        ->build($request, $entity, BlueprintResult::success($entity), NULL, [], 'full');
    }
    finally {
      $request_stack->pop();
    }
    $payload = Json::decode($response->getContent());

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $payload['warnings'], 'The edit-mode render succeeded.');
    $this->assertArrayHasKey('entity:' . $entity->uuid(), $payload['markup']);
    // The body half: present and annotated.
    $this->assertIsArray($payload['blueprint']);
    $this->assertArrayHasKey('_element', $payload['blueprint']);

    $settings = array_values(array_filter($payload['attachments'], static fn (array $command): bool => $command['command'] === 'settings'));
    $this->assertCount(1, $settings, 'The render bubbled one settings command.');
    // attach() ran (the roster rode) and stood down for the blueprint.
    $this->assertSame(['materials'], array_keys($settings[0]['settings']['daedalusUi']));
    $this->assertSame(['records'], array_keys(Json::decode($settings[0]['settings']['daedalusUi']['materials'])));
    $this->assertFalse(MutationRenderScope::isActive($request));
    $this->assertFalse($request->attributes->has(MutationRenderScope::ATTRIBUTE));
  }

  /**
   * The subscriber strips a settings envelope a render still delivered.
   *
   * The belt to the flag's braces: the key goes, the command stays with
   * its ajaxPageState and the materials roster, every other command is
   * untouched, and a payload without a blueprint (nothing to annotate)
   * is still stripped.
   */
  public function testMutationResponseSubscriberStripsTheSettingsEnvelope(): void {
    $entity = $this->createTestEntity(['name' => 'Mirror page']);
    $request = Request::create('/daedalus-blueprint/entity_test/' . $entity->id(), 'POST');
    $payload = [
      'success' => TRUE,
      'blueprint' => [],
      'attachments' => [
        ['command' => 'add_css', 'data' => [['href' => '/a.css', 'rel' => 'stylesheet']]],
        [
          'command' => 'settings',
          'settings' => [
            'ajaxPageState' => ['libraries' => 'core/drupal'],
            'daedalusUi' => ['blueprint' => '{"uuid":"stale"}', 'materials' => '{"records":[]}'],
          ],
          'merge' => TRUE,
        ],
      ],
    ];
    $event = new BlueprintMutationResponseEvent($request, $entity, BlueprintResult::success($entity), $payload);
    $this->container->get('event_dispatcher')->dispatch($event);

    $expected = $payload;
    unset($expected['attachments'][1]['settings']['daedalusUi']['blueprint']);
    $this->assertSame($expected, $event->getPayload());
  }

  /**
   * Removes `_element` annotations recursively.
   */
  protected function stripAnnotations(array $nodes): array {
    foreach ($nodes as &$node) {
      if (is_array($node)) {
        unset($node['_element']);
        $node = $this->stripAnnotations($node);
      }
    }
    return $nodes;
  }

}
