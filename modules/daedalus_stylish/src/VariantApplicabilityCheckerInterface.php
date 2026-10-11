<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * A cross-module veto on applying a variant to a target element.
 *
 * The applicability seam: daedalus_stylish's own applicability rule is scope (the
 * validator's family pairing), but other modules can hold facts that make a
 * scope-valid apply wrong — daedalus_blueprint_library's variant OWNERSHIP is
 * the founding case (a blueprint's owned variants are its curated switchable
 * looks and apply only inside that blueprint's placements). Daedalus Stylish stays
 * ignorant of what those facts are: it asks "may this variant apply to this
 * target?" through every service tagged daedalus_stylish.variant_applicability_checker
 * and refuses the gesture on any non-empty answer, exactly mirroring how the
 * ownership fields themselves are provided cross-module onto daedalus_variant.
 *
 * The seam gates the apply_variant GESTURE only — a NEW wearing. Existing
 * references keep validating, rendering, and stay removable/detachable
 * anywhere (orphan tolerance), and nested distribution references are the
 * variant's own composition, never re-checked. The client's offer filter
 * (js/src/variant-scope.js applicableVariants) and this server check are two
 * implementations of one rule; the seam is the backstop that makes the rule
 * hold for callers with no picker, the AI's op lane first among them.
 */
interface VariantApplicabilityCheckerInterface {

  /**
   * Checks whether a variant may be newly applied to a target element.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant the gesture would apply.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host carrying the style map.
   * @param string $key
   *   The gesture target's selectable key.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors refusing the apply; empty when this checker allows it.
   */
  public function check(DaedalusStylishVariantInterface $variant, HostInterface $host, string $key): array;

}
