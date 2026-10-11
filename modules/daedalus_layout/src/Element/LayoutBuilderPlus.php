<?php

namespace Drupal\daedalus_layout\Element;

use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_layout\Frame\MainContentStandIn;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\daedalus_ui\ModeState;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\SidebarPluginManager;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\layout_builder\LayoutBuilderEvents;
use Drupal\layout_builder\Element\LayoutBuilder;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\layout_builder\Event\PrepareLayoutEvent;
use Drupal\layout_builder\LayoutBuilderHighlightTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Context\LayoutBuilderContextTrait;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a render element for the Daedalus Layout UI.
 *
 * The one canvas of a host: the layout root over the host's section
 * storage, rendered wherever the host's document is edited. On a canonical
 * page the entity view is the canvas root and hands the element its host
 * with #canvas_root FALSE (EditableUiBuilder); on core's manage-layout
 * routes (an entity's overrides, a display's defaults) and for a host with
 * no entity view (a template host) the element is the canvas root itself:
 * it wraps the layout in the main-entity wrapper carrying the host's
 * identity and delivers the blueprint mirror in edit mode. The site frame
 * is such a host too (FrameCanvas, its canonical route): its sections
 * render under the frame render's own contexts (renderContexts()), the
 * main slot showing a stand-in for the route's output. A storage with no
 * host (core's navigation layout) falls back to core's element.
 *
 * A plain host (no document of its own) renders a foreign document, its
 * display's: the layout root carries the owner stamp and nothing is staged
 * (staging the shared display from an entity page would edit every
 * entity of the bundle).
 *
 * @RenderElement("layout_builder_plus")
 *
 * @internal
 *   Plugin classes are internal.
 */
class LayoutBuilderPlus extends RenderElementBase implements ContainerFactoryPluginInterface {

  use AjaxHelperTrait;
  use LayoutBuilderContextTrait;
  use LayoutBuilderHighlightTrait;
  use StringTranslationTrait;

  /**
   * The section storage being rendered.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface
   */
  protected SectionStorageInterface $sectionStorage;

  /**
   * The host whose canvas is rendered.
   */
  protected HostInterface $host;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('layout_builder.tempstore_repository'),
      $container->get('plugin.manager.element_info'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_tempstore.history_recorder'),
      $container->get('module_handler'),
      $container->get('plugin.manager.daedalus_ui_sidebars'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('daedalus_ui.blueprint_mirror'),
      $container->get('daedalus_ui.mode_state'),
      $container->get('daedalus_blueprint.inner_render'),
    );
  }

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LayoutTempstoreRepositoryInterface $tempstoreRepository,
    protected ElementInfoManagerInterface $elementInfoManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected HistoryRecorder $historyRecorder,
    protected ModuleHandlerInterface $moduleHandler,
    protected SidebarPluginManager $sidebarManager,
    protected HostResolver $hostResolver,
    protected BlueprintMirror $blueprintMirror,
    protected ModeState $modeState,
    protected InnerRender $innerRender,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#section_storage' => NULL,
      // The host whose canvas this element renders. NULL (core's form
      // routes build the element with the storage alone) resolves the
      // storage's own host; the editable UI builder passes the route host,
      // which a plain host's foreign document render differs from.
      '#host' => NULL,
      // Whether this element is the canvas root: TRUE wraps the layout in
      // the main-entity wrapper and delivers the blueprint mirror (core's
      // manage-layout routes, a template host's canvas); the entity view
      // passes FALSE because the entity build already wears the wrapper.
      '#canvas_root' => TRUE,
      '#pre_render' => [
        [$this, 'preRender'],
      ],
    ];
  }

  /**
   * Pre-render callback: Renders the Layout Builder UI.
   */
  public function preRender($element) {
    if ($element['#section_storage'] instanceof SectionStorageInterface) {
      $this->sectionStorage = $element['#section_storage'];

      $host = $element['#host'] instanceof HostInterface
        ? $element['#host']
        : $this->hostResolver->fromSectionStorage($this->sectionStorage);
      if ($host === NULL) {
        // Use Core's Layout Builder for any section storage with no host,
        // like NavigationSectionStorage. Core's class is named explicitly:
        // daedalus_layout_element_info_alter() points the layout_builder definition
        // at this class, so createInstance('layout_builder') would land here
        // again.
        $element_definition = $this->elementInfoManager->getDefinition('layout_builder');
        $original_layout_builder_element = LayoutBuilder::create(\Drupal::getContainer(), [], 'layout_builder', $element_definition);
        return $original_layout_builder_element->preRender($element);
      }
      $this->host = $host;

      // The element always renders the ROOT layout. Core form rebuilds hand
      // it the nested-scoped storage a route enhancer resolved (a nested
      // block's configure form, a nested section's form); unscoping keeps
      // the instance, and with it the bubbled, unsaved nested changes,
      // while rendering the whole page. Nested layouts render as plain
      // output inside it; drilling in is a selection-tree gesture, not a
      // UI swap.
      $this->sectionStorage = NestedAwareSectionStorage::wrap($this->sectionStorage);
      if ($this->sectionStorage->getSectionScope()) {
        $this->sectionStorage = $this->sectionStorage->forSection(NULL);
      }
      $element['layout_builder'] = $this->layout();

      if ($element['#canvas_root'] ?? TRUE) {
        $this->wrapAsCanvasRoot($element);
      }
    }
    return $element;
  }

  /**
   * Makes the element the canvas root: the main-entity wrapper and the mirror.
   *
   * The same attribute set DaedalusUiHooks::entityView() stamps on a route
   * entity's build, on a container of the element's own (no entity
   * template renders here), so the client finds one root wherever the
   * canvas is built. The mirror rides only in edit mode: outside it the
   * wrapper is inert bytes around a static render.
   *
   * @param array $element
   *   The element, modified in place.
   */
  protected function wrapAsCanvasRoot(array &$element): void {
    $entity = $this->host->entity();
    $view_mode = $this->host->viewMode();
    $element['#theme_wrappers'][] = 'container';
    $element['#attributes']['class'][] = 'daedalus-ui-entity-wrapper';
    $element['#attributes'][ElementAddress::ATTRIBUTE] = ElementAddress::ENTITY;
    $element['#attributes']['data-daedalus-ui-entity-wrapper'] = daedalus_ui_entity_wrapper_id($entity);
    $element['#attributes']['data-daedalus-ui-view-mode'] = $view_mode;
    $element['#attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::entity($this->host->uuid());
    $element['#attributes']['data-entity-type'] = $this->host->entityTypeId();
    $element['#attributes']['data-entity-id'] = daedalus_ui_entity_identifier($entity);
    $element['#attributes']['data-main-entity'] = 'true';
    $element['#attributes']['data-entity-uuid'] = $this->host->uuid();
    $element['#cache']['contexts'][] = 'daedalus_ui_mode';
    if ($this->modeState->getMode() === 'edit') {
      $this->blueprintMirror->attach($element, $this->host, $view_mode);
    }
    // The canvas root is the host's render root where no entity view
    // supplies one, so what hook_entity_view() delivers inside an entity's
    // build (a style tag, root attributes) is delivered here for the host.
    $this->moduleHandler->alter('daedalus_layout_canvas_root', $element, $this->host);
  }

  /**
   * Renders the Layout UI: the edit canvas's layout root.
   *
   * The container this returns is the ONE element the root sections render
   * inside on the edit canvas, so it IS the layout root there and wears
   * LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS beside its own
   * `layout-builder` id and class (the AJAX rebuild target in
   * DaedalusLayoutRebuildTrait and daedalus_ui's edit-mode-plugin read those;
   * daedalus_stylish's declared defaults, the page's gap and the inspector's part
   * facts read the class). Live/edit parity: buildSections() gives the
   * view render the same element, so a gap written on the page's Layout
   * part paints identically in both modes.
   *
   * Presence differs from the view render on purpose. The view render
   * wraps only while sections exist (core's empty-list semantics, see
   * buildSections()); the canvas container always exists, holding the
   * blank-page affordance when the layout is empty, and it always wears
   * the class. The blueprint's markup annotation resolves the entity
   * kind's declared parts with no presence condition, so the model says
   * the layout root is present on every Layout Builder entity in edit
   * mode; the canvas must agree, and a class gated on the section count
   * would contradict the model on a blank page. The gap gate reads the
   * part's child-box count, and one affordance counts one, so the Gap row
   * hides on a blank page exactly as it does on a one-section page.
   *
   * @return array
   *   A render array.
   */
  protected function layout() {
    // A foreign document (a plain host rendering its display's template)
    // is read, never staged: preparing the layout would seed the SHARED
    // display's defaults storage into this user's tempstore from an entity
    // page. The owner of that document is where it is edited.
    $owner = $this->hostResolver->foreignOwner($this->host);
    if ($owner === NULL) {
      // Render-time tempstore writes are staging bookkeeping, not user
      // mutations: core's PrepareLayout subscriber seeds tempstore on first
      // render, and the tree-index re-save below persists generated section
      // UUIDs. Suppress history capture so neither becomes an undo entry:
      // recorded, the seed shows a phantom "Edited content" entry on entering
      // edit mode, and re-seeding during a restore response's render truncates
      // the very timeline being restored.
      $this->historyRecorder->suppress(function () {
        $this->prepareLayout($this->sectionStorage);

        // Ensure TreeIndex is built, which proactively generates section UUIDs.
        $nestedAware = $this->getNestedAwareStorage();
        if ($nestedAware) {
          $nestedAware->getTreeIndex();
          // Re-save to tempstore if tree indexing generated new section UUIDs.
          // Without this, old sections that lacked UUIDs get different random
          // UUIDs on each AJAX request, causing mismatches with the rendered
          // markup.
          if ($nestedAware->isDirty()) {
            $this->tempstoreRepository->set($this->sectionStorage);
          }
        }
      });
    }

    $output = [];
    if ($this->isAjax()) {
      $output['status_messages'] = [
        '#type' => 'status_messages',
      ];
    }
    $count = 0;
    $sections_count = $this->sectionStorage->count();
    if ($sections_count) {
      // Build the admin controls for each section. A slot section (a fill
      // of a slot the template or the frame around this document declares)
      // renders inside that slot block, never in the document's own flow
      // (InnerRender::renderedBySlot()), on the canvas as in the view.
      for ($i = 0; $i < $sections_count; $i++) {
        if ($this->host !== NULL && $this->innerRender->renderedBySlot($this->host, $this->sectionStorage->getSection($count))) {
          $count++;
          continue;
        }
        $output[] = $this->buildAdministrativeSection($this->sectionStorage, $count);
        $count++;
      }
    }
    else {
      $output['add_block'] = $this->buildBlankPage();
    }

    // As the Layout Builder UI is typically displayed using the frontend theme,
    // it is not marked as an administrative page at the route level even though
    // it performs an administrative task. Mark this as an administrative page
    // for JavaScript.
    $output['#attached']['drupalSettings']['path']['currentPathIsAdmin'] = TRUE;

    $output['#attached']['drupalSettings']['Daedalus Layout'] = [
      'sectionStorageType' => $this->sectionStorage->getStorageType(),
      'sectionStorage' => $this->sectionStorage->getStorageId(),
    ];
    $output['#type'] = 'container';
    $output['#attributes']['id'] = 'layout-builder';
    $output['#attributes']['class'][] = 'layout-builder';
    $output['#attributes']['class'][] = 'active';
    $output['#attributes']['class'][] = LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS;
    // Mark this UI as uncacheable.
    $output['#cache']['max-age'] = 0;
    return $output;
  }

  /**
   * Builds the blank-page affordance: the first section, not yet here.
   *
   * One button filling the layout root, so the drop lands where the hint
   * stood. It carries data-sidebar-button-for, daedalus_ui's one road from
   * a chrome click to a panel toggle (sidebar-buttons.js), so a click on
   * the empty canvas opens the Blocks panel exactly as the top-bar button
   * and the panel's hotkey do, and the panel arms the root as the insert
   * anchor while the document is empty (js/src/insert-session.js), so
   * the key the hint promises places by click as well as by drag. The hint
   * names its host (the canvas serves three hosts and only one is a page),
   * the key cap prints that panel's declared hot_key, the same code default
   * the tool tooltips print, and the AI clause prints only where
   * daedalus_ai exists. The id is the element's identity for daedalus_ui's
   * file drag (removed on the first Files drag) and the tests. Its look is
   * css/blank-page.css.
   */
  protected function buildBlankPage(): array {
    $definition = $this->sidebarManager->getDefinition('place_block');
    $key = new FormattableMarkup('<kbd>@key</kbd>', ['@key' => $definition['hot_key'] ?? '']);
    $hint = $this->moduleHandler->moduleExists('daedalus_ai')
      ? $this->t('Drag a block here, press @key for Blocks, or ask the AI.', ['@key' => $key])
      : $this->t('Drag a block here, or press @key for Blocks.', ['@key' => $key]);
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'id' => 'daedalus-layout-blank-page',
        'class' => ['daedalus-layout-blank-page'],
        'type' => 'button',
        'data-sidebar-button-for' => '#place_block',
      ],
      '#value' => new FormattableMarkup('<span class="daedalus-layout-blank-page__hint">@empty @hint</span>', [
        '@empty' => $this->emptyCanvasName(),
        '@hint' => $hint,
      ]),
    ];
  }

  /**
   * The sentence naming the empty host: blueprint, template, frame or page.
   *
   * A blueprint host is a content host (BlueprintHost extends ContentHost),
   * so it is told apart first; every other content host is a page.
   */
  protected function emptyCanvasName(): TranslatableMarkup {
    return match (TRUE) {
      $this->host instanceof BlueprintHost => $this->t('Empty blueprint.'),
      $this->host instanceof DisplayHost => $this->t('Empty template.'),
      $this->host instanceof FrameHost => $this->t('Empty frame.'),
      default => $this->t('Empty page.'),
    };
  }

  /**
   * Prepares a layout for use in the UI.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   */
  protected function prepareLayout(SectionStorageInterface $section_storage) {
    $event = new PrepareLayoutEvent($section_storage);
    $this->eventDispatcher->dispatch($event, LayoutBuilderEvents::PREPARE_LAYOUT);
  }

  /**
   * Builds the render array for the layout section while editing.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $section_delta
   *   The delta of the section.
   *
   * @return array
   *   The render array for a given section.
   */
  protected function buildAdministrativeSection(SectionStorageInterface $section_storage, $section_delta) {
    $section = $section_storage->getSection($section_delta);
    $contexts = $this->renderContexts($section_storage);
    $layout = $section->getLayout($contexts);

    // The frame's sections render through the primitive's section render,
    // as they do on every page of the theme: a render with no subject
    // skips a component that needs one instead of failing on it.
    $section_render = $this->host instanceof FrameHost
      ? $this->innerRender->sectionRenderArray($section, $contexts, TRUE)
      : $section->toRenderArray($contexts, TRUE);
    LayoutBuilderEntityViewDisplay::getSectionAttributes($section_delta, $section_render, $this->sectionStorage, $section);

    // No admin wrapper around the section: the root section's layout
    // element carries its own selection identity (data-selectable), the
    // same shape nested sections render with. Core's per-section wrapper
    // (layout-builder__section, highlight ids) only fed core's
    // drupal.layout_builder library, which never loads under Daedalus Layout.
    $build = [
      'section' => $section_render,
    ];

    $layout_definition = $layout->getPluginDefinition();
    $build['#layout'] = $layout_definition;

    foreach ($layout_definition->getRegions() as $region => $info) {
      $build['section'][$region]['#attributes']['class'][] = 'layout__region';
      $build['section'][$region]['#attributes']['class'][] = 'js-layout-builder-region';
      $build['section'][$region]['#attributes']['region'] = $region;
      if (!empty($build['section'][$region])) {
        foreach (Element::children($build['section'][$region]) as $uuid) {
          $build['section'][$region][$uuid]['#attributes']['class'][] = 'js-layout-builder-block';
          $build['section'][$region][$uuid]['#attributes']['class'][] = 'layout-builder-block';
          $build['section'][$region][$uuid]['#attributes']['class'][] = 'block-layout-builder';
          // Placement identity is daedalus_ui's
          // data-layout-builder-block-uuid (and the block: selectable key);
          // the admin element stamps no duplicate uuid attribute.
          $build['section'][$region][$uuid]['#attributes']['data-layout-builder-highlight-id'] = $this->blockUpdateHighlightId($uuid);
        }
      }
    }
    return $build;
  }

  /**
   * The contexts the sections render with.
   *
   * The host's subject is the render subject, whatever storage the
   * sections come from: it rides as layout_builder.entity and as the
   * stable root-entity context (NestedSectionStorageInterface::
   * ROOT_ENTITY_CONTEXT_ID) over the storage's populated contexts, so field
   * blocks resolve against it at the root and in nested renders alike. For
   * a page that is what the storage carries already; for a template, core's
   * sample entity; for a blueprint, the sample entity of the bundle it is
   * for (the storage's preview entity, where the entity itself is the
   * blueprint); for a plain host rendering a foreign document (its
   * display's template) the host's own entity, not the storage's preview
   * sample, so the page shows its own fields in the template's field blocks
   * and those fields edit in place.
   *
   * The site frame renders under the frame render's own contexts
   * (InnerRender::frameContexts(), the one spelling: the frame under its
   * id for the slot blocks, a stand-in route output for the main slot
   * (MainContentStandIn, the sentence the page display variant hands a
   * blueprint canvas too), no subject) with the frame as the root, exactly
   * as the page display
   * variant renders it around a route, and NOT under the storage's
   * populated contexts: the frame storage's `entity` context is the frame
   * itself, which is nobody's subject.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The contexts.
   */
  protected function renderContexts(SectionStorageInterface $section_storage): array {
    if ($this->host instanceof FrameHost) {
      // No route output exists on the frame's own route (the frame is the
      // page's content), so the stand-in IS the output, with no host: the
      // main slot hosts it and prints it inside the main landmark.
      $contexts = $this->innerRender->frameContexts($this->host, new RouteOutput(MainContentStandIn::build(), $this->t('Page title')), NULL);
      $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = EntityContext::fromEntity($this->host->entity());
      return $contexts;
    }
    $contexts = $this->getPopulatedContexts($section_storage);
    // A host with no subject (the site frame) renders its canvas with no
    // entity in context: nothing on it presents a field.
    $subject_entity = $this->host->subject();
    if ($subject_entity !== NULL) {
      $subject = EntityContext::fromEntity($subject_entity);
      $contexts['layout_builder.entity'] = $subject;
      $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = $subject;
    }
    return $contexts;
  }

  /**
   * Gets the nested-aware section storage.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   The nested-aware storage, or NULL if not available.
   */
  private function getNestedAwareStorage(): ?NestedSectionStorageInterface {
    if ($this->sectionStorage instanceof NestedSectionStorageInterface) {
      return $this->sectionStorage;
    }
    return NULL;
  }

}
