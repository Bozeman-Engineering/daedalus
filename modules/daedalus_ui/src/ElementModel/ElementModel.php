<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\daedalus_ui\Presentation\Presentation;

/**
 * THE element model: one anatomy highway, every consumer a thin adapter.
 *
 * Answers "what does this thing render as, and what of it is addressable?"
 * along the model's two axes:
 * - EXISTENCE: does anything answer to this key, in any view? Never
 *   bypassed. A nonexistent field has no row, no canvas element, no
 *   styling surface (nonExistentFields()).
 * - PRESENTATION: open / unit / merged, how a node that exists presents
 *   (bundlePresentation(), fieldPresentation()). Compose (isComposed(),
 *   gated on the `composed: true`
 *   declaration) is its own axis inside the model: an identity merger (the
 *   block's composed root IS the block element and the section key is
 *   never stamped), not a field-existence fact.
 *
 * Every answer resolves through one precedence, most specific human intent
 * first:
 * 1. Site-builder third-party settings (self::SETTING on bundle and field
 *    config, the only UI-exposed tier).
 * 2. Developer/theme declarations (the layered PROVIDER.page_builder.yml
 *    family, served by PageBuilderDeclarations).
 * 3. Inference: what the code can derive. The derived presentation defaults
 *    (a container whose meaningful content is exactly one thing merges into
 *    it) and the tagged existence feeders (ExistenceFeederInterface,
 *    collected via the daedalus_ui.existence_feeder tag), so modules
 *    that own render truths (daedalus_layout's preserved fields) feed the model
 *    without a dependency cycle.
 *
 * The presentation inventory is DEFINITION-based, never
 * serialized-output-based: counting serialized fields would make structure
 * vary per user (field access) and flicker as content fills (sparse
 * serialization). Meaningful things are the bundle's content-field
 * definitions (FieldConfig instances; base fields like block_content's
 * info are excluded free, and the layout_section type is structure, not
 * content) plus the visible configuration label (per-instance input).
 * Access still gates VERBS at use time, never structure.
 *
 * Both presentation carriers call here: blueprint annotations at the
 * delivery seam (panel projection) and DOM attributes at attribution
 * (canvas resolver), so the two agree by construction.
 */
final class ElementModel {

  /**
   * The third-party-settings key on bundle and field config.
   *
   * Bundle values: 'auto' (the default: fall through to declarations, then
   * derive), 'unit' ("One element"), 'open' ("Show structure"), 'merged'
   * ("One element (merged)", for bundles whose render composes several
   * fields into one visual element, e.g. a composite-edited text block).
   * Field values: 'unit' or 'open'.
   */
  public const SETTING = 'presentation';

  /**
   * The root-entity node kind: fields render through the routed display.
   */
  public const KIND_ENTITY = 'entity';

  /**
   * The block node kind.
   *
   * An inline block rendering through its bundle's own view display.
   */
  public const KIND_BLOCK = 'block';

  /**
   * The registered existence feeders (inference tier).
   *
   * @var \Drupal\daedalus_ui\ElementModel\ExistenceFeederInterface[]
   */
  private array $feeders = [];

  /**
   * Memoized existence answers, keyed by kind:entity_type:bundle:view_mode.
   *
   * @var array<string, string[]>
   */
  private array $nonExistentFields = [];

