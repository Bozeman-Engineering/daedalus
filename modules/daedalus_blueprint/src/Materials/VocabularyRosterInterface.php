<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * What one address may style, as the spine's consumers read it.
 *
 * The vocabulary spine's contract, the twin of the materials roster: the
 * materials roster answers "which materials exist and where do they
 * apply", a vocabulary roster answers "which CSS properties may this
 * address style" for ONE address (Address), with the corrective for a
 * property outside the grant on the object beside the grant itself, the
 * way MaterialsRoster carries candidates() and didYouMean() beside its
 * records. The styling layer builds the roster (its law, its property
 * groups, its enforcement mode); the spine's dialect (the catalog the
 * prompt reads) consumes it through this interface only, so the spine
 * stays styling-agnostic while every door refuses a miss with one
 * corrective.
 */
interface VocabularyRosterInterface {

  /**
   * The address this roster answers for.
   */
  public function address(): Address;

  /**
   * The granted CSS property names, in declaration order.
   *
   * @return string[]
   *   The names; [] when the address grants nothing.
   */
  public function granted(): array;

  /**
   * The granted names nearest to a property outside the grant.
   *
   * @return string[]
   *   The candidates, best first, at most $limit of them.
   */
  public function candidates(string $property, int $limit = 5): array;

  /**
   * The did-you-mean sentence for a property outside the grant, or ''.
   *
   * The materials roster's shape: '' when nothing granted is close.
   */
  public function didYouMean(string $property, int $limit = 5): string;

  /**
   * The one corrective for a property outside the grant.
   *
   * The whole sentence a refusal carries (the semantic validator's
   * STYLE_PROPERTY_NOT_DECLARED, on every door a write reaches it by):
   * the did-you-mean when something is close, otherwise that nothing is,
   * and where the whole roster lives.
   */
  public function corrective(string $property): string;

}
