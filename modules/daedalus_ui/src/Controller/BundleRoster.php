<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Event\BundleRosterEvent;
use Drupal\daedalus_ui\Form\BundleEditFormAlter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The bundle roster: the page builder's state on every bundle, read-only.
 *
 * The second door to the bundle form's Page builder tab, under the Daedalus
 * config category's UI sub-category (/admin/config/daedalus/ui/content-types).
 * The tab is the one write path for a bundle's page builder settings (one
 * owner, one submit, the schema probe), so this page never carries a
 * control: it is one table per bundle config type the tab serves (the same
 * BundleEditFormAlter::hasSettingsSchema() probe), one row per bundle, with
 * what the tab would show (on or off; on a page bundle whether new pages
 * open in the page builder, on a block type how the block is selected), the
 * columns other modules contribute through BundleRosterEvent (the tab's
 * contributed controls, mirrored), and operations into the bundle form's
 * tab and its manage-display page. Operations show only where the actor
 * may go; the roster itself is gated by the module's own
 * `administer Daedalus UI configuration`, the chrome form's gate.
 */
final class BundleRoster extends ControllerBase {

  /**
   * The HTML id of the tab on the bundle form, the operation's fragment.
   *
   * The details element's id as core derives it from its key
   * (BundleEditFormAlter::ensurePageBuilderSection()); core's vertical tabs
   * open the tab whose pane the fragment names.
   */
  public const TAB_FRAGMENT = 'edit-page-builder';

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected BundleEditFormAlter $sectionOwner,
    protected EventDispatcherInterface $eventDispatcher,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('daedalus_ui.form_alter.bundle_edit'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Builds the roster: one table per bundle config type the tab serves.
   */
  public function build(): array {
    $cacheability = (new CacheableMetadata())
      // The operations are filtered by the actor's access.
      ->addCacheContexts(['user.permissions']);
    $build = [
      'intro' => [
        '#markup' => '<p>' . $this->t('Where the page builder is on, and how each type uses it. A type is set up on its own form, under Page builder.') . '</p>',
      ],
    ];
    foreach ($this->bundleTypes() as $bundle_type) {
      $cacheability->addCacheTags($bundle_type->getListCacheTags());
      $build[$bundle_type->id()] = $this->table($bundle_type, $cacheability);
    }
    if (count($build) === 1) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No content type of this site can carry the page builder.') . '</p>',
      ];
    }
    $cacheability->applyTo($build);
    return $build;
  }

  /**
   * The bundle config types the Page builder tab serves, by collection label.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityTypeInterface[]
   *   The types, keyed by id.
   */
  private function bundleTypes(): array {
    $types = array_filter(
      $this->entityTypeManager->getDefinitions(),
      fn($type) => $type instanceof ConfigEntityTypeInterface
        && $type->getBundleOf() !== NULL
        && $this->sectionOwner->hasSettingsSchema($type),
    );
    uasort($types, fn(ConfigEntityTypeInterface $a, ConfigEntityTypeInterface $b) => strcmp((string) $a->getCollectionLabel(), (string) $b->getCollectionLabel()));
    return $types;
  }

  /**
   * One bundle config type's table, headed by its collection label.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $bundle_type
   *   The bundle config type.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The page's cacheability, which the rows' events refine.
   *
   * @return array
   *   A render array.
   */
  private function table(ConfigEntityTypeInterface $bundle_type, CacheableMetadata $cacheability): array {
    $block_type = $bundle_type->getBundleOf() === 'block_content';
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase[] $bundles */
    $bundles = $this->entityTypeManager->getStorage($bundle_type->id())->loadMultiple();
    uasort($bundles, [ConfigEntityBase::class, 'sort']);

    // Every row's event first: the contributed columns' headers are the
    // union of what the rows answered, in the order first seen.
    $rows = [];
    $contributed = [];
    foreach ($bundles as $bundle) {
      $event = new BundleRosterEvent($bundle);
      $this->eventDispatcher->dispatch($event);
      $cacheability->addCacheableDependency($event);
      $rows[$bundle->id()] = ['bundle' => $bundle, 'columns' => $event->getColumns()];
      foreach ($event->getColumns() as $id => $column) {
        $contributed[$id] ??= $column['header'];
      }
    }

    $header = [
      'label' => $this->t('Name'),
      'enabled' => $this->t('Page builder'),
      'mode' => $block_type ? $this->t('How the block is selected') : $this->t('Opens in page builder'),
    ];
    foreach ($contributed as $id => $label) {
      $header[$id] = $label;
    }
    $header['operations'] = $this->t('Operations');

    $table_rows = [];
    foreach ($rows as $id => ['bundle' => $bundle, 'columns' => $columns]) {
      $enabled = (bool) $bundle->getThirdPartySetting('daedalus_ui', 'enabled', FALSE);
      $row = [
        'label' => $bundle->label(),
        'enabled' => $enabled ? $this->t('On') : $this->t('Off'),
        'mode' => $block_type ? $this->presentation($bundle) : $this->opensInPageBuilder($bundle),
      ];
      foreach (array_keys($contributed) as $column_id) {
        $cell = $columns[$column_id]['cell'] ?? '';
        $row[$column_id] = is_array($cell) ? ['data' => $cell] : $cell;
      }
      $row['operations'] = ['data' => $this->operations($bundle)];
      $table_rows[$id] = $row;
    }

    return [
      '#type' => 'container',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $bundle_type->getCollectionLabel(),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $table_rows,
        '#empty' => $this->t('No @types yet.', ['@types' => $bundle_type->getPluralLabel()]),
        '#attributes' => ['class' => ['daedalus-ui-bundle-roster']],
      ],
    ];
  }

  /**
   * Whether a page bundle's new pages open in the page builder, as text.
   */
  private function opensInPageBuilder(ConfigEntityBundleBase $bundle): TranslatableMarkup {
    return $bundle->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none') !== 'none'
      ? $this->t('Yes')
      : $this->t('No');
  }

  /**
   * A block type's presentation setting, as the tab's label for it.
   */
  private function presentation(ConfigEntityBundleBase $bundle): TranslatableMarkup|string {
    $value = (string) $bundle->getThirdPartySetting('daedalus_ui', ElementModel::SETTING, 'auto');
    return $this->sectionOwner->presentationOptions()[$value] ?? $value;
  }

  /**
   * The row's operations: the bundle form's tab and the manage-display page.
   *
   * Only the destinations the actor may reach; a row with none renders an
   * empty cell rather than a link to a 403.
   */
  private function operations(ConfigEntityBundleBase $bundle): array {
    $links = [];
    if ($bundle->hasLinkTemplate('edit-form')) {
      $edit = $bundle->toUrl('edit-form')->setOption('fragment', self::TAB_FRAGMENT);
      if ($edit->access()) {
        $links['edit'] = ['title' => $this->t('Page builder settings'), 'url' => $edit];
      }
    }
    $display = $this->sectionOwner->manageDisplayUrl($bundle);
    if ($display !== NULL && $display->access()) {
      $links['display'] = ['title' => $this->t('Manage display'), 'url' => $display];
    }
    return [
      '#type' => 'operations',
      '#links' => $links,
    ];
  }

}