  public function __construct(
    private readonly PageBuilderDeclarations $declarations,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Registers an existence feeder (service collector callback).
   */
  public function addFeeder(ExistenceFeederInterface $feeder): void {
    $this->feeders[] = $feeder;
  }

  /**
   * Decides presentation for a fieldable bundle node (a block, a paragraph).
   *
   * @param string $entity_type_id
   *   The content entity type id ('block_content', 'paragraph').
   * @param string $bundle
   *   The bundle machine name.
   * @param bool $label_visible
   *   Whether the node displays a configuration label as page content
   *   (label_display === 'visible'). Per-instance input; always FALSE
   *   for nodes without a displayed label (paragraphs).
   *
   * @return \Drupal\daedalus_ui\Presentation\Presentation
   *   The decision.
   */
  public function bundlePresentation(string $entity_type_id, string $bundle, bool $label_visible = FALSE): Presentation {
    // Tier 1: the site builder's three-way setting ('auto' means no
    // decision: fall through).
    $configured = $this->bundleSetting($entity_type_id, $bundle);
    if ($configured === Presentation::UNIT || $configured === Presentation::OPEN) {
      return new Presentation($configured);
    }
    if ($configured === Presentation::MERGED) {
      // A configured merge: the bundle declares its render composes the
      // fields into ONE visual element (a composite editor's block type).
      // NULL label keeps the component's own label; the edit target (and
      // with it the projection's live row label) is the bundle's single
      // plain-text field when one is unambiguous.
      return new Presentation(Presentation::MERGED, NULL, $this->primaryTextField($entity_type_id, $bundle));
    }

    // Tier 2: the developer/theme declaration.
    $declared = $this->nodeDeclaration($entity_type_id, $bundle)['presentation'] ?? NULL;
    if ($declared !== NULL) {
      return $this->declaredPresentation($declared, $entity_type_id, $bundle);
    }

    // Tier 3, derived default: a container whose meaningful content is
    // exactly one thing merges into it.
    $content_fields = $this->contentFieldDefinitions($entity_type_id, $bundle);

    // Structural containers (a bundle carrying a nested layout) stay OPEN:
    // their children are sections, not content.
    if ($this->hasLayoutField($entity_type_id, $bundle)) {
      return new Presentation(Presentation::OPEN);
    }

    $meaningful = count($content_fields) + ($label_visible ? 1 : 0);

    if ($meaningful > 1) {
      return new Presentation(Presentation::OPEN);
    }

    if ($label_visible && empty($content_fields)) {
      // The displayed label IS the content (heading-style blocks): the node
      // merges into its label row and keeps its own label text.
      return new Presentation(Presentation::MERGED, NULL, 'label');
    }

    if (count($content_fields) === 1) {
      $definition = reset($content_fields);
      return new Presentation(
        Presentation::MERGED,
        (string) $definition->getLabel(),
        $definition->getName(),
      );
    }

    // Zero fields, no displayed label: nothing inside at all, one element
    // with nothing to route edits to (the form is the editing surface).
    return new Presentation(Presentation::MERGED);
  }

  /**
   * Decides presentation for the ROOT entity node (the routed entity).
   *
   * The root presents OPEN (it is the page) with one derived rule (the
   * heading block's merged rule at entity kind): an entity's label field
   * presents AS the entity row. The root row already shows the label, so a
   * separate label-field row would say the same thing twice; instead the
   * root row stands in for the label field (edit_target: the row shows its
   * text live and renames it), exactly as a merged block row stands in for
   * its absorbed content. The root's own editor is the entity-form panel,
   * never a leaf reached through this target. Derived, not config:
   * the entity type's own label key is the one authority. The rule only
   * fires when the label key is a real field on the bundle AND render
   * configuration does not prove nothing answers to it (the inference
   * feeders). A declared `element: false` (block_content's info) does NOT
   * veto: the declaration says the field earns no row of its own inside
   * the entity render, but the root rule creates no row anyway — the label
   * presents AS the root row — and the label's one canvas render is
   * allowed to live OUTSIDE the entity as the page title (daedalus_edit's
   * PageTitleEditing stamps it), which a declaration cannot speak for. On
   * the block_content editing surface the H1 IS the info label, so the
   * root must name it as its target for that stamp to happen.
   *
   * @param string $entity_type_id
   *   The root entity type.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode the root renders with.
   *
   * @return \Drupal\daedalus_ui\Presentation\Presentation
   *   The decision: OPEN, with the label field as edit target when routable.
   */
  public function rootPresentation(string $entity_type_id, string $bundle, string $view_mode): Presentation {
    $label_field = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('label');
    if (
      is_string($label_field)
      && isset($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle)[$label_field])
      && !in_array($label_field, $this->inferredNonExistentFields(self::KIND_ENTITY, $entity_type_id, $bundle, $view_mode), TRUE)
    ) {
      return new Presentation(Presentation::OPEN, NULL, $label_field);
    }
    return new Presentation(Presentation::OPEN);
  }

