<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Serializes Drupal entities to blueprint JSON format.
 */
interface BlueprintSerializerInterface {

  /**
   * Serializes an entity to a full blueprint array.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The blueprint array with entity_type, bundle, fields, and optionally
   *   _root_entity and the document keys layout and styles; `_verdict` on
   *   the root and on every layout node says what the current user may do
   *   there (VerdictStamper).
   */
  public function serialize(HostInterface $host, ?string $langcode = NULL): array;

  /**
   * Serializes an entity to a structural summary.
   *
   * Shows the full tree hierarchy at all depths but never includes field
   * values. Used as a "table of contents" for AI navigation.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to summarize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array
   *   The summary blueprint array.
   */
  public function serializeSummary(HostInterface $host, ?string $langcode = NULL): array;

  /**
   * Serializes a single element by UUID.
   *
   * A layout element (a block or a container) answers its lean node, the
   * same object it is inside the full read's `layout`: a container with
   * its subtree, every node's styles on the node. A paragraph answers its
   * fields in full, with nested content as summaries.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host containing the element.
   * @param string $uuid
   *   The UUID of the element to serialize.
   * @param string|null $langcode
   *   The language code for translation output. NULL for default language.
   *
   * @return array|null
   *   The element, stamped with `_verdict` (what the current user may do
   *   to it; a template element a plain host renders is read from the
   *   document the host renders and refused everything), or NULL if the
   *   UUID was not found.
   */
  public function serializeElement(HostInterface $host, string $uuid, ?string $langcode = NULL): ?array;

}
