<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\layout_builder\Section;

/**
 * Dependency-free reader of daedalus_layout section identity.
 *
 * The daedalus_layout section UUID lives in a third-party setting on the Section
 * value, so reading it needs no services. Keeping this accessor free of
 * them lets PlacementRestrictions consume section identity without
 * depending on DaedalusLayoutAdapter, whose default-section ladder depends on
 * PlacementRestrictions — a service cycle otherwise. An unstamped section
 * (including any section on a site without daedalus_layout) reads NULL.
 */
final class SectionIdentity {

  /**
   * Returns a section's daedalus_layout UUID, or NULL when unstamped.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   *
   * @return string|null
   *   The daedalus_layout section UUID, or NULL.
   */
  public static function uuid(Section $section): ?string {
    return $section->getThirdPartySetting('daedalus_layout', 'uuid');
  }

}
