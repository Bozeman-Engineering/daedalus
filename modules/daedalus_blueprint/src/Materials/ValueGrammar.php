<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The value grammar: what a string in a slot IS, and how a material spells.
 *
 * The ONE resolver for "is this a material reference, an inline value, or
 * nothing we know". Every consumer that used to sniff (the dialect
 * parser's var() check, the class walk, the media and generate prefix
 * tests, the pickers wrapping var() themselves, the dual variant forms)
 * asks here instead, and its JS twin (daedalus_ui
 * js/edit_mode/materials/grammar.js, locked by ValueGrammarTest's
 * twin-equivalence method through tests/js/materials-cases.mjs) answers
 * identically on the client. Change this class and the twin in the same
 * commit, or the lock fails.
 *
 * The grammar owns no vocabulary of its own: material spellings come from
 * the roster (the provider is the one place that knows "--swatch-" or
 * that a width-axis variant spells as an attribute), so resolution is an
 * exact-match index lookup per slot plus the four inline syntaxes the
 * spine defines here: a literal (anything else), url('media:<uuid>'),
 * url('generate:<prompt>') and url('file:<name>') (a file the
 * conversation holds, by the name the model knows it under).
 *
 * Slots (MaterialRecord::SLOT_*): "style" holds a CSS declaration value
 * (tokens and swatches by var(), media, generate and file by url(),
 * literals);
 * "class" holds one class token (style-axis variants);
 * "attr:{scope}:{name}" holds an axis attribute's value on one variant
 * scope (axis variants: the same attribute value on the container kind
 * and on the block kind are two slots, so a look name may repeat across
 * scopes and a consumer resolves against the scope the element's axis
 * view names); "stencil" holds a
 * placement name; "animation" holds one keyframes name (an
 * animation-name value, or one name component of the animation
 * shorthand once a property-aware consumer has split it) and resolves
 * animations by their prefixed ident. The style slot never resolves a
 * bare ident: a consumer that knows the property is animation-* asks
 * the animation slot. Any other slot is refused loudly.
 */
final class ValueGrammar {

  /**
   * A whole value that is exactly one var() reference (no fallback).
   */
  private const VAR_WHOLE_RE = '/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/';

  /**
   * A whole value that is exactly one url() call: quote and argument.
   */
  private const URL_WHOLE_RE = '/^url\(\s*(["\']?)(.*?)\1\s*\)$/is';

  /**
   * Every reference a style value can carry, in source order.
   *
   * Group 1 is a var() reference's custom property (with or without a
   * fallback after it), the whitespace around it consumed so canonical()
   * can respell the head; groups 2 and 3 a url() call's quote and
   * argument.
   */
  private const REFERENCE_RE = '/var\(\s*(--[A-Za-z0-9_-]+)\s*|url\(\s*(["\']?)(.*?)\2\s*\)/is';

  private const MEDIA_PREFIX = 'media:';
  private const GENERATE_PREFIX = 'generate:';
  private const FILE_PREFIX = 'file:';

  /**
   * Resolves a value in a slot to what it is.
   *
   * The whole value is the reference: a style value that is exactly one
   * var() resolves to the material spelled so (or Unknown); exactly one
   * url('media:…') / url('generate:…') / url('file:…') resolves to the
   * inline kind; any
   * other style value is a literal, even when references sit inside it
   * (references() lists those). A class token, an axis attribute value,
   * a stencil name and an animation name resolve to the variant, stencil
   * or animation spelled so, or Unknown: those slots hold references
   * only. An empty value is the empty literal in every slot.
   */
  public function resolve(string $value, string $slot, MaterialsRoster $roster): ValueReference {
    self::assertSlot($slot);
    $value = trim($value);
    if ($value === '') {
      return ValueReference::literal('');
    }
    if ($slot !== MaterialRecord::SLOT_STYLE) {
      return $this->reference($slot, $value, $value, $roster);
    }
    if (preg_match(self::VAR_WHOLE_RE, $value, $m)) {
      return $this->reference($slot, 'var(' . $m[1] . ')', $m[1], $roster);
    }
    if (preg_match(self::URL_WHOLE_RE, $value, $m)) {
      return self::urlReference($m[2]) ?? ValueReference::literal($value);
    }
    return ValueReference::literal($value);
  }

