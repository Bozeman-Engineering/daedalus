<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_assistant_api\Entity\AiAssistant;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\Plugin\Sidebar\ChatSidebar;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\Tests\daedalus_ai_ledger\Traits\DaedalusAiServicesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
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
 * The kebab's groups ride drupalSettings.daedalus_ai too: modelChoices
 * (the curated models for a user who may choose, the current one marked),
 * usageUrl (the ledger's status route for a user a configured window
 * applies to: a site window applies to everyone, the administrator's
 * bypass included, a user window to those without the bypass), and the
 * AI context usage display's URL, contextDialogUrl (daedalus_blueprint's
 * dialog route, by route name, for a user holding "view ai context
 * usage" and no one else, the popup opener daedalus_ui/modal attached
 * with it).
 * The first two are off until an administrator configures them, so the
 * render contract above holds on a plain site up to the dialog URL the
 * administrator's permissions bring; with models curated and a
 * site cap set they appear for the administrator and for a permitted
 * user alike and the usage library attaches, and a user cap alone leaves
 * the administrator without the meter. The ledger module is REAL here
 * (daedalus_ai's usage reader registered as its own Kernel tests do), the
 * model choice
 * resolver real over a mocked editor-preference lane (daedalus_ui is not
 * enabled, so its field does not exist) with the stub as the managed
 * provider (the site's default chat provider, since the seeded assistant
 * names none; every curated row must name it), and the permitted user a mocked
 * account on the current-user proxy, since the permissions it needs are
 * declared by modules this test does not enable.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ChatSidebarTest extends KernelTestBase {

  use DaedalusAiServicesTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The ai_chatbot module provides the deep-chat style file and pulls
   * ai_assistant_api (the ai_assistant entity type) + ai as dependencies.
   * The ledger brings the usage gate the sidebar reads the caps through. No
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
    'daedalus_ai_ledger',
    // The AI context usage's two routes the sidebar hands the kebab for a
    // user holding MaterialsBudget::PERMISSION are daedalus_blueprint's
    // (the materials budget's spine); filter is its one service
    // dependency this container must carry.
    'filter',
    'daedalus_blueprint',
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
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerDaedalusAiServices($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ai_usage');
    $this->installConfig(['daedalus_ai_ledger']);

    // A real assistant the sidebar loads by id (matches daedalus_ai.settings).
    // Any non-'none' allow_history keeps the history path active. AiAssistant
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

    // The sidebar reads its assistant id from config; daedalus_ai is not
    // enabled so its default config is seeded directly (as the gate/handler
    // tests do).
    $this->config('daedalus_ai.settings')
      ->set('assistant_id', 'chatbot')
      ->save();

    // The managed provider, the one every curated row must name: the
    // assistant above names none, so it is the site's default chat
    // provider, the stub here.
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => 'daedalus_ai_test_stub', 'model_id' => 'stub'])
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
    // The pair the assistant resolves to (the site default): what the
    // model group marks when the user stored no choice.
    $runner->method('getProviderAndModel')->willReturn(['provider_id' => 'daedalus_ai_test_stub', 'model_id' => 'stub']);

    // The editor-preference lane, mocked: daedalus_ui is not enabled, so
    // the user entity has no settings field to read. $stored is what the
    // lane answers for the model key.
    $stored = NULL;
    $editor_settings = $this->createMock(EditorSettings::class);
    $editor_settings->method('getFor')->willReturnCallback(static function () use (&$stored) {
      return $stored;
    });
    $model_choice = new ModelChoice(
      $this->container->get('config.factory'),
      $editor_settings,
      new NullLogger(),
      $this->container->get('entity_type.manager'),
      $this->container->get('ai.provider'),
    );

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
      // library — the non-editable-page case of getLibraries().
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
        $this->container->get('transliteration'),
        $this->container->get('language_manager'),
      ),
      $model_choice,
      $this->container->get('daedalus_ai_ledger.usage_gate'),
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
    $expected_formats = implode(',', array_map(static fn (string $extension): string => ".$extension", [
      ...FileLane::TEXTUAL_EXTENSIONS,
      ...FileLane::FONT_EXTENSIONS,
    ]));
    $mixed_files = Json::decode($attributes['mixedfiles']);
    $this->assertSame($expected_formats, $mixed_files['files']['acceptedFormats'], 'The attach button accepts exactly what the lane accepts.');
    $this->assertSame('inside-left', $mixed_files['button']['position']);
    $this->assertTrue(Json::decode($attributes['draganddrop']), 'The chat is a drop zone.');
    $attachments = $build['#attached']['drupalSettings']['daedalus_ai']['attachments'];
    $this->assertSame($expected_formats, $attachments['accepted_formats']);
    $this->assertSame(FileLane::SIZE_LIMIT, $attachments['size_limit']);

    // The shadow-DOM CSS keeps the input on the textarea text model: WebKit
    // swallows a Space typed at the wrap point of deep-chat's padded
    // contenteditable flex item under its white-space: normal (the W44
    // safari-deep-chat probe shows it; chromedriver never does, so this
    // string is the suite's whole guard that the rule ships).
    $this->assertMatchesRegularExpression('/#text-input\s*\{[^}]*\bwhite-space:\s*pre-wrap\b/', $attributes['auxiliarystyle'], 'The shadow-DOM CSS sets white-space: pre-wrap on #text-input (the Safari wrap-point Space cure).');

    // History filter: the >1-day message is dropped; the fresh one survives and
    // its markdown is rendered to HTML.
    $messages = $settings['messages'];
    $this->assertCount(1, $messages, 'Only history newer than one day is replayed.');
    $this->assertSame('assistant', $messages[0]['role']);
    $this->assertStringContainsString('hi', $messages[0]['html']);

    // The kebab's configured groups are off until configured: with no
    // model curated and no cap set, the module's settings carry the
    // attachments and the AI context usage display's route (the
    // administrator holds every permission, "view ai context usage"
    // included; the URL is the permission's alone, by route name), and
    // nothing else; the usage library is not attached, the popup opener
    // (daedalus_ui/modal) is.
    $this->assertSame(['attachments', 'contextDialogUrl'], array_keys($build['#attached']['drupalSettings']['daedalus_ai']), 'Neither modelChoices nor usageUrl rides a plain site; the dialog URL rides the permission.');
    $this->assertSame(MaterialsBudget::DIALOG_PATH, $build['#attached']['drupalSettings']['daedalus_ai']['contextDialogUrl'], 'The "Design system size" row opens the spine\'s dialog route, the path the refusal hint names.');
    $this->assertNotContains('daedalus_ai/usage', $build['#attached']['library']);
    $this->assertContains('daedalus_ui/modal', $build['#attached']['library'], 'The "Design system size" row opens the display through the daedalus popup\'s one opener.');

    // Two models curated and a site cap set. A site window applies to
    // everyone, the administrator's bypass included (the gate refuses
    // them on it), so the meter is theirs too; the model group appears,
    // the site default marked since nothing is stored.
    $this->config('daedalus_ai.settings')
      ->set('model_choices', [
        ['provider' => 'daedalus_ai_test_stub', 'model' => 'stub', 'label' => 'Fast'],
        ['provider' => 'daedalus_ai_test_stub', 'model' => 'stub-alt', 'label' => ''],
      ])
      ->save();
    $site_cap = ['scope' => Window::SCOPE_SITE, 'period' => 'P1M', 'usd' => 1.0, 'anchor' => ''];
    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [$site_cap])->save();
    $this->assertTrue($this->container->get('current_user')->hasPermission(UsageGate::BYPASS_PERMISSION), 'The administrator holds the bypass.');
    $build = $sidebar->build();
    $daedalus_ai = $build['#attached']['drupalSettings']['daedalus_ai'];
    $this->assertSame(['attachments', 'modelChoices', 'usageUrl', 'contextDialogUrl'], array_keys($daedalus_ai), 'A site window applies to the administrator: the meter is theirs.');
    $this->assertSame('/daedalus-ai/usage', $daedalus_ai['usageUrl']);
    $this->assertContains('daedalus_ai/usage', $build['#attached']['library']);
    $this->assertSame([
      ['key' => 'daedalus_ai_test_stub__stub', 'model' => 'stub', 'label' => 'Fast', 'current' => TRUE],
      ['key' => 'daedalus_ai_test_stub__stub-alt', 'model' => 'stub-alt', 'label' => NULL, 'current' => FALSE],
    ], $daedalus_ai['modelChoices'], 'The curated list in config order, the site default marked, an empty label absent.');

    // A user window alone is what the bypass skips: no meter for the
    // administrator then, and nothing else changes.
    $this->config(CostResolver::SETTINGS)
      ->set(UsageGate::CAPS_KEY, [
        ['scope' => Window::SCOPE_USER, 'period' => 'PT5H', 'usd' => 1.0, 'anchor' => ''],
      ])
      ->save();
    $build = $sidebar->build();
    $this->assertSame(['attachments', 'modelChoices', 'contextDialogUrl'], array_keys($build['#attached']['drupalSettings']['daedalus_ai']), 'The bypass skips the user windows: no meter for the administrator over a user cap alone.');
    $this->assertNotContains('daedalus_ai/usage', $build['#attached']['library']);
    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [$site_cap])->save();

    // A permitted user the caps apply to, with a stored choice: both
    // configured groups, the stored key marked, the usage library
    // attached; without "view ai context usage" the dialog URL and the
    // popup opener are absent, whatever the site shares.
    $permitted = $this->createMock(AccountInterface::class);
    $permitted->method('id')->willReturn(7);
    $permitted->method('isAuthenticated')->willReturn(TRUE);
    $granted = ['use ai assistant', ModelChoice::PERMISSION, 'use daedalus ui edit mode'];
    $permitted->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $granted, TRUE));
    $this->container->get('current_user')->setAccount($permitted);
    $stored = 'daedalus_ai_test_stub__stub-alt';
    $build = $sidebar->build();
    $daedalus_ai = $build['#attached']['drupalSettings']['daedalus_ai'];
    $this->assertSame(['attachments', 'modelChoices', 'usageUrl'], array_keys($daedalus_ai));
    $this->assertSame([FALSE, TRUE], array_column($daedalus_ai['modelChoices'], 'current'), 'The stored choice is the current one.');
    $this->assertSame('/daedalus-ai/usage', $daedalus_ai['usageUrl'], 'The meter reads the ledger\'s status route.');
    $this->assertContains('daedalus_ai/usage', $build['#attached']['library']);
    $this->assertNotContains('daedalus_ui/modal', $build['#attached']['library'], 'Without the permission the popup opener is not attached.');

    // The permission alone brings the dialog URL and the popup opener; no
    // configured group is needed for it.
    $reader = $this->createMock(AccountInterface::class);
    $reader->method('id')->willReturn(9);
    $reader->method('isAuthenticated')->willReturn(TRUE);
    $reader_held = ['use ai assistant', MaterialsBudget::PERMISSION];
    $reader->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $reader_held, TRUE));
    $this->container->get('current_user')->setAccount($reader);
    $build = $sidebar->build();
    $this->assertSame(['attachments', 'usageUrl', 'contextDialogUrl'], array_keys($build['#attached']['drupalSettings']['daedalus_ai']), 'The dialog URL is the permission\'s alone.');
    $this->assertContains('daedalus_ui/modal', $build['#attached']['library']);
    $this->container->get('current_user')->setAccount($permitted);

    // Without the choice permission the model group is absent whatever is
    // stored; the meter stays. The same without the lane's save permission
    // (the generic save route is gated on Edit Mode): a group whose pick
    // could never be saved is not shown.
    foreach (['use ai assistant', ModelChoice::PERMISSION] as $alone) {
      $unpermitted = $this->createMock(AccountInterface::class);
      $unpermitted->method('id')->willReturn(8);
      $unpermitted->method('isAuthenticated')->willReturn(TRUE);
      $held = ['use ai assistant', $alone];
      $unpermitted->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => in_array($permission, $held, TRUE));
      $this->container->get('current_user')->setAccount($unpermitted);
      $daedalus_ai = $sidebar->build()['#attached']['drupalSettings']['daedalus_ai'];
      $this->assertSame(['attachments', 'usageUrl'], array_keys($daedalus_ai), "With only '$alone' beside the chat permission there is no model group.");
    }
    $this->container->get('current_user')->setAccount($permitted);

    // With the caps cleared again the meter goes for the permitted user
    // too, and their model group stays.
    $this->config(CostResolver::SETTINGS)->set(UsageGate::CAPS_KEY, [])->save();
    $build = $sidebar->build();
    $this->assertSame(['attachments', 'modelChoices'], array_keys($build['#attached']['drupalSettings']['daedalus_ai']));
    $this->assertNotContains('daedalus_ai/usage', $build['#attached']['library']);
  }

}
