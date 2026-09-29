<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Masterminds\HTML5\Parser\Scanner;
use Masterminds\HTML5\Parser\Tokenizer;

/**
 * Parses dialect documents and fragments into the lean node tree.
 *
 * The dialect (spec v0.2) is THE model-facing serialization: the print of
 * the lean node tree, parsed back to the same tree, so the spine adds no
 * second validation path; what parses cleanly hits the same validators
 * every other consumer hits. This parser owns the STRUCTURAL grammar:
 * tags (pb- prefixed, sugar and unprefixed spellings get correctives),
 * attributes (fields under short names via the catalog's contracts, axes,
 * class variants), bodies (plain text collapsed, rich text opaque and
 * routed through the text format filter downstream), ids, containment,
 * and the <style> block's selector/wrapper/value grammar (shared with the
 * style op via DialectCssParser). Errors carry line numbers.
 *
 * Identity: an id that is a printed RFC 4122 uuid lands on the node's
 * `uuid` key; any other legal id is an author-chosen alias and lands on
 * `id` (the op layer mints uuids and persists aliases).
 */
class DialectParser {

  /**
   * Legal ids: an author alias or a printed RFC 4122 uuid.
   */
  public const ID_RE = '/^(?:[A-Za-z][A-Za-z0-9_-]*|[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12})$/';

  /**
   * Uuid-shaped ids (reads print uuids as ids).
   */
  private const UUID_RE = '/^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$/';

  /**
   * Server-derived residue: never printed as attributes, never authored.
   */
  private const RESIDUE_ATTRS = [
    'info', 'label-display', 'label_display', 'view-mode', 'view_mode',
    'uuid', 'block-revision-id', 'section-index', 'region',
  ];

  /**
   * HTML spellings at element position, each corrective naming the form.
   */
  private const SUGAR = [
    'div' => 'use <pb-container> for grouping (a container renders as one element)',
    'section' => 'use <pb-container>; top-level containers render as page bands',
    'h1' => 'use <pb-text tag="h1">',
    'h2' => 'use <pb-text tag="h2">',
    'h3' => 'use <pb-text tag="h3">',
    'h4' => 'use <pb-text tag="h4">',
    'h5' => 'use <pb-text tag="h5">',
    'h6' => 'use <pb-text tag="h6">',
    'p' => 'use <pb-text> for a single line or <pb-formatted-text> for rich copy',
    'span' => 'use <pb-text tag="span">',
    'a' => 'use <pb-text url="...">',
    'button' => 'use <pb-text url="...">',
    'ul' => 'use <pb-formatted-text> (lists are rich text)',
    'ol' => 'use <pb-formatted-text> (lists are rich text)',
    'li' => 'use <pb-formatted-text> (lists are rich text)',
    'header' => 'use <pb-container>',
    'footer' => 'use <pb-container>',
    'article' => 'use <pb-container>',
    'aside' => 'use <pb-container>',
    'main' => 'use <pb-container>',
    'nav' => 'use <pb-container>',
    'table' => 'use <pb-formatted-text> if the format allows tables',
    'figure' => 'use <pb-container> with a <pb-image> and a <pb-text> caption',
    'img' => 'use <pb-image>; reference existing media with media="id or label", mint one with generate="prompt" + alt, or place a file this conversation holds with file="name" + alt (src is not writable)',
    'image' => 'use <pb-image>; reference existing media with media="id or label", mint one with generate="prompt" + alt, or place a file this conversation holds with file="name" + alt (src is not writable)',
  ];

  /**
   * Unprefixed tag spellings; correctives name the pb- form.
   */
  private const UNPREFIXED = [
    'container' => 'pb-container',
    'text' => 'pb-text',
    'formatted-text' => 'pb-formatted-text',
    'teaser' => 'pb-teaser',
    'block' => 'pb-block',
    'field' => 'pb-field',
    'stencil' => 'pb-stencil',
  ];

