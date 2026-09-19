<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\FileInterface;
use Drupal\link\LinkItemInterface;
use Drupal\media\MediaInterface;

/**
 * Resolves a declaration to the concrete element tree an instance renders.
 *
 * The INSTANCE tier of the page-elements model: a declaration
 * (PageBuilderDeclarations) says what a type CAN render as — data-decided
 * tags, conditional parts, ordered root forms — and this resolver computes
 * what THIS element IS rendering as from entity data alone. Every markup
 * variation the declarations can spell is a deterministic function of
 * entity data (a tag field's value, a url field's emptiness, the referenced
 * media's kind, the section's nesting depth), so the resolution needs no
 * DOM, runs no Twig, and issues no queries: (declaration, entity data,
 * depth) in, concrete tree out, the same answer for the same inputs on
 * every surface (server render, blueprint annotation, AI projection).
 *
 * The resolved tree, one node per concrete element:
 * - tag: the root's resolved tag name, or NULL when the declaration names
 *   none (bespoke markup the grammar describes no root for) or a
 *   data-decided tag cannot resolve (degenerate entity data).
 * - attributes: the root's declared attributes, resolved to literal
 *   strings ({field: NAME} values read the named field; a link field
 *   resolves to its URL). Only the matched root form carries declared root
 *   attributes; hoisted machine attributes (selection keys, editing
 *   attribution) are the renderer's business, never modeled here.
 * - classes: part classes the root wears via the hoisting rule below.
 * - hoisted: the hoisted parts' keys — each still addresses an element,
 *   and that element is the root.
 * - parts: the PRESENT parts as their own elements, keyed by part key,
 *   each a {tag, attributes, parts} node (a part's class is its key with
 *   the leading dot stripped; absent parts are simply not in the tree).
 *   A multi-form part (`forms` instead of `tag`) resolves through the
 *   same first-match-wins list the root does, and the matched form
 *   supplies the part's tag and attributes; unlike root forms the list
 *   need not be exhaustive, so a part none of whose forms match is
 *   absent, subtree included. When the matched form declares a `label`
 *   (the form's own name, "Raster" for the image part's img form), the
 *   node carries it as `label`, between attributes and parts; a node
 *   without the key resolved through a plain tag or a nameless form, and
 *   consumers fall back to the declaration's part-level label.
 *   A part whose `contains:` claims a `field:` slot carries the
 *   CLAIMS_CONTENT_ATTRIBUTE marker in its attributes: the anatomy fact
 *   "this element claims the block's field content" enters the markup
 *   spine here, once, so every consumer (server render, projection, CSS
 *   co-targeting) reads it from the markup instead of the element model.
 *   No field-type gating: inherited text properties are inert where no
 *   glyphs render, so a non-text claim stamps harmlessly. A HOISTED
 *   part's element is the root itself, already reached directly, so
 *   hoists never stamp (their claims dissolve to the root the same way
 *   their slots do).
 * - slots: one flat map for the whole tree, contains token => the present
 *   part the children it names mount in (part keys are unique per tree, so
 *   flat covers every depth). Children whose token is unclaimed mount at
 *   the element root — the mounting rule — so a conditional wrapper (the
 *   text block's anchor) needs no branch spelling: while the anchor is
 *   absent its claim is gone and the text mounts at the root.
 *
 * Presence resolves per the closed condition vocabulary: {field: NAME} is
 * the named field's non-emptiness, {media: raster|vector} the referenced
 * media's kind (derived from the source FILE — svg by extension or mime is
 * vector, any other file raster — mirroring the render lanes, which branch
 * on the file so an svg reaching any media type takes the vector lane),
 * {empty: true} whether the element has no content — every content field
 * empty (the definition-based inventory, see
 * ElementModel::contentFieldDefinitions) for entity-backed elements, or
 * the caller's emptiness input for elements with no entity of their own (a
 * section's "no children" fact, fed by the annotator and the render from
 * the section node) — and {depth: root|nested} the depth handed in. A
 * ROOT FORM may compose several conditions in one map, resolved as an AND
 * (the container's empty-at-root form); parts carry one condition each.
 *
 * The hoisting rule (grammar v2): a part whose element coincides with its
 * root hoists its class onto the root, and the part key still addresses
 * that element. Coincidence is a multi-form root resolving to the part's
 * own element: when the matched root form and a present top-level part's
 * shape — the part itself, or its MATCHED form for a multi-form part —
 * declare the same element (equal present condition, equal resolved tag),
 * the root IS that part (the unlinked image block's img), so the class
 * hoists. Any other failed condition means the element does not exist at
 * all (an unlinked text block renders no anchor), so the part — subtree
 * included — is absent, not hoisted.
 *
 * Pattern parts (".s-*") resolve to no concrete element: they name a class
 * NAMESPACE whose instances are content, each carrying its own markup, so
 * no instance tree can enumerate them.
 *
 * Input is an EFFECTIVE declaration as PageBuilderDeclarations serves it
 * (getBlockType()/getBlockPlugin()/getLayout()/getKind()), already
 * validated at build; the resolver assumes the grammar invariants
 * (exhaustive root forms, one condition per part present map, depth and
 * composed conditions only on root forms) instead of re-checking them.
 *
 * The client twin (js/edit_mode/blueprint/element-resolver.js) mirrors
 * this resolution over a data facade so editing previews resolve the same
 * trees before any commit; ElementResolverTest::testClientTwinEquivalence
 * locks the pair together (the CssPrinter/printer.js pattern). Change the
 * semantics here and in the twin in the same commit, or the lock fails.
 */
