<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Plugin\DisplayVariant;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\MessagesBlockPluginInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Display\ContextAwareVariantInterface;
use Drupal\Core\Display\PageVariantInterface;
use Drupal\Core\Display\VariantBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_layout\Frame\MainContentStandIn;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page rendered as its theme's site frame around the route's output.
 *
 * The site frame is the outermost document of the stack: a daedalus_frame
 * config entity per theme, rendered around every front-end route of that
 * theme. This variant replaces the block module's page variant for a
 * theme that has a frame (FramePageVariant selects it): block layout is
 * not consulted, the frame's document is the page. It renders through the
 * one primitive (InnerRender::renderFrame()): the frame's own sections as
 * a document root, the route's entity as the subject when the route has
 * one, and the route's output (RouteOutput, carrying the route's host and
 * the main slot's stand-in) carried into the render, where the slot
 * hosting the route (InnerRender::routeSlot(): the frame's main slot,
 * daedalus_slot:main, or on a blueprint's canvas the door whose default
 * is that blueprint) prints the main content inside the page's main
 * landmark, main prints the stand-in (MainContentStandIn) when a door
 * hosts the route, and a placed title block (TitleBlockPluginInterface,
 * fed by FrameTitle) shows the title. Status
 * messages render through a placed messages block
 * (MessagesBlockPluginInterface) and, when the frame places none, are
 * prepended to the body, so a frame can never lose them (core's rule in
 * BlockPageVariant, Canvas's in its page variant).
 *
 * The build lands in the `content` region, the one region core's own
 * variants fill and every theme declares: a theme shows the frame by
 * printing that region and nothing else (daedalus_theme's page template),
 * and the frame's root containers are what its regions used to be.
 */
#[PageDisplayVariant(
  id: DaedalusPageVariant::ID,
  admin_label: new TranslatableMarkup('Daedalus site frame'),
)]
final class DaedalusPageVariant extends VariantBase implements PageVariantInterface, ContextAwareVariantInterface, ContainerFactoryPluginInterface {

  /**
   * The plugin id.
   */
  public const ID = 'daedalus_page';

  /**
   * The configuration key naming the frame: the theme's machine name.
   */
  public const FRAME_KEY = 'frame';

  /**
   * The main content render array.
   */
  protected array $mainContent = [];

  /**
   * The page title: a string, or a render array for a formatted title.
   */
  protected string|array|\Stringable $title = '';

  /**
   * The contexts the selection event handed over: the route's entity.
   *
   * @var \Drupal\Core\Plugin\Context\ContextInterface[]
   */
  protected array $contexts = [];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected InnerRender $innerRender,
    protected HostResolver $hostResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DaedalusLayoutAdapter $layoutAdapter,
    protected BlockManagerInterface $blockManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('daedalus_blueprint.inner_render'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('entity_type.manager'),
      $container->get('daedalus_blueprint.daedalus_layout_adapter'),
      $container->get('plugin.manager.block'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [self::FRAME_KEY => NULL] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setMainContent(array $main_content) {
    $this->mainContent = $main_content;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle($title) {
    $this->title = $title;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getContexts() {
    return $this->contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function setContexts(array $contexts) {
    $this->contexts = $contexts;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $frame = $this->hostResolver->fromEntity($this->frame());
    $subject = $this->subject();
    $output = new RouteOutput($this->mainContent, $this->title, $this->routeHost($subject), MainContentStandIn::build());
    $build = [
      'content' => [
        'frame' => $this->innerRender->renderFrame($frame, $output, $subject),
      ],
    ];
    $slots = $this->layoutAdapter->declaredSlots($frame->ownSections());
    if (!in_array(FrameHost::MAIN_SLOT, $slots, TRUE)) {
      // A frame that declares no main slot would swallow every page of its
      // theme: the main content still shows, after the frame, as the
      // block module shows it when no block does. The frame canvas is
      // where the site builder puts the slot where it belongs. A frame
      // that places a main slot the roster no longer names is the same
      // case: the adapter counts only the slots whose block exists, so a
      // roster write core never validated (drush config:set, a bare
      // config save) dropping main lands here too, the main content after
      // the frame and core's broken block where the slot was.
      $build['content']['main'] = ['#weight' => 1000] + $this->mainContent;
    }
    if (!$this->placesMessages($frame)) {
      $build['content']['messages'] = [
        '#type' => 'status_messages',
        '#weight' => -1000,
        '#include_fallback' => TRUE,
      ];
    }
    CacheableMetadata::createFromObject($this)
      ->addCacheableDependency($frame->entity())
      ->applyTo($build);
    return $build;
  }

  /**
   * The frame this variant renders: the configured theme's.
   */
  protected function frame(): FrameInterface {
    $theme = $this->configuration[self::FRAME_KEY] ?? NULL;
    $frame = is_string($theme) && $theme !== ''
      ? $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID)->load($theme)
      : NULL;
    if (!$frame instanceof FrameInterface) {
      throw new \LogicException(sprintf('The %s page display variant was built without a frame to render (configuration "%s" names the theme whose frame it is; FramePageVariant selects it only for a theme that has one).', self::ID, self::FRAME_KEY));
    }
    return $frame;
  }

  /**
   * The route's entity, from the contexts the selection handed over.
   */
  protected function subject(): ?FieldableEntityInterface {
    $context = $this->contexts['entity'] ?? NULL;
    if ($context === NULL || !$context->hasContextValue()) {
      return NULL;
    }
    $subject = $context->getContextValue();
    return $subject instanceof FieldableEntityInterface ? $subject : NULL;
  }

  /**
   * The route's host, carried on the route output for the frame's slots.
   *
   * The entity the selection handed over is the route host's own fields
   * entity (FramePageVariant hands HostInterface::fields(), resolved by
   * daedalus_ui's one route derivation), so its host is the route host: a
   * page's content host, a blueprint's host on its canvas. NULL on a route
   * without one (a view page, a form), which the main slot hosts.
   */
  protected function routeHost(?FieldableEntityInterface $subject): ?HostInterface {
    return $subject !== NULL && $this->hostResolver->hosts($subject)
      ? $this->hostResolver->fromEntity($subject)
      : NULL;
  }

  /**
   * Whether the frame places a block that shows status messages.
   *
   * Judged by the plugin definitions of what the frame's document places,
   * at any depth, without building a plugin: a MessagesBlockPluginInterface
   * block anywhere in the frame is the messages' home.
   */
  protected function placesMessages(HostInterface $frame): bool {
    foreach ($this->layoutAdapter->components($frame->ownSections()) as $component) {
      $definition = $this->blockManager->getDefinition($component->getPluginId(), FALSE);
      if ($definition !== NULL && is_a($definition['class'], MessagesBlockPluginInterface::class, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
