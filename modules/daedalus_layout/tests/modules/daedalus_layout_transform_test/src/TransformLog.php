<?php

namespace Drupal\daedalus_layout_transform_test;

/**
 * Records the order in which test transforms ran.
 */
class TransformLog {

  /**
   * Log entries in call order, e.g. 'legacy:{uuid}'.
   *
   * @var string[]
   */
  public static array $calls = [];

  /**
   * Entity saves in order, as 'entity_type_id:id:revision_id'.
   *
   * Recorded by daedalus_layout_transform_test_entity_presave() so walker tests can
   * assert exactly which host revisions were re-saved.
   *
   * @var string[]
   */
  public static array $saves = [];

  /**
   * Clears the log.
   */
  public static function reset(): void {
    static::$calls = [];
    static::$saves = [];
  }

}
