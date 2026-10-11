<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_ui\EventSubscriber\InitialMode;
use Drupal\daedalus_ui\ModeState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The one door that queues the mode cookie, and the response that sets it.
 *
 * The navigationMode cookie is scoped to the edited page's path, so a
 * server-side door that sends the user to a page in a mode queues the
 * cookie (ModeState::queueModeCookie) and InitialMode::setResponseCookies
 * emits it on the response, where the next navigation to that path carries
 * it. Three doors queue: a created entity whose bundle has an initial mode
 * (InitialMode::prepare on hook_entity_insert), a canvas route's first
 * visit (ModeState::enterModeOnFirstVisit, block_content's canvas and a
 * blueprint's), and the Add blueprint form (the library's own test).
 */
#[CoversClass(ModeState::class)]
#[CoversClass(InitialMode::class)]
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ModeCookieTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies (daedalus_blueprint + daedalus_blueprint_api)
   * are invisible to kernel environments and must be listed explicitly or
   * the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
  }

  /**
   * A queued cookie rides the response once, scoped to its path.
   */
  public function testQueuedCookieRidesTheResponse(): void {
    $mode_state = $this->container->get(ModeState::class);
    $subscriber = $this->container->get('daedalus_ui.event_subscriber.initial_mode');
    $request = $this->container->get('request_stack')->getCurrentRequest();

    // Nothing queued, nothing set.
    $response = new Response();
    $subscriber->setResponseCookies(new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    $this->assertSame([], $response->headers->getCookies(), 'A response with nothing queued carries no mode cookie.');

    // Queued for a path: the response carries it for that path, and only
    // the once.
    $mode_state->queueModeCookie('/node/7');
    $response = new Response();
    $subscriber->setResponseCookies(new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    $cookies = $response->headers->getCookies();
    $this->assertCount(1, $cookies);
    $this->assertSame('navigationMode', $cookies[0]->getName());
    $this->assertSame('edit', $cookies[0]->getValue());
    $this->assertSame('/node/7', $cookies[0]->getPath(), 'The cookie is scoped to the page it is for.');
    $this->assertFalse($cookies[0]->isHttpOnly(), 'The client boot gate reads the cookie, so it is not HttpOnly.');
    $response = new Response();
    $subscriber->setResponseCookies(new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response));
    $this->assertSame([], $response->headers->getCookies(), 'Taking the cookie empties the queue.');

    // One queued cookie per response: a later queue replaces the earlier
    // one, and the mode is the door's to name.
    $mode_state->queueModeCookie('/node/7');
    $mode_state->queueModeCookie('/node/8', 'preview');
    $cookie = $mode_state->takeQueuedModeCookie();
    $this->assertSame('/node/8', $cookie?->getPath());
    $this->assertSame('preview', $cookie?->getValue());
    $this->assertNull($mode_state->takeQueuedModeCookie());
  }

  /**
   * A created entity whose bundle has an initial mode queues the cookie.
   */
  public function testCreatedEntityInitialMode(): void {
    $mode_state = $this->container->get(ModeState::class);
    NodeType::create(['type' => 'landing', 'name' => 'Landing'])
      ->setThirdPartySetting('daedalus_ui', 'initial_mode', 'edit')
      ->save();
    NodeType::create(['type' => 'plain', 'name' => 'Plain'])->save();

    // hook_entity_insert queues the cookie for the new entity's own page.
    $landing = Node::create(['type' => 'landing', 'title' => 'A landing']);
    $landing->save();
    $cookie = $mode_state->takeQueuedModeCookie();
    $this->assertNotNull($cookie, 'Creating an entity of a bundle with an initial mode queued the cookie.');
    $this->assertSame('edit', $cookie->getValue());
    $this->assertSame($landing->toUrl()->toString(), $cookie->getPath(), 'The cookie is scoped to the created entity\'s page.');

    // A bundle with no initial mode queues nothing.
    Node::create(['type' => 'plain', 'title' => 'A plain one'])->save();
    $this->assertNull($mode_state->takeQueuedModeCookie(), 'A bundle without an initial mode lands as the browser would.');
  }

  /**
   * A canvas route's first visit enters the mode; later visits are left alone.
   */
  public function testEnterModeOnFirstVisit(): void {
    $mode_state = $this->container->get(ModeState::class);

    // The first visit: this response renders in the mode (the request's
    // cookie bag, which getMode() reads) and the browser is handed the
    // path-scoped cookie for every later request.
    $request = $this->visit('/block-content/3');
    $mode_state->enterModeOnFirstVisit('/block-content/3');
    $this->assertSame('edit', $request->cookies->get('navigationMode'));
    $this->assertSame('edit', $mode_state->getMode(), 'The response of the first visit renders the mode\'s chrome.');
    $cookie = $mode_state->takeQueuedModeCookie();
    $this->assertSame('/block-content/3', $cookie?->getPath());
    $this->assertSame('edit', $cookie?->getValue());

    // A later visit in the session honours what the browser carries: here
    // nothing, so no mode, and nothing queued again.
    $request = $this->visit('/block-content/3');
    $mode_state->enterModeOnFirstVisit('/block-content/3');
    $this->assertFalse($request->cookies->has('navigationMode'), 'A later visit is left as the browser sent it.');
    $this->assertSame('none', $mode_state->getMode());
    $this->assertNull($mode_state->takeQueuedModeCookie(), 'Nothing is queued twice for one path in a session.');

    // Another canvas is another first visit, in the mode the door names.
    $request = $this->visit('/blueprint/2');
    $mode_state->enterModeOnFirstVisit('/blueprint/2', 'preview');
    $this->assertSame('preview', $request->cookies->get('navigationMode'));
    $this->assertSame('/blueprint/2', $mode_state->takeQueuedModeCookie()?->getPath());
  }

  /**
   * Makes a fresh request to a path the current one, on the one session.
   */
  private function visit(string $path): Request {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create($path);
    $request->setSession($request_stack->getSession());
    $request_stack->push($request);
    return $request;
  }

}
