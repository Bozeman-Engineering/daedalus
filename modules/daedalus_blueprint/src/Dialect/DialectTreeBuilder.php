<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Masterminds\HTML5\Elements;
use Masterminds\HTML5\Parser\EventHandler;
use Masterminds\HTML5\Parser\Scanner;

/**
 * HTML5 tokenizer event handler building the raw dialect parse tree.
 *
 * The dialect is parsed with an HTML5 tokenizer (Masterminds, core's own),
 * never XML: models write bare '&' and unescaped punctuation constantly,
 * and XML dies loud on the most common token. Loudness is recovered here
 * and in the walk: unclosed elements and mismatched end tags are reported
 * with line numbers instead of silently swallowing siblings the way a
 * spec tree builder's absorption would.
 *
 * Deliberate deltas from the HTML5 tree-construction algorithm, per spec
 * v0.2 section 2:
 * - The childless kinds (d-image, d-blueprint) are void: they never open,
 *   so the ignored-self-closing-slash quirk cannot make them adopt their
 *   siblings (the spec's self-closing normalization, implemented at the
 *   builder where the slash is still visible).
 * - A self-closing slash closes ANY dialect element: authors who write
 *   <d-teaser ... /> mean a closed element, and nothing in the dialect
 *   needs the browser's contrary reading.
 * - Rich bodies (every tag the catalog declares with one,
 *   DialectCatalog::richTags()), generic field bodies (d-field), file
 *   declarations (d-file) and <style> are RAWTEXT: their content is
 *   captured verbatim (opaque to the dialect; rich text is governed by the
 *   text format filter downstream, a declared file's bytes by the file
 *   lane). The printer decides which bodies print raw from the same
 *   catalog, so the two agree for every block type.
 * - Nesting has a ceiling (DialectParser::MAX_DEPTH): the first element
 *   past it stops the build, since each level is a level of recursion for
 *   every later walk of the tree and a longer scan for every end tag.
 * - Tokenizer-level parse errors (character reference recovery and
 *   friends) are not surfaced: HTML5 recovery is the point of choosing
 *   this parser. Structure is judged on the built tree.
 */
final class DialectTreeBuilder implements EventHandler {

  /**
   * The dialect's own tags whose bodies are captured verbatim (RAWTEXT).
   *
   * The rich block types' tags join them per catalog ($rawTextTags).
   */
  private const STRUCTURAL_RAW_TEXT_TAGS = [
    DialectWalk::FIELD_TAG,
    DialectParser::FILE_TAG,
    'style',
  ];

  /**
   * Dialect tags that never take content and parse as void.
   */
  private const VOID_DIALECT_TAGS = [RelativeSelector::TYPE_PREFIX . 'image', DialectWalk::BLUEPRINT_TAG];

  /**
   * The synthetic root element the parsed tree hangs from.
   */
  public RawElement $root;

  /**
   * The structural errors found while building.
   *
   * @var \Drupal\daedalus_blueprint\Dialect\DialectError[]
   */
  public array $errors = [];

  /**
   * The open-element stack; index 0 is the synthetic root.
   *
   * @var \Drupal\daedalus_blueprint\Dialect\RawElement[]
   */
  private array $stack;

  /**
   * The source line of the first element nested past the ceiling, if any.
   *
   * Such an element is not built and neither is anything after it; the
   * parser answers the one error and no tree (DialectParser::tokenize()).
   */
  public ?int $tooDeepAt = NULL;

  /**
   * Every tag whose body is captured verbatim, as tag => TRUE.
   *
   * @var array<string, true>
   */
  private readonly array $rawTextTags;

