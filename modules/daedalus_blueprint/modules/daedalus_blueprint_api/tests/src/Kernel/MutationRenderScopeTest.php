<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Render\MutationRenderScope;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint_api_render_test\RenderProbe;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * The mutation-render flag: raised for the render's span, never left set.
 *
 * A render-time delivery that belongs to a page load only (daedalus_ui's
 * drupalSettings blueprint envelope) reads the flag off the current request
 * from inside the entity render to stand down. The contract pinned here:
 * the flag is false outside SubtreeRenderer::render(), true inside it (as
 * hook_entity_view_alter sees it), and false again after a render that
 * threw, including the render failure MutationResponseBuilder degrades to
 * a warning.
 */
#[CoversClass(MutationRenderScope::class)]
#[CoversClass(SubtreeRenderer::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class MutationRenderScopeTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_blueprint_api',
    'daedalus_blueprint_api_render_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    RenderProbe::$seen = [];
    RenderProbe::$throw = FALSE;
  }

  /**
   * The scope itself: raised during the callable, restored however it ends.
   */
  public function testScopeRestoresOnReturnAndThrow(): void {
    $request = Request::create('/daedalus-blueprint/entity_test/1', 'POST');
    $this->assertFalse(MutationRenderScope::isActive($request));
    $this->assertFalse(MutationRenderScope::isActive(NULL));

    $result = MutationRenderScope::during($request, function () use ($request): string {
      $this->assertTrue(MutationRenderScope::isActive($request));
      // Nested: still active inside, still active after the inner leaves.
      MutationRenderScope::during($request, fn () => $this->assertTrue(MutationRenderScope::isActive($request)));
      $this->assertTrue(MutationRenderScope::isActive($request));
      return 'rendered';
    });
    $this->assertSame('rendered', $result);
    $this->assertFalse(MutationRenderScope::isActive($request));
    $this->assertFalse($request->attributes->has(MutationRenderScope::ATTRIBUTE), 'The attribute leaves with the scope.');

    try {
      MutationRenderScope::during($request, static function (): void {
        throw new \RuntimeException('render failed');
      });
      $this->fail('The render failure propagates.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('render failed', $e->getMessage());
    }
    $this->assertFalse(MutationRenderScope::isActive($request));

    // No request on the stack: the render still runs, unmarked.
    $this->assertSame('rendered', MutationRenderScope::during(NULL, static fn (): string => 'rendered'));
  }

  /**
   * The renderer raises the flag for its span; the builder never keeps it.
   */
  public function testRenderRunsUnderTheScope(): void {
    $builder = $this->container->get('daedalus_blueprint_api.mutation_response_builder');
    $entity = $this->createTestEntity();
    // The renderer marks the CURRENT request, the one the probe reads.
    $request = Request::create('/daedalus-blueprint/entity_test/' . $entity->id(), 'POST');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $this->assertFalse(MutationRenderScope::isActive($request));
      $render = $this->container->get('daedalus_blueprint_api.subtree_renderer')->render($entity, [], 'full');
      $this->assertArrayHasKey('entity:' . $entity->uuid(), $render['markup'], 'The entity rendered.');
      $this->assertSame([TRUE], RenderProbe::$seen, 'The render saw the flag raised.');
      $this->assertFalse(MutationRenderScope::isActive($request));

      // A render that throws inside the builder is degraded to a warning;
      // the flag must not survive it into the rest of the request.
      RenderProbe::$seen = [];
      RenderProbe::$throw = TRUE;
      $response = $builder->build($request, $entity, BlueprintResult::success($entity), NULL, [], 'full');
      $payload = Json::decode($response->getContent());
      $this->assertSame(200, $response->getStatusCode());
      $this->assertSame('RENDER_FAILED', $payload['warnings'][0]['code']);
      $this->assertSame([TRUE], RenderProbe::$seen);
      $this->assertFalse(MutationRenderScope::isActive($request));

      // The next render of the request starts clean.
      RenderProbe::$seen = [];
      $payload = Json::decode($builder->build($request, $entity, BlueprintResult::success($entity), NULL, [], 'full')->getContent());
      $this->assertSame([], $payload['warnings']);
      $this->assertArrayHasKey('entity:' . $entity->uuid(), $payload['markup']);
      $this->assertSame([TRUE], RenderProbe::$seen);
      $this->assertFalse(MutationRenderScope::isActive($request));
    }
    finally {
      // KernelTestBase's teardown asks the stack's request for a session.
      $request_stack->pop();
    }
  }

}
