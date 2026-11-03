<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * One row of the bundle roster, open for other modules' columns.
 *
 * The roster (/admin/config/daedalus/ui/content-types, Controller\BundleRoster)
 * mirrors the bundle form's Page builder tab as a read-only table, and the
 * tab is shared: daedalus_ui owns the switch and the creation and
 * presentation controls, other modules add theirs into its groups. The
 * roster is shared the same way. daedalus_ui dispatches this event once per
 * bundle it lists, with the bundle config entity in, and renders whatever
 * columns come back; a module that owns a bundle setting answers with one
 * column per setting (daedalus_layout's starting designs, daedalus_stylish's
 * styling), the SettingsSidebarEvent shape (contributor in, keyed render
 * arrays out).
 *
 * A column is a header and this row's cell under one id. The header is the
 * same on every row (the table has one header line, built from the union
 * of the ids the rows answered); the cell is the row's value for it, as
 * plain text, TranslatableMarkup or a render array. A row that says
 * nothing for an id the table has gets an empty cell. A subscriber whose
 * answer depends on more than the bundle entity (a display, a field)
 * declares it through the cacheable dependency interface so the roster
 * invalidates with it; the bundle config types' list cache tags are the
 * roster's own.
 */
final class BundleRosterEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * The columns, keyed by id: ['header' => ..., 'cell' => ...].
   */
  private array $columns = [];

  public function __construct(
    private readonly ConfigEntityBundleBase $bundle,
  ) {}

  /**
   * The bundle config entity the row is for.
   */
  public function getBundle(): ConfigEntityBundleBase {
    return $this->bundle;
  }

  /**
   * The content entity type the bundle belongs to (node, block_content, ...).
   */
  public function getBundleOf(): string {
    return (string) $this->bundle->getEntityType()->getBundleOf();
  }

  /**
   * Adds this row's cell under a column, with the column's header.
   *
   * @param string $id
   *   The column id, the same on every row ('starting_designs', 'styling').
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string $header
   *   The column header, the same on every row.
   * @param array|\Drupal\Core\StringTranslation\TranslatableMarkup|string $cell
   *   This row's value: a render array, or text.
   */
  public function setColumn(string $id, TranslatableMarkup|string $header, array|TranslatableMarkup|string $cell): void {
    $this->columns[$id] = ['header' => $header, 'cell' => $cell];
  }

  /**
   * The collected columns, keyed by id: ['header' => ..., 'cell' => ...].
   */
  public function getColumns(): array {
    return $this->columns;
  }

}
