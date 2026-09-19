<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * Reads the instance identities out of a rich-text body.
 *
 * Rich bodies are opaque-verbatim to the dialect grammar, but their
 * instance classes (the catalog's declared namespaces, '.s-*') are the
 * addressable text vocabulary, so two dialect checks read them:
 * - the `#id .s-x` existence check (a style selector must name an
 *   identity the element's ACTUAL content carries: staged post-op
 *   content, so a same-batch body write + style validates), with the
 *   element's real instances and excerpts riding the corrective;
 * - the within-batch collision check on authored bodies (the same
 *   authored identity on two mintable carriers would be silently
 *   re-minted by the identity minter, orphaning the batch's own styles;
 *   the dialect refuses it loudly instead).
 *
 * The mintable-carrier rule mirrors the minter exactly: minting is
 * universal, so every declared-tag element is a mintable carrier at any
 * depth, list items and nested lists included.
 */
final class DialectTextInstances {

  /**
   * The instance identities a rich body carries, in document order.
   *
   * @param string $html
   *   The rich body markup.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The site vocabulary (declared instance namespaces).
   *
   * @return array<string, array{tag: string, excerpt: string}>
   *   class => tag + text excerpt, first carrier per class.
   */
  public static function instancesIn(string $html, DialectCatalog $catalog): array {
    $instances = [];
    foreach (self::carriers($html, $catalog) as [$class, $element]) {
      $instances[$class] ??= [
        'tag' => $element->nodeName,
        'excerpt' => DialectParser::excerpt($element->textContent),
      ];
    }
    return $instances;
  }

  /**
   * Authored identities that collide: one class, two mintable carriers.
   *
   * A collision is any instance class whose SECOND-or-later carrier is a
   * mintable element (a declared-tag element, at any depth: minting is
   * universal): that is exactly where the identity minter would silently
   * re-mint, so the styles the batch wrote against the class would
   * silently detach.
   *
   * @return string[]
   *   The colliding class names, in document order.
   */
  public static function collisions(string $html, DialectCatalog $catalog): array {
    $tags = array_keys($catalog->instanceTags());
    $owner = [];
    $collisions = [];
    foreach (self::carriers($html, $catalog) as [$class, $element]) {
      if (!isset($owner[$class])) {
        $owner[$class] = $element;
        continue;
      }
      if ($owner[$class] === $element) {
        continue;
      }
      if (in_array($element->nodeName, $tags, TRUE)) {
        $collisions[$class] = TRUE;
      }
    }
    return array_keys($collisions);
  }

  /**
   * Walks every element carrying an instance class, in document order.
   *
   * @return iterable<array{0: string, 1: \DOMElement}>
   *   [class, element] pairs; an element yields once per instance class
   *   it carries.
   */
  private static function carriers(string $html, DialectCatalog $catalog): iterable {
    if (trim($html) === '' || $catalog->instances() === []) {
      return;
    }
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    foreach ($document->getElementsByTagName('*') as $element) {
      if (!$element->hasAttribute('class')) {
        continue;
      }
      foreach (preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) as $class) {
        if ($catalog->instanceNamespaceFor($class) !== NULL) {
          yield [$class, $element];
        }
      }
    }
  }

}