  /**
   * The file declaration's tag and its two attributes.
   */
  public const FILE_TAG = 'pb-file';
  private const FILE_ATTRS = ['name', 'type'];

  /**
   * Parses a full page document: top-level containers plus <style> blocks.
   *
   * The top level holds <pb-container> elements and <pb-stencil>
   * references (a placement lands as its root; the same set the insert
   * door admits at the page's root anchor); blocks belong inside a
   * container. Beside them, write-only: <style> blocks and <pb-file
   * name type> declarations (bytes the author wrote, carried on the
   * result's files for the op layer to save before the tree lands; they
   * never print, since after commit a declared file is media).
   */
  public function parseDocument(string $html, DialectCatalog $catalog): DialectParseResult {
    $walk = new DialectWalk($catalog);
    $root = $this->tokenize($html, $walk->errors);

    $tree = [];
    $style_elements = [];
    $files = [];
    foreach ($root->children as $child) {
      if (!$child instanceof RawElement) {
        if (trim($child['#text']) !== '') {
          $walk->errors[] = new DialectError('STRAY_TEXT', sprintf('bare text "%s" at top level: wrap copy in a <pb-container>', self::excerpt($child['#text'])), $child['#line']);
        }
        continue;
      }
      if ($child->tag === 'style') {
        $style_elements[] = $child;
        continue;
      }
      if ($child->tag === self::FILE_TAG) {
        $declaration = self::fileDeclaration($child, $walk->errors);
        if ($declaration !== NULL) {
          $files[] = $declaration;
        }
        continue;
      }
      if (!in_array($child->tag, ['pb-container', 'pb-stencil'], TRUE) && !isset(self::SUGAR[$child->tag]) && !isset(self::UNPREFIXED[$child->tag])) {
        if ($catalog->blockType((string) DialectCatalog::blockTypeForTag($child->tag)) !== NULL || in_array($child->tag, [
          'pb-block',
          'pb-field',
        ], TRUE)) {
          $walk->errors[] = new DialectError('ILLEGAL_CONTAINMENT', sprintf('top level of a page holds <pb-container> elements; <%s> belongs inside one', $child->tag), $child->line);
        }
      }
      $node = $walk->element($child, 0);
      if ($node !== NULL) {
        $tree[] = $node;
      }
    }

    // The known ids the <style> block's selectors judge against: derived
    // from the parsed tree by the ONE derivation the style op uses too
    // (DialectCssParser::knownId()), so ownership answers the same at both
    // doors.
    $css_parser = new DialectCssParser($catalog);
    $known_ids = DialectCssParser::knownIds($tree, $catalog);
    $styles_by_id = [];
    foreach ($style_elements as $style_element) {
      $assignments = $css_parser->parseStyleBlock($style_element->text(), $style_element->line, $known_ids, $walk->errors);
      foreach ($assignments as [$id, $local, $condition, $properties]) {
        $styles_by_id[$id][$local][$condition] = array_merge($styles_by_id[$id][$local][$condition] ?? [], $properties);
      }
    }
    if ($styles_by_id !== []) {
      self::injectStyles($tree, $styles_by_id);
    }

    return new DialectParseResult($tree, $walk->errors, $files);
  }

