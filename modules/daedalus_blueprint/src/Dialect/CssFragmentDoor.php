<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The parse door: a CSS fragment read under one style key, writing nothing.
 *
 * The panel's paste is a gesture, never text that stays: a designer pastes
 * a nested block, a whole `#id { }` block from the AI's answer or a plain
 * declaration list into one element's or one rule's island, and the client
 * needs the flat (key, condition) entries the text means so it can emit
 * them as its own update_style batch (the element document) or stage them
 * (the variant document). This door answers that read through the ONE
 * parser the AI's style op uses (DialectCssParser::parseStyleBlock()), so
 * what the panel accepts and what the model may write is one grammar with
 * one set of correctives; the property grant stays the validator's at the
 * write, exactly as on the AI's path.
 *
 * The fragment is wrapped under its key as CSS nesting: `#id { css }` for an
 * element key, `#id { selector { css } }` for a rule key, so `&`, a leading
 * `:`, a relative selector and an `@wrapper` block all read as the sugar
 * the parser already flattens. Before wrapping, every rule-level `#<id>`
 * prelude (at the top of the text, or inside an at-rule wrapper there) is
 * re-rooted to `&`, so a block copied from another element ("Copy CSS"
 * prints `#<uuid>` rules, wrappers around the conditioned ones) pastes onto
 * the key it was pasted into instead of failing on a foreign id. Under a
 * rule key `&` is the rule, so `& > pb-text` continues it by descent, as
 * nesting means. Ownership answers (`known_ids`) come from the STAGED tree
 * by the derivation the style op uses (DialectCssParser::knownIds()).
 *
 * Errors keep their source line relative to the text that was sent: the
 * blanking and the re-rooting add no line, and the wrapper's opening brace
 * shares line 1 with the text's first line.
 */
final class CssFragmentDoor {

  /**
   * The id token a rule prelude may start with: `#` and the id's chars.
   *
   * The parser's selector grammar (DialectCssParser::SELECTOR_RE): the id
   * runs to the first space, state, combinator or class.
   */
  private const ID_TOKEN_RE = '/^#[^\s:>.+~]+/';

  /**
   * The style key grammar's root: the kind prefix and the element's uuid.
   */
  private const ROOT_RE = '/^(?<kind>container|block):(?<uuid>.+)$/';

  public function __construct(
    private readonly NodeTreeBuilder $treeBuilder,
    private readonly DialectCatalogBuilder $catalogBuilder,
  ) {}

