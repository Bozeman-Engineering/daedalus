<?php

namespace Drupal\daedalus_layout\Element;

use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
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
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

/**
 * Defines a render element for the Daedalus Layout UI.
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

  /**
   * The section storage being rendered.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface
   */
  protected SectionStorageInterface $sectionStorage;

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
      $container->get('config.factory'),
      $container->get('daedalus_tempstore.history_recorder'),
    );
  }

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LayoutTempstoreRepositoryInterface $tempstoreRepository,
    protected ElementInfoManagerInterface $elementInfoManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected ConfigFactoryInterface $configFactory,
    protected HistoryRecorder $historyRecorder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#section_storage' => NULL,
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

      if (!$this->isCompatibleStorage($this->sectionStorage)) {
        // Use Core's Layout Builder for any section storages we aren't aware of
        // like NavigationSectionStorage. Core's class is named explicitly:
        // daedalus_layout_element_info_alter() points the layout_builder definition
        // at this class, so createInstance('layout_builder') would land here
        // again.
        $element_definition = $this->elementInfoManager->getDefinition('layout_builder');
        $original_layout_builder_element = LayoutBuilder::create(\Drupal::getContainer(), [], 'layout_builder', $element_definition);
        return $original_layout_builder_element->preRender($element);
      }

      // The element always renders the ROOT layout. Core form rebuilds hand
      // it the nested-scoped storage a route enhancer resolved (a nested
      // block's configure form, a nested section's form); unscoping keeps
      // the instance — and with it the bubbled, unsaved nested changes —
      // while rendering the whole page. Nested layouts render as plain
      // output inside it; drilling in is a selection-tree gesture, not a
      // UI swap.
      $this->sectionStorage = NestedAwareSectionStorage::wrap($this->sectionStorage);
      if ($this->sectionStorage->getSectionScope()) {
        $this->sectionStorage = $this->sectionStorage->forSection(NULL);
      }
      $element['layout_builder'] = $this->layout();

    }
    return $element;
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
    // Render-time tempstore writes are staging bookkeeping, not user
    // mutations: core's PrepareLayout subscriber seeds tempstore on first
    // render, and the tree-index re-save below persists generated section
    // UUIDs. Suppress history capture so neither becomes an undo entry —
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

    $output = [];
    if ($this->isAjax()) {
      $output['status_messages'] = [
        '#type' => 'status_messages',
      ];
    }
    $count = 0;
    $sections_count = $this->sectionStorage->count();
    if ($sections_count) {
      // Build the admin controls for each section.
      for ($i = 0; $i < $sections_count; $i++) {
        $output[] = $this->buildAdministrativeSection($this->sectionStorage, $count);
        $count++;
      }
    }
    else {
      // Show a default blank page.
      $output['add_block'] = [
        '#markup' => '<div id="daedalus-layout-blank-page" class="blank-page-wrapper"><i class="fas fa-th-large" aria-hidden="true"></i><p>Drag and drop a block here to begin creating your layout.</p></div>',
      ];
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
    $layout = $section->getLayout($this->getPopulatedContexts($section_storage));

    // The storage's contexts include the stable root-entity context
    // (NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID), so parent
    // field blocks resolve here and in nested plain renders alike.
    $contexts = $this->getPopulatedContexts($section_storage);

    $section_render = $section->toRenderArray($contexts, TRUE);
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

          $build = $this->addContextualLinks($region, $section_delta, $uuid, $build);
        }
      }
    }
    return $build;
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

  /**
   * Checks if the storage is or wraps an OverridesSectionStorage.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The section storage to check.
   *
   * @return bool
   *   TRUE if the storage is an OverridesSectionStorage or a
   *   NestedSectionStorageInterface wrapping one.
   */
  private function isCompatibleStorage(SectionStorageInterface $storage): bool {
    if ($storage instanceof OverridesSectionStorage) {
      return TRUE;
    }
    if ($storage instanceof NestedSectionStorageInterface) {
      return $storage->getRootStorage() instanceof OverridesSectionStorage;
    }
    return FALSE;
  }

  /**
   * Add contextual links.
   *
   * @param int|string $region
   *   The region.
   * @param int $section_delta
   *   The section delta.
   * @param mixed $uuid
   *   The uuid.
   * @param array $build
   *   The administrative section.
   *
   * @return array
   *   The administrative section.
   */
  public function addContextualLinks(int|string $region, int $section_delta, mixed $uuid, array $build): array {
    $contextual_links = (bool) $this->configFactory->get('daedalus_layout.settings')->get('contextual_links');
    if ($contextual_links) {
      $build['section'][$region][$uuid]['#contextual_links'] = [
        'layout_builder_block' => [
          'route_parameters' => [
            'section_storage_type' => $this->sectionStorage->getStorageType(),
            'section_storage' => $this->sectionStorage->getStorageId(),
            'region' => $region,
            'delta' => $section_delta,
            'uuid' => $uuid,
          ],
          'metadata' => [
            'operations' => 'move:update:remove:duplicate',
          ],
        ],
      ];
    }
    return $build;
  }

}
