<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

/**
 * What an actor may do to an element: the closed vocabulary.
 *
 * Every operation declares the capability it needs of each target (see
 * OperationHandlerInterface::getRequirements()), the resolver answers a
 * verdict per capability and target, and every surface (the op door, the
 * reads, the client gate) speaks these five words and no others.
 */
enum Capability: string {

  // Change field values the host owns (the root's fields, a block's fields).
  case Content = 'content';

  // Change an element's settings: plugin configuration, label, layout
  // settings.
  case Configure = 'configure';

  // Write style entries or variants addressed at the element.
  case Style = 'style';

  // Move, remove or duplicate the element.
  case Arrange = 'arrange';

  // Place or move something into this container (asked of the destination).
  case Insert = 'insert';

  /**
   * The five capability names, for messages that teach the vocabulary.
   *
   * @return string[]
   *   The names.
   */
  public static function names(): array {
    return array_map(static fn (self $capability): string => $capability->value, self::cases());
  }

  /**
   * Whether the capability writes the document's structure or styling.
   *
   * Everything but content: the document (the section tree plus its style
   * map) is one structure shared by every translation and owned by one
   * host, so these are the capabilities ownership and translation freeze.
   */
  public function isDocumentWrite(): bool {
    return $this !== self::Content;
  }

}