  /**
   * The root field a Layout Builder field block presents, or NULL.
   *
   * Core's field block plugin id is field_block:{entity_type}:{bundle}:
   * {field}; the placement presents that field exactly when the entity it
   * renders for is one the derivative was made for (same type and bundle)
   * and carries the field. Anything else (a plugin block, a stale
   * derivative, a mismatched context) is no field render and presents open
   * like any plugin component.
   *
   * @param string $plugin_id
   *   The component's block plugin id.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the placement renders for (the layout's root).
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The presented field's definition on that entity, or NULL.
   */
  public function presentedField(string $plugin_id, FieldableEntityInterface $entity): ?FieldDefinitionInterface {
    $parts = explode(':', $plugin_id);
    if (count($parts) !== 4 || $parts[0] !== 'field_block') {
      return NULL;
    }
    [, $entity_type_id, $bundle, $field_name] = $parts;
    if ($entity->getEntityTypeId() !== $entity_type_id || $entity->bundle() !== $bundle || !$entity->hasField($field_name)) {
      return NULL;
    }
    return $entity->getFieldDefinition($field_name);
  }

  /**
   * Decides presentation for a field-block placement.
   *
   * A field block renders one root field inside a plugin-block placement:
   * the placement wrapper (block:{uuid}) around the field's own render
   * (field:{root}:{name}, attributed exactly as a preserved field's, the
   * same leaf inside). The placement presents MERGED into that field, the
   * rule a single-content inline block merges under: one row keyed by the
   * placement (the outermost carrier, so move, trash and duplicate arm),
   * labeled with the field's definition label, editing routed to the
   * field's leaf, which edits in place. Rendered evidence beats display
   * inference: the root's existence feeders may report the field
   * nonexistent for the root display's own rows (it renders here, not
   * there), and the placement's merged fold is what keeps the leaf
   * editable regardless.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The presented field (presentedField()).
   *
   * @return \Drupal\daedalus_ui\Presentation\Presentation
   *   MERGED, labeled and targeted at the field.
   */
  public function fieldBlockPresentation(FieldDefinitionInterface $definition): Presentation {
    return new Presentation(Presentation::MERGED, (string) $definition->getLabel(), $definition->getName());
  }

