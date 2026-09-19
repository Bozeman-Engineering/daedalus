<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\Section;

/**
 * Rewrites one deprecated SECTION shape to its replacement.
 *
 * The component-transform spine covers components; some deprecations change
 * the section itself (its layout plugin and how its components are grouped).
 * Core's Section cannot change layout id in place, so a section transform
 * RETURNS the section to keep: either the given instance (mutated) or a
 * freshly built replacement. The registry writes replacements back into
 * whatever list held them (host layout fields, display defaults, embedded
 * container configurations), so one tagged class covers every store and
 * every depth exactly like component transforms do.
 *
 * Contract:
 * - MUST preserve the section's identity: the daedalus_layout uuid third-party
 *   setting carries styles, ops addressing, and tree identity, and a
 *   replacement section must keep it verbatim.
 * - MUST NOT save the host; the caller (store walkers, the presave net)
 *   owns persisting.
 * - applies() runs for every section of every store and every save while
 *   the transform is registered, so it must be cheap (typically a layout id
 *   check).
 * - May touch the host (e.g. its style field) to keep host state coherent
 *   with the structural rewrite, mirroring component transforms.
 *
 * Register with the daedalus_layout.section_transform service tag (optional
 * priority, higher first). Section transforms run BEFORE the component walk
 * of the section, so freshly minted container components are descended in
 * the same pass.
 */
interface SectionTransformInterface {

  /**
   * Whether this transform applies to the given section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   A section from any store the spine walks.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the section (a content entity revision
   *   or translation, or an entity view display).
   *
   * @return bool
   *   TRUE when transform() should run.
   */
  public function applies(Section $section, EntityInterface $host): bool;

  /**
   * Transforms the section, returning the section to keep.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to rewrite.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the section.
   *
   * @return \Drupal\layout_builder\Section
   *   The section to keep in the section's place: the same instance or a
   *   replacement carrying the same daedalus_layout uuid.
   */
  public function transform(Section $section, EntityInterface $host): Section;

}
