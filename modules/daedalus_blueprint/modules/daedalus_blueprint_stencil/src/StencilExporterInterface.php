<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Exports a placed element as a stencil definition.
 *
 * The inverse of the instantiate_stencil operation: build a thing in the UI
 * or through the ops spine, capture it, ship it as content or a file. The
 * save_material / update_material spine ops and the stencil shipper (behind
 * drush daedalus-blueprint:stencil-ship) are thin adapters over this one walk,
 * so every emitter of stencil definitions speaks the same grammar the
 * discovery validates.
 */
interface StencilExporterInterface {

  /**
   * Exports a placed element subtree as a stencil definition.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity hosting the element. Per the map-host rule this is also
   *   the entity whose daedalus_stylish_styles map is chased for the subtree's styles
   *   (every element's styles live on the ROOT entity).
   * @param string $uuid
   *   The UUID of the root element to export: a SECTION uuid (top-level band
   *   or nested section) or an inline-block component uuid.
   * @param array $options
   *   Export options:
   *   - label (string): the stencil label. Defaults to the root element's
   *     display label (a section's label or layout name, a block's display
   *     label or block type label).
   *   - description (string): the stencil description. Defaults to a
   *     placeholder naming the source entity; edit it before shipping.
   *   - strip_values (bool): skeletonize — omit all pinned block_fields so
   *     every field sample-populates at placement. Structure, layout
   *     settings, and styles are kept. Defaults to FALSE.
   *   - strip_references (bool): prune reference fields (entity, media,
   *     image, file) from block_fields — the disk-shipping strategy, since
   *     reference targets are site-local IDs; the absent fields
   *     sample-populate at placement. Defaults to FALSE: ordinary captures
   *     PRESERVE references.
   *
   * @return \Drupal\daedalus_blueprint_stencil\StencilExport
   *   The export: the stencil definition and warnings for styling the file
   *   grammar cannot express.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilExportException
   *   When the element does not exist on the entity, is not a Layout
   *   Builder element, or the subtree contains something the
   *   stencil grammar cannot represent at all (e.g. a plugin block).
   */
  public function export(FieldableEntityInterface $entity, string $uuid, array $options = []): StencilExport;

  /**
   * The variant references a stencil element's styles carry.
   *
   * One walk over the stored element shape (the exporter's own output:
   * per-node `styles` maps whose entries carry normalized bare-string
   * `variants` lists; the pre-normalization {variant, fragment} shape is
   * read defensively). Adoption validates against this set and the stencil
   * shipper's variant roster is built from it.
   *
   * @param array $element
   *   A stencil element tree (StencilInterface::getElement()).
   *
   * @return string[]
   *   The unique references, in first-seen order.
   */
  public function referencedVariants(array $element): array;

}
