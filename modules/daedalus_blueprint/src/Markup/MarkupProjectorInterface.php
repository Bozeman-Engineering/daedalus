<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Markup;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Projects an entity's blueprint as key-annotated HTML — the print format.
 *
 * The canonical instance model of what every element renders as is the
 * `_element.markup` annotation on delivered blueprints (JSON, resolved
 * server-side from entity data by the page builder's element resolver).
 * At the AI read boundary that same tree prints as annotated HTML:
 * `data-key` attributes carry the selectable element keys the style and
 * operation grammar addresses (entity:{uuid}, container:{uuid},
 * block:{uuid}), and inner elements wear the part classes that compose
 * sub-element keys ({key}::.class-name). HTML is a PRINT FORMAT of the
 * one model — never a scrape of rendered output, never a second model.
 *
 * This interface is the SOCKET: daedalus_blueprint owns the seam every
 * consumer reads through (the AI read tools' markup mode, write-result
 * markup echoes), and the module that owns the element model plugs a
 * projector in via the `daedalus_blueprint.markup_projector` service tag.
 * The dependency direction is load-bearing: the model owner depends on
 * daedalus_blueprint, never the reverse, so this module defines the
 * contract and holds no projection logic of its own.
 */
interface MarkupProjectorInterface {

  /**
   * Projects a whole entity's page tree as key-annotated HTML.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity, in its staged state.
   * @param string|null $langcode
   *   The language code for translation output, or NULL for the default
   *   language.
   *
   * @return string|null
   *   The key-annotated HTML, or NULL when this projector does not answer
   *   for the entity.
   */
  public function projectEntity(FieldableEntityInterface $entity, ?string $langcode = NULL): ?string;

  /**
   * Projects one element (a block or section) as key-annotated HTML.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity containing the element.
   * @param string $uuid
   *   The element's UUID (a Layout Builder block or section).
   * @param string|null $langcode
   *   The language code for translation output, or NULL for the default
   *   language.
   *
   * @return string|null
   *   The key-annotated HTML, or NULL when this projector does not answer
   *   for the entity or no element carries the UUID.
   */
  public function projectElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?string;

}