  /**
   * Parses a CSS fragment under a style key into its flat entries.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host in the state the client edits (the staged working copy).
   * @param string $key
   *   The style key the text was pasted into: `container:<uuid>` or
   *   `block:<uuid>`, with `::` and a relative selector in its canonical
   *   spelling for a rule.
   * @param string $css
   *   The fragment.
   *
   * @return array{entries: list<array{key: string, condition: string, properties: array}>, wear: list<array{key: string, variant: string}>, errors: list<array>}
   *   The entries in source order (the condition canonical, the properties
   *   the parsed map), the variants a rule wears (`class:` and axis
   *   declarations in a rule's plain block), and the correctives as
   *   BlueprintError::toArray() with `context.line` the source line in the
   *   sent text. Under errors the entries are best-effort, as the parser's
   *   are: every rule and declaration that did parse.
   */
  public function parse(HostInterface $host, string $key, string $css): array {
    $split = RelativeSelector::splitKey($key);
    if ($split === NULL || !preg_match(self::ROOT_RE, $split[0], $m) || !DialectParser::isUuid($m['uuid'])) {
      return self::refused(new BlueprintError('key', 'BAD_KEY', sprintf('"%s" is not a style key.', $key), ['key' => $key], 'A style key is container:<uuid> or block:<uuid>, with "::" and a relative selector in its canonical spelling for a rule (container:<uuid>::> pb-text).'));
    }
    [, $selector] = $split;
    $uuid = $m['uuid'];
    $catalog = $this->catalogBuilder->build();
    $known_ids = DialectCssParser::knownIds($this->treeBuilder->build($host), $catalog);
    $known = $known_ids[$uuid] ?? NULL;
    if ($known === NULL) {
      return self::refused(new BlueprintError('key', 'ELEMENT_NOT_FOUND', sprintf('No element %s on this page.', $uuid), ['key' => $key], 'Keys name the elements of the page being edited; reload the page and paste again.'));
    }
    $kind = $known['container'] ? 'container' : 'block';
    if ($m['kind'] !== $kind) {
      return self::refused(new BlueprintError('key', 'BAD_KEY', sprintf('"%s" names a %s, but #%s is a %s.', $key, $m['kind'], $uuid, $kind), ['key' => $key], sprintf('The element\'s key is %s:%s.', $kind, $uuid)));
    }

    $text = self::reroot(DialectCssParser::blankComments($css));
    $wrapped = $selector === NULL
      ? sprintf('#%s { %s }', $uuid, $text)
      : sprintf('#%s { %s { %s } }', $uuid, $selector, $text);

    $errors = [];
    $tuples = (new DialectCssParser($catalog))->parseStyleBlock($wrapped, 1, $known_ids, $errors);
    $entries = [];
    $wear = [];
    foreach ($tuples as [$id, $local, $condition, $payload]) {
      if ($payload === []) {
        continue;
      }
      $entry_key = $kind . ':' . $id . $local;
      if ($condition === 'variants') {
        // The rule's wear (DialectCssParser::peelWear()), one reference
        // per apply_variant the client will emit.
        foreach ($payload as $reference) {
          $wear[] = ['key' => $entry_key, 'variant' => (string) $reference['variant']];
        }
        continue;
      }
      $entries[] = ['key' => $entry_key, 'condition' => $condition, 'properties' => $payload];
    }
    return [
      'entries' => $entries,
      'wear' => $wear,
      'errors' => array_map(static fn (DialectError $error): array => $error->toBlueprintError('css')->toArray(), $errors),
    ];
  }

  /**
   * Re-roots every rule-level `#id` prelude to `&`.
   *
   * Rule level is the top of the text and the inside of at-rule wrappers
   * there (a copied block prints its conditioned rules under wrappers); a
   * rule's own block is opaque, because a prelude nested inside a rule
   * composes under that rule by the parser's nesting rule. Only the id
   * token is replaced, so `#abc:hover` reads `&:hover`, `#abc > *` reads
   * `& > *`, `#abc::after` reads `&::after`; no newline is added or
   * removed, so error lines stay the sent text's. Comments are blanked
   * before this scan.
   *
   * @param string $css
   *   The fragment, comments blanked.
   *
   * @return string
   *   The fragment with its rule-level ids re-rooted.
   */
  private static function reroot(string $css): string {
    $pos = 0;
    while (($stop = $pos + strcspn($css, ';{}', $pos)) < strlen($css)) {
      if ($css[$stop] !== '{') {
        // A declaration's end or a wrapper's close: rule level continues.
        $pos = $stop + 1;
        continue;
      }
      $start = $pos + strspn($css, " \t\r\n", $pos);
      $prelude = substr($css, $start, $stop - $start);
      if (str_starts_with($prelude, '@')) {
        // A wrapper: the rules inside it are rule level too.
        $pos = $stop + 1;
        continue;
      }
      if (preg_match(self::ID_TOKEN_RE, $prelude, $m)) {
        $css = substr_replace($css, RelativeSelector::ANCHOR, $start, strlen($m[0]));
        $stop -= strlen($m[0]) - strlen(RelativeSelector::ANCHOR);
      }
      $close = DialectCssParser::matchingBrace($css, $stop, strlen($css));
      if ($close === -1) {
        // Unclosed: the parser reports it at its line.
        break;
      }
      $pos = $close + 1;
    }
    return $css;
  }

  /**
   * The answer when the key itself is refused: nothing parsed.
   */
  private static function refused(BlueprintError $error): array {
    return ['entries' => [], 'wear' => [], 'errors' => [$error->toArray()]];
  }

}
