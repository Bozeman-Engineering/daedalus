<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Plugin\Sidebar;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\daedalus_ui\SidebarPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Serialization\Yaml;

/**
 * The always-on AI assistant chat panel.
 *
 * A no-mode, no-tool sidebar: it exists on every page (gated only by the
 * "use ai assistant" permission) and its visibility is driven purely by its
 * open/close cookie, so the single <deep-chat> element survives every Edit
 * Mode toggle untouched. Having no mode binding, its toggle is rendered by
 * the collector as a standalone floating button; in Edit Mode that launcher
 * is hidden and the mode's top bar collects the toggle instead (see
 * Edit::buildTopBar).
 */
#[Sidebar(
  id: 'daedalus_ai',
  label: new TranslatableMarkup('AI'),
  side: 'right',
  // Bottom-right is where people expect an AI chat (the web-wide chat
  // convention); the top slot belongs to the Daedalus Stylish inspector. Both can be
  // open at once -- the AI restyles while the inspector shows values change.
  slot: 'bottom',
  // In Edit Mode the toggle collects into the top bar's right toggle group;
  // after the Daedalus Stylish inspector (weight 0) so AI sits beside Styles.
  weight: 2,
  sidebar_type: 'default',
  // The <deep-chat> custom element upgrades from a deferred ES module; wired
  // inline before the upgrade, its JS-property callbacks get wiped. Render the
  // panel through a lazy builder so that, under BigPipe, it streams in
  // post-upgrade. See DaedalusUi::buildSidebars().
  use_lazy_builder: TRUE,
  hot_key: 'a',
)]
final class ChatSidebar extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use ButtonBuilderTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiAssistantApiRunner $aiAssistantRunner,
    protected CurrentPathStack $currentPath,
    protected RequestStack $requestStack,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected CsrfTokenGenerator $csrfToken,
    protected AccountProxyInterface $currentUser,
    protected ModePluginManager $modeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('ai_assistant_api.runner'),
      $container->get('path.current'),
      $container->get('request_stack'),
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('csrf_token'),
      $container->get('current_user'),
      $container->get('plugin.manager.modes'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    return $this->currentUser->hasPermission('use ai assistant');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->configFactory->get('daedalus_ai.settings');
    $assistant_id = $config->get('assistant_id');

    $assistant = $this->entityTypeManager->getStorage('ai_assistant')
      ->load($assistant_id);
    if (!$assistant) {
      return [];
    }

    $this->aiAssistantRunner->setAssistant($assistant);
    $thread_id = $this->aiAssistantRunner->getThreadsKey();

    // Url::fromRoute() yields a stale token for this endpoint, so mint it
    // directly from the CSRF generator.
    $token = $this->csrfToken->get('api/deepchat');
    $url = '/api/deepchat?token=' . $token;
    $connect = Json::encode([
      'url' => $url,
      'method' => 'POST',
      // No streaming on either side. The server must answer JSON, never
      // SSE: the continuation loop and the DeepChatResponseListener
      // injections both ride the JSON body, and an agent-fronted assistant
      // cannot stream anyway (ai_agents buffers its LLM calls). deep-chat's
      // simulation mode (progressive rendering of a JSON reply) is not an
      // option either: its HTML splitter emits every element and then each
      // of its descendants as separate chunks, so lists, links, and the
      // copy button render twice, and it re-runs the responseInterceptor
      // per chunk, which resets ai_chatbot's should_continue flag before
      // onMessage fires.
      'additionalBodyProps' => [
        'assistant_id' => $assistant_id,
        'stream' => FALSE,
        'structured_results' => FALSE,
        'show_copy_icon' => TRUE,
        'verbose_mode' => TRUE,
        'contexts' => [
          'current_route' => $this->currentPath->getPath(),
        ],
      ],
    ]);

    $deepchat_attributes = $this->getDeepChatStyleAttributes();

    return [
      // Per-session CSRF token and message history make the panel uncacheable.
      '#cache' => ['max-age' => 0],
      // The shared header grammar (SidebarPluginBase::buildPanelHeader):
      // the assistant's name as the title, the options kebab as the one
      // action, and the close control the grammar appends last.
      'header' => $this->buildPanelHeader(
        [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $assistant->label(),
          '#attributes' => ['class' => ['daedalus-ai-chat-title']],
        ],
        [
          'menu' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => '⋮',
            '#attributes' => [
              'type' => 'button',
              'class' => [
                'daedalus-ai-chat-menu-button',
                'daedalus-ui-icon-button',
                'daedalus-ui-icon-button--muted',
              ],
              // Secondary chrome: transparent from minimal density up until
              // the pointer nears (daedalus_ui's reveal spine).
              'data-daedalus-ui-conceal' => 'minimal',
              'title' => $this->t('Chat options'),
              'aria-label' => $this->t('Chat options'),
              // Opens the shared menu (daedalus_ui/menu) with the Clear
              // History item; binding lives in daedalus-ai-chat-menu.js.
              'aria-haspopup' => 'menu',
              'aria-expanded' => 'false',
            ],
          ],
        ],
      ),
      'chat' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['chat-container'],
          'data-chat-id' => 'bot1',
        ],
        'deep_chat' => [
          '#type' => 'html_tag',
          '#tag' => 'deep-chat',
          '#attributes' => [
            'class' => ['deepchat-element'],
            // Deep-chat only applies its DEFAULT_COMPONENT_STYLE properties
            // (including the 1px solid #cacaca border) to inline styles that
            // serialize as empty. 'border: none' fails that test — all of its
            // longhands are initial values, so style.borderTop serializes to
            // '' and deep-chat overwrites it. A zero-width transparent solid
            // border is non-initial in every component, so it survives and
            // renders as no border. The font-family and color pin the chrome
            // typeface and text token past the same defaulting (deep-chat
            // would otherwise apply its own Inter stack); both inherit into
            // the shadow DOM.
            'style' => 'height: 100%; width: 100%; border: 0 solid transparent; background: transparent; font-family: var(--daedalus-font); color: var(--daedalus-text);',
            'connect' => $connect,
          ] + $deepchat_attributes,
        ],
      ],
      '#attached' => [
        'library' => $this->getLibraries(),
        'drupalSettings' => [
          'ai_deepchat' => [
            'assistant_id' => $assistant_id,
            'thread_id' => $thread_id,
            'messages' => $this->getHistoricalMessages(),
            'session_exists' => $this->requestStack->getCurrentRequest()->getSession()->isStarted(),
            'verbose_mode' => TRUE,
            'loading_message' => '',
            'show_copy_icon' => TRUE,
            'structured_results' => FALSE,
          ],
        ],
      ],
    ];
  }

  /**
   * The libraries the chat panel attaches.
   *
   * The panel owns its whole front end (there is no DaedalusAi TOOL, and
   * Sidebar plugins have no attachments seam: build() '#attached' is the
   * seam, as for the Add panel). The edit-mode chrome (selection chips +
   * the layout/view-mode context provider) attaches only where Edit Mode
   * applies: on non-editable pages the chat is just a chat.
   *
   * @return string[]
   *   Library names for '#attached'.
   */
  protected function getLibraries(): array {
    $libraries = ['daedalus_ai/chat'];
    if (isset($this->modeManager->getModePlugins()['edit'])) {
      $libraries[] = 'daedalus_ai/edit_mode';
      $libraries[] = 'daedalus_ai/layout_context';
    }
    return $libraries;
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-ai-chat-toggle',
      $this->t('AI'),
      $this->t('Open the AI assistant'),
      'daedalus_ai',
      'ai',
      ['data-sidebar-button-for' => '#daedalus_ai'],
    );
  }

  /**
   * Gets historical chat messages for the current session.
   *
   * @return array
   *   The message history formatted for DeepChat.
   */
  protected function getHistoricalMessages(): array {
    $messages = [];
    $assistant = $this->aiAssistantRunner->getAssistant();
    if ($assistant->get('allow_history') === 'none') {
      return $messages;
    }

    $session_messages = $this->aiAssistantRunner->getMessageHistory();
    $converter = NULL;
    if (class_exists('League\CommonMark\CommonMarkConverter')) {
      // @codingStandardsIgnoreLine
      $converter = new \League\CommonMark\CommonMarkConverter();
    }

    foreach ($session_messages as $message) {
      if (isset($message['timestamp']) && $message['timestamp'] > strtotime('-1 day')) {
        $messages[] = [
          'role' => $message['role'],
          'html' => $converter ? $converter->convert($message['message'])->__toString() : $message['message'],
        ];
      }
    }

    return $messages;
  }

  /**
   * Builds deep-chat element attributes from ai_chatbot's toolbar style.
   *
   * @return array
   *   Attributes array with JSON-encoded style parameters.
   */
  protected function getDeepChatStyleAttributes(): array {
    $style_path = $this->moduleHandler->getModule('ai_chatbot')->getPath()
      . '/deepchat_styles/toolbar.yml';
    $style = Yaml::decode(file_get_contents($style_path));
    $params = $style['parameters'] ?? [];

    // Tighten AI message spacing for the narrow sidebar by overriding the
    // inline styles deep-chat applies to its AI message containers (inherited
    // from ai_chatbot's toolbar.yml) rather than editing contrib.
    if (isset($params['messageStyles']['default']['ai'])) {
      $ai = &$params['messageStyles']['default']['ai'];
      // Outer container: replace marginRight:auto + marginBottom:16px with a
      // tight 8px vertical / 0 horizontal margin.
      unset(
        $ai['outerContainer']['marginTop'],
        $ai['outerContainer']['marginBottom'],
        $ai['outerContainer']['marginLeft'],
        $ai['outerContainer']['marginRight'],
      );
      $ai['outerContainer']['margin'] = '0.5rem 0 0 0';
      $ai['outerContainer']['width'] = '100%';
      // The inner container inherits marginRight:12px from the shared style;
      // zero it.
      $ai['innerContainer']['marginRight'] = '0';
      unset($ai);
    }
    if (isset($params['messageStyles']['default']['user'])) {
      $user = &$params['messageStyles']['default']['user'];
      unset(
        $user['outerContainer']['marginTop'],
        $user['outerContainer']['marginBottom'],
        $user['outerContainer']['marginLeft'],
        $user['outerContainer']['marginRight'],
      );
      $user['outerContainer']['margin'] = '.5rem .5rem 1rem auto';
      unset($user);
    }

    // Recolor toolbar.yml's hardcoded Gin palette with the Daedalus chrome
    // tokens. Custom properties inherit across the shadow boundary, so these
    // var() references resolve inside deep-chat's shadow DOM and follow the
    // light/dark scheme with the rest of the chrome.
    $params['messageStyles']['default']['user']['outerContainer']['backgroundColor'] = 'var(--daedalus-toggle-background)';
    $params['messageStyles']['default']['user']['bubble']['color'] = 'var(--daedalus-text)';
    $params['messageStyles']['default']['ai']['bubble']['color'] = 'var(--daedalus-text)';
    // The input wears the chrome's recessed-well treatment (the block
    // palette's filter pill in daedalus_layout): a borderless toggle-background well —
    // inputs are wells, not raised boxes. The focus entry stays to suppress
    // deep-chat's default blue focus border; focus shows no ring at all. The
    // border stays 1px transparent so focus doesn't shift layout.
    $params['textInput']['styles']['container']['backgroundColor'] = 'var(--daedalus-toggle-background)';
    $params['textInput']['styles']['container']['border'] = '1px solid transparent';
    $params['textInput']['styles']['container']['borderRadius'] = 'var(--daedalus-border-radius)';
    $params['textInput']['styles']['text']['color'] = 'var(--daedalus-text)';
    $params['textInput']['styles']['focus'] = [
      'outline' => 'none',
      'border' => '1px solid transparent',
      'boxShadow' => 'none',
    ];
    $params['textInput']['placeholder']['style']['color'] = 'var(--daedalus-text-muted)';

    // Injected into deep-chat's shadow DOM. The chat-view rule keeps the
    // message list and input on separate grid rows. The text-input rules let
    // the input grow with its content and scroll once it reaches 350px,
    // overriding the fixed height that ai_chatbot's toolbar style applies
    // inline on the container (hence the !important).
    $auxiliary_style = <<<'CSS'
      #chat-view { grid-template-rows: 1fr auto; }
      #text-input-container { height: auto !important; max-height: 350px; }
      #text-input { max-height: 350px; overflow-y: auto; }
      /* Match the chrome's thin token-colored scrollbars in place of
         deep-chat's fat hardcoded gray ones. The standard properties win in
         Chromium (setting them disables ::-webkit-scrollbar styling); the
         webkit overrides carry Safari, out-cascading deep-chat's
         equal-specificity rules by order. */
      #messages, #text-input {
        scrollbar-width: thin;
        scrollbar-color: var(--daedalus-scrollbar-thumb) transparent;
      }
      ::-webkit-scrollbar { width: 9px; }
      ::-webkit-scrollbar-thumb {
        background-color: var(--daedalus-scrollbar-thumb);
      }
      ::-webkit-scrollbar-track { background-color: transparent; }
      CSS;

    // Let other modules theme the chat panel: adjust deep-chat style parameters
    // (brand colors, fonts, message bubbles) and append shadow-DOM CSS. Runs
    // before encoding so implementations work with the structured arrays.
    $this->moduleHandler->alter('daedalus_ai_deepchat_style', $params, $auxiliary_style);

    $attributes = [
      'htmlclassutilities' => '{}',
      'errormessages' => Json::encode(['displayServiceErrorMessages' => TRUE]),
      'auxiliarystyle' => $auxiliary_style,
    ];

    $keys = [
      'messageStyles',
      'textInput',
      'submitButtonStyles',
      'avatars',
      'names',
    ];
    foreach ($keys as $key) {
      if (isset($params[$key])) {
        $attributes[strtolower($key)] = Json::encode($params[$key]);
      }
    }

    return $attributes;
  }

}
