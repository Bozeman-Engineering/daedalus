<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\Plugin\AiFunctionCall\RenderPage;
use Drupal\daedalus_ai\Render\HeadlessRenderException;
use Drupal\daedalus_ai\Render\HeadlessRendererInterface;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint_ai\ConversationContext;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
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
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'entity_test',
    // The kept shot: the lane over core's upload handler, and an image
    // media type so the lane's accepted set includes png.
    'file',
    'field',
    'image',
    'media',
  ];

  /**
   * The recording renderer stub behind the tool.
   */
  protected object $stub;

  /**
   * The store the shot is held in.
   */
  protected Attachments $attachments;

  /**
   * The conversation the tool holds the shot for.
   */
  protected ConversationContext $conversation;

  /**
   * The conversation id the fixture stamps.
   */
  protected const THREAD = 'thread-render';

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
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'file', 'image', 'media']);

    $this->setUpCurrentUser(['name' => 'editor'], ['view test entity']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    // The lane and the store as their service definitions wire them, and
    // the conversation the agent runner would have stamped.
    $this->container->set('daedalus_ui.file_lane', new FileLane(
      $this->container->get('file.upload_handler'),
      $this->container->get('file_system'),
      $this->container->get('entity_type.manager'),
    ));
    $this->attachments = new Attachments(
      $this->container->get('file.usage'),
      new DialectAliasMap($this->container->get('tempstore.shared')),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $this->container->set('daedalus_ai.attachments', $this->attachments);
    $this->conversation = new ConversationContext();
    $this->conversation->set(self::THREAD);
    $this->container->set('daedalus_blueprint_ai.conversation_context', $this->conversation);
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

    // The kept shot: named by page and pose, saved through the lane into
    // the store's directory, held for the conversation, and the result
    // names it as the handle view_file re-opens.
    $name = 'render-entity_test-' . $this->entity->id() . '-375-dark.png';
    $this->assertSame($name, $images[0]->getFilename());
    $this->assertStringContainsString("kept as \"$name\"", $output);
    $this->assertStringContainsString("view_file(\"$name\")", $output);
    $held = $this->attachments->resolve(self::THREAD, $name);
    $this->assertInstanceOf(FileInterface::class, $held);
    $this->assertStringStartsWith(Attachments::DIRECTORY . '/', (string) $held->getFileUri());
    $this->assertSame('image/png', $held->getMimeType());
    $this->assertSame($images[0]->getBinary(), file_get_contents($held->getFileUri()));
    $this->assertSame(['daedalus_ai' => ['thread' => [self::THREAD => '1']]], $this->container->get('file.usage')->listUsage($held));

    // The same pose rendered again collides; the lane renames and the
    // result reports the saved name, which is the one that resolves.
    $tool = $this->tool([
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'canvas_width' => '375',
      'canvas_scheme' => 'dark',
    ]);
    $tool->execute();
    $renamed = 'render-entity_test-' . $this->entity->id() . '-375-dark_0.png';
    $this->assertStringContainsString("kept as \"$renamed\"", $tool->getReadableOutput());
    $this->assertNotSame($held->id(), $this->attachments->resolve(self::THREAD, $renamed)?->id());
    $this->assertSame($held->id(), $this->attachments->resolve(self::THREAD, $name)?->id());
  }

  /**
   * A shot that cannot be held is still delivered, and says why.
   *
   * Outside a conversation (no runner stamped one) there is nothing to
   * hold the file for; a lane refusal (here: no media type accepts png,
   * so the accepted set is the textual kinds alone) is reported the same
   * way. Either way the bytes still ride the image contract, since a
   * render the model cannot see is worse than one it cannot re-open. The
   * default pose names the shot by its page alone.
   */
  public function testUnkeptShotIsStillDelivered(): void {
    $this->conversation->set('');
    $tool = $this->tool(['entity_type' => 'entity_test', 'entity_id' => (string) $this->entity->id()]);
    $tool->execute();
    $output = $tool->getReadableOutput();
    $this->assertStringContainsString('not kept for later: this run has no conversation', $output);
    $this->assertStringNotContainsString('view_file', $output);
    $images = $tool->getResult()->getContextValues()['_images'];
    $this->assertSame('render-entity_test-' . $this->entity->id() . '.png', $images[0]->getFilename());
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());

    $this->conversation->set(self::THREAD);
    $this->container->get('entity_type.manager')->getStorage('media_type')->load('image')->delete();
    $tool = $this->tool(['entity_type' => 'entity_test', 'entity_id' => (string) $this->entity->id()]);
    $tool->execute();
    $output = $tool->getReadableOutput();
    $this->assertStringContainsString('not kept for later: ', $output);
    $this->assertStringContainsString('extension', $output);
    $this->assertStringNotContainsString('view_file', $output);
    $this->assertNotNull($tool->getResult());
    $this->assertSame([], $this->container->get('entity_type.manager')->getStorage('file')->loadMultiple());
    $this->assertNull($this->attachments->resolve(self::THREAD, 'render-entity_test-' . $this->entity->id() . '.png'));
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
