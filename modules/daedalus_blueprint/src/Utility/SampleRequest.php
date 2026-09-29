<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Utility;

/**
 * The reserved `_sample` request: fill this field with a sample value.
 *
 * A write op names a field's value in the blueprint grammar, or hands the
 * spine an ExtractedValue, or asks the spine to make the value up:
 * `{field: {"_sample": true}}`. The request is resolved server-side at
 * write time through the ladder's request entrance (SampleFiller::extract():
 * the field's default value, then the site's configured field_sample_value
 * generator, whose answer is final even when empty, then core's
 * generateSampleItems() only when no generator is configured) into an
 * ExtractedValue, so the fill is one labeled, undoable, access- and
 * constraint-checked op like any other, usable by the panel's
 * "Fill with sample" verb and by the AI alike. The request means "make one
 * up", which is why it may invent where a placement (add_element's
 * `populate: 'sample'`) fills only what the site answers.
 *
 * The underscore prefix is the grammar's reserved vocabulary (see
 * StructuralValidator); a request carries no other keys. It is an op-time
 * word only: a whole blueprint (create_entity, a full update) carries
 * values, and Phase A refuses the request there.
 */
final class SampleRequest {

  /**
   * The marker key identifying a sample request.
   */
  public const KEY = '_sample';

  /**
   * Whether a written field value is a sample request.
   *
   * @param mixed $value
   *   The field value from an op's property bag.
   *
   * @return bool
   *   TRUE for a request (any array carrying a truthy marker; the write
   *   path refuses one that carries anything else beside it).
   */
  public static function isRequest(mixed $value): bool {
    return is_array($value) && !empty($value[self::KEY]);
  }

  /**
   * Whether a sample request is well-formed: the marker and nothing else.
   *
   * @param array $value
   *   A value isRequest() admitted.
   *
   * @return bool
   *   TRUE when the marker is the only key.
   */
  public static function isBare(array $value): bool {
    return array_keys($value) === [self::KEY];
  }

}
