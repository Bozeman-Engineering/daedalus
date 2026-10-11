<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Utility;

use Drupal\Component\Uuid\Uuid;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * The element a selectable or style map key addresses.
 *
 * Every key is a kind qualified by a live uuid (entity:{uuid},
 * field:{uuid}:{name}, container:{uuid}, block:{uuid},
 * block:{uuid}:{field}), optionally continued by "::" plus a relative
 * selector (a rule under the element). Whatever the key addresses (the
 * element itself, one of its fields, a rule under it), the capability
 * asked is the element's: this reads the uuid out of the key and nothing
 * else. The full key grammar (which kinds, which selectors) stays the
 * style map validator's.
 */
final class ElementKey {

  /**
   * The key prefixes that qualify an element uuid.
   */
  private const KINDS = ['entity', 'field', 'container', 'block'];

  /**
   * The uuid of the element a key addresses.
   *
   * @param string $key
   *   The key.
   *
   * @return string|null
   *   The element uuid, or NULL when the key does not name one.
   */
  public static function uuid(string $key): ?string {
    $split = RelativeSelector::splitKey($key);
    if ($split === NULL) {
      return NULL;
    }
    $parts = explode(':', $split[0], 3);
    if (count($parts) < 2 || !in_array($parts[0], self::KINDS, TRUE) || !Uuid::isValid($parts[1])) {
      return NULL;
    }
    return $parts[1];
  }

}
