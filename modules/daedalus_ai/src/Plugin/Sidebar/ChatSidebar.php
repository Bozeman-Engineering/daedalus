<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Plugin\Sidebar;

use Drupal\ai_assistant_api\AiAssistantApiRunner;
use Drupal\ai_chatbot\Plugin\Block\DeepChatFormBlock;
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
use Drupal\Core\Url;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\ReplyDisplay;
use Drupal\daedalus_ai\ThreadOwnership;
use Drupal\daedalus_ai\ModelChoiceOption;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\File\FileLane;
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
 *
 * The header's kebab carries three groups above its own items, each only
 * when it has content, all fed through drupalSettings.daedalus_ai: the
 * curated models a user holding "choose ai model" may run the chat on
 * (modelChoices, the current one marked), the usage meter's route
 * (usageUrl) when a configured cap applies to the user: a site window
 * applies to everyone, a user window to those without "bypass ai usage
 * caps" (UsageGate::windowsFor() is the one definition), and the AI
 * context usage display's route (contextDialogUrl, the "Design system
 * size" row's target) for a user holding "view ai context usage"
 * (MaterialsBudget::PERMISSION). Budgeting and the model choice are off
 * until an administrator configures them, so a plain site renders
 * neither of those keys; the dialog URL is the permission's alone.
 */
