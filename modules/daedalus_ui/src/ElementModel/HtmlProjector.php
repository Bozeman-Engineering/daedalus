<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\Markup\MarkupProjectorInterface;

/**
 * Prints the annotated blueprint as key-annotated HTML — the print format.
 *
 * The canonical instance model is the `_element.markup` annotation the
 * BlueprintElementAnnotator stamps on delivered blueprints: the element
 * resolver's concrete tree for each owning node's data. This projector is
 * a PRINTER of that one model for the AI read boundary — the same trees
 * the client mirror consumes, spelled as HTML because markup competence
 * in LLM training is inseparable from markup: delivered structure turns
 * memorized prose rules into visible elements. Never a scrape of rendered
 * output, never a second model; it plugs into daedalus_blueprint's
 * markup-projection socket (the `daedalus_blueprint.markup_projector` tag),
 * so the spine that the AI tools read through stays ignorant of this
 * module.
 *
 * Print-format conventions (kept literal and concrete — the readers are
 * small models):
 * - Every keyed box carries `data-key` with its selectable element key
 *   (entity:{uuid}, container:{uuid}, block:{uuid}) and `data-type` with
 *   its type fact (the bundle, the layout id, the plugin id). Inner part
 *   elements wear their part class and no key: the part's address
 *   composes as {key}::.class-name, and instance elements as
 *   {key}::.s-xxx.
 * - A keyed box whose tag the grammar leaves undeclared (the entity
 *   render's wrapper sandwich, a section's outer band — bespoke markup
 *   the model honestly does not describe) prints as <div>: the print
 *   format needs a printable element for the box its key addresses, and
 *   every shipped unmodeled wrapper renders as a div. The JSON model
 *   keeps the honest NULL; the stand-in is a fact of the print format
 *   only, recorded here.
 * - Content prints where the mounting rule puts it: a text value mounts
 *   in the part whose slot claims its field token, at the box root when
 *   unclaimed; minted rich-text instances print as real elements wearing
 *   their identity classes. Text is excerpted (120 chars), never the
 *   whole document — markup mode shows structure; the data reads own the
 *   values.
 * - The root sections are content of the entity box under the entity
 *   kind's `sections` token: they print inside the part claiming it
 *   (daedalus_layout's layout root, `div.daedalus-layout-layout`) and directly inside
 *   the entity box when no part does, exactly where the render mounts
 *   them and where the markup view's sketch draws them.
 * - Components the model cannot back print as HTML comments naming their
 *   key: a plugin block's bespoke markup is described nowhere, and an
 *   inline block whose entity did not resolve has no data to claim, so
 *   neither gets invented elements.
 */
final class HtmlProjector implements MarkupProjectorInterface {

  /**
   * Content-excerpt cap, matching the annotator's instance excerpts.
   */
  private const TEXT_CAP = 120;

  /**
   * Tags that self-close: no closing tag, no interior content.
   */
  private const VOID_TAGS = [
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
    'meta', 'source', 'track', 'wbr',
  ];

  /**
   * The view mode the annotation resolves existence facts against.
   *
   * The AI read boundary has no rendering view mode of its own; 'default'
   * is the display every bundle has and the mode the audited AI turns
   * report. Only root-field existence facts (comment lines) depend on it
   * — the markup trees themselves are view-mode independent.
   */
  private const VIEW_MODE = 'default';

  public function __construct(
    private readonly BlueprintBackendResolver $backendResolver,
    private readonly BlueprintElementAnnotator $annotator,
  ) {}