  /**
   * Builds over a scanner and the catalog's rich tags.
   *
   * @param \Masterminds\HTML5\Parser\Scanner $scanner
   *   The scanner the tokenizer reads, asked for the current line.
   * @param string[] $rich_tags
   *   The tags the catalog declares with a rich body
   *   (DialectCatalog::richTags()).
   */
  public function __construct(
    private readonly Scanner $scanner,
    array $rich_tags,
  ) {
    $this->rawTextTags = array_fill_keys([...self::STRUCTURAL_RAW_TEXT_TAGS, ...$rich_tags], TRUE);
    $this->root = new RawElement('#root', [], 0);
    $this->stack = [$this->root];
  }

  /**
   * {@inheritdoc}
   *
   * @return int
   *   The text mode for the element's content (TEXT_RAW for opaque
   *   bodies), consumed by the tokenizer.
   */
  public function startTag($name, $attributes = [], $selfClosing = FALSE) {
    // The stack holds the root and every open element, so its count is
    // the depth this element would sit at.
    if ($this->tooDeepAt !== NULL || count($this->stack) > DialectParser::MAX_DEPTH) {
      $this->tooDeepAt ??= $this->scanner->currentLine();
      return 0;
    }
    $node = new RawElement($name, $attributes, $this->scanner->currentLine());
    $node->parent = end($this->stack);
    $node->parent->children[] = $node;

    $void = in_array($name, self::VOID_DIALECT_TAGS, TRUE)
      || Elements::isA($name, Elements::VOID_TAG);
    if (!$void && !$selfClosing) {
      $this->stack[] = $node;
      if (isset($this->rawTextTags[$name])) {
        return Elements::TEXT_RAW;
      }
    }
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function endTag($name) {
    if ($this->tooDeepAt !== NULL) {
      return;
    }
    for ($i = count($this->stack) - 1; $i >= 1; $i--) {
      if ($this->stack[$i]->tag === $name) {
        foreach (array_slice($this->stack, $i + 1) as $swallowed) {
          $this->errors[] = new DialectError(
            'UNCLOSED_ELEMENT',
            sprintf('<%s> opened on line %d was never closed (implicitly closed by </%s>); dialect elements must be explicitly closed', $swallowed->tag, $swallowed->line, $name),
            $swallowed->line,
          );
        }
        $this->stack = array_slice($this->stack, 0, $i);
        return;
      }
    }
    // An end tag for a void or self-closed element is tolerated silently
    // only for HTML void tags (sugar like <img> already errors in the
    // walk); anything else matches no open element.
    if (!Elements::isA($name, Elements::VOID_TAG) && !in_array($name, self::VOID_DIALECT_TAGS, TRUE)) {
      $this->errors[] = new DialectError(
        'MISMATCHED_END',
        sprintf('</%s> matches no open element', $name),
        $this->scanner->currentLine(),
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function text($cdata) {
    if ($this->tooDeepAt !== NULL) {
      return;
    }
    $current = end($this->stack);
    $current->children[] = ['#text' => $cdata, '#line' => $this->scanner->currentLine()];
  }

  /**
   * {@inheritdoc}
   */
  public function eof() {
    if ($this->tooDeepAt !== NULL) {
      return;
    }
    foreach (array_slice($this->stack, 1) as $open) {
      $this->errors[] = new DialectError(
        'UNCLOSED_ELEMENT',
        sprintf('<%s> opened on line %d is never closed; an unclosed element swallows its siblings', $open->tag, $open->line),
        $open->line,
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function parseError($msg, $line, $col) {
    // HTML5 recovery (bare '&', stray '<') is deliberate tolerance.
  }

  /**
   * {@inheritdoc}
   */
  public function doctype($name, $idType = 0, $id = NULL, $quirks = FALSE) {
    // A doctype carries no dialect structure; ignored.
  }

  /**
   * {@inheritdoc}
   */
  public function comment($cdata) {
    // Comments carry no dialect structure; dropped from the tree.
  }

  /**
   * {@inheritdoc}
   */
  public function cdata($data) {
    // CDATA is an XML holdover; ignored.
  }

  /**
   * {@inheritdoc}
   */
  public function processingInstruction($name, $data = NULL) {
    // Processing instructions carry no dialect structure; ignored.
  }

}
