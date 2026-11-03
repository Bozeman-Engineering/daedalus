<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Component\Utility\Html;

/**
 * Prints a resolved element tree as a one-line HTML sketch.
 *
 * The sketch is the output half of a render card's pair: the markup a
 * dialect write renders as, printed from the ElementResolver's tree for
 * the facade that write sets (ElementCardsSubscriber), never hand-written,
 * so it cannot drift from the render. The print format is the keyed-box format
 * of the markup model without its keys (a card addresses no instance):
 * the root element wearing its hoisted classes and its resolved
 * attributes, every present part nested where the tree puts it wearing
 * its part class, and the element's content slot (a block's body, a
 * container's children) printed as an ellipsis exactly where the mounting
 * rule lands it (inside the part whose slot claims the content token, at
 * the root when nothing does).
 *
 * Two facts of the print format, recorded here because the tree alone
 * does not spell them: a root the grammar names no tag for prints as the
 * `div` stand-in (every shipped unmodeled wrapper renders as a div, and a
 * sketch needs a printable box for the parts to nest in), and a part the
 * grammar names no tag for prints nothing, subtree included (it is a
 * shell the theme's template owns; the composed render mounts nothing for
 * it either, ComposedTree::buildElement). The resolver's
 * content-claim marker (ElementResolver::CLAIMS_CONTENT_ATTRIBUTE) is the
 * renderer's co-targeting hook, not markup a reader needs, so the sketch
 * drops it; where the content placeholder lands says where the content
 * mounts.
 */
final class SketchPrinter {

  /**
   * The placeholder every open value prints as: an attribute's, the content's.
   */
  public const PLACEHOLDER = '…';

  /**
   * Tags that self-close: no closing tag, no interior content.
   */
  private const VOID_TAGS = [
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
    'meta', 'source', 'track', 'wbr',
  ];

  /**
   * Prints one resolved tree.
   *
   * @param array $tree
   *   The resolver's tree ({tag, attributes, classes, hoisted, parts,
   *   slots}, see ElementResolver).
   * @param string|null $content_token
   *   The slot token of the element's content (a block's body field as
   *   `field:NAME`, a container's `regions`), printed as the placeholder
   *   where the tree's slots mount it; NULL prints no content (an element
   *   with no content, or a form the content cannot be in).
   *
   * @return string
   *   The sketch, one line.
   */
  public static function print(array $tree, ?string $content_token): string {
    $slots = [];
    foreach ($tree['slots'] ?? [] as $token => $claimant) {
      if (is_string($token) && is_string($claimant)) {
        $slots[$token] = $claimant;
      }
    }
    $classes = array_values(array_filter(array_map('strval', $tree['classes'] ?? [])));
    return self::element($tree['tag'] ?? 'div', $classes, self::attributesOf($tree), $tree['parts'] ?? [], '', $slots, $content_token);
  }

  /**
   * Prints one element: open tag, nested parts and content, close tag.
   *
   * The print twin of ComposedTree::buildElement, one line: the same part
   * nesting, the same slot claims, the same mounting-rule residual.
   */
  private static function element(string $tag, array $classes, array $attributes, mixed $parts, string $self_key, array $slots, ?string $content_token): string {
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $tag)) {
      throw new \UnexpectedValueException(sprintf('"%s" resolved as an element tag but is not a printable tag name.', $tag));
    }
    $open = '<' . $tag;
    if ($classes !== []) {
      $open .= ' class="' . Html::escape(implode(' ', array_unique($classes))) . '"';
    }
    foreach ($attributes as $name => $value) {
      $open .= ' ' . $name . '="' . Html::escape($value) . '"';
    }
    if (in_array($tag, self::VOID_TAGS, TRUE)) {
      return $open . '>';
    }
    $inner = '';
    foreach (is_array($parts) ? $parts : [] as $key => $part) {
      if (!is_array($part) || !is_string($part['tag'] ?? NULL)) {
        continue;
      }
      $inner .= self::element($part['tag'], [ltrim((string) $key, '.')], self::attributesOf($part), $part['parts'] ?? [], (string) $key, $slots, $content_token);
    }
    if ($content_token !== NULL && ($slots[$content_token] ?? '') === $self_key) {
      $inner .= self::PLACEHOLDER;
    }
    return $open . '>' . $inner . '</' . $tag . '>';
  }

  /**
   * A node's literal attributes, the content-claim marker dropped.
   *
   * @return array<string, string>
   *   Attribute name => value.
   */
  private static function attributesOf(array $node): array {
    $attributes = [];
    foreach ($node['attributes'] ?? [] as $name => $value) {
      if (is_string($name) && is_scalar($value) && $name !== ElementResolver::CLAIMS_CONTENT_ATTRIBUTE) {
        $attributes[$name] = (string) $value;
      }
    }
    return $attributes;
  }

}