  /**
   * Decides presentation for a field node.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return string
   *   Presentation::UNIT for fields that present as one semantic element
   *   (a media reference renders a whole entity the user means as ONE
   *   thing, "the image"), Presentation::OPEN otherwise. A FieldConfig
   *   third-party setting outranks the owner's declaration, which outranks
   *   the field-type default.
   */
  public function fieldPresentation(FieldDefinitionInterface $definition): string {
    if ($definition instanceof FieldConfig) {
      $configured = $definition->getThirdPartySetting('daedalus_ui', self::SETTING);
      if ($configured === Presentation::UNIT || $configured === Presentation::OPEN) {
        return $configured;
      }
    }
    $fields = $this->nodeDeclaration($definition->getTargetEntityTypeId(), $definition->getTargetBundle())['fields'] ?? [];
    $declared = $fields[$definition->getName()]['presentation'] ?? NULL;
    if ($declared === Presentation::UNIT || $declared === Presentation::OPEN) {
      return $declared;
    }
    if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media') {
      return Presentation::UNIT;
    }
    return Presentation::OPEN;
  }

  /**
   * Whether the block plugin renders composed to its single root.
   *
   * Compose is a block-type fact (`composed: true` in the bundle's
   * PROVIDER.page_builder.block_type.BUNDLE.yml declaration), so only
   * inline-block plugin ids can answer TRUE. A reusable placement of the
   * same bundle ('block_content:{uuid}') is a different plugin (its render
   * path never composes), and plain plugin blocks have no declaration to
   * opt in with.
   *
   * @param string $plugin_id
   *   The block plugin id, e.g. 'inline_block:text'.
   */
  public function isComposed(string $plugin_id): bool {
    return str_starts_with($plugin_id, 'inline_block:')
      && $this->isComposedInlineBlock(substr($plugin_id, strlen('inline_block:')));
  }

  /**
   * Whether the block_content bundle's inline block renders composed.
   *
   * Consumers that hold a block_content entity rather than a component
   * (e.g. the bundle's own section render) ask through here so the
   * plugin-id derivation lives in one place.
   *
   * @param string $block_type
   *   The block_content bundle.
   */
  public function isComposedInlineBlock(string $block_type): bool {
    return ($this->declarations->getBlockType($block_type)['composed'] ?? FALSE) === TRUE;
  }

  /**
   * The block_content bundles whose inline blocks render composed.
   *
   * The list twin of isComposedInlineBlock(): consumers that enumerate
   * bundles rather than test one (e.g. daedalus_stylish surfacing composed block
   * types in its AI schema) read the list through here.
   *
   * @return string[]
   *   block_content bundle machine names.
   */
  public function composedInlineBlockBundles(): array {
    return array_values(array_filter(
      $this->declarations->getBlockTypes(),
      fn (string $bundle): bool => $this->isComposedInlineBlock($bundle),
    ));
  }

  /**
   * The field names nothing answers to, in any view (the EXISTENCE axis).
   *
   * The union of two tiers:
   * - Declarations: fields the owner declares `element: false` (metadata
   *   like block_content's info: no canvas render by design).
   * - Inference: what the registered feeders derive from render
   *   configuration: daedalus_ui's own display-hidden inference for
   *   inline blocks, daedalus_layout's preserved-fields rule for root entities on
   *   Layout Builder displays.
   *
   * A nonexistent field never renders as an attributed element, so no
   * canvas element answers to its key (no row, no outline, no styling
   * surface, no edit path).
   *
   * @param string $kind
   *   The node kind whose fields these are: self::KIND_ENTITY for a root
   *   entity rendering through the routed display, self::KIND_BLOCK for an
   *   inline block rendering through its bundle's own view display.
   * @param string $entity_type_id
   *   The entity type the fields belong to ('block_content' for blocks).
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode the node renders with ('full' for inline blocks).
   *
   * @return string[]
   *   The nonexistent field names.
   */
  public function nonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array {
    if ($kind !== self::KIND_ENTITY && $kind !== self::KIND_BLOCK) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an existence kind (expected "%s" or "%s").', $kind, self::KIND_ENTITY, self::KIND_BLOCK));
    }
    $cid = "$kind:$entity_type_id:$bundle:$view_mode";
    if (isset($this->nonExistentFields[$cid])) {
      return $this->nonExistentFields[$cid];
    }
    $names = [];
    $fields = $this->nodeDeclaration($entity_type_id, $bundle, $kind)['fields'] ?? [];
    foreach ($fields as $name => $field) {
      if (($field['element'] ?? NULL) === FALSE) {
        $names[] = $name;
      }
    }
    $names = array_merge($names, $this->inferredNonExistentFields($kind, $entity_type_id, $bundle, $view_mode));
    return $this->nonExistentFields[$cid] = array_values(array_unique($names));
  }

  /**
   * The inference tier of nonExistentFields(): the feeders alone.
   *
   * What render configuration proves nothing answers to, without the
   * declared `element: false` tier. rootPresentation()'s derived label rule
   * consults only this tier: a declaration speaks for rows inside the
   * entity render, not for the page-title render the root label may live
   * in (see rootPresentation()).
   *
   * @param string $kind
   *   The node kind (self::KIND_ENTITY / self::KIND_BLOCK).
   * @param string $entity_type_id
   *   The entity type the fields belong to.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode the node renders with.
   *
   * @return string[]
   *   The inferred nonexistent field names.
   */
  private function inferredNonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array {
    $names = [];
    foreach ($this->feeders as $feeder) {
      $names = array_merge($names, $feeder->nonExistentFields($kind, $entity_type_id, $bundle, $view_mode));
    }
    return array_values(array_unique($names));
  }

  /**
   * The node's own declaration from the layered file family.
   *
   * Block_content bundles ARE the page builder's block types, so they
   * resolve to their effective block type declaration (the universal block
   * kind layered underneath), whether asked about as components or as
   * roots. Everything else (and block_content base-field definitions with
   * no bundle) resolves to the matching universal kind.
   */
  private function nodeDeclaration(string $entity_type_id, ?string $bundle, string $kind = self::KIND_ENTITY): array {
    if ($entity_type_id === 'block_content') {
      return $bundle === NULL
        ? $this->declarations->getKind(self::KIND_BLOCK)
        : $this->declarations->getBlockType($bundle);
    }
    return $this->declarations->getKind($kind === self::KIND_BLOCK ? self::KIND_BLOCK : self::KIND_ENTITY);
  }

  /**
   * Builds a Presentation from a declared presentation section.
   *
   * A merged declaration without an explicit edit_target derives it exactly
   * as the configured tier does: the bundle's single plain-text field when
   * one is unambiguous.
   *
   * @param string|array $declared
   *   The declaration: a mode string, or a map with mode plus optional
   *   label and edit_target (validated at declaration build).
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   */
  private function declaredPresentation(string|array $declared, string $entity_type_id, string $bundle): Presentation {
    $mode = is_array($declared) ? $declared['mode'] : $declared;
    if ($mode !== Presentation::MERGED) {
      return new Presentation($mode);
    }
    $label = is_array($declared) ? ($declared['label'] ?? NULL) : NULL;
    $edit_target = is_array($declared) ? ($declared['edit_target'] ?? NULL) : NULL;
    return new Presentation(
      Presentation::MERGED,
      $label,
      $edit_target ?? $this->primaryTextField($entity_type_id, $bundle),
    );
  }

  /**
   * The bundle's primary plain-text field, when exactly one exists.
   *
   * The field a merged row's live label reads from: plain text only (string /
   * string_long), because list_string values are machine keys and link uris are
   * not titles. More than one candidate is ambiguous; the row falls back to the
   * component label.
   *
   * @return string|null
   *   The field machine name, or NULL when none or several qualify.
   */
  private function primaryTextField(string $entity_type_id, string $bundle): ?string {
    $candidates = array_filter(
      $this->contentFieldDefinitions($entity_type_id, $bundle),
      fn(FieldDefinitionInterface $definition) => in_array($definition->getType(), ['string', 'string_long'], TRUE),
    );
    return count($candidates) === 1 ? reset($candidates)->getName() : NULL;
  }

  /**
   * The bundle's setting: 'auto', 'unit', 'open', or 'merged'.
   */
  private function bundleSetting(string $entity_type_id, string $bundle): string {
    $bundle_entity_type = $this->entityTypeManager->getDefinition($entity_type_id)->getBundleEntityType();
    if ($bundle_entity_type === NULL) {
      return 'auto';
    }
    $bundle_entity = $this->entityTypeManager->getStorage($bundle_entity_type)->load($bundle);
    if ($bundle_entity === NULL || !method_exists($bundle_entity, 'getThirdPartySetting')) {
      return 'auto';
    }
    return $bundle_entity->getThirdPartySetting('daedalus_ui', self::SETTING, 'auto');
  }

  /**
   * The bundle's content-field definitions.
   *
   * FieldConfig instances, minus structure (layout_section).
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   Keyed by field name.
   */
  private function contentFieldDefinitions(string $entity_type_id, string $bundle): array {
    return array_filter(
      $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle),
      fn(FieldDefinitionInterface $definition) =>
        $definition instanceof FieldConfig
        && $definition->getType() !== 'layout_section',
    );
  }

  /**
   * Whether the bundle carries a nested layout field.
   */
  private function hasLayoutField(string $entity_type_id, string $bundle): bool {
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $definition) {
      if ($definition->getType() === 'layout_section') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
