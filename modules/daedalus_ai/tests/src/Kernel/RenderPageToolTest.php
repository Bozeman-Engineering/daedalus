<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\daedalus_ai\Plugin\AiFunctionCall\RenderPage;
use Drupal\daedalus_ai\Render\HeadlessRenderException;
use Drupal\daedalus_ai\Render\HeadlessRendererInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Pins the render_page tool's contract around a stubbed browser.
 *
 * What must not regress, in rough order of damage:
 * - The boot URL grammar: the tool addresses the canvas state vector
 *   through the exact ?canvasWidth/?canvasScheme/?canvasMotion parameters
 *   daedalus_ui's vector-state.js boots from. A drifted parameter name
 *   silently renders the DEFAULT state while the model believes it
 *   verified the posed one.
 * - The browsing-context replication: the render must carry the editor's
 *   OWN session cookie (staged tempstore edits key to it) plus
 *   navigationMode=edit, or the screenshot shows the wrong page truthfully.
 * - The ai_agents image contract: AiAgentEntityWrapper discovers tool
 *   images by duck-typing getPluginInstance()/getResult()/
 *   getContextValues()['_images']. That chain is re-walked here literally;
 *   renaming any link delivers no screenshot and no error.
 *
 * The browser itself is stubbed at the HeadlessRendererInterface seam: the
 * real WebDriver conversation needs a live selenium container (exercised
 * manually / by the FJS tier), while everything above the seam is this
 * module's own logic. The daedalus_ai module is not enabled (its full AI-stack
 * dependency chain is unrelated); the tool is built exactly as its
 * create() factory would, from the real attribute definition.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class RenderPageToolTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'entity_test',
  ];

  /**
   * The recording renderer stub behind the tool.
   */
  protected object $stub;

  /**
   * The entity whose page the tests render.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');

    $this->setUpCurrentUser(['name' => 'editor'], ['view test entity']);
    $this->entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Rendered page']);
    $this->entity->save();

    // The request the tool reads the editor's session cookie from.
    $request = Request::create('/');
    $session = new Session(new MockArraySessionStorage());
    $session->setId('editor-session-id');
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);

    // A 1x1 transparent PNG: real decodable image bytes, so the ImageFile
    // the model receives is a genuine PNG, not just "some string".
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $this->stub = new class ($png) implements HeadlessRendererInterface {

      /**
       * Every render() invocation's url/cookies/canvas_width, in order.
       *
       * @var array
       */
      public array $calls = [];

      /**
       * When set, render() throws this instead of returning the PNG.
       *
       * @var \Drupal\daedalus_ai\Render\HeadlessRenderException|null
       */
      public ?HeadlessRenderException $throw = NULL;

      public function __construct(private readonly string $png) {}

      /**
       * {@inheritdoc}
       */
      public function render(string $url, array $cookies, ?int $canvas_width = NULL): array {
        $this->calls[] = ['url' => $url, 'cookies' => $cookies, 'canvas_width' => $canvas_width];
        if ($this->throw) {
          throw $this->throw;
        }
        return ['png' => $this->png, 'width' => 1, 'height' => 1, 'truncated' => FALSE];
      }

    };
    $this->container->set('daedalus_ai.headless_renderer', $this->stub);
  }

  /**
   * Builds the tool exactly as the plugin manager would.
   *
   * The definition comes from the REAL FunctionCall attribute (not a
   * hand-written copy), so the context definitions under test are the ones
   * the model sees.
   */
  protected function tool(array $contexts): RenderPage {
    $attribute = (new \ReflectionClass(RenderPage::class))
      ->getAttributes(FunctionCall::class)[0]
      ->newInstance();
    $attribute->setClass(RenderPage::class);
    $definition = $attribute->get();
    $tool = RenderPage::create($this->container, [], $definition['id'], $definition);
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    return $tool;
  }

  /**
   * The happy path: boot URL grammar, cookies, and the image contract.
   */
  public function testPosedRenderDeliversScreenshot(): void {
    $tool = $this->tool([
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'canvas_width' => '375',
      'canvas_scheme' => 'dark',
    ]);
    $tool->execute();

    // One render, at the entity's canonical URL, posed through the exact
    // boot parameter names, defaults (motion) omitted.
    $this->assertCount(1, $this->stub->calls);
    $call = $this->stub->calls[0];
    $this->assertStringContainsString('/entity_test/' . $this->entity->id(), $call['url']);
    $this->assertStringContainsString('canvasWidth=375', $call['url']);
    $this->assertStringContainsString('canvasScheme=dark', $call['url']);
    $this->assertStringNotContainsString('canvasMotion', $call['url']);
    $this->assertSame(375, $call['canvas_width']);

    // The editor's own browsing context: session cookie + edit mode.
    $this->assertSame([
      ['name' => 'MOCKSESSID', 'value' => 'editor-session-id'],
      ['name' => 'navigationMode', 'value' => 'edit'],
    ], $call['cookies']);

    // The model-facing text names the pose and the delivery mechanism.
    $output = $tool->getReadableOutput();
    $this->assertStringContainsString('width=375px, scheme=dark', $output);
    $this->assertStringContainsString('next turn', $output);

    // The ai_agents duck-typed chain, walked literally as
    // AiAgentEntityWrapper::determineSolvability() walks it.
    $this->assertTrue(method_exists($tool, 'getPluginInstance'));
    $instance = $tool->getPluginInstance();
    $this->assertTrue(method_exists($instance, 'getResult'));
    $images = $instance->getResult()->getContextValues()['_images'];
    $this->assertCount(1, $images);
    $this->assertInstanceOf(ImageFile::class, $images[0]);
    $this->assertSame('image/png', $images[0]->getMimeType());
    $this->assertNotFalse(getimagesizefromstring($images[0]->getBinary()));
  }

  /**
   * Width clamps through the shared vector grammar before hitting the URL.
   */
  public function testWidthClampsToVectorBounds(): void {
    $tool = $this->tool([
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'canvas_width' => '100',
    ]);
    $tool->execute();
    $this->assertStringContainsString('canvasWidth=240', $this->stub->calls[0]['url']);
  }

  /**
   * Vocabulary violations come back as correctives, never as renders.
   */
  public function testInvalidAxisValueTeachesTheGrammar(): void {
    $tool = $this->tool([
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'canvas_scheme' => 'darkmode',
    ]);
    $tool->execute();
    $this->assertStringContainsString('auto, light, dark', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());
    $this->assertCount(0, $this->stub->calls);
  }

  /**
   * Unknown targets and renderer failures surface as readable output.
   */
  public function testFailuresSurfaceToTheModel(): void {
    $tool = $this->tool(['entity_type' => 'entity_test', 'entity_id' => '999']);
    $tool->execute();
    $this->assertStringContainsString('not found', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());

    $this->stub->throw = new HeadlessRenderException('no WebDriver endpoint is configured');
    $tool = $this->tool(['entity_type' => 'entity_test', 'entity_id' => (string) $this->entity->id()]);
    $tool->execute();
    $this->assertStringContainsString('Render failed: no WebDriver endpoint is configured', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());
  }

  /**
   * Without an editor session there is nothing truthful to render.
   *
   * The sessionless request still carries a Session OBJECT (an unstarted
   * one with an empty id, as an anonymous request would after the kernel
   * attaches the session): the refusal keys on "no usable session id",
   * not on the object's absence, and test teardown needs the object.
   */
  public function testNoSessionRefusesToRender(): void {
    $request = Request::create('/');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->pop();
    $this->container->get('request_stack')->push($request);
    $tool = $this->tool(['entity_type' => 'entity_test', 'entity_id' => (string) $this->entity->id()]);
    $tool->execute();
    $this->assertStringContainsString('authenticated editor session', $tool->getReadableOutput());
    $this->assertCount(0, $this->stub->calls);
  }

}