  /**
   * Every reference a value carries, in source order.
   *
   * A style value yields each var() reference (with or without a
   * fallback) and each media, generate or file url() inside it, so a
   * compound like "1px solid var(--swatch-x)" or "linear-gradient(…),
   * url('media:…')" is checked reference by reference; a plain url()
   * (a path or an external URL) is no reference and is not listed. A class list yields
   * one reference per whitespace-separated token. An attribute value, a
   * stencil name or an animation name yields its one reference. Literals
   * never appear here:
   * the list is what must resolve for the value to be legal.
   *
   * @return \Drupal\daedalus_blueprint\Materials\ValueReference[]
   *   The references, never literal.
   */
  public function references(string $value, string $slot, MaterialsRoster $roster): array {
    self::assertSlot($slot);
    if ($slot === MaterialRecord::SLOT_STYLE) {
      preg_match_all(self::REFERENCE_RE, $value, $matches, PREG_SET_ORDER);
      $references = [];
      foreach ($matches as $m) {
        if (($m[1] ?? '') !== '') {
          $references[] = $this->reference($slot, 'var(' . $m[1] . ')', $m[1], $roster);
        }
        elseif (($reference = self::urlReference($m[3] ?? '')) !== NULL) {
          $references[] = $reference;
        }
      }
      return $references;
    }
    $tokens = $slot === MaterialRecord::SLOT_CLASS
      ? preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY)
      : array_values(array_filter([trim($value)], static fn (string $s): bool => $s !== ''));
    return array_map(fn (string $token): ValueReference => $this->reference($slot, $token, $token, $roster), $tokens);
  }

  /**
   * A value with every reference rewritten to its canonical spelling.
   *
   * The one rewrite rule behind every parse and every print (the dialect
   * parser's media-token canonicalization and the client's token builders
   * were copies of it): in a style value each var() head is normalized
   * (whitespace dropped, a fallback left untouched) and each media,
   * generate or file url() is respelled single-quoted with a lowercased
   * uuid, a trimmed prompt or a trimmed name, whatever quoting it carried;
   * everything else in the value, a plain url() included, stays
   * byte-identical. A class list
   * canonicalizes to its tokens joined by one space; an attribute value,
   * a stencil name or an animation name to its trimmed self. Unknown
   * references keep their
   * spelling: what a value IS is settled here, whether it is acceptable
   * is the consumer's rule.
   */
  public function canonical(string $value, string $slot, MaterialsRoster $roster): string {
    self::assertSlot($slot);
    if ($slot !== MaterialRecord::SLOT_STYLE) {
      return implode(' ', array_map(static fn (ValueReference $ref): string => $ref->spelling, $this->references($value, $slot, $roster)));
    }
    return preg_replace_callback(self::REFERENCE_RE, static function (array $m): string {
      if (($m[1] ?? '') !== '') {
        return 'var(' . $m[1];
      }
      return self::urlReference($m[3] ?? '')?->spelling ?? $m[0];
    }, $value);
  }

  /**
   * How a material is written in a slot.
   *
   * The provider-contributed spelling, refused loudly when the material
   * has none there: a writer choosing a slot a material cannot occupy
   * (a swatch as a class, a block-axis variant on a page tag) is a
   * programming error, never a silent empty string.
   */
  public function spell(MaterialRecord $record, string $slot): string {
    self::assertSlot($slot);
    $spelling = $record->spelling($slot);
    if ($spelling === NULL) {
      throw new \InvalidArgumentException(sprintf('%s "%s" has no spelling in the %s slot; it spells in: %s.', ucfirst($record->kind->noun()), $record->id, $slot, implode(', ', array_keys($record->spellings)) ?: '(no slot)'));
    }
    return $spelling;
  }

  /**
   * The canonical spelling of a media reference: url('media:<uuid>').
   *
   * Single-quoted (the one form valid inside style="" attributes, <style>
   * blocks and JSON strings), lowercased uuid.
   */
  public static function spellMedia(string $uuid): string {
    return sprintf("url('media:%s')", strtolower(trim($uuid)));
  }

  /**
   * The canonical spelling of a generation request: url('generate:…').
   */
  public static function spellGenerate(string $prompt): string {
    return sprintf("url('generate:%s')", trim($prompt));
  }

  /**
   * The canonical spelling of a file reference: url('file:<name>').
   *
   * The name is the handle the conversation knows the file under (the
   * saved filename), kept case-exact: file aliases are exact-match.
   */
  public static function spellFile(string $name): string {
    return sprintf("url('file:%s')", trim($name));
  }

  /**
   * Whether a slot name is one the grammar resolves.
   */
  public static function isSlot(string $slot): bool {
    return in_array($slot, [
      MaterialRecord::SLOT_STYLE,
      MaterialRecord::SLOT_CLASS,
      MaterialRecord::SLOT_STENCIL,
      MaterialRecord::SLOT_ANIMATION,
    ], TRUE)
      || MaterialRecord::slotAttribute($slot) !== NULL;
  }

  /**
   * Resolves a spelling in a slot to its material, or to Unknown.
   */
  private function reference(string $slot, string $spelling, string $name, MaterialsRoster $roster): ValueReference {
    $record = $roster->bySpelling($slot, $spelling);
    if ($record === NULL) {
      return new ValueReference(ValueKind::Unknown, $name, $spelling);
    }
    return new ValueReference(ValueKind::ofMaterial($record->kind), $record->id, $spelling, $record);
  }

  /**
   * The inline reference a url() argument names, or NULL for any other.
   *
   * Reading is quote- and case-agnostic on the prefix (values stored
   * before the single-quote canon keep resolving); the spelling returned
   * is canonical. A generate request with an empty prompt (or a file
   * reference with an empty name) still resolves as that kind (an empty
   * id): what it IS is settled here, and whether it is acceptable is the
   * consumer's rule.
   */
  private static function urlReference(string $argument): ?ValueReference {
    $argument = trim($argument);
    $lower = strtolower($argument);
    if (str_starts_with($lower, self::MEDIA_PREFIX)) {
      $uuid = strtolower(trim(substr($argument, strlen(self::MEDIA_PREFIX))));
      return new ValueReference(ValueKind::Media, $uuid, self::spellMedia($uuid));
    }
    if (str_starts_with($lower, self::GENERATE_PREFIX)) {
      $prompt = trim(substr($argument, strlen(self::GENERATE_PREFIX)));
      return new ValueReference(ValueKind::Generate, $prompt, self::spellGenerate($prompt));
    }
    if (str_starts_with($lower, self::FILE_PREFIX)) {
      $name = trim(substr($argument, strlen(self::FILE_PREFIX)));
      return new ValueReference(ValueKind::File, $name, self::spellFile($name));
    }
    return NULL;
  }

  /**
   * Refuses a slot the grammar does not know.
   */
  private static function assertSlot(string $slot): void {
    if (!self::isSlot($slot)) {
      throw new \InvalidArgumentException(sprintf('Unknown value slot "%s"; one of %s, %s, %s, %s or %s{scope}:{name}.', $slot, MaterialRecord::SLOT_STYLE, MaterialRecord::SLOT_CLASS, MaterialRecord::SLOT_STENCIL, MaterialRecord::SLOT_ANIMATION, MaterialRecord::SLOT_ATTR_PREFIX));
    }
  }

}