  /**
   * Reads one <pb-file> declaration's grammar: name, type, a raw body.
   *
   * The body is the RAWTEXT the tree builder captured (an SVG's own
   * markup, a stylesheet, CSV rows), trimmed at both ends; what the bytes
   * ARE (the type against the name, the sanitizer's verdict, the site's
   * accepted extensions) is the file lane's judgement at the op layer,
   * never the parser's.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\RawElement $el
   *   The declaration element.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector for the declaration's grammar errors.
   *
   * @return array{name: string, type: string, content: string, line: int}|null
   *   The declaration, or NULL when its grammar failed.
   */
  private static function fileDeclaration(RawElement $el, array &$errors): ?array {
    $valid = TRUE;
    foreach (array_keys($el->attributes) as $attr) {
      if (!in_array($attr, self::FILE_ATTRS, TRUE)) {
        $errors[] = new DialectError(
          'ILLEGAL_ATTR',
          sprintf('<%s> takes name and type only; "%s" is not an attribute of a file declaration', self::FILE_TAG, (string) $attr),
          $el->line,
          ['attribute' => (string) $attr, 'legal' => self::FILE_ATTRS],
          'A file declaration is <pb-file name="logo.svg" type="image/svg+xml">the bytes</pb-file>; what places the file is the element (file="logo.svg") or the CSS (url(\'file:logo.svg\')).',
        );
        $valid = FALSE;
      }
    }
    $name = trim((string) $el->attr('name'));
    $type = trim((string) $el->attr('type'));
    $content = trim($el->text());
    if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
      $errors[] = new DialectError('FILE_DECLARATION', sprintf('<%s> needs name="<filename>" (a bare filename with its extension, the handle later writes address it by)', self::FILE_TAG), $el->line, ['name' => $name], 'Write <pb-file name="logo.svg" type="image/svg+xml">...</pb-file>; the name is what file="" and url(\'file:...\') refer to.');
      $valid = FALSE;
    }
    if ($type === '') {
      $errors[] = new DialectError('FILE_DECLARATION', sprintf('<%s> needs type="<MIME type>" (image/svg+xml, text/css, text/csv, text/plain, text/markdown, application/json, text/html)', self::FILE_TAG), $el->line, ['name' => $name], 'The type must agree with the name\'s extension: name="logo.svg" is type="image/svg+xml".');
      $valid = FALSE;
    }
    if ($content === '') {
      $errors[] = new DialectError('FILE_DECLARATION', sprintf('<%s name="%s"> has an empty body; a declaration carries the file\'s bytes between its tags', self::FILE_TAG, $name), $el->line, ['name' => $name], 'Write the file\'s content (the SVG markup, the stylesheet, the rows) as the body of the declaration.');
      $valid = FALSE;
    }
    if (!$valid) {
      return NULL;
    }
    return ['name' => $name, 'type' => $type, 'content' => $content, 'line' => $el->line];
  }

  /**
   * Parses an op-payload fragment: elements legal at an insertion point.
   *
   * @param string $html
   *   The fragment's dialect markup.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog to parse against.
   * @param bool $top_level
   *   TRUE when the payload lands at the page's top level (inserted
   *   beforebegin/afterend of a top-level container, or replacing one), so
   *   top-level-only axes are legal on its root containers.
   */
  public function parseFragment(string $html, DialectCatalog $catalog, bool $top_level = FALSE): DialectParseResult {
    $walk = new DialectWalk($catalog);
    $root = $this->tokenize($html, $walk->errors);

    $tree = [];
    foreach ($root->children as $child) {
      if (!$child instanceof RawElement) {
        if (trim($child['#text']) !== '') {
          $walk->errors[] = new DialectError('STRAY_TEXT', sprintf('bare text "%s" at fragment top level: wrap copy in <pb-text> or <pb-formatted-text>', self::excerpt($child['#text'])), $child['#line']);
        }
        continue;
      }
      if ($child->tag === 'style') {
        $walk->errors[] = new DialectError('STYLE_IN_FRAGMENT', '<style> is not legal in an op payload; use the style op or the elements\' own style="" attributes', $child->line);
        continue;
      }
      if ($child->tag === self::FILE_TAG) {
        $walk->errors[] = new DialectError('FILE_IN_FRAGMENT', sprintf('<%s> is not legal in an op payload; declare the file with the file op ({op: "file", name, type, content}) ahead of the op that places it', self::FILE_TAG), $child->line, [], 'A <pb-file> declaration belongs at the top level of a page_create document; in a page_edit batch the file op carries the same name, type and content.');
        continue;
      }
      $node = $walk->element($child, $top_level ? 0 : 1);
      if ($node !== NULL) {
        $tree[] = $node;
      }
    }

    return new DialectParseResult($tree, $walk->errors);
  }

  /**
   * Runs the HTML5 tokenizer into a raw parse tree.
   *
   * @param string $html
   *   The dialect markup.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectError[] $errors
   *   Collector for tree-level structural errors.
   */
  private function tokenize(string $html, array &$errors): RawElement {
    $scanner = new Scanner($html);
    $builder = new DialectTreeBuilder($scanner);
    $tokenizer = new Tokenizer($scanner, $builder);
    $tokenizer->parse();
    foreach ($builder->errors as $error) {
      $errors[] = $error;
    }
    return $builder->root;
  }

  /**
   * Whether an id is uuid-shaped (a printed identity, not an alias).
   */
  public static function isUuid(string $id): bool {
    return (bool) preg_match(self::UUID_RE, $id);
  }

  /**
   * The sugar corrective for a tag, or NULL.
   *
   * The link spellings (<a>, <button>) close with the site's own
   * call-to-action hint when the catalog offers one: the text element's
   * button axis (its first value) or a class on its roster named
   * "button"; a site offering neither gets the bare form, never a
   * spelling the roster lacks.
   */
  public static function sugarCorrective(string $tag, DialectCatalog $catalog): ?string {
    $corrective = self::SUGAR[$tag] ?? NULL;
    if ($corrective === NULL || !in_array($tag, ['a', 'button'], TRUE)) {
      return $corrective;
    }
    $hint = self::callToActionHint($catalog);
    if ($hint === NULL) {
      return $corrective;
    }
    return $tag === 'a'
      ? sprintf('%s; add %s for a call-to-action', $corrective, $hint)
      : sprintf('use <pb-text url="..." %s>', $hint);
  }

  /**
   * The call-to-action spelling the text element offers, or NULL.
   *
   * @return string|null
   *   An attribute spelling (button="primary", class="button"), NULL
   *   when the site has no text type, or its rosters offer neither.
   */
  private static function callToActionHint(DialectCatalog $catalog): ?string {
    $type = DialectCatalog::blockTypeForTag('pb-text');
    if ($type === NULL || $catalog->blockType($type) === NULL) {
      return NULL;
    }
    $axes = $catalog->axesFor(FALSE, $type, NULL);
    if (isset($axes['button']) && $axes['button']['values'] !== []) {
      return sprintf('button="%s"', array_key_first($axes['button']['values']));
    }
    if (isset($catalog->classRosterFor(FALSE, $type, NULL)['button'])) {
      return 'class="button"';
    }
    return NULL;
  }

  /**
   * The pb- spelling an unprefixed tag names, or NULL.
   */
  public static function unprefixedTarget(string $tag): ?string {
    return self::UNPREFIXED[$tag] ?? NULL;
  }

  /**
   * The server-derived attribute names.
   */
  public static function residueAttrs(): array {
    return self::RESIDUE_ATTRS;
  }

  /**
   * A short excerpt of stray text for error messages.
   */
  public static function excerpt(string $text): string {
    return mb_substr(trim($text), 0, 40);
  }

  /**
   * Distributes <style>-block entries onto the nodes their ids address.
   */
  private static function injectStyles(array &$nodes, array $styles_by_id): void {
    foreach ($nodes as &$node) {
      if (!is_array($node)) {
        continue;
      }
      $identity = $node['uuid'] ?? $node['id'] ?? NULL;
      if ($identity !== NULL && isset($styles_by_id[$identity])) {
        foreach ($styles_by_id[$identity] as $local => $conditions) {
          foreach ($conditions as $condition => $properties) {
            $node['styles'][$local][$condition] = array_merge($node['styles'][$local][$condition] ?? [], $properties);
          }
        }
      }
      if (isset($node['children'])) {
        self::injectStyles($node['children'], $styles_by_id);
      }
    }
  }

}
