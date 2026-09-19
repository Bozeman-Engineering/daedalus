<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Plugin\Sidebar;

use Drupal\Core\Url;
use Drupal\Core\Cache\Cache;
use Drupal\daedalus_layout\DaedalusLayoutToolTrait;
use Drupal\daedalus_layout\DaedalusLayoutSettingsTrait;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_layout\DaedalusLayoutEntityHelperTrait;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\SidebarPluginBase;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\Context\LayoutBuilderContextTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Add panel: blocks, sections, and stencils.
 *
 * A tabbed list of promoted blocks plus a filterable list of every placeable
 * block. A plain, tool-independent panel (there is no place_block TOOL):
 * its items are always-armed caret drag sources, so dragging out of it
 * works whatever the cursor is doing, and the Insert verb opens it with
 * the selection armed as the pending click-to-place anchor. Present (hidden
 * or open) whenever Layout Builder editing applies to the routed entity;
 * opened by its top-bar toggle or the Insert verb. The plugin id keeps the
 * historical 'place_block' name: it is the panel's DOM id and cookie
 * identity; renaming is chrome-theme territory.
 *
 * Stencils are not rendered here: they are materials, and the client
 * renders their items from the materials store (js/add-panel/
 * stencil-items.js over Drupal.Daedalus.Materials), so a stencil
 * saved by a person, the AI or another window appears in the open panel
 * the moment its delta lands, with no server round trip and no optimistic
 * patch. The server's one stencil contribution is the "save stencils"
 * grant the client gestures gate on (drupalSettings.daedalusLayout.canSaveStencils,
 * honest chrome; the ops and routes enforce the permission for real).
 */
