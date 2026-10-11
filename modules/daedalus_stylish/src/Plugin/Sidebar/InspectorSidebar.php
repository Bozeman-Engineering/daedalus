<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Sidebar;

use Drupal\Core\Cache\Cache;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\EditSessionScope;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\SidebarPluginBase;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\InspectorGates;
use Drupal\daedalus_stylish\MediaValueResolver;
use Drupal\daedalus_stylish\DaedalusStylishMediaLibraryOpener;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The style inspector: a passive follower of the selection.
 *
 * Not a tool and not a verb — the inspector is a continuous surface that
 * re-renders for whatever element is selected (the Figma properties-panel
 * model). It owns the right/top slot ALONE — the transient right-rail panels
 * (settings, notifications, history, the chat) live in the bottom slot — so
 * like the page-elements tree on the left, nothing evicts it: it opens by
 * default and only its toggle closes it (a close sticks for the rest of the
 * mode session; selection never opens it). Weight puts its toggle right-most
 * in the top bar's button cluster.
 *
 * The build is only a mount point. Daedalus Stylish's own client bundle renders the
 * panel interior (header, property sections, pop-out controls) so the same
 * component can also render in a popped-out satellite window where none of
 * the server-rendered editing chrome exists.
 *
 * The pop-out pair finds each other over daedalus_ui's shared
 * BroadcastChannel wire, under the 'daedalus_stylish' prefix. The channel scope
 * (entity uuid + langcode + workspace) is daedalus_ui's edit-session
 * scope: deterministic naming is what lets either window refresh and
 * reconnect, and the scope boundary is what keeps two different edit
 * sessions (another entity, another translation, another workspace) from
 * cross-talking.
 */