#[Sidebar(
  id: 'daedalus_ai',
  label: new TranslatableMarkup('AI'),
  side: 'right',
  // Bottom-right is where people expect an AI chat (the web-wide chat
  // convention); the top slot belongs to the Daedalus Stylish inspector. Both
  // can be open at once -- the AI restyles while the inspector shows values
  // change.
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

  /**
   * The usage gate, when the ledger module is installed.
   *
   * The ledger (daedalus_ai_ledger) is a dependency of daedalus_ai_memory,
   * not of this module, so the gate is the one optional service here: NULL
   * on a site without the ledger, where there is no cap to show.
   */
  protected ?UsageGate $usageGate;

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
    protected FileLane $fileLane,
    protected ModelChoice $modelChoice,
    protected ThreadOwnership $threadOwnership,
    protected ReplyDisplay $replyDisplay,
    ?UsageGate $usage_gate = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->usageGate = $usage_gate;
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
      $container->get('daedalus_ui.file_lane'),
      $container->get('daedalus_ai.model_choice'),
      $container->get('daedalus_ai.thread_ownership'),
      $container->get('daedalus_ai.reply_display'),
      $container->get('module_handler')->moduleExists('daedalus_ai_ledger')
        ? $container->get('daedalus_ai_ledger.usage_gate')
        : NULL,
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
    // The id goes to this user's browser and comes back on the attach
    // route and the op endpoint, which take it only from this user.
    $this->threadOwnership->record($thread_id);

    // Url::fromRoute() yields a stale token for this endpoint, so mint it
    // directly from the CSRF generator.
    $token = $this->csrfToken->get('api/deepchat');
    $url = '/api/deepchat?token=' . $token;
    $connect = Json::encode([
      'url' => $url,
      'method' => 'POST',
      // No streaming on either side. The server must answer JSON, never
      // SSE: the continuation loop and the DeepChatResponseListener
      // injections both ride the JSON body, and the memory runner absorbs
      // each leg's whole reply into the transcript before it answers.
      // (ai_assistant_api can stream an agent-fronted assistant since
      // drupal/ai 1.5.3; this sidebar does not ask it to.) deep-chat's
      // simulation mode (progressive rendering of a JSON reply) is not an
      // option either: its HTML splitter emits every element and then each
      // of its descendants as separate chunks, so lists, links, and the
      // copy button render twice, and it re-runs the responseInterceptor
      // per chunk, which resets ai_chatbot's should_continue flag before
      // onMessage fires.
      //
      // The body is ai_chatbot's chat-turn request contract (drupal/ai 1.5:
      // DeepChatApi::api() refuses any body without a chat_processor_plugin
      // it knows), and this object is the ONE place daedalus spells it:
      // ai_chatbot's deepchat-init.js reads the processor keys off this same
      // object for its clear-history reset and its prepend call, and the
      // follow-up loop derives its body from it. The processor id is
      // ai_chatbot's own constant, so a rename there fails here at load;
      // plugin_configuration carries exactly the processor's
      // defaultConfiguration() keys (ChatSidebarTest pins both against the
      // live plugin).
      'additionalBodyProps' => [
        'chat_processor_plugin' => DeepChatFormBlock::ASSISTANT_PROCESSOR_PLUGIN_ID,
        'plugin_configuration' => [
          'assistant_id' => $assistant_id,
          'stream_output' => FALSE,
          'verbose_mode' => TRUE,
          'show_structured_results' => FALSE,
        ],
        'stream' => FALSE,
        'contexts' => [
          'current_route' => $this->currentPath->getPath(),
        ],
      ],
    ]);

    $deepchat_attributes = $this->getDeepChatStyleAttributes();

    // The kebab's groups: the curated models for a user who may choose,
    // the meter's route for a user the caps apply to, and the AI context
    // usage's routes for a user who may read it. Absent keys mean absent
    // groups; the client never guesses.
    $daedalus_ai_settings = [
      // The attachment lane as the client sees it: the context manager's
      // interceptor refuses a file over the ceiling before uploading
      // (deep-chat has no size gate of its own) and the widget's attach
      // button and drop zone accept the same formats the lane does (the
      // mixedFiles attribute below).
      'attachments' => [
        'accepted_formats' => $this->acceptedFormats(),
        'size_limit' => FileLane::SIZE_LIMIT,
      ],
    ];
    $model_choices = $this->modelChoices();
    if ($model_choices !== []) {
      $daedalus_ai_settings['modelChoices'] = $model_choices;
    }
    $usage_url = $this->usageUrl();
    if ($usage_url !== NULL) {
      $daedalus_ai_settings['usageUrl'] = $usage_url;
    }
    $context_dialog_url = $this->contextDialogUrl();
    if ($context_dialog_url !== NULL) {
      $daedalus_ai_settings['contextDialogUrl'] = $context_dialog_url;
    }

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
              // Opens the shared menu (daedalus_ui/menu): the model and
              // usage groups when configured, the "Design system size"
              // row with the permission, and Clear History; binding
              // lives in daedalus-ai-chat-menu.js.
              // The usage script marks it data-usage="low" or "out".
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
        'library' => $this->getLibraries($usage_url !== NULL, $context_dialog_url !== NULL),
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
          'daedalus_ai' => $daedalus_ai_settings,
        ],
      ],
    ];
  }

  /**
   * The lane's accepted extensions as deep-chat's acceptedFormats string.
   *
   * @return string
   *   Comma-separated dotted extensions (".png,.svg,..."), the spelling
   *   deep-chat matches attached files against and hands the file input's
   *   accept attribute.
   */
  protected function acceptedFormats(): string {
    $dotted = array_map(
      static fn (string $extension): string => ".$extension",
      $this->fileLane->acceptedExtensions(),
    );
    return implode(',', $dotted);
  }

  /**
   * The permission the editor-preference lane's save route is gated on.
   *
   * The choice is stored through daedalus_ui's generic save route, which
   * admits only users who may use Edit Mode; a user without it could see
   * the group and never save a pick, so the group is theirs only with both
   * permissions.
   */
  protected const SAVE_PERMISSION = 'use daedalus ui edit mode';

  /**
   * The curated models the kebab lists, with the current one marked.
   *
   * Only for a user holding the choice permission (and the lane's save
   * permission) and only when the administrator curated at least one
   * model; otherwise empty and the group is absent. The current one is
   * the user's valid choice, else the pair the assistant resolves to when
   * it is in the list (the site default, which is what runs with no
   * choice stored), else none is marked.
   *
   * @return list<array{key: string, model: string, label: string|null, current: bool}>
   *   One row per curated model, in config order.
   */
  protected function modelChoices(): array {
    if (!$this->currentUser->hasPermission(ModelChoice::PERMISSION) || !$this->currentUser->hasPermission(self::SAVE_PERMISSION)) {
      return [];
    }
    $choices = $this->modelChoice->choices();
    if ($choices === []) {
      return [];
    }
    $current = $this->modelChoice->current($this->currentUser)?->key();
    if ($current === NULL) {
      $pair = $this->aiAssistantRunner->getProviderAndModel();
      if (!empty($pair['provider_id']) && !empty($pair['model_id'])) {
        $default = $pair['provider_id'] . ModelChoiceOption::SEPARATOR . $pair['model_id'];
        foreach ($choices as $option) {
          if ($option->key() === $default) {
            $current = $default;
            break;
          }
        }
      }
    }
    return array_map(static fn (ModelChoiceOption $option): array => [
      'key' => $option->key(),
      'model' => $option->model,
      'label' => $option->label,
      'current' => $option->key() === $current,
    ], $choices);
  }

  /**
   * The usage meter's route, when the user has a meter to read.
   *
   * NULL without the ledger module and when no configured window applies
   * to the user (UsageGate::windowsFor(): the site windows apply to
   * everyone, the user windows to those without the bypass permission,
   * the same rule the gate refuses by and the status route answers by).
   * No bypass check of its own.
   *
   * @return string|null
   *   The status route's path, or NULL.
   */
  protected function usageUrl(): ?string {
    if ($this->usageGate === NULL || $this->usageGate->windowsFor($this->currentUser) === []) {
      return NULL;
    }
    return Url::fromRoute('daedalus_ai_ledger.usage')->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * The AI context usage display's route, for a user who may read it.
   *
   * The materials budget (daedalus_blueprint's MaterialsBudget: what the
   * site shares with the AI as materials, against the budget an admin
   * set) shows as one display, which the kebab's "Design system size"
   * row opens in the daedalus popup through its one opener
   * (daedalus_ui/modal, Drupal.Daedalus.openModal), under one permission,
   * MaterialsBudget::PERMISSION. It rides by route name; the client
   * never spells a path. NULL without the permission, and the row is
   * absent.
   *
   * @return string|null
   *   The dialog route's URL, or NULL.
   */
  protected function contextDialogUrl(): ?string {
    if (!$this->currentUser->hasPermission(MaterialsBudget::PERMISSION)) {
      return NULL;
    }
    return Url::fromRoute('daedalus_blueprint.ai_context')->toString(TRUE)->getGeneratedUrl();
  }

  /**
   * The libraries the chat panel attaches.
   *
   * The panel owns its whole front end (there is no DaedalusAi TOOL, and
   * Sidebar plugins have no attachments seam: build() '#attached' is the
   * seam, as for the Add panel). The edit-mode chrome (selection chips +
   * the layout/view-mode context provider) attaches only where Edit Mode
   * applies: on non-editable pages the chat is just a chat. The usage
   * script attaches only when there is a meter to fetch, the popup's
   * opener (the usage display's door) only when the user may read it.
   *
   * @param bool $usage
   *   Whether the usage meter's route is set for this user.
   * @param bool $context
   *   Whether the AI context usage display's route is set for this user.
   *
   * @return string[]
   *   Library names for '#attached'.
   */
  protected function getLibraries(bool $usage, bool $context): array {
    $libraries = ['daedalus_ai/chat'];
    if ($usage) {
      $libraries[] = 'daedalus_ai/usage';
    }
    if ($context) {
      // The kebab's "Design system size" row opens the usage display in
      // the daedalus popup through its one opener; the chat hosts it on
      // any page, edit mode or not.
      $libraries[] = 'daedalus_ui/modal';
    }
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
   * The chat widget renders each message's `html` as markup, so a stored
   * message is replayed through the conversion and filters a live reply
   * passes (ReplyDisplay::html()), never as it was stored.
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

    foreach ($this->aiAssistantRunner->getMessageHistory() as $message) {
      if (isset($message['timestamp']) && $message['timestamp'] > strtotime('-1 day')) {
        $messages[] = [
          'role' => $message['role'],
          'html' => $this->replyDisplay->html((string) $message['message']),
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
    // The input wears the chrome's recessed-well treatment (the block palette's
    // filter pill in daedalus_layout): a borderless toggle-background well —
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
    // The attach button sits inside the well at its start (mixedFiles below)
    // and spans about 32px from the edge; toolbar.yml's inline 18px left
    // padding overrides the clearance deep-chat's own inside-left class
    // would give the text, so the text (and placeholder) started under the
    // clip. 44px clears the button with a 12px gap; the right side keeps
    // toolbar.yml's room for the submit button.
    $params['textInput']['styles']['text']['padding'] = '16px 60px 16px 44px';

    // Attachments: the attach button (deep-chat's mixedFiles, any kind the
    // lane accepts, the formats from the lane so the widget refuses what
    // the server would) inside the input well at its start, and the whole
    // chat as a drop zone. The interceptor uploads the attached files and
    // re-encodes the turn as JSON (daedalus-ai-context-manager.js); the
    // widget never posts multipart itself.
    $params['mixedFiles'] = [
      'files' => ['acceptedFormats' => $this->acceptedFormats()],
      'button' => ['position' => 'inside-left'],
    ];
    $params['dragAndDrop'] = TRUE;

    // Injected into deep-chat's shadow DOM. The chat-view rule keeps the
    // message list and input on separate grid rows; the messages rules
    // anchor the list at its end. The text-input rules let
    // the input grow with its content and scroll once it reaches 350px,
    // overriding the fixed height that ai_chatbot's toolbar style applies
    // inline on the container (hence the !important).
    $auxiliary_style = <<<'CSS'
      #chat-view { grid-template-rows: 1fr auto; }
      /* The list is a column anchored at its end: a short history sits
         against the input and grows upward. The spacer is a pseudo-element
         (deep-chat's message wrapper carries inline margins, which would
         beat a margin rule here) that takes the free space and shrinks to
         nothing once the history overflows, so scrolling and
         scrollToBottom() behave as before. */
      #messages { display: flex; flex-direction: column; }
      #messages::before { content: ""; flex: 1 1 auto; }
      #text-input-container { height: auto !important; max-height: 350px; }
      #text-input { max-height: 350px; overflow-y: auto; }
      /* Safari: with deep-chat's own white-space: normal, WebKit swallows a
         Space typed exactly at the wrap point of this padded contenteditable
         flex item (the nbsp lands in the DOM but innerText and the line
         count do not move, so the key looks dead; WebKit bug 156657's
         lineage, still live on this geometry). pre-wrap is the textarea
         text model, cures it, and changes nothing in Chromium. Verified by
         the WebKit probe beside the safari-deep-chat note (W44); no FJS
         step can see it, chromedriver never shows the bug. */
      #text-input { white-space: pre-wrap; }
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
      'mixedFiles',
      'dragAndDrop',
    ];
    foreach ($keys as $key) {
      if (isset($params[$key])) {
        $attributes[strtolower($key)] = Json::encode($params[$key]);
      }
    }

    return $attributes;
  }

}
