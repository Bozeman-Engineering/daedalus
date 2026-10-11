<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerBase;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProse;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;

/**
 * The daedalus_blueprint field handler for the style map.
 *
 * This is the style map's single mutation point: the blueprint paths (full
 * create/update, field updates) and the update_style operation both land
 * here or in the StyleMapValidator it wraps, so the human panel and the AI
 * are validated identically.
 *
 * Serializes the map as structured JSON (not an opaque string) so an AI
 * reads and writes real style data, and exposes the style guide as field
 * schema additions. The guide is MATERIALS-FIRST: it enumerates the valid
 * space by the site's materials (the hook-landed materials roster) plus
 * the applicability story, every line of it read from the vocabulary
 * roster (VocabularyRosterBuilder, the one derivation of "what may this
 * address style") and rendered by the one prose generator
 * (VocabularyProse): bindings (site-wide property -> value-space lines),
 * deviations (only the places that narrow below the bindings), the
 * stylable entries (what every declared place may style, by property
 * name, in every posture) and one guidance paragraph carrying the
 * standing procedure and the polarity sentence. It answers the
 * designer's question (what materials exist, what applies where), never
 * the validator's: per-property grammar stays a server guarantee taught
 * lazily by correctives. The enforcement mode is OPAQUE to the AI: the
 * fragment states what is true of this site (the polarity sentence, the
 * roster sentence) and never that modes exist. The guide is STYLING
 * ONLY: the markup shells (labels, contains: slots, the composed compose
 * fact) are the element model's and ride the bundle schema's anatomy
 * fragment (daedalus_ui's daedalus_blueprint context feed), never a
 * second copy here.
 */
class StyleMapFieldHandler extends FieldHandlerBase {