#[Sidebar(
  id: 'daedalus_stylish_inspector',
  label: new TranslatableMarkup('Styles'),
  side: 'right',
  weight: 0,
  mode: 'edit',
  slot: 'top',
  default_open: TRUE,
)]
final class InspectorSidebar extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use ButtonBuilderTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly DaedalusUi $daedalusUi,
    private readonly ClientStyleGuide $clientStyleGuide,
    private readonly InspectorGates $gates,
    private readonly EditSessionScope $editSessionScope,
    private readonly MediaValueResolver $mediaValueResolver,
    private readonly EntityStorageHandler $storageHandler,
    private readonly VerdictStamper $verdictStamper,
    private readonly ?DaedalusStylishMediaLibraryOpener $mediaLibraryOpener = NULL,
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
      $container->get('daedalus_ui.ui'),
      $container->get('daedalus_stylish.client_style_guide'),
      $container->get('daedalus_stylish.inspector_gates'),
      $container->get('daedalus_ui.edit_session_scope'),
      $container->get('daedalus_stylish.media_value_resolver'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint.verdict_stamper'),
      // The opener registers only when media_library is installed
      // (DaedalusStylishServiceProvider); without it the panel simply attaches no
      // library URL and pickers degrade to free text.
      $container->has(DaedalusStylishMediaLibraryOpener::SERVICE_ID) ? $container->get(DaedalusStylishMediaLibraryOpener::SERVICE_ID) : NULL,
    );
  }

  /**
   * {@inheritdoc}
   *
   * The inspector (and its toggle button) only exists on pages whose route
   * host keeps a style map (a content host whose bundle has styling
   * enabled, a template host) AND where the actor may style something:
   * the capability spine's verdict set allows style on the root or on
   * some element (VerdictStamper::allowsAnywhere). A bundle with styling
   * off is a deliberate admin choice, and an actor the role policy or
   * ownership refuses style everywhere (an editor without the bundle's
   * style permission, a frozen translation) has nothing to style: in
   * both cases a panel that can never do anything renders truthfully as
   * absent, not as a permanently-dead husk.
   */
  public function applies(): bool {
    $host = $this->daedalusUi->deriveHostFromRoute();
    return $host !== NULL
      && $host->hasStyleStore()
      && $this->verdictStamper->allowsAnywhere($host, Capability::Style);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $route_host = $this->daedalusUi->deriveHostFromRoute();
    $entity = $route_host?->entity();

    // The client mirror's media table: lowercased media uuid => URL for
    // every token in the host's style map, so the live-preview tag resolves
    // tokens exactly where the server print sites would. Seeded from the
    // working copy (the staged map is what the blueprint mirror will
    // show), which is per-user state, hence the user cache context below.
    // Picks made during the session extend the table client-side; this
    // seed covers reload with staged-but-unsaved tokens.
    $media_urls = [];
    $media_tags = [];
    if ($route_host !== NULL) {
      $host = $this->storageHandler->load($route_host->entityTypeId(), $route_host->id()) ?? $route_host;
      if ($host->hasStyleStore()) {
        $media_urls = $this->mediaValueResolver->clientUrlTable($host->styleMap(), $media_tags, $host);
      }
    }

    $build = [
      'inspector' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'daedalus-stylish-inspector-root',
          // Chrome contract with the armed edit session's press decision: a
          // press in the inspector keeps the current edit open (styling and
          // editing coexist).
          'data-keeps-edit' => '',
        ],
      ],
      '#attached' => [
        'library' => ['daedalus_stylish/inspector'],
        // The guide payload the panel's property-group sections project:
        // declarations for every kind and block type, so the panel follows
        // selection without a round trip per click.
        'drupalSettings' => [
          'daedalusStylish' => [
            'guide' => $this->clientStyleGuide->build(),
            // The variant, swatch, token, animation and skin gesture gates:
            // which controls the panel's variant area, Materials cards,
            // gradient builder and skin session render (InspectorGates:
            // honest chrome, not access control; the satellite window
            // reads the same).
            'variants' => $this->gates->variants(),
            'swatches' => $this->gates->swatches(),
            'tokens' => $this->gates->tokens(),
            'animations' => $this->gates->animations(),
            'fonts' => $this->gates->fonts(),
            'skin' => $this->gates->skin(),
            'mediaUrls' => $media_urls,
          ],
        ],
      ],
      '#cache' => [
        // The user context is the media table's: its seed reads the
        // per-user tempstore-staged host.
        'contexts' => ['route', 'user'],
        // The host's own tag re-seeds the table on save; the referenced
        // media's (and image styles') tags re-seed it on file replace.
        'tags' => Cache::mergeTags(
          ClientStyleGuide::CACHE_TAGS,
          $entity?->getCacheTags() ?? [],
          $media_tags,
        ),
      ],
    ];

    // The media library URL the panel's media pickers open as an AJAX
    // modal: state built server-side (hash included, the map host in the
    // opener parameters), so the client only ever opens it — no client-side
    // state construction, no retargeting. One URL per host serves every
    // media-capable property row. Absent (media_library not installed, host
    // unsaved, no image- or vector-source media types) the pickers degrade
    // to free text.
    $library_url = $entity !== NULL ? $this->mediaLibraryOpener?->buildUrl($entity) : NULL;
    if ($library_url !== NULL) {
      $build['#attached']['drupalSettings']['daedalusStylish']['mediaLibrary'] = ['url' => $library_url];
    }

    // The pop-out needs a channel scope, which needs a main entity; without
    // one (an admin page, an ambiguous route) the client renders no pop-out
    // control and the panel stays in-page only. The scope itself is the
    // edit session's identity, delivered once by the Edit mode as
    // daedalusUi.editSession.scope (the leader pairs on that); this setting
    // carries only the satellite's URL, with the scope forwarded as ?scope
    // per the pop-out route contract (Chromeless).
    $scope = $entity !== NULL ? $this->editSessionScope->derive($entity) : NULL;
    if ($scope !== NULL) {
      $build['#attached']['drupalSettings']['daedalusStylish']['popout'] = [
        'url' => Url::fromRoute('daedalus_stylish.inspector_popout', [], ['query' => ['scope' => $scope]])->toString(),
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-stylish-inspector-toggle',
      $this->t('Styles'),
      $this->t('Style the selected element'),
      'daedalus_stylish',
      'brush',
      ['data-sidebar-button-for' => '#daedalus_stylish_inspector'],
    );
  }

}
