<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * The two kinds of value a field write on the spine can carry.
 *
 * A field in an update or add operation's property bag is either a grammar
 * value (JSON-shaped data a field handler deserializes) or an ExtractedValue
 * (an item list the caller extracted and validated in-process). The kind is
 * decided per field at apply time and reported on the FieldAppliedEvent, so
 * a subscriber that treats the two differently (or the same) reads one fact
 * instead of inspecting the value.
 */
enum FieldValueKind: string {

  /**
   * A grammar value, deserialized by the field's handler before the set.
   */
  case Value = 'value';

  /**
   * An ExtractedValue, applied as the item list the caller built.
   */
  case Extracted = 'extracted';

}
