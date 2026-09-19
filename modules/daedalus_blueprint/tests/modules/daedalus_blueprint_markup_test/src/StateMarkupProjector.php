<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_markup_test;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\State\StateInterface;
use Drupal\daedalus_blueprint\Markup\MarkupProjectorInterface;

/**
 * A fixture projector answering with HTML stored in state.
 *
 * Lets tests exercise the markup socket's consumers (the write echo, the
 * structural audit) with exact HTML fixtures, independent of any element
 * model: set the PROJECTION_KEY state value to the HTML the projection
 * should answer with, or leave it unset for a NULL (non-answering)
 * projector.
 */
final class StateMarkupProjector implements MarkupProjectorInterface {

  /**
   * The state key holding the fixture HTML.
   */
  public const PROJECTION_KEY = 'daedalus_blueprint_markup_test.projection';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function projectEntity(FieldableEntityInterface $entity, ?string $langcode = NULL): ?string {
    return $this->state->get(self::PROJECTION_KEY);
  }

  /**
   * {@inheritdoc}
   */
  public function projectElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?string {
    return $this->state->get(self::PROJECTION_KEY);
  }

}
