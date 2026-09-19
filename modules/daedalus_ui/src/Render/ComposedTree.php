<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\AttributeHelper;

/**
 * Prints one resolved element tree as a render array.
 *
 * THE shared tree builder under every composed render: composed blocks
 * (ComposedBlockRenderer) and composed containers (ComposedLayoutRenderer)
 * both hand it the element resolver's concrete tree and get back the same
 * markup shape — the matched root form's tag and attributes, the present
 * parts as nested elements wearing their part classes, hoisted classes and
 * attribute overrides on the root, the caller's hoisted attribute bag
 * merged onto the root, and children mounted by the mounting rule (a token
 * a present part claims mounts in that part, unclaimed tokens mount at the
 * root). One computation, so what a block tree prints and what a container
 * tree prints cannot drift.
 *
 * The builder is pure markup mechanics: no entity knowledge, no provider
 * knowledge, no cacheability of its own. Owners keep those concerns at
 * their entry points.
 */
final class ComposedTree {

  /**
   * Tags that self-close: no closing tag, no interior content.
   *
   * The subset of HTML's void elements the markup grammar can reach; a
   * resolved void element with mounted children is a declaration bug and
   * fails loud below.
   */
  private const VOID_TAGS = [
    'area',
    'base',
    'br',
    'col',
    'embed',
    'hr',
    'img',
    'input',
    'link',
    'meta',
    'source',
    'track',
    'wbr',
  ];

  /**
   * Builds the render array for one resolved tree.
   *
   * @param array $resolved
   *   The element resolver's tree ({tag, attributes, classes, hoisted,
   *   parts, slots}); the tag must have resolved (callers own the
   *   undeclared-root degrade).
   * @param array $hoisted_attributes
   *   The caller's attribute bag for the tree root (a composed block's
   *   editing attributes, a section's identity attributes), merged after
   *   the declared attributes and classes.
   * @param array $children
   *   The tree's children by mount token; mounted children are consumed,
   *   and the residual of the mounting rule mounts at the root.
   * @param array $overrides
   *   Per-element attribute maps by part key ('' for the root); a hoisted
   *   part's overrides land on the root, because its key still addresses
   *   an element and that element is the root.
   *
   * @return array
   *   The tree's render array.
   */
  public function build(array $resolved, array $hoisted_attributes = [], array $children = [], array $overrides = []): array {
    $attributes = $resolved['attributes'];
    foreach ($resolved['classes'] as $class) {
      $attributes['class'][] = $class;
    }
    $attributes = AttributeHelper::mergeCollections($attributes, $hoisted_attributes);
    foreach ($resolved['hoisted'] as $key) {
      if (isset($overrides[$key])) {
        $attributes = AttributeHelper::mergeCollections($attributes, $overrides[$key]);
      }
    }
    if (isset($overrides[''])) {
      $attributes = AttributeHelper::mergeCollections($attributes, $overrides['']);
    }

    $element = $this->buildElement($resolved['tag'], $attributes, $resolved['parts'], '', $resolved['slots'], $children, $overrides);
    // The residual of the mounting rule: children no present part claims
    // mount at the element root.
    foreach ($children as $token => $child) {
      $element[$token] = $child;
    }
    return $element;
  }

  /**
   * Builds one resolved element as a render array, parts nested inside.
   *
   * @param string $tag
   *   The element's resolved tag.
   * @param array $attributes
   *   The element's complete attribute set.
   * @param array $parts
   *   The element's present parts (resolved child nodes keyed by part
   *   key).
   * @param string $self_key
   *   The part key this element answers to ('' for the root), matched
   *   against slot claims to mount this element's children.
   * @param array $slots
   *   The tree's flat slot map (token => claiming part key).
   * @param array $children
   *   The caller's children by token; mounted children are consumed.
   * @param array $overrides
   *   The caller's per-element attribute maps by part key.
   */
  private function buildElement(string $tag, array $attributes, array $parts, string $self_key, array $slots, array &$children, array $overrides): array {
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $tag)) {
      // A tag that cannot be printed safely is broken entity data or a
      // broken declaration; fail loud rather than render around it.
      throw new \UnexpectedValueException(sprintf('"%s" resolved as a composed element tag but is not a printable tag name.', $tag));
    }
    $element = [];
    foreach ($parts as $key => $part) {
      if ($part['tag'] === NULL) {
        // A part with no resolvable tag contributes no element of its own;
        // its subtree resolves against the parent (nothing shipped
        // declares this shape, but the grammar does not forbid it).
        continue;
      }
      $part_attributes = $part['attributes'];
      // A part's class is its key with the leading dot stripped.
      $part_attributes['class'][] = ltrim($key, '.');
      if (isset($overrides[$key])) {
        $part_attributes = AttributeHelper::mergeCollections($part_attributes, $overrides[$key]);
      }
      $element[$key] = $this->buildElement($part['tag'], $part_attributes, $part['parts'], $key, $slots, $children, $overrides);
    }
    foreach ($slots as $token => $claimant) {
      if ($claimant === $self_key && isset($children[$token])) {
        $element[$token] = $children[$token];
        unset($children[$token]);
      }
    }

    $attribute = new Attribute($attributes);
    if (in_array($tag, self::VOID_TAGS, TRUE)) {
      if ($element !== []) {
        throw new \UnexpectedValueException(sprintf('The composed element <%s> is a void tag but resolved with children to mount.', $tag));
      }
      return ['#markup' => Markup::create('<' . $tag . $attribute . ' />')];
    }
    // Prefix/suffix wrapping keeps mounted children live render arrays, so
    // their own cacheability and attachments bubble as normal.
    $element['#prefix'] = Markup::create('<' . $tag . $attribute . '>');
    $element['#suffix'] = Markup::create('</' . $tag . '>');
    return $element;
  }

}