#[Sidebar(
  id: 'place_block',
  label: new TranslatableMarkup('Blocks'),
  side: 'left',
  // After the page-elements tree (weight 10): the persistent tree owns the
  // first left-rail toggle; the transient Insert panel follows it.
  weight: 12,
  mode: 'edit',
  // The Add panel shares the left side with the page-elements tree
  // (left/top) without evicting it: two slots, both can be open at once. The
  // persistent tree anchors the top and is never displaced; the transient
  // Add panel opens beneath it, nearer the bottom tool pill, and the tree
  // takes the full height whenever the panel is closed.
  slot: 'bottom',
  // Shares its letter with the Insert VERB deliberately: with a selection
  // the verb wins (selection context outranks global) and opens the Add panel
  // anchored there; without one this binding opens the panel. One key, one
  // meaning — insert.
  hot_key: 'i',
)]
final class PlaceBlock extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use DaedalusLayoutToolTrait;
  use DaedalusLayoutSettingsTrait;
  use StringTranslationTrait;
  use DaedalusLayoutEntityHelperTrait;
  use LayoutBuilderContextTrait;
  use ButtonBuilderTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DaedalusUi $daedalusUi,
    protected CurrentRouteMatch $currentRouteMatch,
    protected ModuleHandlerInterface $moduleHandler,
    protected BlockManagerInterface $blockManager,
    protected AccountProxyInterface $currentUser,
    protected IconResolver $iconResolver,
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
      $container->get('daedalus_ui.ui'),
      $container->get('current_route_match'),
      $container->get('module_handler'),
      $container->get('plugin.manager.block'),
      $container->get('current_user'),
      $container->get('daedalus_blueprint.icon_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    $entity = $this->daedalusUi->deriveEntityFromRoute();
    return $entity && $this->daedalusLayoutToolApplies($entity);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function build(): array {
    // The panel owns its assets (drag sources, tabs, the Insert verb's
    // click-to-place): sidebar plugins have no attachments seam, and no
    // tool attaches this library. The panel renders present-but-hidden
    // whenever it applies, so the Add panel JS (including the Insert verb)
    // is live before the panel first opens.
    $build['#attached']['library'][] = 'daedalus_layout/add_panel';

    $parameters = $this->currentRouteMatch->getParameters();
    // Include a side bar for adding blocks to the layout.
    $section_storage = $parameters->get('section_storage');

    if (!($section_storage instanceof SectionStorageInterface)) {
      $entity = $this->daedalusUi->deriveEntityFromRoute();
      $section_storage = $this->getSectionStorageForEntity($entity);
    }
    // The Add panel always offers the ROOT display's blocks: nested layouts
    // render in place, so there is no per-scope panel to swap to.
    $current_section_storage = NestedAwareSectionStorage::wrap($section_storage);

    $path = $this->moduleHandler->getModule('daedalus_layout')->getPath();

    // The shared header grammar (SidebarPluginBase::buildPanelHeader): the
    // Promoted/Other tabs are the panel's one header action, followed by
    // the close control the grammar appends.
    $tabs = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'choose-block-tabs',
        // The shared segmented-control recipe (daedalus_ui's
        // panel-controls.css); the tabs wear .daedalus-ui-segment below.
        'class' => ['daedalus-ui-segments'],
        // Secondary chrome: transparent (space preserved) in clean density
        // until the pointer nears; daedalus_ui's reveal spine owns the
        // hiding and the proximity reveal.
        'data-daedalus-ui-conceal' => 'clean',
        'data-daedalus-ui-reveal-near' => '120',
      ],
      'promoted' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Promoted'),
        '#attributes' => [
          'id' => 'promoted-block',
          'class' => ['choose-block-tab', 'daedalus-ui-segment', 'active', 'daedalus-ui-segment--active'],
        ],
      ],
      'other' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Other'),
        '#attributes' => [
          'id' => 'other-block',
          'class' => ['choose-block-tab', 'daedalus-ui-segment'],
        ],
      ],
    ];
    $build['header'] = $this->buildPanelHeader($this->t('Blocks'), ['tabs' => $tabs]);

    // Both tabs offer the layout_builder-filtered definitions: a block a
    // module hides from Layout Builder placement (e.g. a deprecated block
    // type) must not resurface through a stale promoted_blocks setting, and
    // a promoted id whose module is gone must not fatal the panel.
    $definitions = $this->blockManager->getFilteredDefinitions('layout_builder', $this->getPopulatedContexts($current_section_storage), [
      'section_storage' => $current_section_storage,
      // Without this flag core's plugin_filter_block_alter strips every
      // inline_block definition from the listing.
      'list' => 'inline_blocks',
      // Restriction providers (layout_builder_restrictions) skip filtering
      // when a 'list' is requested unless 'browse' rides along (the
      // layout_builder_browser convention). With it, the Add panel is a
      // filtered projection of the same answer the blueprint ops enforce.
      // Per-region restrictions stay drop-time answers (the panel is
      // region-agnostic), enforced by the ops' placement checks.
      'browse' => TRUE,
    ]);
    $promoted_block_ids = $this->getDaedalusLayoutSetting($current_section_storage, 'promoted_blocks');
    $default_icon = '/' . $path . '/assets/default-block-icon.svg';

    $blocks = [];
    foreach ($promoted_block_ids as $promoted_block_id) {
      if (!isset($definitions[$promoted_block_id])) {
        continue;
      }
      $promoted_block_definition = $definitions[$promoted_block_id];
      $icon_path = $this->blockTypeIcon($promoted_block_id, $build) ?? $default_icon;
      $blocks[] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $promoted_block_id,
          'class' => ['draggable-block', 'daedalus-ui-panel-item'],
          'draggable' => 'true',
          // Add panel items are keyboard-reachable: roving tabindex
          // (add-panel.js seats tabindex="0" on exactly one item) and
          // Enter/Space on a focused item lifts it into the caret walk.
          'tabindex' => '-1',
          'role' => 'button',
        ],
        'icon' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['daedalus-ui-panel-item__image'],
            'style' => ["background-image: url('$icon_path');"],
          ],
        ],
        'label' => ['#markup' => "<div class='daedalus-ui-panel-item__label'>{$promoted_block_definition['admin_label']}</div>"],
      ];
    }

    // Stencil items ride the Promoted tab beside the blocks, rendered by the
    // client from the materials store (see the class doc). With the stencil
    // module installed the panel attaches the "save stencils" grant the
    // stencil gestures (js/verbs/stencil.js, the panel's item menu) gate
    // on as chrome; without it there is no grant and no gesture, whatever
    // the account's permissions say (user 1 holds every permission,
    // defined or not).
    if ($this->moduleHandler->moduleExists('daedalus_blueprint_stencil')) {
      $build['#attached']['drupalSettings']['daedalusLayout']['canSaveStencils'] = $this->currentUser->hasPermission('save stencils');
      $build['#cache']['contexts'][] = 'user.permissions';
    }

    $build['promoted_blocks'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'promoted-block-content',
        'class' => ['tabbed-content', 'active', 'daedalus-ui-panel__body', 'daedalus-ui-panel-list'],
        // The fallback icon the client-rendered stencil items paint when a
        // record carries no icon: the same default the promoted items wear.
        'data-default-icon' => $default_icon,
      ],
    ];
    // The promoted tab's type-to-filter box: the anchor-less
    // keyboard flow is open panel → type to filter → ArrowDown into the
    // items → Enter lifts. A plain label match over the promoted items
    // (add-panel.js); the Other tab keeps its own category-aware filter.
    $build['promoted_blocks']['filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter by block name'),
      '#title_display' => 'invisible',
      '#size' => 30,
      '#placeholder' => $this->t('Filter by block name'),
      '#attributes' => [
        'class' => ['daedalus-layout-add-panel-filter'],
        'title' => $this->t('Enter a part of the block name to filter by.'),
        'data-daedalus-ui-conceal' => 'minimal',
        'data-daedalus-ui-reveal-near' => '120',
      ],
    ];
    // Let users place an empty section. Always offered: the Container is
    // placeable whether or not any block is promoted, and the client's
    // stencil items join this same list after the blocks.
    $build['promoted_blocks']['blocks'][] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'empty-section',
        'class' => ['draggable-section', 'daedalus-ui-panel-item'],
        'draggable' => 'true',
        'tabindex' => '-1',
        'role' => 'button',
      ],
      'icon' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['daedalus-ui-panel-item__image'],
          'style' => ['background-image: url("/' . $path . '/assets/container.svg");'],
        ],
      ],
      // The one noun, every depth: the panel's container drops as a page row
      // OR nests into any region (the drag layer offers both).
      'label' => ['#markup' => $this->t("<div class='daedalus-ui-panel-item__label'>Container</div>")],
    ];
    $build['promoted_blocks']['blocks'] = array_merge($build['promoted_blocks']['blocks'], $blocks);
    if (empty($blocks)) {
      // Give users a link to promote blocks if there are none.
      $entity_view_display_id = $this->loadEntityViewDisplay($current_section_storage)->id();
      $build['promoted_blocks']['promote'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['daedalus-layout-add-panel-promote']],
        '#markup' => $this->t('No blocks have been promoted. Click <a href="@url">here</a> to promote some.', [
          '@url' => Url::fromRoute('daedalus_layout.settings.promoted_blocks', [
            'entity' => $entity_view_display_id,
          ])->toString(),
        ]),
      ];
    }

    $build['other_blocks'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'other-block-content',
        'class' => ['tabbed-content', 'daedalus-ui-panel__body'],
      ],
    ];
    $build['other_blocks']['filter'] = [
      '#type' => 'search',
      '#title' => $this->t('Filter by block name'),
      '#title_display' => 'invisible',
      '#size' => 30,
      '#placeholder' => $this->t('Filter by block name'),
      '#attributes' => [
        'class' => ['js-layout-builder-filter'],
        'title' => $this->t('Enter a part of the block name to filter by.'),
        'data-daedalus-ui-conceal' => 'minimal',
        'data-daedalus-ui-reveal-near' => '120',
      ],
    ];
    $block_categories['#type'] = 'container';
    $block_categories['#attributes']['class'][] = 'block-categories';
    $block_categories['#attributes']['class'][] = 'js-layout-builder-categories';

    $grouped_definitions = $this->blockManager->getGroupedDefinitions($definitions);
    foreach ($grouped_definitions as $category => $blocks) {
      $block_categories[$category]['#type'] = 'details';
      $block_categories[$category]['#attributes']['class'][] = 'daedalus-ui-details';
      $block_categories[$category]['#attributes']['class'][] = 'js-layout-builder-category';
      $block_categories[$category]['#open'] = FALSE;
      $block_categories[$category]['#title'] = $category;
      $block_categories[$category]['blocks'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['daedalus-ui-details__body'],
        ],
        'blocks' => $this->getBlocks($blocks),
      ];
    }
    $build['other_blocks']['block_categories'] = $block_categories;

    return $build;
  }

  /**
   * Resolves a promoted Add panel item's icon URL from its block type.
   *
   * The icon is a property of the block TYPE
   * (`block_content.type.*.third_party.daedalus_layout.icon`, in the icon value
   * grammar \Drupal\daedalus_blueprint\IconResolver documents), so only
   * inline_block derivatives can carry one: every other plugin, an unset
   * icon, and a dangling media reference all resolve to NULL and the caller
   * renders the default icon. The block type's cache tags merge into the
   * build even when nothing resolves, because configuring an icon later
   * must invalidate the Add panel; the resolver adds the media's tags
   * whenever the icon is a media reference.
   *
   * @param string $block_plugin_id
   *   The promoted block plugin id, e.g. `inline_block:formatted_text`.
   * @param array $build
   *   The panel build, receiving cache tags.
   *
   * @return string|null
   *   A root-relative icon URL, or NULL for "render the default icon".
   */
  protected function blockTypeIcon(string $block_plugin_id, array &$build): ?string {
    if (!str_starts_with($block_plugin_id, 'inline_block:')) {
      return NULL;
    }
    // A promoted inline_block id implies block_content is installed (its
    // deriver produced the definition), but the Add panel must not fatal on
    // a site whose module set says otherwise.
    if (!$this->entityTypeManager()->hasDefinition('block_content_type')) {
      return NULL;
    }
    $block_type = $this->entityTypeManager()->getStorage('block_content_type')->load(substr($block_plugin_id, strlen('inline_block:')));
    if ($block_type === NULL) {
      return NULL;
    }
    $tags = $block_type->getCacheTags();
    $icon_url = $this->iconResolver->resolve($block_type->getThirdPartySetting('daedalus_layout', 'icon'), $tags);
    $build['#cache']['tags'] = Cache::mergeTags($build['#cache']['tags'] ?? [], $tags);
    return $icon_url;
  }

  /**
   * Gets a render array of draggable blocks.
   *
   * @param array $blocks
   *   The information for each block.
   *
   * @return array
   *   The block links render array.
   */
  protected function getBlocks(array $blocks) {
    $draggable_blocks = [];
    foreach ($blocks as $block_id => $block) {
      $draggable_blocks[] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => $block_id,
          'class' => ['draggable-block', 'js-layout-builder-block-link', 'daedalus-ui-details__item'],
          'draggable' => 'true',
          // Keyboard-reachable like the promoted items.
          'tabindex' => '-1',
          'role' => 'button',
        ],
        'label' => ['#markup' => "<div class='daedalus-ui-panel-item__label'>{$block['admin_label']}</div>"],
      ];
    }
    return $draggable_blocks;
  }

  /**
   * {@inheritdoc}
   *
   * The panel's user-facing opener (no tool opens it): a standard top-bar
   * toggle beside the page-elements tree's, wired through the shared
   * data-sidebar-button-for behavior.
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-layout-add',
      $this->t('Blocks'),
      $this->t('Insert blocks and containers into the page'),
      'daedalus_layout',
      'block',
      ['data-sidebar-button-for' => '#place_block'],
    );
  }

}
