<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\daedalus_ai\Plugin\Sidebar\ChatSidebar;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Pins the always-on chat sidebar's access gate and render contract.
 *
 * ChatSidebar wires the persistent <deep-chat> panel to the chatbot endpoint.
 * Two things it produces are load-bearing and regress silently:
 *
 * - applies(): the panel exists on every page gated ONLY by the
 *   'use ai assistant' permission. A regression here either hides the assistant
 *   from authorized editors or exposes it to everyone.
 * - build(): the deep-chat `connect` object and drupalSettings are the exact
 *   seam the client JS consumes. The connect url must carry a FRESH CSRF token
 *   minted for the 'api/deepchat' endpoint (Url::fromRoute yields a stale one),
 *   be POST with no streaming on either side (the body's stream prop stays
 *   FALSE so the server never answers SSE, and no deep-chat `stream` key so
 *   the client never simulates), and pass the current route as context so
 *   the AI knows the page. The panel is #cache max-age 0 (per-session token +
 *   history), and only history newer than one day is replayed into the widget.
 *   The body's chat-turn contract (chat_processor_plugin and
 *   plugin_configuration, drupal/ai 1.5's shape) is asserted against the LIVE
 *   chat processor manager and plugin, never against a shape this test
 *   spells itself: a Kernel assertion in daedalus's own words is how the 1.5
 *   contract move stayed invisible while the suites were green.
 *
 * The runner is MOCKED: getMessageHistory() reads session-scoped tempstore
 * keyed by an internal thread id, so seeding it for real would couple this test
 * to ai_assistant_api internals. Mocking it isolates the sidebar's OWN logic
 * (the <1-day filter, the connect/settings shape) exactly as the sibling
 * deferred tests fake the processor. Everything else is real: a real
 * ai_assistant entity
 * (ai_chatbot pulls ai_assistant_api + ai), the real csrf generator (so "fresh
 * token" is a genuine assertion), and the real module handler + on-disk
 * ai_chatbot toolbar.yml (so the deep-chat style read is faithful, not a
 * hardcoded path). The plugin is constructed directly, since daedalus_ai is not
 * enabled (its info.yml drags the full editing stack) and SidebarPluginBase is
 * a plain PluginBase needing no daedalus_ui services.
 *
 * The deep-chat STYLE attributes (getDeepChatStyleAttributes) are presentation,
 * not a JS behavioral contract, so they are exercised (the real toolbar.yml is
 * parsed) but not asserted in detail.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ChatSidebarTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The ai_chatbot module provides the deep-chat style file and pulls
   * ai_assistant_api (the ai_assistant entity type) + ai as dependencies. No
   * config is applied, so the shipped defaults are not schema-audited here.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'ai',
    'ai_assistant_api',
    'ai_chatbot',
    // The attachment lane the sidebar reads its accepted formats and size
    // ceiling from is built on core's upload handler.
    'field',
    'file',
  ];

  /**
   * The seeded assistant the sidebar renders.
   *
   * @var \Drupal\ai_assistant_api\Entity\AiAssistant
   */
  protected AiAssistant $assistant;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');

    // A real assistant the sidebar loads by id (matches daedalus_ai.settings). Any
    // non-'none' allow_history keeps the history path active. AiAssistant
    // declares several typed non-nullable properties with no default, so they
    // must be seeded or the config save fails on a null assignment.
    $this->assistant = AiAssistant::create([
      'id' => 'chatbot',
      'label' => 'Chatbot',
      'description' => '',
      'allow_history' => 'session_one_thread',
      'system_role' => '',
      'preprompt_instructions' => '',
      'assistant_message' => '',
      'error_message' => '',
      'llm_provider' => '',
      'llm_model' => '',
      'llm_configuration' => [],
    ]);
    $this->assistant->save();

    // The sidebar reads its assistant id from config; daedalus_ai is not enabled so
    // its default config is seeded directly (as the gate/handler tests do).
    $this->config('daedalus_ai.settings')
      ->set('assistant_id', 'chatbot')
      ->save();
  }

  /**
   * Access gate, the empty-assistant guard, and the full render contract.
   */
  public function testAppliesGuardAndRenderContract(): void {
    // A request with a started session at a known route. build() reads the
    // route as context and reports whether the session is started.
    $request = Request::create('/node/5');
    $session = new Session(new MockArraySessionStorage());
    $session->start();
    $request->setSession($session);
    $this->container->get('request_stack')->push($request);
    $this->container->get('path.current')->setPath('/node/5', $request);

    // getMessageHistory returns the session-scoped store shape (role/message/
    // timestamp). One entry is older than a day (must be dropped) and one is
    // fresh markdown (kept, converted to HTML).
    $recent = \Drupal::time()->getRequestTime();
    $old = $recent - (2 * 86400);
    $runner = $this->createMock(AiAssistantApiRunner::class);
    $runner->method('getThreadsKey')->willReturn('thread-xyz');
    $runner->method('getAssistant')->willReturn($this->assistant);
    $runner->method('getMessageHistory')->willReturn([
      ['role' => 'user', 'message' => 'stale', 'timestamp' => $old],
      ['role' => 'assistant', 'message' => '**hi**', 'timestamp' => $recent],
    ]);

    $sidebar = new ChatSidebar(
      [],
      'daedalus_ai',
      ['id' => 'daedalus_ai', 'label' => new TranslatableMarkup('AI'), 'provider' => 'daedalus_ai'],
      $this->container->get('entity_type.manager'),
      $runner,
      $this->container->get('path.current'),
      $this->container->get('request_stack'),
      $this->container->get('module_handler'),
      $this->container->get('config.factory'),
      $this->container->get('csrf_token'),
      $this->container->get('current_user'),
      // A REAL mode plugin manager (the class is final — not mockable) built
      // against this test's module set: daedalus_ui is not enabled, so it
      // discovers no modes and the sidebar attaches only the plain chat
      // library — the non-editable-page posture of getLibraries().
      new ModePluginManager(
        $this->container->get('container.namespaces'),
        $this->container->get('cache.discovery'),
        $this->container->get('module_handler'),
      ),
      // The REAL lane (daedalus_ui is not enabled, so its service is not
      // in this container) over core's upload handler: with no media
      // module, its accepted set is exactly the textual kinds.
      new FileLane(
        $this->container->get('file.upload_handler'),
        $this->container->get('file_system'),
        $this->container->get('entity_type.manager'),
      ),
    );

    // The gate is the 'use ai assistant' permission and nothing else.
    $this->setUpCurrentUser([], []);
    $this->assertFalse($sidebar->applies(), 'Without the permission the panel does not apply.');
    $this->setUpCurrentUser([], [], TRUE);
    $this->assertTrue($sidebar->applies(), 'An authorized user gets the panel.');

    // Guard: a configured-but-missing assistant renders nothing.
    $this->config('daedalus_ai.settings')->set('assistant_id', '__missing__')->save();
    $this->assertSame([], $sidebar->build(), 'An unknown assistant renders an empty panel.');
    $this->config('daedalus_ai.settings')->set('assistant_id', 'chatbot')->save();

    // The full render contract.
    $build = $sidebar->build();

    // Per-session token + history make the panel uncacheable.
    $this->assertSame(0, $build['#cache']['max-age']);
    $this->assertContains('daedalus_ai/chat', $build['#attached']['library']);

    // The connect object the deep-chat element consumes.
    $connect = Json::decode($build['chat']['deep_chat']['#attributes']['connect']);
    $fresh_token = $this->container->get('csrf_token')->get('api/deepchat');
    $this->assertSame('/api/deepchat?token=' . $fresh_token, $connect['url'], 'The connect url carries a fresh api/deepchat CSRF token.');
    $this->assertSame('POST', $connect['method']);
    // No client-side streaming of any kind. A bare `stream: true` would flip
    // deep-chat to fetchEventSource, and the agent path never answers SSE,
    // so the JSON should_continue payload would be dropped and the
    // continuation loop would die. `stream: {simulation: true}` is out too:
    // deep-chat's HTML simulation splits a reply into every element AND each
    // of its descendants and appends them all, so nested markup (lists,
    // links, the copy button, the "Contacting agents.." wrapper) renders
    // twice, and it re-runs the responseInterceptor per chunk. The body prop
    // must stay FALSE: the server-side transport contract is JSON (the
    // continuation loop and the response listener's
    // drupal_commands/blueprint_responses injections all ride the JSON body).
    $this->assertArrayNotHasKey('stream', $connect, 'deep-chat neither streams nor simulates streaming; replies render whole.');
    $this->assertFalse($connect['additionalBodyProps']['stream'], 'The server-side transport stays JSON; SSE is never requested.');
    $this->assertSame('/node/5', $connect['additionalBodyProps']['contexts']['current_route'], 'The current route is passed as AI context.');

    // The chat-turn request contract, locked against ai_chatbot rather than
    // against daedalus's own words: DeepChatApi::api() refuses any body
    // whose chat_processor_plugin the processor manager has no definition
    // for, and hands plugin_configuration to that plugin's createInstance().
    // So the body names a processor the LIVE manager knows and configures it
    // with exactly the keys the live plugin declares as its defaults; the
    // next contract move in ai (a renamed processor, a new or dropped
    // configuration key) fails here, not on the installed site.
    $body = $connect['additionalBodyProps'];
    $processor_manager = $this->container->get('plugin.manager.ai.chat_processor');
    $this->assertTrue($processor_manager->hasDefinition($body['chat_processor_plugin']), 'The body names a chat processor ai_chatbot can instantiate.');
    $processor = $processor_manager->createInstance($body['chat_processor_plugin']);
    $this->assertSame(array_keys($processor->defaultConfiguration()), array_keys($body['plugin_configuration']), 'plugin_configuration carries exactly the processor\'s own configuration keys.');
    $this->assertSame('chatbot', $body['plugin_configuration']['assistant_id'], 'The configured assistant rides inside the processor configuration.');
    $this->assertFalse($body['plugin_configuration']['stream_output'], 'The processor never streams its output.');
    $this->assertTrue($body['plugin_configuration']['verbose_mode']);
    $this->assertFalse($body['plugin_configuration']['show_structured_results']);
    // The pre-1.5 top-level keys are gone: the controller reads none of
    // them, and a surviving one is a second spelling of the contract.
    foreach (['assistant_id', 'structured_results', 'show_copy_icon', 'verbose_mode'] as $legacy_key) {
      $this->assertArrayNotHasKey($legacy_key, $body, "No pre-1.5 top-level '$legacy_key' survives in the body.");
    }

    // drupalSettings the client bootstraps deep-chat from.
    $settings = $build['#attached']['drupalSettings']['ai_deepchat'];
    $this->assertSame('chatbot', $settings['assistant_id']);
    $this->assertSame('thread-xyz', $settings['thread_id']);
    $this->assertTrue($settings['session_exists'], 'A started session is reported to the client.');

    // Attachments: the widget's attach button and drop zone take the lane's
    // accepted formats (the textual and font kinds here, with no media type
    // installed) and the client reads the same formats and the lane's size
    // ceiling from drupalSettings for its pre-upload refusal.
    $attributes = $build['chat']['deep_chat']['#attributes'];
    $expected_formats = implode(',', array_map(static fn (string $extension): string => ".$extension", [...FileLane::TEXTUAL_EXTENSIONS, ...FileLane::FONT_EXTENSIONS]));
    $mixed_files = Json::decode($attributes['mixedfiles']);
    $this->assertSame($expected_formats, $mixed_files['files']['acceptedFormats'], 'The attach button accepts exactly what the lane accepts.');
    $this->assertSame('inside-left', $mixed_files['button']['position']);
    $this->assertTrue(Json::decode($attributes['draganddrop']), 'The chat is a drop zone.');
    $attachments = $build['#attached']['drupalSettings']['daedalus_ai']['attachments'];
    $this->assertSame($expected_formats, $attachments['accepted_formats']);
    $this->assertSame(FileLane::SIZE_LIMIT, $attachments['size_limit']);

    // History filter: the >1-day message is dropped; the fresh one survives and
    // its markdown is rendered to HTML.
    $messages = $settings['messages'];
    $this->assertCount(1, $messages, 'Only history newer than one day is replayed.');
    $this->assertSame('assistant', $messages[0]['role']);
    $this->assertStringContainsString('hi', $messages[0]['html']);
  }

}
