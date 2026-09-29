<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * A styling address: where a rule, a material or a roster answer applies.
 *
 * The one home of the address grammar every styling surface speaks. An
 * address names a place in the declaration space, never a live element:
 * a family and an id ("kind:container", "block_type:text",
 * "block_plugin:system_menu_block", "layout:layout_onecol", "base:a"),
 * optionally refined by a relative selector into that element's subtree
 * ("::.class-name" a declared part, "::p" its paragraphs, "::> *" its
 * direct children, "::> pb-text .link"; RelativeSelector is the grammar).
 * A variant's scope is a root address (no selector); the element key
 * grammar (daedalus_stylish's StyleMapValidator::parseKey(),
 * "container:{uuid}::> pb-text") shares the selector grammar through
 * RelativeSelector::splitKey() and resolves to an address by capture.
 *
 * Families:
 * - kind:{kind}: an element kind (entity, container, field, block); the
 *   family wildcard for the bundle-like families below.
 * - block_type:{bundle}: the block kind refined by inline block bundle.
 * - block_plugin:{plugin_id}: the block kind refined by base block plugin.
 * - layout:{layout_id}: the container kind refined by layout plugin.
 * - base:{tag}: bare prose markup by tag (tag treatments, site defaults).
 *
 * The grammar here is charset only. Which kinds, tags, bundles, plugins
 * and layouts exist is vocabulary, and stays with the module that owns
 * it (daedalus_stylish's style guide law); an address that parses may still name
 * nothing declared.
 *
 * Immutable and stringable: printed, an address is exactly the string
 * the client twin reads and the variant entity stores.
 */
final class Address implements \Stringable {

  public const FAMILY_KIND = 'kind';
  public const FAMILY_BLOCK_TYPE = 'block_type';
  public const FAMILY_BLOCK_PLUGIN = 'block_plugin';
  public const FAMILY_LAYOUT = 'layout';
  public const FAMILY_BASE = 'base';
  public const FAMILIES = [
    self::FAMILY_KIND,
    self::FAMILY_BLOCK_TYPE,
    self::FAMILY_BLOCK_PLUGIN,
    self::FAMILY_LAYOUT,
    self::FAMILY_BASE,
  ];

  /**
   * The kinds the bundle-like families refine.
   */
  public const KIND_CONTAINER = 'container';
  public const KIND_BLOCK = 'block';

  /**
   * The id charset: a machine name (a bundle, a plugin id, a tag).
   */
  public const ID = '[a-z0-9_]+';

  /**
   * The suffix separator (the relative selector grammar's).
   */
  public const SUFFIX = RelativeSelector::SUFFIX;

  /**
   * Constructs an address; use the named constructors or parse().
   */
  private function __construct(
    public readonly string $family,
    public readonly string $id,
    public readonly ?RelativeSelector $selector = NULL,
  ) {}

  /**
   * An address from its parts, refusing anything outside the grammar.
   *
   * @param string $family
   *   One of FAMILIES.
   * @param string $id
   *   The machine name.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|string|null $selector
   *   The relative selector, as an object or as text in any spelling the
   *   grammar reads (canonicalized), or NULL for the root.
   *
   * @throws \InvalidArgumentException
   *   When the family, id or selector is not in the grammar.
   */
  public static function of(string $family, string $id, RelativeSelector|string|null $selector = NULL): self {
    if (!in_array($family, self::FAMILIES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an address family; one of %s.', $family, implode(', ', self::FAMILIES)));
    }
    if (!preg_match('/^' . self::ID . '$/', $id)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an address id (%s:{id}); an id is a machine name.', $id, $family));
    }
    if (is_string($selector)) {
      $selector = RelativeSelector::fromString($selector);
    }
    return new self($family, $id, $selector);
  }

  /**
   * The address of an element kind: "kind:{kind}".
   */
  public static function kind(string $kind): self {
    return self::of(self::FAMILY_KIND, $kind);
  }

  /**
   * The address of an inline block bundle: "block_type:{bundle}".
   */
  public static function blockType(string $bundle): self {
    return self::of(self::FAMILY_BLOCK_TYPE, $bundle);
  }

  /**
   * The address of a base block plugin: "block_plugin:{plugin_id}".
   */
  public static function blockPlugin(string $plugin_id): self {
    return self::of(self::FAMILY_BLOCK_PLUGIN, $plugin_id);
  }

  /**
   * The address of a layout plugin: "layout:{layout_id}".
   */
  public static function layout(string $layout_id): self {
    return self::of(self::FAMILY_LAYOUT, $layout_id);
  }

  /**
   * The address of a bare-markup tag: "base:{tag}".
   */
  public static function base(string $tag): self {
    return self::of(self::FAMILY_BASE, $tag);
  }

  /**
   * Parses a printed address, or NULL when the string is not one.
   *
   * The selector, when present, is admitted in its canonical spelling
   * only (RelativeSelector::splitKey()): a printed address has one shape.
   */
  public static function parse(string $address): ?self {
    $split = RelativeSelector::splitKey($address);
    if ($split === NULL) {
      return NULL;
    }
    [$root, $selector] = $split;
    if (!preg_match('/^(' . implode('|', self::FAMILIES) . '):(' . self::ID . ')$/', $root, $matches)) {
      return NULL;
    }
    return new self($matches[1], $matches[2], $selector);
  }

  /**
   * Parses a printed address, refusing anything else.
   *
   * For strings the grammar already admitted (a saved variant's scope, a
   * roster record's print): a failure here is corruption, not input.
   *
   * @throws \InvalidArgumentException
   *   When the string is not an address.
   */
  public static function fromString(string $address): self {
    return self::parse($address) ?? throw new \InvalidArgumentException(sprintf('"%s" is not a styling address; an address is {family}:{id} with family one of %s, optionally followed by "::" and a relative selector in its canonical spelling. %s', $address, implode(', ', self::FAMILIES), RelativeSelector::GRAMMAR));
  }

  /**
   * The address without its selector.
   */
  public function root(): self {
    return $this->isRoot() ? $this : new self($this->family, $this->id);
  }

  /**
   * The same root refined by another selector (or none).
   */
  public function withSelector(?RelativeSelector $selector): self {
    return new self($this->family, $this->id, $selector);
  }

  /**
   * Whether the address carries no selector.
   */
  public function isRoot(): bool {
    return $this->selector === NULL;
  }

  /**
   * Whether the address is a bare kind (the family wildcard).
   */
  public function isKind(): bool {
    return $this->family === self::FAMILY_KIND;
  }

  /**
   * Whether the address is a bare-markup tag treatment.
   */
  public function isBase(): bool {
    return $this->family === self::FAMILY_BASE;
  }

  /**
   * The element kind the address belongs to, or NULL for a base tag.
   *
   * A layout address is a container, a block type or block plugin
   * address a block, a kind address its own kind; bare markup is not an
   * element kind.
   */
  public function elementKind(): ?string {
    return match ($this->family) {
      self::FAMILY_KIND => $this->id,
      self::FAMILY_LAYOUT => self::KIND_CONTAINER,
      self::FAMILY_BLOCK_TYPE, self::FAMILY_BLOCK_PLUGIN => self::KIND_BLOCK,
      self::FAMILY_BASE => NULL,
    };
  }

  /**
   * Whether this variant address pairs with an element's (the family rule).
   *
   * ONE family rule for both bundle-like families (containers refined by
   * layout, blocks refined by block type OR base block plugin): addresses
   * pair only within their family, and within a family the kind is the
   * wildcard, so refinements must match exactly. "layout:x" pairs only
   * with "layout:x", "block_type:cta" only with "block_type:cta",
   * "block_plugin:menu" only with "block_plugin:menu" (the block family's
   * two refinements never pair with each other: different templates,
   * different markup); a kind on EITHER side pairs with any address of
   * its family. A base:{tag} variant (a tag treatment) pairs with EVERY
   * element address: whether an element renders as that tag is a
   * live-DOM fact the families cannot express, so the tag gate is soft
   * enforcement at offer time. Selectors ride their element: pairing
   * reads the roots, exactly as a selector key captures its parent's
   * scope.
   *
   * The client twin is daedalus_ui's roster.js pairing over printed
   * scopes, locked to the roster's string door (MaterialsRoster, which
   * delegates here) by ValueGrammarTest::testClientTwinEquivalence.
   */
  public function pairsWith(Address $element): bool {
    if ($this->root()->equals($element->root())) {
      return TRUE;
    }
    if ($this->isBase()) {
      return TRUE;
    }
    $kind = $this->elementKind();
    return $kind !== NULL && $kind === $element->elementKind() && ($this->isKind() || $element->isKind());
  }

  /**
   * Whether two addresses print the same.
   */
  public function equals(Address $other): bool {
    return (string) $this === (string) $other;
  }

  /**
   * The address as the grammar prints it: "{family}:{id}[::selector]".
   */
  public function __toString(): string {
    $printed = $this->family . ':' . $this->id;
    if ($this->selector !== NULL) {
      $printed .= self::SUFFIX . $this->selector;
    }
    return $printed;
  }

}