  /**
   * Prints the entity's page: the root box, its parts, fields and sections.
   *
   * The root's field comments carry the private `__field_comment` token
   * (no part can claim them, so they always print at the entity box) and
   * the root sections carry the entity kind's `sections` slot token: they
   * mount inside the part whose `contains:` claims it (daedalus_layout's layout
   * root, `div.daedalus-layout-layout`, once daedalus_layout is installed), else directly
   * inside the entity box, the same mounting rule the markup view's root
   * composition follows for the same declaration.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   * @param string|null $langcode
   *   The translation to print, or NULL for the entity's own language.
   *
   * @return string|null
   *   The page's printed lines, or NULL when no blueprint backend serves
   *   the entity type.
   */
  public function projectEntity(FieldableEntityInterface $entity, ?string $langcode = NULL): ?string {
    $blueprint = $this->annotatedBlueprint($entity, $langcode);
    if ($blueprint === NULL) {
      return NULL;
    }
    $items = [];
    foreach ($this->rootFieldComments($blueprint, $entity) as $comment) {
      $items[] = ['token' => '__field_comment', 'lines' => [$comment]];
    }
    foreach ($blueprint['layout_builder__layout'] ?? [] as $section) {
      $items[] = ['token' => 'sections', 'lines' => $this->sectionLines($section, 0)];
    }
    $markup = $blueprint['_element']['markup'] ?? $this->emptyMarkup();
    return implode("\n", $this->boxLines(
      $markup,
      'entity:' . $entity->uuid(),
      $entity->getEntityTypeId() . '/' . $entity->bundle(),
      $items,
      0,
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function projectElement(FieldableEntityInterface $entity, string $uuid, ?string $langcode = NULL): ?string {
    $blueprint = $this->annotatedBlueprint($entity, $langcode);
    if ($blueprint === NULL) {
      return NULL;
    }
    $lines = $this->findElement($blueprint['layout_builder__layout'] ?? [], $uuid);
    return $lines === NULL ? NULL : implode("\n", $lines);
  }

  /**
   * Prints a composed block type's schema example.
   *
   * Same print format as a live component box, with one example-only
   * convention: the key placeholder is the literal `block:UUID`, since a schema
   * example addresses no real component, and every live print of the same
   * shape shows where the real uuid lands.
   *
   * @param array $markup
   *   The resolver's tree for the sample entity's data.
   * @param string $block_type
   *   The block type (printed as data-type).
   * @param array<string, string> $texts
   *   Sample text content by field name; each mounts at its `field:NAME`
   *   token per the mounting rule (claimed part, else the box root),
   *   excerpted like live content.
   *
   * @return string
   *   The example HTML.
   */
  public function projectBlockTypeExample(array $markup, string $block_type, array $texts = []): string {
    $items = [];
    foreach ($texts as $name => $value) {
      $items[] = ['token' => 'field:' . $name, 'lines' => [$this->excerpt($value)]];
    }
    return implode("\n", $this->boxLines($markup, 'block:UUID', $block_type, $items, 0));
  }

  /**
   * Prints a section layout's schema example at one nesting depth.
   *
   * Same print format as a live section box (key placeholder
   * `container:UUID`, matching the block example convention), with the
   * component list replaced by a comment mounted at the `regions` token:
   * inside whichever part claims it, or directly inside the section root
   * when nothing does, exactly where real children land.
   *
   * @param array $markup
   *   The resolver's tree for the layout declaration at the chosen depth.
   * @param string $layout_id
   *   The layout plugin id (printed as data-type).
   *
   * @return string
   *   The example HTML.
   */
  public function projectLayoutExample(array $markup, string $layout_id): string {
    $items = [['token' => 'regions', 'lines' => ['<!-- child blocks and nested sections mount here -->']]];
    return implode("\n", $this->boxLines($markup, 'container:UUID', $layout_id, $items, 0));
  }

  /**
   * Serializes and annotates the entity's blueprint, or NULL if no backend.
   */
  private function annotatedBlueprint(FieldableEntityInterface $entity, ?string $langcode): ?array {
    $backend = $this->backendResolver->resolve($entity->getEntityTypeId());
    if ($backend === NULL) {
      return NULL;
    }
    if ($langcode !== NULL && $entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }
    return $this->annotator->annotate($backend->serialize($entity, $langcode), $entity, self::VIEW_MODE);
  }

  /**
   * Searches serialized sections (recursively) for the element to print.
   *
   * @return string[]|null
   *   The element's printed lines, or NULL when no node carries the UUID.
   */
  private function findElement(array $sections, string $uuid): ?array {
    foreach ($sections as $section) {
      if (($section['uuid'] ?? NULL) === $uuid) {
        return $this->sectionLines($section, 0);
      }
      foreach ($this->sectionComponentLists($section) as $components) {
        foreach ($components as $component) {
          if (!is_array($component)) {
            continue;
          }
          if (isset($component['layout_id'])) {
            $found = $this->findElement([$component], $uuid);
            if ($found !== NULL) {
              return $found;
            }
            continue;
          }
          if (($component['uuid'] ?? NULL) === $uuid) {
            return $this->componentLines($component, 0);
          }
        }
      }
    }
    return NULL;
  }

  /**
   * The section's component lists (regions map, or the container children).
   *
   * @return array[]
   *   Component lists, keyed by region name ('' for a container's ordered
   *   children list).
   */
  private function sectionComponentLists(array $section): array {
    $lists = [];
    if (!empty($section['regions']) && is_array($section['regions'])) {
      foreach ($section['regions'] as $region => $components) {
        $lists[(string) $region] = is_array($components) ? $components : [];
      }
    }
    if (!empty($section['children']) && is_array($section['children'])) {
      $lists[''] = $section['children'];
    }
    return $lists;
  }

  /**
   * Prints one section node.
   *
   * The section's components mount under the 'regions' token: inside the
   * part whose contains: claims it, or directly inside the section box
   * when nothing does (the one-box container), exactly as the render
   * mounts them. Multi-region layouts get a region comment per
   * region so the grouping stays visible without inventing region
   * elements (regions are not addressable).
   *
   * @return string[]
   *   The printed lines.
   */
  private function sectionLines(array $section, int $indent): array {
    $lists = $this->sectionComponentLists($section);
    $content = [];
    foreach ($lists as $region => $components) {
      if ($region !== '' && count($lists) > 1) {
        $content[] = ['token' => 'regions', 'lines' => ['<!-- region: ' . Html::escape($region) . ' -->']];
      }
      foreach ($components as $component) {
        $lines = $this->componentEntryLines($component, 0);
        if ($lines !== []) {
          $content[] = ['token' => 'regions', 'lines' => $lines];
        }
      }
    }
    $markup = $section['_element']['markup'] ?? $this->emptyMarkup();
    return $this->boxLines(
      $markup,
      'container:' . (string) ($section['uuid'] ?? ''),
      (string) ($section['layout_id'] ?? ''),
      $content,
      $indent,
    );
  }

  /**
   * Prints one component-list entry (a component or a nested section).
   *
   * @return string[]
   *   The printed lines (indent 0; the mounting box re-indents).
   */
  private function componentEntryLines(array|string $component, int $indent): array {
    if (!is_array($component)) {
      // Bare-UUID relocation strings carry no node to print.
      return [];
    }
    if (isset($component['layout_id'])) {
      return $this->sectionLines($component, $indent);
    }
    return $this->componentLines($component, $indent);
  }

  /**
   * Prints one component node.
   *
   * @return string[]
   *   The printed lines.
   */
  private function componentLines(array $component, int $indent): array {
    $pad = str_repeat('  ', $indent);
    $uuid = (string) ($component['uuid'] ?? '');
    $label = (string) ($component['label'] ?? '');
    $block_type = $component['block_type'] ?? NULL;

    // A plugin component renders bespoke markup the model describes
    // nowhere; the comment keeps its key and identity visible without
    // inventing elements.
    if ($block_type === NULL) {
      $plugin_id = (string) ($component['plugin_id'] ?? '');
      return [
        sprintf(
          '%s<!-- block:%s — plugin %s%s: bespoke markup (not modeled) -->',
          $pad,
          $uuid,
          Html::escape($plugin_id),
          $label !== '' ? ' "' . Html::escape($label) . '"' : '',
        ),
      ];
    }

    $markup = $component['_element']['markup'] ?? NULL;
    if ($markup === NULL) {
      // No resolved entity backs this component (a dangling revision):
      // the model only claims what data can back.
      return [
        sprintf(
          '%s<!-- block:%s — %s%s: markup unresolved -->',
          $pad,
          $uuid,
          Html::escape((string) $block_type),
          $label !== '' ? ' "' . Html::escape($label) . '"' : '',
        ),
      ];
    }

    return $this->boxLines(
      $markup,
      'block:' . $uuid,
      (string) $block_type,
      $this->componentContent($component, $markup),
      $indent,
    );
  }

  /**
   * The component's content items, each assigned its mount token.
   *
   * Minted rich-text instances print as real elements (their classes are
   * sub-element addresses); plain-text content comes from the fields the
   * tree itself names — the slot-claimed field tokens plus the
   * presentation's edit target — excerpted. Tokens the tree's slots claim
   * mount in the claiming part, everything else at the box root (the
   * mounting rule).
   *
   * @return array<int, array{token: string, lines: string[]}>
   *   The content items.
   */
  private function componentContent(array $component, array $markup): array {
    $items = [];
    foreach ($markup['instances'] ?? [] as $instance) {
      $items[] = ['token' => '__instances', 'lines' => $this->instanceLines($instance, 0)];
    }

    $fields = [];
    foreach (array_keys($this->slotsOf($markup)) as $token) {
      if (str_starts_with((string) $token, 'field:')) {
        $fields[] = substr((string) $token, strlen('field:'));
      }
    }
    $edit_target = $component['_element']['presentation']['edit_target'] ?? NULL;
    if (is_string($edit_target) && $edit_target !== '') {
      $fields[] = $edit_target;
    }
    foreach (array_unique($fields) as $name) {
      $value = $component['block_fields'][$name] ?? NULL;
      if (is_string($value) && trim($value) !== '') {
        $items[] = ['token' => 'field:' . $name, 'lines' => [$this->excerpt($value)]];
      }
    }
    return $items;
  }

  /**
   * Prints one minted rich-text instance row ({class, tag, children}).
   *
   * @return string[]
   *   The printed lines.
   */
  private function instanceLines(array $instance, int $indent): array {
    $pad = str_repeat('  ', $indent);
    $tag = $this->printableTag((string) ($instance['tag'] ?? 'div'));
    $open = sprintf('%s<%s class="%s">', $pad, $tag, Html::escape((string) ($instance['class'] ?? '')));
    $text = isset($instance['text']) ? $this->excerpt((string) $instance['text']) : '';
    $children = [];
    foreach ($instance['children'] ?? [] as $child) {
      $children = array_merge($children, $this->instanceLines($child, $indent + 1));
    }
    if ($children === []) {
      return [$open . $text . '</' . $tag . '>'];
    }
    $lines = [$open];
    if ($text !== '') {
      $lines[] = $pad . '  ' . $text;
    }
    $lines = array_merge($lines, $children);
    $lines[] = $pad . '</' . $tag . '>';
    return $lines;
  }

  /**
   * Prints one resolved box: the keyed root element and its part tree.
   *
   * @param array $markup
   *   The node's `_element.markup` tree ({tag, attributes, classes,
   *   hoisted, parts, slots}, see ElementResolver).
   * @param string $key
   *   The selectable element key the box answers to.
   * @param string $type
   *   The type fact printed as data-type (bundle, layout id).
   * @param array<int, array{token: string, lines: string[]}> $items
   *   The content items to mount per the slot claims.
   * @param int $indent
   *   The indent level.
   *
   * @return string[]
   *   The printed lines.
   */
  private function boxLines(array $markup, string $key, string $type, array $items, int $indent): array {
    $attributes = $this->attributesOf($markup);
    $classes = array_values(array_filter(array_map('strval', $markup['classes'] ?? [])));
    $extra = [];
    if ($key !== '') {
      $extra['data-key'] = $key;
    }
    if ($type !== '') {
      $extra['data-type'] = $type;
    }
    return $this->elementLines(
      $markup['tag'] ?? NULL,
      $attributes,
      $classes,
      $extra,
      $this->partsOf($markup),
      '',
      $this->slotsOf($markup),
      $items,
      $indent,
    );
  }

  /**
   * Prints one element: open tag, parts and mounted content, close tag.
   *
   * The print twin of ComposedTree::buildElement — same part
   * nesting, same slot claims, same mounting-rule residual — producing
   * indented source lines instead of render arrays.
   *
   * @param string|null $tag
   *   The resolved tag, or NULL for an unmodeled box (prints the div
   *   stand-in, see the class docblock).
   * @param array<string, string> $attributes
   *   The resolved literal attributes.
   * @param string[] $classes
   *   The element's classes (hoisted classes on a box, the part class on
   *   a part).
   * @param array<string, string> $extra
   *   Print-format annotations (data-key, data-type), first in the tag.
   * @param array $parts
   *   The present parts (resolved {tag, attributes, parts} by part key).
   * @param string $self_key
   *   The part key this element answers to ('' for the box root).
   * @param array<string, string> $slots
   *   The tree's flat slot map (token => claiming part key).
   * @param array<int, array{token: string, lines: string[]}> $items
   *   The content items, mounted where their token's claim lands; items
   *   with unclaimed tokens mount at the box root.
   * @param int $indent
   *   The indent level.
   *
   * @return string[]
   *   The printed lines.
   */
  private function elementLines(?string $tag, array $attributes, array $classes, array $extra, array $parts, string $self_key, array $slots, array $items, int $indent): array {
    $pad = str_repeat('  ', $indent);
    $tag = $this->printableTag($tag ?? 'div');
    $open = $pad . '<' . $tag . $this->attributeString($extra, $classes, $attributes);

    if (in_array($tag, self::VOID_TAGS, TRUE)) {
      return [$open . ' />'];
    }

    $children = [];
    foreach ($parts as $part_key => $part) {
      if (!is_array($part) || ($part['tag'] ?? NULL) === NULL) {
        continue;
      }
      $children = array_merge($children, $this->elementLines(
        (string) $part['tag'],
        $this->attributesOf($part),
        [ltrim((string) $part_key, '.')],
        [],
        $this->partsOf($part),
        (string) $part_key,
        $slots,
        $items,
        $indent + 1,
      ));
    }
    foreach ($items as $item) {
      $claimant = $slots[$item['token']] ?? '';
      if ($claimant === $self_key) {
        foreach ($item['lines'] as $line) {
          $children[] = $line === '' ? '' : $pad . '  ' . $line;
        }
      }
    }

    if ($children === []) {
      return [$open . '></' . $tag . '>'];
    }
    if (count($children) === 1 && !str_contains(trim($children[0]), '<')) {
      return [$open . '>' . trim($children[0]) . '</' . $tag . '>'];
    }
    return array_merge([$open . '>'], $children, [$pad . '</' . $tag . '>']);
  }

  /**
   * Comment lines for the root's own non-layout fields.
   *
   * Fields the element model marks non-existent (nothing answers to their
   * key in any view) are silent; anything else that would present in the
   * canvas is named by key so the reader knows the element exists even
   * though its shell markup is bespoke (the field template chain).
   *
   * @return string[]
   *   One comment line per open root field.
   */
  private function rootFieldComments(array $blueprint, FieldableEntityInterface $entity): array {
    $comments = [];
    $facts = $blueprint['_element']['fields'] ?? [];
    foreach ($blueprint['fields'] ?? [] as $name => $value) {
      if (($facts[$name]['element'] ?? NULL) === FALSE) {
        continue;
      }
      $excerpt = is_string($value) && trim($value) !== '' ? ' — "' . $this->excerpt($value) . '"' : '';
      $comments[] = sprintf('<!-- field:%s:%s%s -->', $entity->uuid(), $name, $excerpt);
    }
    return $comments;
  }

  /**
   * A node's resolved attribute map (tolerating the empty-list encode).
   *
   * @return array<string, string>
   *   The literal attributes.
   */
  private function attributesOf(array $markup): array {
    $attributes = [];
    foreach ($markup['attributes'] ?? [] as $name => $value) {
      if (is_string($name) && is_scalar($value)) {
        $attributes[$name] = (string) $value;
      }
    }
    return $attributes;
  }

  /**
   * A node's parts map (tolerating the empty-list encode).
   */
  private function partsOf(array $markup): array {
    $parts = $markup['parts'] ?? [];
    return is_array($parts) ? $parts : [];
  }

  /**
   * A node's slot map (tolerating the empty-list encode).
   *
   * @return array<string, string>
   *   Token => claiming part key.
   */
  private function slotsOf(array $markup): array {
    $slots = [];
    foreach ($markup['slots'] ?? [] as $token => $claimant) {
      if (is_string($token) && is_string($claimant)) {
        $slots[$token] = $claimant;
      }
    }
    return $slots;
  }

  /**
   * Prints the attribute string: data annotations, class, then the rest.
   */
  private function attributeString(array $extra, array $classes, array $attributes): string {
    $ordered = $extra;
    // Declared class attributes (none shipped) merge with the model's.
    if (isset($attributes['class'])) {
      $classes[] = $attributes['class'];
      unset($attributes['class']);
    }
    if ($classes !== []) {
      $ordered['class'] = implode(' ', array_unique($classes));
    }
    $ordered += $attributes;
    $printed = '';
    foreach ($ordered as $name => $value) {
      $printed .= ' ' . $name . '="' . Html::escape($value) . '"';
    }
    return $printed;
  }

  /**
   * Guards a tag name the way the renderer does — fail loud, never print.
   */
  private function printableTag(string $tag): string {
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $tag)) {
      throw new \UnexpectedValueException(sprintf('"%s" resolved as an element tag but is not a printable tag name.', $tag));
    }
    return $tag;
  }

  /**
   * A single-line, escaped, capped content excerpt.
   */
  private function excerpt(string $text): string {
    $text = trim((string) preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) > self::TEXT_CAP) {
      $text = mb_substr($text, 0, self::TEXT_CAP) . '…';
    }
    return Html::escape($text);
  }

  /**
   * The empty markup tree, for nodes the annotator left unstamped.
   */
  private function emptyMarkup(): array {
    return ['tag' => NULL, 'attributes' => [], 'classes' => [], 'hoisted' => [], 'parts' => [], 'slots' => []];
  }

}