final class ElementResolver {

  /**
   * The root nesting depth: the section renders as a page band.
   */
  public const DEPTH_ROOT = 'root';

  /**
   * The nested nesting depth: the section renders inside another section.
   */
  public const DEPTH_NESTED = 'nested';

  /**
   * The content-claim marker a field-claiming part wears in live markup.
   *
   * An anatomy fact stamped at resolution so it prints on every surface
   * from this one seam: the element this attribute marks claims the
   * block's field content (`contains: field:*`), which is what lets CSS
   * tiers co-target a block root's inherited text properties onto the
   * claiming interior element with zero element-model knowledge at print
   * time. Live (non-edit) markup carries it deliberately, like variant
   * classes: the compiled stylesheets need the marker in every mode.
   */
  public const CLAIMS_CONTENT_ATTRIBUTE = 'data-claims-content';

  /**
   * Resolves a declaration against entity data into a concrete tree.
   *
   * @param array $declaration
   *   The effective declaration (validated, kind-layered).
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity whose data decides the variation (the block_content of an
   *   inline block, the root entity), or NULL for elements with no entity
   *   of their own (containers, regions, the field shell). Without an
   *   entity every data condition is FALSE and data-decided values resolve
   *   NULL.
   * @param string $depth
   *   The nesting depth (self::DEPTH_ROOT / self::DEPTH_NESTED); only
   *   container-family declarations vary on it.
   * @param bool|null $empty
   *   The emptiness fact for elements with NO entity of their own: a
   *   section is empty when its node carries no components, a fact only
   *   the caller (the annotator walking the serialized node, the render
   *   walking the regions build) can answer. When given it IS the
   *   {empty: true} answer; NULL (the default) derives emptiness from the
   *   entity's content fields, and with no entity either the condition is
   *   FALSE — nothing exists that the data cannot vouch for.
   *
   * @return array
   *   The resolved tree: {tag, attributes, classes, hoisted, parts, slots}
   *   as documented on the class.
   */
  public function resolve(array $declaration, ?FieldableEntityInterface $entity = NULL, string $depth = self::DEPTH_ROOT, ?bool $empty = NULL): array {
    if ($depth !== self::DEPTH_ROOT && $depth !== self::DEPTH_NESTED) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a nesting depth (expected "%s" or "%s").', $depth, self::DEPTH_ROOT, self::DEPTH_NESTED));
    }
    $resolved = [
      'tag' => NULL,
      'attributes' => [],
      'classes' => [],
      'hoisted' => [],
      'parts' => [],
      'slots' => [],
    ];
    $form = NULL;
    if (isset($declaration['root']['forms'])) {
      // First-match-wins over the ordered forms. Validation proves the
      // order exhaustive for declared data states; degenerate data outside
      // them (a media reference whose file is gone) matches no form and
      // the root honestly resolves undeclared.
      $form = $this->matchForm($declaration['root']['forms'], $entity, $depth, $empty);
      if ($form !== NULL) {
        $resolved['tag'] = $this->resolveTag($form['tag'] ?? NULL, $entity);
        $resolved['attributes'] = $this->resolveAttributes($form['attributes'] ?? [], $entity);
      }
    }
    elseif (array_key_exists('tag', $declaration)) {
      $resolved['tag'] = $this->resolveTag($declaration['tag'], $entity);
    }
    $resolved['parts'] = $this->resolveElements($declaration['elements'] ?? [], $entity, $depth, $empty, $form, $resolved, TRUE);
    return $resolved;
  }

  /**
   * The root form a declaration resolves under, for the given facts.
   *
   * The same first-match-wins answer resolve() computes, exposed for
   * render seams that need the MATCHED form's own declaration rather than
   * the resolved tree: the composed container render stamps its edit-only
   * empty hint exactly when the matched form is an {empty: true} form —
   * the affordance is declared, never guessed from attribute names. NULL
   * when the declaration has no root forms or none matches (the honest
   * undeclared root).
   *
   * @param array $declaration
   *   The effective declaration (validated, kind-layered).
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity whose data decides the variation, or NULL (see
   *   resolve()).
   * @param string $depth
   *   The nesting depth (self::DEPTH_ROOT / self::DEPTH_NESTED).
   * @param bool|null $empty
   *   The caller's emptiness input (see resolve()).
   *
   * @return array|null
   *   The matched root form as declared, or NULL.
   */
  public function matchedRootForm(array $declaration, ?FieldableEntityInterface $entity = NULL, string $depth = self::DEPTH_ROOT, ?bool $empty = NULL): ?array {
    return $this->matchForm($declaration['root']['forms'] ?? NULL, $entity, $depth, $empty);
  }

  /**
   * Resolves one elements: level into present part nodes.
   *
   * @param mixed $elements
   *   The level's elements map as declared.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The entity whose data decides presence.
   * @param string $depth
   *   The nesting depth.
   * @param bool|null $empty
   *   The caller's emptiness input (see resolve()).
   * @param array|null $form
   *   The matched root form, for the coincidence hoisting rule; only the
   *   top level can coincide with the root.
   * @param array $root
   *   The tree being resolved; hoists append to its classes/hoisted.
   * @param bool $top
   *   Whether this level is the declaration's top level (hoisting is
   *   root-coincidence, so only top-level parts hoist).
   *
   * @return array
   *   Present part nodes keyed by part key.
   */
  private function resolveElements(mixed $elements, ?FieldableEntityInterface $entity, string $depth, ?bool $empty, ?array $form, array &$root, bool $top): array {
    $parts = [];
    if (!is_array($elements)) {
      return $parts;
    }
    foreach ($elements as $key => $part) {
      if (!is_array($part)) {
        continue;
      }
      $key = (string) $key;
      if (preg_match(PageBuilderDeclarations::PART_PATTERN, $key)) {
        // A pattern part names a class namespace whose instances are
        // content; no concrete element resolves from it.
        continue;
      }
      if (!$this->conditionHolds($part['present'] ?? NULL, $entity, $depth, $empty)) {
        // A failed condition means the element does not exist: absent,
        // subtree included, never hoisted.
        continue;
      }
      // The part's shape: the part itself, or — for a multi-form part —
      // its matched form, which supplies the tag, attributes, and label.
      // Unlike root forms the list need not be exhaustive: a part none of
      // whose forms match is absent, subtree included.
      $part_form = NULL;
      $shape = $part;
      if (isset($part['forms'])) {
        $part_form = $this->matchForm($part['forms'], $entity, $depth, $empty);
        if ($part_form === NULL) {
          continue;
        }
        $shape = $part_form;
      }
      if ($top && $form !== NULL && $this->coincides($shape, $form, $entity)) {
        // The matched root form resolved the part's own element as the
        // root: one element, class hoisted, key addressing the root.
        $this->hoist($key, $root);
        $parts += $this->resolveElements($part['elements'] ?? [], $entity, $depth, $empty, NULL, $root, FALSE);
        continue;
      }
      $attributes = $this->resolveAttributes($shape['attributes'] ?? [], $entity);
      foreach ((array) ($part['contains'] ?? []) as $token) {
        $root['slots'][(string) $token] = $key;
        if (str_starts_with((string) $token, 'field:')) {
          // The claim is an anatomy fact the markup carries (see
          // CLAIMS_CONTENT_ATTRIBUTE); stamped after the declared
          // attributes so the twin resolves the identical order.
          $attributes[self::CLAIMS_CONTENT_ATTRIBUTE] = '';
        }
      }
      $node = [
        'tag' => $this->resolveTag($shape['tag'] ?? NULL, $entity),
        'attributes' => $attributes,
      ];
      if (is_string($part_form['label'] ?? NULL)) {
        // The matched form's own name; the key exists only when the form
        // speaks, so plain tag-parts keep their shape and consumers fall
        // back to the declaration's part-level label.
        $node['label'] = $part_form['label'];
      }
      $node['parts'] = $this->resolveElements($part['elements'] ?? [], $entity, $depth, $empty, NULL, $root, FALSE);
      $parts[$key] = $node;
    }
    return $parts;
  }

  /**
   * Hoists a part onto the root: class worn, key addressing the root.
   */
  private function hoist(string $key, array &$root): void {
    $root['classes'][] = ltrim($key, '.');
    $root['hoisted'][] = $key;
  }

  /**
   * The first form whose condition holds, root or part alike.
   *
   * NULL when none matches.
   */
  private function matchForm(mixed $forms, ?FieldableEntityInterface $entity, string $depth, ?bool $empty): ?array {
    if (!is_array($forms)) {
      return NULL;
    }
    foreach ($forms as $form) {
      if (!is_array($form)) {
        continue;
      }
      if (!array_key_exists('present', $form) || $this->conditionHolds($form['present'], $entity, $depth, $empty)) {
        return $form;
      }
    }
    return NULL;
  }

  /**
   * Whether a top-level part is the element the matched root form resolved.
   *
   * Coincidence is declared, not guessed: the form and the part name the
   * same element when they resolve to the same tag under the same present
   * condition (the image block's `img` form and `.image-block-image` part
   * both say "the raster media render"). A form matched by a DIFFERENT
   * condition (the anchor form) is a wrapper the part nests inside. For a
   * multi-form part, $shape is the part's MATCHED form, so coincidence is
   * decided between matched forms (the root's svg form and the part's svg
   * form both say "the vector media render").
   */
  private function coincides(array $shape, array $form, ?FieldableEntityInterface $entity): bool {
    if (($shape['present'] ?? NULL) != ($form['present'] ?? NULL)) {
      return FALSE;
    }
    $tag = $this->resolveTag($shape['tag'] ?? NULL, $entity);
    return $tag !== NULL && $tag === $this->resolveTag($form['tag'] ?? NULL, $entity);
  }

  /**
   * Whether one present: condition map holds against the instance facts.
   *
   * The closed vocabulary, every condition in the map required (root
   * forms may compose several as an AND; parts carry one, validated at
   * build). No entity means every data condition is FALSE — nothing
   * exists that the data cannot vouch for — except an emptiness the
   * caller's input vouches for (a section's "no children" fact).
   */
  private function conditionHolds(mixed $present, ?FieldableEntityInterface $entity, string $depth, ?bool $empty): bool {
    if ($present === NULL) {
      return TRUE;
    }
    if (!is_array($present) || $present === []) {
      return FALSE;
    }
    foreach ($present as $condition => $value) {
      $holds = match ($condition) {
        'field' => $entity !== NULL && is_string($value) && $entity->hasField($value) && !$entity->get($value)->isEmpty(),
        'media' => $entity !== NULL && $this->mediaKind($entity) === $value,
        'depth' => $depth === $value,
        'empty' => $empty ?? ($entity !== NULL && $this->isEmptyOfContent($entity)),
        default => FALSE,
      };
      if (!$holds) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Resolves a declared tag: a literal name, or the named field's value.
   *
   * @return string|null
   *   The tag name, or NULL when none is declared or the deciding field
   *   holds no value.
   */
  private function resolveTag(mixed $tag, ?FieldableEntityInterface $entity): ?string {
    if (is_string($tag)) {
      return $tag;
    }
    if (is_array($tag) && is_string($tag['field'] ?? NULL)) {
      return $this->fieldValue($entity, $tag['field']);
    }
    return NULL;
  }

  /**
   * Resolves a declared attributes map to literal string values.
   *
   * A {field: NAME} value whose field holds nothing resolves to no
   * attribute at all: an empty href would be a claim the data cannot back.
   */
  private function resolveAttributes(mixed $attributes, ?FieldableEntityInterface $entity): array {
    $resolved = [];
    if (!is_array($attributes)) {
      return $resolved;
    }
    foreach ($attributes as $name => $value) {
      if (is_string($value)) {
        $resolved[$name] = $value;
      }
      elseif (is_array($value) && is_string($value['field'] ?? NULL)) {
        $field_value = $this->fieldValue($entity, $value['field']);
        if ($field_value !== NULL) {
          $resolved[$name] = $field_value;
        }
      }
    }
    return $resolved;
  }

  /**
   * The named field's first value as the string markup prints.
   *
   * A link field resolves to its URL (what an href means), mirroring the
   * composed templates' preprocess; anything else resolves its main
   * property when scalar.
   */
  private function fieldValue(?FieldableEntityInterface $entity, string $name): ?string {
    if ($entity === NULL || !$entity->hasField($name) || $entity->get($name)->isEmpty()) {
      return NULL;
    }
    $item = $entity->get($name)->first();
    if ($item instanceof LinkItemInterface) {
      return $item->getUrl()->toString();
    }
    $property = $item->getFieldDefinition()->getFieldStorageDefinition()->getMainPropertyName() ?? 'value';
    $value = $item->get($property)->getValue();
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * Whether the entity has no content at all (the {empty: true} fact).
   *
   * Definition-based like every model inventory (see
   * ElementModel::contentFieldDefinitions): the content fields are the
   * bundle's FieldConfig fields minus structure (layout_section), and empty
   * means every one of them is.
   */
  private function isEmptyOfContent(FieldableEntityInterface $entity): bool {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if (!$definition instanceof FieldConfig || $definition->getType() === 'layout_section') {
        continue;
      }
      if (!$entity->get($name)->isEmpty()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The referenced media's kind: 'raster', 'vector', or NULL for neither.
   *
   * The element's media rides its media reference field (the first
   * non-empty entity_reference targeting media, the same field shape
   * ElementModel::fieldPresentation treats as "the image"), and the kind
   * derives from the source FILE, not the media bundle: svg by extension
   * or mime is vector, any other file raster, so an svg reaching any media
   * type resolves the same lane the render takes. Media resolving no file
   * has no kind (NULL): neither condition can claim it.
   */
  private function mediaKind(FieldableEntityInterface $entity): ?string {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'media') {
        continue;
      }
      if ($entity->get($name)->isEmpty()) {
        continue;
      }
      $media = $entity->get($name)->first()->entity;
      if ($media instanceof MediaInterface) {
        return $this->kindOfMedia($media);
      }
    }
    return NULL;
  }

  /**
   * One media entity's kind from its source file.
   */
  private function kindOfMedia(MediaInterface $media): ?string {
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
    if ($source_field === NULL) {
      return NULL;
    }
    $item = $media->get($source_field->getName())->first();
    $file = $item?->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    // Case-insensitive on either spelling, mirroring the render lane and
    // the upload sanitizer: a mixed-case extension was an actual
    // sanitizer-bypass vector.
    $uri = (string) $file->getFileUri();
    if (preg_match('/\.svg$/i', $uri) === 1 || strtolower($file->getMimeType() ?? '') === 'image/svg+xml') {
      return 'vector';
    }
    return 'raster';
  }

}