  public function __construct(
    protected StyleMapValidator $validator,
    protected StyleGuide $styleGuide,
    protected PageBuilderDeclarations $declarations,
    protected ConditionTypePluginManager $conditionTypes,
    protected StyleValueResolver $valueResolver,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected VocabularyRosterBuilder $vocabulary,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_stylish_style_map';
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $item = $items->first();
    return $item instanceof StyleMapItem ? [$item->getStyleMap()] : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    if (is_string($value)) {
      $value = json_decode($value, TRUE);
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'INVALID_STYLE_MAP', 'The style map is not an object of selectable keys.', [], 'Provide an object mapping selectable keys to style entries, e.g. {"block:UUID": {"default": {"padding": "2rem"}}}.'),
      ]);
    }

    // The input tier resolves first (CHECK): handled-value objects and
    // layers lists become sound plain-CSS stand-ins, so the validator runs
    // unchanged and side-effect-free over an all-strings map. The commit
    // (where handlers perform their effects and the canonical strings land)
    // waits until validation succeeded, so a failed write mints nothing.
    // The map's host rides the options (every write door hands it, the
    // display host's store being a third-party setting with no entity of
    // its own); a content host's field write without one resolves the
    // entity being written, which IS the host of the map. The validator
    // resolves the map's block bundles and section layouts through it.
    $host = $options['host'] ?? NULL;
    if (!$host instanceof HostInterface) {
      $entity = $options['entity'] ?? NULL;
      $host = $entity instanceof FieldableEntityInterface ? $this->hostResolver->fromEntity($entity) : NULL;
    }
    $resolution = $this->valueResolver->check($value, $host, (string) ($options['conversation'] ?? ''));
    if ($resolution->getErrors()) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath("fields.$field_name." . $error->getPath()),
        $resolution->getErrors(),
      ));
    }

    $warnings = [];
    $errors = $this->validator->validate($resolution->getMap(), ['host' => $host], $warnings);
    if ($errors) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath("fields.$field_name." . $error->getPath()),
        $errors,
      ));
    }
    $value = $resolution->commit();

    // Compound condition keys were accepted in any part order; storage
    // keeps the canonical spelling (axis-declaration order) so identical
    // compounds can never fork into distinct keys.
    $value = $this->conditionTypes->normalizeMapConditions($value);

    // Canonical storage matches StyleMapItem::setStyleMap(): JSON text, and
    // an empty map is no value at all. The validator's observations (an
    // owned property written instead of worn) and commit-time handler
    // observations (e.g. a disabled capability's fallback) ride as
    // warnings, re-rooted onto the field's path like the errors.
    return new FieldHandlerResult($value === [] ? NULL : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [], [
      ...array_map(static fn (BlueprintWarning $warning) => new BlueprintWarning("fields.$field_name." . $warning->getPath(), $warning->getCode(), $warning->getMessage()), $warnings),
      ...$resolution->getWarnings(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    // The input tier is schema-taught only where a handler is registered:
    // teaching a marker no handler backs would guide the AI straight into
    // UNKNOWN_STYLE_VALUE_MARKER.
    $input_tier = '';
    if ($handlers = $this->valueResolver->getHandlerGuidance()) {
      $input_tier = ' A property value may also be written in the input tier: a handled-value object dispatched on its marker key, or, on a media-capable property, a LIST of layers (plain CSS strings and handled objects) that joins into the one comma-separated stored value. Handled values resolve at write time; what is stored and read back is always the plain CSS string. Handled values on this site: ' . implode(' ', $handlers);
    }
    return 'A style map: an object keyed by selectable key — the data-selectable value of a page element (entity:{uuid}, field:{uuid}:{field_name}, container:{uuid} — a Layout Builder section IS a container, and its regions are not addressable — block:{component_uuid}, block:{component_uuid}:{field_name}; append "::" and a relative selector to target inside the element: ::.class-name a declared part of the element, from the schema\'s anatomy trees (anatomy.kinds, anatomy.block_plugins, available_block_types[*].anatomy, available_layouts[*].anatomy), which model each element\'s markup shells; ::> * ALL its direct children at once; ::> pb-text the text blocks among them; ::p every paragraph inside it; ::&::before or ::&::after the element\'s own generated box, ::.class-name::after a part\'s, ::> pb-text::before each text block\'s (a box entry is born with content: "" at default, its existence switch: content takes "", none or one media reference url(\'media:<uuid>\') as the whole value and nothing else, since text belongs in a field; a content image shows at its own size, while background-image on the box sizes, positions and layers; content is refused anywhere but a box). ' . RelativeSelector::GRAMMAR . ' A "::" entry is a rule: its properties and its wear reach every element the selector matches under the key, never one element. The nearest element\'s own rule wins: a child\'s own entry beats an ancestor\'s children styles on conflict). Each entry may carry a "variants" reference list ({"variants": [{"variant": "variant_id", "fragment": ""}]}, ordered — later references take precedence, at most one reference per variant axis, and an element composes variants across axes; every reference is a {"variant": id, "fragment": slot-key} object — fragment "" wears the variant root, a non-empty fragment wears ONE fragment of a composition variant bound onto this element when the variant was applied to its container, and a fragment the variant no longer holds delivers nothing; a reference a composition variant\'s apply distributed onto this element also carries "via": that variant\'s id, the reference\'s provenance, so switching or removing the composition removes the reference while one applied directly to this element stays; never write fragment references or "via" by hand, apply the composition variant to the container instead; a RULE entry ("{key}::{selector}") wears a variant too, at every element it matches: the variant must be scoped for the rule\'s landing, the element its last compound names (::> pb-text wears text variants, ::a link variants; a part, ::> * and a subjectless compound land on no element and wear nothing), and a rule never wears a composition variant or a root-only axis) and/or condition-keyed CSS properties, e.g. {"default": {"padding": "2rem"}, "hover": {"background-color": "#eee"}} — "default" always applies, conditions on different axes combine with "+" into a compound that applies when every part does ("narrow+dark"; at most one condition per axis, any part order — writes normalize it), and an entry\'s own properties override its referenced variants. Values are plain semantic CSS; the field schema\'s style_guide key says what is valid on this site (the materials roster, the bindings and deviations lines, its guidance paragraph, and the valid condition names under conditions).' . $input_tier . ' This field\'s value in a read entity is the current styles; prefer the update_style, apply_variant, remove_variant, and detach_variant batch operations over writing the whole map — they validate one element\'s change precisely and leave the others untouched.';
  }

  /**
   * {@inheritdoc}
   */
  public function getRelevantSettings(array $settings): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    $theme = $this->declarations->siteTheme();
    $rosters = $this->rosters($theme);
    $style_guide = [];
    if ($bindings = $this->bindingLines($theme)) {
      $style_guide['bindings'] = $bindings;
    }
    if ($deviations = $this->deviationLines($rosters, $theme)) {
      $style_guide['deviations'] = $deviations;
    }
    $style_guide['stylable'] = $this->stylableEntries($rosters, $theme);
    $style_guide['guidance'] = $this->guidanceParagraph($theme);
    // The rich-text instance vocabulary rides only where the spine
    // declares a namespace (grows-by-data): the identity classes bodies
    // wear, the addressable tag tier, and the identity rules an AI
    // rewrite must keep.
    if ($this->styleGuide->getInstances($theme) !== []) {
      $style_guide['rich_text'] = $this->richTextVocabulary($theme);
    }
    $style_guide['conditions'] = $this->conditionsSchema();
    // Schemas are bundle-level and cached; per-entity data lives on the
    // entities, so point at it rather than embedding a stale copy.
    $style_guide['current_values'] = 'An entity\'s current styles are the top-level "styles" key of its blueprint when the entity is read — the same element-keyed map this schema describes.';
    // The markup model is the element model's, delivered once as the
    // schema's anatomy fragment; the guide only says what those selectors
    // may wear.
    $style_guide['anatomy'] = 'The markup shells behind these selectors (which wrapper contains what, and which block types compose to ONE root element — anatomy.composed: true) are the schema\'s anatomy trees (anatomy.kinds, anatomy.block_plugins, available_block_types[*].anatomy, available_layouts[*].anatomy), not repeated here.';
    return ['style_guide' => $style_guide];
  }

  /**
   * The rich-text instance vocabulary: one lean paragraph.
   *
   * States the instance namespace(s), the addressable tag vocabulary (the
   * site's base tags, the one declaration of a prose tag's styleability),
   * the three key spellings ({key}::.s-x one element, {key}::tag all of
   * an element's text elements of a declared base tag, {key}::.s-x > *
   * ONE list's items — the one-list gesture beside the li tag tier once
   * li is declared), the identity preservation rule for body rewrites, authored
   * identity for create-and-style, and the honesty line: every OTHER tag
   * is the text format's business (its allowlist strips at render) and is
   * never a styling target. Per-format allowed-tag rosters are
   * deliberately not serialized: the payload cost of enumerating every
   * format's allowlist outweighs a fact the format already enforces
   * loudly at save.
   */
  protected function richTextVocabulary(?string $theme): string {
    $instances = $this->styleGuide->getInstances($theme);
    $namespaces = implode(', ', array_keys($instances));
    $tags = $this->styleGuide->baseTags($theme);
    return sprintf(
      'Rich-text bodies style by element. The identity classes in a body (the %s namespace) address its elements: {key}::.s-x styles that ONE element, {key}::tag styles ALL the element\'s text elements of a declared tag (present and future), and {key}::.s-x > * styles ONE list\'s items (that element\'s direct children). Addressable tags: %s. The s-x classes are element IDENTITIES: keep them on elements you are keeping when rewriting a body (a dropped class orphans every style targeting it), and when you create rich text you intend to style per element, author your own classes in the namespace (one class names ONE element; the identity minter honors authored identity). Any other tag in a body is governed by the text format\'s allowed tags and strips at render; only the declared tags above are styling targets.',
      $namespaces,
      $tags === [] ? 'none (the site declares no base tags)' : implode(', ', $tags),
    );
  }

  /**
   * The guidance paragraph: the standing procedure plus the polarity.
   *
   * The designer-facing frame, stated once: the preference order over the
   * materials (MaterialsProse, the one source the design system brief
   * shares), the media-token value rule (derived: a site whose granted
   * sets carry no media-capable property is never taught the token), and
   * the polarity sentence saying what this site accepts (VocabularyProse,
   * the one source the design system brief's Law line shares). Everything
   * mode-variant in it is DERIVED from the site's posture; no mode is
   * ever named. Blueprints are named among the materials only while the
   * roster has some at block scope (grows-by-data; a page blueprint is a
   * start, listed under the schema's starts, never a material to place).
   */
  protected function guidanceParagraph(?string $theme): string {
    $guidance = 'The site\'s materials (the named values and saved variants' . ($this->rosterBuilder->build()->blueprints(MaterialRecord::SCOPE_BLOCK) !== [] ? ', and the blueprints' : '') . ' under the schema\'s materials key) are the design language\'s pre-validated parts. ' . MaterialsProse::preferenceSentence() . ' Standing procedure: when no material fits, compose freely with blocks and styles; novel styling stays on this page; if something deserves to join the design system, propose it and let the user decide.';
    if ($media = $this->styleGuide->mediaProperties($theme)) {
      $guidance .= sprintf(' %s also accept%s an image media reference url(\'media:<uuid>\'): store the token, never a file URL (the file URL and its image-style derivative resolve at render); the token composes with gradients and layers wherever the property\'s own grammar takes them.', implode(', ', $media), count($media) > 1 ? '' : 's');
    }
    return $guidance . ' ' . $this->prose()->polaritySentence();
  }

  /**
   * The prose generator for the site's current posture.
   */
  protected function prose(): VocabularyProse {
    return VocabularyProse::of($this->styleGuide);
  }

  /**
   * The bindings map: the site's own site-wide bindings, one line each.
   *
   * Every authored constraint_defaults block (StyleGuide::
   * constraintDefaults()) rendered as a designer-facing value-space
   * line, keyed by CSS property: "font-size: text values", not a
   * constraint-grammar dump. The polarity sentence says whether these
   * lines bind or advise; the lines themselves never do. Only what the
   * site declares site-wide: a node's own block is that node's value
   * space and rides the deviations lines.
   *
   * @return array<string, string>
   *   Property => line; [] when the site declares no site-wide blocks.
   */
  protected function bindingLines(?string $theme): array {
    $lines = [];
    foreach ($this->styleGuide->constraintDefaults($theme) as $property => $space) {
      $lines[$property] = VocabularyProse::valueSpaceLine($space);
    }
    return $lines;
  }

  /**
   * The roots this fragment's rosters cover, in teaching order.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address[]
   *   The root addresses: the universal kinds, then every declared block
   *   type, block plugin and layout.
   */
  protected function roots(?string $theme): array {
    $roots = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      $roots[] = Address::kind($kind);
    }
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $roots[] = Address::blockType($bundle);
    }
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $roots[] = Address::blockPlugin($plugin_id);
    }
    foreach ($this->styleGuide->getLayouts($theme) as $layout_id) {
      $roots[] = Address::layout($layout_id);
    }
    return $roots;
  }

  /**
   * This fragment's label for a root address.
   *
   * The style map's fragment addresses roots as kinds.container,
   * block_types.button, block_plugins.system_menu_block and
   * layouts.layout_onecol; the scope-keyed fragments (variant styles,
   * skin defaults, through ScopeKeyedSchemaTrait) label a root by its
   * address print, the write grammar of their scope field.
   */
  protected function rootLabel(Address $root): string {
    return match ($root->family) {
      Address::FAMILY_KIND => 'kinds.' . $root->id,
      Address::FAMILY_BLOCK_TYPE => 'block_types.' . $root->id,
      Address::FAMILY_BLOCK_PLUGIN => 'block_plugins.' . $root->id,
      Address::FAMILY_LAYOUT => 'layouts.' . $root->id,
      Address::FAMILY_BASE => (string) $root,
    };
  }

  /**
   * The label of any address under a root.
   *
   * The root's label, then the style map's own suffix ("::" and the
   * relative selector), the key grammar of the map itself.
   */
  protected function label(Address $address): string {
    $root = (string) $address->root();
    return $this->rootLabel($address->root()) . substr((string) $address, strlen($root));
  }

  /**
   * The rosters this fragment teaches: every root and every place under it.
   *
   * @return array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster>
   *   Label => roster: each root in teaching order, followed by its
   *   children pseudo-part and its declared parts (at any authored
   *   depth, flat, the map's key grammar) with their own children.
   */
  protected function rosters(?string $theme): array {
    $rosters = [];
    foreach ($this->roots($theme) as $root) {
      foreach ($this->addressesUnder($root, $theme) as $address) {
        $rosters[$this->label($address)] = $this->vocabulary->build($address, $theme);
      }
    }
    return $rosters;
  }

  /**
   * A root and every declared place under it, the roster deciding.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address[]
   *   The root, its children pseudo-part when declared, then each part
   *   with its own children pseudo-part when declared.
   */
  protected function addressesUnder(Address $root, ?string $theme): array {
    $addresses = [$root];
    $roster = $this->vocabulary->build($root, $theme);
    if ($roster->childrenDeclared()) {
      $addresses[] = Address::of($root->family, $root->id, '> *');
    }
    foreach (array_keys($roster->parts()) as $selector) {
      $part = Address::of($root->family, $root->id, (string) $selector);
      $addresses[] = $part;
      if ($this->vocabulary->build($part, $theme)->childrenDeclared()) {
        $addresses[] = Address::of($root->family, $root->id, $selector . ' > *');
      }
    }
    return $addresses;
  }

  /**
   * The deviations map: only the places that narrow below the bindings.
   *
   * Each place's value spaces (VocabularyRoster::valueSpaces(), the law
   * the validator judges that address by) as ONE line at its label
   * ("block_types.button: background-color limited to brand-1,
   * brand-2"); a property whose space merely repeats the site's own
   * binding stays silent. Empty (and absent from the fragment) on a site
   * whose nodes constrain nothing beyond the bindings.
   *
   * @param array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster> $rosters
   *   Label => roster (rosters()).
   * @param string|null $theme
   *   The theme the rosters were built for, or NULL for the default.
   *
   * @return array<string, string>
   *   Label => deviation line.
   */
  protected function deviationLines(array $rosters, ?string $theme): array {
    $bindings = $this->styleGuide->constraintDefaults($theme);
    $lines = [];
    foreach ($rosters as $label => $roster) {
      $phrases = [];
      foreach ($roster->valueSpaces() as $property => $space) {
        if (array_key_exists($property, $bindings) && $bindings[$property] == $space) {
          continue;
        }
        $phrases[] = $property . ' ' . VocabularyProse::deviationPhrase($space, $bindings[$property] ?? NULL);
      }
      if ($phrases) {
        $lines[$label] = implode('; ', $phrases);
      }
    }
    return $lines;
  }

  /**
   * The stylable section: what every declared place may style, by name.
   *
   * Served in every posture; the roster is the design system's
   * vocabulary whatever the site does outside it, and the roster
   * sentence (the one posture-variant sentence) says what that is. The
   * entries are the prose generator's phrases (the widest roster in
   * full, the others as deltas against it) over every place the
   * fragment covers, leaned: a refinement's place that restates its
   * universal kind's is omitted, the entry grammar saying so.
   *
   * @param array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster> $rosters
   *   Label => roster (rosters()).
   * @param string|null $theme
   *   The theme the rosters were built for, or NULL for the default.
   *
   * @return array{guidance: string, entries: array<string, string>}
   *   The entry grammar and the label => phrase map.
   */
  protected function stylableEntries(array $rosters, ?string $theme): array {
    $taught = [];
    foreach ($rosters as $label => $roster) {
      if (!$this->restatesKind($roster, $theme)) {
        $taught[$label] = $roster;
      }
    }
    return [
      'guidance' => $this->prose()->rosterSentence() . '. ' . $this->stylableGrammar($theme),
      'entries' => $this->prose()->phrases($taught),
    ];
  }

  /**
   * Whether a refinement's place restates its universal kind's same place.
   *
   * A block type, block plugin or layout whose roster at a place (its
   * root, a part, the children) grants exactly what its kind grants
   * there says nothing the kind entry does not, and is omitted rather
   * than restated. A kind's own place, and a base tag's, never restate.
   */
  protected function restatesKind(VocabularyRoster $roster, ?string $theme): bool {
    $address = $roster->address();
    $kind = $address->elementKind();
    if ($address->isKind() || $kind === NULL) {
      return FALSE;
    }
    $twin = $this->vocabulary->build(Address::of(Address::FAMILY_KIND, $kind, $address->selector), $theme);
    return $twin->declared() && $twin->granted() === $roster->granted();
  }

  /**
   * The entry grammar of the stylable section, for this fragment's keys.
   *
   * @param string|null $theme
   *   The theme the entries were built for, or NULL for the default.
   */
  protected function stylableGrammar(?string $theme): string {
    return 'What each declared place may style, by property name: an entry per element (kinds.block, block_types.text, block_plugins.system_menu_block, layouts.layout_onecol), its declared sub-elements ("{key}::.class-name") and its direct children ("{key}::> *"). A block type, block plugin, or layout absent here at one of those places styles exactly what its universal kind does there (kinds.block; kinds.container for layouts); a sub-element or children key absent here is not declared there. ' . $this->selectorGrammar('{key}', $theme);
  }

  /**
   * The relative-selector grammar for a fragment's keys, types enumerated.
   *
   * The one grammar sentence (RelativeSelector::GRAMMAR) followed by
   * where a type or tag compound's rules are judged (the named type's
   * own entry, the base tag's) with every type spelling this site
   * declares, so the JSON door enumerates the types the way the cached
   * prompt does.
   *
   * @param string $key
   *   The key placeholder the fragment spells ("{key}", "{scope}").
   * @param string|null $theme
   *   The theme the declarations are read for, or NULL for the default.
   */
  protected function selectorGrammar(string $key, ?string $theme): string {
    $types = [];
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $types[] = RelativeSelector::typeName(Address::blockType($bundle));
    }
    $types[] = RelativeSelector::TYPE_CONTAINER;
    $types[] = RelativeSelector::TYPE_BLOCK;
    return RelativeSelector::GRAMMAR . sprintf(' A type compound ("%s::> pb-text"; types: %s) styles against the named type\'s own entry, a tag compound ("%s::p") against the base tag\'s.', $key, implode(', ', $types), $key);
  }

  /**
   * The valid condition vocabulary, with its CSS wrappers and axes.
   *
   * Condition names are a closed set (the condition type plugins); exposing
   * the live table — thresholds included — lets an AI know exactly when
   * each condition applies. The axis is the compound lane: conditions on
   * different axes combine with "+" ("narrow+dark"), at most one per axis.
   * A structural fact (fact: true) qualifies the addressed element by its
   * place among its siblings: it is never an interaction state.
   *
   * @return array
   *   Condition name => {wrapper, axis, fact?} ("always applies" for
   *   default).
   */
  protected function conditionsSchema(): array {
    $conditions = ['default' => 'always applies'];
    foreach ($this->conditionTypes->getConditions() as $name => $definition) {
      $conditions[$name] = ['wrapper' => $definition['wrapper'], 'axis' => $definition['axis']]
        + (($definition['fact'] ?? FALSE) === TRUE ? ['fact' => TRUE] : []);
    }
    return $conditions;
  }

}
