<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Selector;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * The rule move: what rekeying a rule's selector does to its family.
 *
 * The one transformation behind "edit this rule's selector", defined
 * over an owner's RELATIVE keys (the selectors after the `::` of its
 * style map entries; the owner's root entry has none and is not one):
 * given `from` (the rule's selector) and `to` (the selector it becomes),
 * the plan maps `from` to `to` and every key that continues `from`
 * (RelativeSelector::continues(): `> d-text .link`, `> d-text > *`,
 * `> d-text::before` continue `> d-text`; `> d-text:has(a)` and
 * `> d-textual` do not) to `to` continued by the same suffix
 * (RelativeSelector::under(): `> d-text .link` follows `> d-text` to
 * `> d-image` as `> d-image .link`, `> d-text::before` as
 * `> d-image::before`). The rule's own key heads the mapping whether or
 * not the list carries it (an absent rule moves nothing of its own; its
 * continuations still follow, and the caller reads presence), then the
 * continuations in list order.
 *
 * Three refusals, each naming the first offending key in that walk:
 * - `to` is outside the grammar (REFUSED_GRAMMAR, the `to` text);
 * - a continuation cannot follow because the grammar refuses the
 *   rebase (REFUSED_CONTINUATION, the key that cannot follow): a `to`
 *   ending in a pseudo-element carries nothing behind its box, a tag
 *   `to` carries no part class (`p .link`);
 * - a target key is already present in the list (REFUSED_TARGET_EXISTS,
 *   the present key): merging would silently pick winners, so the rule's
 *   own landing and every family member's refuse alike, and an unchanged
 *   selector refuses as its own collision (the chrome's no-op gate stands
 *   before this).
 *
 * `from` outside the grammar is a caller bug (a stored key is canonical
 * by the validator), thrown as the grammar's own exception; `to` is read
 * leniently and lands canonical, since the typing surface is the door
 * that canonicalizes. The host map's `move_style` op and the documents'
 * staged move both apply this plan; the client twin is js/src/rule-move.js,
 * locked to this class by RuleMoveTest through the shared battery in
 * tests/js/rule-move-cases.mjs.
 */
final class RuleMove {

  /**
   * The refusal reasons.
   */
  public const REFUSED_GRAMMAR = 'grammar';
  public const REFUSED_CONTINUATION = 'continuation';
  public const REFUSED_TARGET_EXISTS = 'target_exists';

  /**
   * Constructs a plan; use plan().
   *
   * @param array<string, string>|null $moves
   *   Source key to target key, the rule's own first, or NULL when
   *   refused.
   * @param array{reason: string, key: string}|null $refusal
   *   The refusal (one of the REFUSED_* reasons and the offending key),
   *   or NULL when the move is planned.
   */
  private function __construct(
    public readonly ?array $moves,
    public readonly ?array $refusal,
  ) {}

  /**
   * Plans the move of a rule and its family over an owner's keys.
   *
   * @param string[] $keys
   *   The owner's relative keys (canonical selectors). A key outside the
   *   grammar continues nothing.
   * @param string $from
   *   The rule's selector.
   * @param string $to
   *   The selector it becomes, in any spelling the grammar reads.
   *
   * @throws \InvalidArgumentException
   *   When `from` is not a relative selector.
   */
  public static function plan(array $keys, string $from, string $to): self {
    $rule = RelativeSelector::fromString($from);
    $target = RelativeSelector::parse($to);
    if ($target === NULL) {
      return self::refuse(self::REFUSED_GRAMMAR, $to);
    }
    $present = array_fill_keys(array_map(strval(...), $keys), TRUE);
    // The walk refuses in order: the rule's own landing first, then each
    // continuation as the list reads, so the first offending key is the
    // one named.
    $landing = (string) $target;
    if (isset($present[$landing])) {
      return self::refuse(self::REFUSED_TARGET_EXISTS, $landing);
    }
    $moves = [(string) $rule => $landing];
    foreach ($keys as $key) {
      $key = (string) $key;
      $suffix = self::suffixUnder($rule, $key);
      if ($suffix === NULL) {
        continue;
      }
      $continued = RelativeSelector::under($target, $suffix);
      if ($continued === NULL) {
        return self::refuse(self::REFUSED_CONTINUATION, $key);
      }
      $landing = (string) $continued;
      if (isset($present[$landing])) {
        return self::refuse(self::REFUSED_TARGET_EXISTS, $landing);
      }
      $moves[$key] = $landing;
    }
    return new self($moves, NULL);
  }

  /**
   * Whether the move was refused.
   */
  public function refused(): bool {
    return $this->refusal !== NULL;
  }

  /**
   * The plan as data: {moves, refusal}, the shape the client twin answers.
   *
   * @return array{moves: array<string, string>|null, refusal: array{reason: string, key: string}|null}
   *   The plan.
   */
  public function toArray(): array {
    return ['moves' => $this->moves, 'refusal' => $this->refusal];
  }

  /**
   * The suffix a key continues a rule by, or NULL when it does not.
   *
   * The inverse of continues(): the key spells `{rule} {suffix}` (a
   * chain continued by descent) or `{rule}::{pseudo}` (the rule's own
   * box, the suffix `&::{pseudo}`), and under() must spell the key again.
   */
  private static function suffixUnder(RelativeSelector $rule, string $key): ?RelativeSelector {
    $candidate = RelativeSelector::parse($key);
    if ($candidate === NULL || !RelativeSelector::continues($rule, $candidate)) {
      return NULL;
    }
    $prefix = (string) $rule;
    $text = (string) $candidate;
    return str_starts_with($text, $prefix . ' ')
      ? RelativeSelector::fromString(substr($text, strlen($prefix) + 1))
      : RelativeSelector::fromString(RelativeSelector::ANCHOR . substr($text, strlen($prefix)));
  }

  /**
   * A refused plan.
   */
  private static function refuse(string $reason, string $key): self {
    return new self(NULL, ['reason' => $reason, 'key' => $key]);
  }

}
