<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\daedalus_ai\Ajax\OpenLinkCommand;
use Drupal\daedalus_ai\Ajax\TriggerDeferredCommand;
use Drupal\daedalus_ai\EventSubscriber\DeepChatResponseListener;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\daedalus_ai\UiCommandQueue;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Pins the response-listener guards that gate the UI command injection.
 *
 * DeepChatResponseListener is the bridge that carries every server-side UI side
 * effect to the browser: it flushes the UiCommandQueue into the chat response
 * as a 'drupal_commands' array and injects the turn's mutation-response
 * payloads as 'blueprint_responses' (built by the MutatedTurn from what AI
 * tools persisted during the request). Because it runs on EVERY kernel
 * response, three guards keep it from corrupting unrelated traffic, and
 * each is load-bearing:
 *
 * - It injects ONLY on the 'ai_chatbot.api' route. A regression here would
 *   mutate arbitrary JSON responses across the site.
 * - It injects ONLY into a JsonResponse. A regression would corrupt non-JSON
 *   responses (e.g. an HTML page) by appending a commands array.
 * - The route guard short-circuits BEFORE flushing the queue (or building
 *   payloads), so a non-matching route must not drain queued commands;
 *   otherwise a command queued during an unrelated response would be lost
 *   before it ever reached the chatbot endpoint.
 *
 * On top of the guards, the listener owns the END-OF-TURN contract: a chat
 * turn spans several poll requests (the continuation loop runs one LLM leg
 * per POST, each response carrying should_continue until the turn is done),
 * and end-of-turn commands (open-link, the deferred trigger) must reach the
 * client ONLY on the final response — an open-link flushed onto an
 * intermediate poll navigates the browser away mid-turn and the final
 * response lands in a dead document. The hold-and-release test drives the
 * listener through a whole simulated turn and pins both halves: absence on
 * the intermediate response, presence — trigger before open-link, so the
 * localStorage stash exists when the destination page's deferred check
 * runs — on the final one.
 *
 * The listener and queue are plain classes (the MutatedTurn is mocked: its
 * payload build needs a full render stack, which the FJS suite covers), so
 * this builds ResponseEvents by hand (a stub kernel, a real Request, a real
 * Response) rather than driving a full HTTP request; the tempstore holding
 * the turn's commands between polls is the container's real one, which is
 * why this is a kernel test and why a user must be logged in (the store is
 * private, keyed per user).
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class DeepChatResponseListenerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The ai module rides for its hostname filter service: the listener is
   * the display boundary that filters the assistant's html, so the real
   * filter, not a mock, proves the wiring.
   */
  protected static $modules = ['system', 'user', 'file', 'key', 'ai'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // The end-of-turn holding store is a private tempstore: it needs an
    // authenticated current user to key entries by.
    $this->setUpCurrentUser();
  }

  /**
   * Builds a listener around a fresh queue with inert turn collaborators.
   *
   * @param \Drupal\daedalus_ai\UiCommandQueue $queue
   *   The queue to flush.
   * @param \Drupal\daedalus_ai\PendingMedia\PendingTargetCollector|null $pending
   *   The pending-generation collector, or NULL for one that never flushes
   *   a trigger.
   * @param array $payloads
   *   Mutation payloads the mocked MutatedTurn returns; empty by default.
   *
   * @return \Drupal\daedalus_ai\EventSubscriber\DeepChatResponseListener
   *   The listener under test.
   */
  private function makeListener(UiCommandQueue $queue, ?PendingTargetCollector $pending = NULL, array $payloads = []): DeepChatResponseListener {
    // Mutations are mocked: buildPayloads() needs a full render stack,
    // which the FJS suite covers; the listener injects whatever the turn
    // hands it. The payload-injection half is pinned end-to-end by
    // DaedalusAiBehaviorTest's simulated-turn leg.
    $turn = $this->createMock(MutatedTurn::class);
    $turn->method('buildPayloads')->willReturn($payloads);
    if ($pending === NULL) {
      $pending = $this->createMock(PendingTargetCollector::class);
      $pending->method('flush')->willReturn(NULL);
    }
    return new DeepChatResponseListener(
      $queue,
      $turn,
      $pending,
      $this->container->get('tempstore.private'),
      $this->container->get('datetime.time'),
      $this->container->get('ai.hostname_filter_service'),
    );
  }

  /**
   * Builds a ResponseEvent for a given route and response.
   *
   * @param string $route
   *   The value to expose as the request's _route attribute.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response the listener will inspect.
   *
   * @return \Symfony\Component\HttpKernel\Event\ResponseEvent
   *   A main-request response event.
   */
  private function makeEvent(string $route, Response $response): ResponseEvent {
    $request = Request::create('/api/deepchat', 'POST');
    $request->attributes->set('_route', $route);
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
  }

  /**
   * Builds a chat POST request whose body carries a thread id.
   *
   * @param string $thread_id
   *   The continuation loop's thread key, riding every poll of a turn.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   A chat-shaped request.
   */
  private function makeChatRequest(string $thread_id): Request {
    return Request::create('/api/deepchat', 'POST', [], [], [], [], json_encode([
      'thread_id' => $thread_id,
      'messages' => [['role' => 'user', 'text' => 'dummy_loading']],
    ]));
  }

  /**
   * The three guards, exercised on a single shared queue/listener pair.
   *
   * Sweeps all three branches in one method: the route guard not flushing on a
   * mismatch (proven by a later matching flush still seeing the command), the
   * non-JSON guard leaving the body untouched, and the happy path splicing the
   * rendered commands into the JSON while preserving the original keys.
   */
  public function testInjectsOnlyIntoChatbotJsonResponses(): void {
    $queue = new UiCommandQueue();
    $listener = $this->makeListener($queue);

    // The listener subscribes to the kernel RESPONSE event.
    $this->assertArrayHasKey(
      KernelEvents::RESPONSE,
      DeepChatResponseListener::getSubscribedEvents(),
    );

    // (a) NON-MATCHING route: the listener must return before flushing, so the
    // body is untouched AND the queued command survives. Queue first, fire on a
    // foreign route, then assert nothing changed and the command was NOT
    // drained.
    $queue->add(new OpenLinkCommand('/node/9', 'Created page'));
    $foreign = new JsonResponse(['text' => 'hi']);
    $listener->onResponse($this->makeEvent('something.else', $foreign));
    $this->assertSame(
      ['text' => 'hi'],
      json_decode($foreign->getContent(), TRUE),
      'A non-chatbot route leaves the JSON response untouched.',
    );

    // The command was not flushed by the foreign route, so a matching response
    // now still sees it. This is the proof the route guard short-circuits
    // before flush(). The body carries no should_continue (a single-leg turn
    // is final) and the request no thread_id, so the end-of-turn open-link
    // releases immediately.
    $json = new JsonResponse(['text' => 'assistant reply', 'keep' => TRUE]);
    $listener->onResponse($this->makeEvent('ai_chatbot.api', $json));
    $decoded = json_decode($json->getContent(), TRUE);
    $this->assertArrayHasKey('drupal_commands', $decoded);
    $this->assertCount(1, $decoded['drupal_commands']);
    $this->assertSame(
      ['command' => 'daedalusAiOpenLink', 'url' => '/node/9', 'title' => 'Created page'],
      $decoded['drupal_commands'][0],
      'The queued command is rendered into the matching JSON response.',
    );
    // Original payload keys survive alongside the injected commands.
    $this->assertSame('assistant reply', $decoded['text']);
    $this->assertTrue($decoded['keep']);

    // (b) MATCHING route but a plain (non-JSON) Response: the listener must not
    // touch it even on the chatbot endpoint.
    $queue->add(new OpenLinkCommand('/node/10', 'Another'));
    $plain = new Response('<html>not json</html>');
    $listener->onResponse($this->makeEvent('ai_chatbot.api', $plain));
    $this->assertSame(
      '<html>not json</html>',
      $plain->getContent(),
      'A non-JsonResponse is left untouched even on the chatbot route.',
    );
  }

  /**
   * End-of-turn commands hold through intermediate polls, release on final.
   *
   * Simulates the turn shape in which releasing end-of-turn commands early
   * navigated the polling document away mid-turn and lost the final
   * response: a page_create leg queues open-link and (via the pending
   * collector) the deferred trigger, but the model still owes a follow-up
   * tool call, so the leg's response carries should_continue. The
   * intermediate response must carry NEITHER end-of-turn command (only the
   * leg's immediate commands), and the turn's final response must carry
   * both, trigger BEFORE open-link, even though open-link was queued first,
   * so the client stashes the batch to localStorage before navigating.
   */
  public function testHoldsEndOfTurnCommandsUntilFinalResponse(): void {
    $queue = new UiCommandQueue();
    $trigger = new TriggerDeferredCommand('node:3:batch', 'node', '3', 'full');
    // The collector flushes its trigger on the leg that registered the
    // generations (it is request-scoped) and has nothing on later legs.
    $pending = $this->createMock(PendingTargetCollector::class);
    $pending->method('flush')->willReturn($trigger, NULL, NULL);
    $listener = $this->makeListener($queue, $pending);

    // Leg 1: the create tool ran. Its subscriber queued open-link, an
    // unrelated immediate command was queued too, and the pending collector
    // hands over the trigger. The turn continues (should_continue).
    $queue->add(new OpenLinkCommand('/node/3', 'Created page'));
    $queue->addRendered(['command' => 'immediateMarker']);
    $intermediate = $listener->injectInto(
      ['html' => 'Calling Create Page', 'should_continue' => TRUE],
      $this->makeChatRequest('thread-1'),
    );
    $this->assertSame(
      [['command' => 'immediateMarker']],
      $intermediate['drupal_commands'],
      'An intermediate poll response carries only immediate commands: no open-link, no trigger.',
    );

    // Leg 2: the follow-up tool leg produced nothing to queue; the turn
    // completes. The held commands release together, trigger first.
    $final = $listener->injectInto(
      ['html' => 'Done.', 'should_continue' => FALSE],
      $this->makeChatRequest('thread-1'),
    );
    $this->assertSame(
      ['daedalusAiTriggerDeferred', 'daedalusAiOpenLink'],
      array_column($final['drupal_commands'], 'command'),
      'The final response carries the trigger and open-link, in that order.',
    );
    $this->assertSame('/node/3', $final['drupal_commands'][1]['url']);
    $this->assertSame('node:3:batch', $final['drupal_commands'][0]['batchId']);

    // The hold store is drained: another final response on the same thread
    // replays nothing.
    $again = $listener->injectInto(
      ['html' => 'Later turn.', 'should_continue' => FALSE],
      $this->makeChatRequest('thread-1'),
    );
    $this->assertArrayNotHasKey(
      'drupal_commands',
      $again,
      'Released commands are not replayed on a later response.',
    );
  }

  /**
   * Without a thread key an end-of-turn command releases immediately.
   *
   * A thread-less caller (the simulate-turn test route, a bare API client)
   * has no continuation loop to strand and no key to hold against, so
   * holding would only lose the command. This pins the fallback: even with
   * should_continue set, the command rides out.
   */
  public function testThreadlessRequestReleasesImmediately(): void {
    $queue = new UiCommandQueue();
    $listener = $this->makeListener($queue);

    $queue->add(new OpenLinkCommand('/node/7', 'No thread'));
    $request = Request::create('/api/deepchat', 'POST', [], [], [], [], json_encode([
      'messages' => [['role' => 'user', 'text' => 'hi']],
    ]));
    $data = $listener->injectInto(['html' => 'Calling', 'should_continue' => TRUE], $request);
    $this->assertSame(
      ['daedalusAiOpenLink'],
      array_column($data['drupal_commands'], 'command'),
      'With no thread to hold against, the end-of-turn command releases now rather than being lost.',
    );
  }

  /**
   * The assistant's html is hostname-filtered at the display boundary.
   *
   * The trust boundary sits at display, not transport: the page-builder
   * agent runs with transport hostname filtering disabled
   * (hostname_filter_disabled on the daedalus agent) so tool arguments
   * reach the op spine byte-intact, and the chat link protection the
   * transport filter would provide lives here instead, the single display
   * choke point every leg's JSON body passes through (the chat never
   * streams; every leg is a JSON body). Pinned: a disallowed-hostname link
   * is stripped on the FINAL and the INTERMEDIATE leg (intermediate poll
   * responses render in the sidebar too), an allowed-host link and an
   * internal path survive, and the command and mutation injections ride
   * untouched; they are structured app data the server built, not model
   * output.
   */
  public function testFiltersAssistantHtmlAtDisplayBoundary(): void {
    $this->config('ai.settings')->set('allowed_hosts', ['allowed.example'])->save();
    $queue = new UiCommandQueue();
    $payload = [
      'entity' => 'node:9',
      'commands' => [
        [
          'command' => 'daedalusAiOpenLink',
          'url' => 'https://evil.example/payload-not-model-output',
        ],
      ],
    ];
    $listener = $this->makeListener($queue, NULL, [$payload]);

    $html = '<p>See <a href="https://evil.example/phish">your account notice</a>, the <a href="https://allowed.example/docs">docs</a> and <a href="/node/9">your page</a>.</p>';

    // Intermediate leg: the filter runs here too, and the injections are
    // untouched by it.
    $queue->addRendered(['command' => 'immediateMarker', 'url' => 'https://evil.example/command-data']);
    $intermediate = $listener->injectInto(
      ['html' => $html, 'should_continue' => TRUE],
      $this->makeChatRequest('thread-filter'),
    );
    $this->assertStringNotContainsString('evil.example', $intermediate['html'], 'A disallowed-hostname href is stripped on an intermediate leg.');
    $this->assertStringContainsString('your account notice', $intermediate['html'], 'The link text survives; only the disallowed href dies.');
    $this->assertStringContainsString('https://allowed.example/docs', $intermediate['html'], 'An allowed-host link survives.');
    $this->assertStringContainsString('/node/9', $intermediate['html'], 'An internal link survives.');
    $this->assertSame(
      [['command' => 'immediateMarker', 'url' => 'https://evil.example/command-data']],
      $intermediate['drupal_commands'],
      'Command injection is untouched by the display filter.',
    );
    $this->assertSame([$payload], $intermediate['blueprint_responses'], 'Mutation payload injection is untouched by the display filter.');

    // Final leg: the same protection.
    $final = $listener->injectInto(
      ['html' => $html],
      $this->makeChatRequest('thread-filter'),
    );
    $this->assertStringNotContainsString('evil.example', $final['html'], 'A disallowed-hostname href is stripped on the final leg.');
    $this->assertStringContainsString('https://allowed.example/docs', $final['html'], 'An allowed-host link survives on the final leg.');
    $this->assertStringContainsString('/node/9', $final['html'], 'An internal link survives on the final leg.');
  }

}
