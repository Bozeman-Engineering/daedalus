<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;

/**
 * The daedalus_blueprint field handler for a variant's styles.
 *
 * The blueprint lane of the one variant-styles road: serializes the styles
 * as a structured element-relative map (not an opaque JSON string) and
 * validates writes through the validator's one door
 * (StyleMapValidator::validateAt() at the variant's scope), the same gate
 * the variant gestures (VariantManager) and disk-shipped variants converge
 * on, so an AI authoring a variant through the generic create/update tools
 * is validated identically to every other surface. The scope string a
 * payload submits is the one untrusted address on this road, so this
 * handler is the variant door's string gate (StyleMapValidator::
 * variantScope()) ahead of the address door, and the AI schema fragment
 * it serves teaches the roster of every variant scope through the one
 * prose generator (VocabularyProse), never a projection of its own.
 *
 * A sibling of StyleMapFieldHandler, deliberately: the key grammar differs
 * ('' for the root element plus '::.class-name' sub-elements, vs full
 * selectable keys) and validation reads the vocabulary roster of the
 * variant's DECLARED scope, never an address derived from a host entity's
 * layout. The scope is read from the submitted sibling fields first (the
 * scope this write is establishing), then from the entity being written,
 * so blueprint payload key order never matters.
 */
class VariantStylesFieldHandler extends StyleMapFieldHandler {

  use DesignSystemOptionTrait;
  use ScopeKeyedSchemaTrait;

  /**
   * Constructs the variant styles field handler.
   *
   * @param \Drupal\daedalus_stylish\StyleMapValidator $validator
   *   The style map validator.
   * @param \Drupal\daedalus_stylish\StyleGuide $styleGuide
   *   The style guide.
   * @param \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations $declarations
   *   The page builder declarations.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager.
   * @param \Drupal\daedalus_stylish\StyleValueResolver $valueResolver
   *   The style value resolver.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder $rosterBuilder
   *   The materials roster builder.
   * @param \Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder $vocabulary
   *   The vocabulary roster builder.
   * @param \Closure $importer
   *   Service closure resolving the variant importer, the distro lock's
   *   disk read (does the provider ship this entry right now?). Lazy
   *   because field handlers instantiate with the handler manager the
   *   deserializer needs, and the importer injects that deserializer —
   *   the same cycle StyleMapValidator breaks for the component locator.
   * @param \Drupal\daedalus_blueprint\Materials\DistroLock $distroLock
   *   The distro lock: whether shipped materials are read-only on this
   *   site. Read live on every gate and guidance call, never cached.
   */
  public function __construct(
    StyleMapValidator $validator,
    StyleGuide $styleGuide,
    PageBuilderDeclarations $declarations,
    ConditionTypePluginManager $conditionTypes,
    StyleValueResolver $valueResolver,
    MaterialsRosterBuilder $rosterBuilder,
    VocabularyRosterBuilder $vocabulary,
    protected \Closure $importer,
    protected DistroLock $distroLock,
  ) {
    parent::__construct($validator, $styleGuide, $declarations, $conditionTypes, $valueResolver, $rosterBuilder, $vocabulary);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_variant_styles';
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();

    // The distro lock's blueprint-lane gate: a variant with a LIVING disk
    // source (its provider ships the entry right now) is read-only, so a
    // generic update addressing it refuses before any style validation —
    // the same lock VariantManager::checkShippedLock() enforces on push.
    // The importer's own sync passes shipped_sync (VariantImporter
    // deserializes through this handler too; disk is the one author the
    // lock protects, so its writes are the exception, not a bypass). The
    // gate lifts with the distro lock (DistroLock): a development site
    // authoring the package edits its shipped variants here.
    $entity = $options['entity'] ?? NULL;
    if ($this->distroLock->holds() && $entity instanceof DaedalusStylishVariantInterface && !$entity->isNew() && empty($options['shipped_sync']) && ($this->importer)()->getShippedStyles($entity) !== NULL) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'STYLE_VARIANT_SHIPPED_LOCKED', sprintf('The "%s" variant is shipped by the "%s" module; shipped variants are read-only.', $entity->getMachineName(), (string) $entity->getProvider()), ['provider' => (string) $entity->getProvider()], 'Copy it as a new site variant (copy the styles into a create payload, or the UI\'s "Copy as my variant") and edit the copy, or change the shipped defaults in the module\'s variants file.'),
      ]);
    }

    if (is_string($value)) {
      $value = json_decode($value, TRUE);
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'INVALID_VARIANT_STYLES', 'The variant styles are not an object of element-relative keys.', [], 'Provide an object mapping element-relative keys to style entries: "" for the root element, "::.class-name" for a sub-element, e.g. {"": {"default": {"padding": "2rem"}}}.'),
      ]);
    }

    $scope = $this->resolveScope($options);
    if ($scope === NULL) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'VARIANT_SCOPE_REQUIRED', 'The variant styles cannot be validated without the variant\'s scope.', [], 'Set the scope field ("block_type:{bundle}", "layout:{layout_id}", "kind:{entity|container|field|block}", or "base:{tag}" for a bare-markup tag treatment) in the same payload; the styles validate against that scope\'s style guide declaration.'),
      ]);
    }
    // A bad scope surfaces on the styles: they validate against it, and a
    // string outside the variant scope vocabulary names no address to
    // validate at.
    $address = StyleMapValidator::variantScope($scope);
    if ($address === NULL) {
      return new FieldHandlerResult(NULL, [
        StyleMapValidator::invalidScopeError($scope)->withPath("fields.$field_name.scope"),
      ]);
    }

    // A submitted sibling axis gets the corrective error here, in the
    // blueprint lane, instead of the save-time exception that backstops it
    // (DaedalusStylishVariant::preSave()): both resolve the vocabulary through
    // scopeAxes(), so the two checks can never disagree.
    if ($axis_error = $this->checkSubmittedAxis($options, $scope)) {
      return new FieldHandlerResult(NULL, [$axis_error]);
    }

    // The input tier resolves first (CHECK), exactly as in the style-map
    // lane: stand-ins keep validateAt() unchanged and side-effect-free,
    // and the commit below only runs after validation succeeded, so
    // a failed write mints nothing. A variant authored with a handled
    // layer stores the resolved canonical string like any other value.
    $resolution = $this->valueResolver->check($value, $entity instanceof FieldableEntityInterface ? $entity : NULL);
    if ($resolution->getErrors()) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath(
          $error->getPath() === ''
            ? "fields.$field_name"
            : "fields.$field_name" . (str_starts_with($error->getPath(), '::') ? $error->getPath() : '.' . $error->getPath()),
        ),
        $resolution->getErrors(),
      ));
    }

    $errors = $this->validator->validateAt($resolution->getMap(), $address, [
      'variant_identity' => $this->variantIdentity($options),
      'variant_axis' => $this->resolveAxis($options),
      'design_system' => $this->submittedDesignSystem($options),
    ]);
    if ($errors) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath(
          $error->getPath() === ''
            ? "fields.$field_name"
            : "fields.$field_name" . (str_starts_with($error->getPath(), '::') ? $error->getPath() : '.' . $error->getPath()),
        ),
        $errors,
      ));
    }

    // COMMIT: handled values perform their effects and their canonical
    // resolved strings replace the stand-ins.
    $value = $resolution->commit();

    // Compound condition keys were accepted in any part order; storage
    // keeps the canonical spelling (axis-declaration order), the same
    // normalization the style map's write paths apply.
    $value = $this->conditionTypes->normalizeMapConditions($value);

    // Canonical storage matches VariantStylesItem::setStyleMap(): JSON text,
    // and an empty map is no value at all. Commit-time handler observations
    // (e.g. a disabled capability's fallback) ride as warnings.
    return new FieldHandlerResult($value === [] ? NULL : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [], $resolution->getWarnings());
  }

  /**
   * Resolves the scope the styles validate against.
   *
   * The submitted sibling 'scope' value wins — it is the scope the variant
   * will have after this write — with the entity's stored scope as the
   * fallback for payloads that only touch the styles. NULL when neither is
   * available; there is no degraded validation for variant styles, because a
   * scope-less variant can never be saved (DaedalusStylishVariant::preSave()).
   */
  protected function resolveScope(array $options): ?string {
    $submitted = $options['fields']['scope'] ?? NULL;
    if (is_array($submitted) && isset($submitted['value'])) {
      $submitted = $submitted['value'];
    }
    if (is_string($submitted) && $submitted !== '') {
      return $submitted;
    }
    $entity = $options['entity'] ?? NULL;
    if ($entity instanceof FieldableEntityInterface && $entity->hasField('scope') && !$entity->get('scope')->isEmpty()) {
      return (string) $entity->get('scope')->value;
    }
    return NULL;
  }

  /**
   * The axis the saved variant will live on, for the root-entry axis gate.
   *
   * Sibling-field reads win exactly as resolveScope()'s do: the submitted
   * axis is the axis this write establishes, the entity's stored axis
   * covers styles-only payloads, and an absent axis lands on the built-in
   * "style" axis exactly as DaedalusStylishVariant::preSave() defaults it. An
   * INVALID submitted axis never reaches here — checkSubmittedAxis()
   * errored first.
   */
  protected function resolveAxis(array $options): string {
    $axis = $options['fields']['axis'] ?? NULL;
    if (is_array($axis) && isset($axis['value'])) {
      $axis = $axis['value'];
    }
    if (is_string($axis) && $axis !== '') {
      return $axis;
    }
    $entity = $options['entity'] ?? NULL;
    if ($entity instanceof FieldableEntityInterface && $entity->hasField('axis') && !$entity->get('axis')->isEmpty()) {
      return (string) $entity->get('axis')->value;
    }
    return StyleGuide::STYLE_AXIS;
  }

  /**
   * The saved variant's own reference identities, for the cycle walk.
   *
   * A nested reference chain that leads back to the variant being written
   * must fail at save (validateAt()'s checkReferenceCycles()), so
   * the walk needs to recognize this variant among the references: by its
   * stored machine name and uuid (updates), and by a machine name the
   * same payload is submitting (the name this write is establishing —
   * sibling-field reads win exactly as resolveScope()'s do).
   *
   * @return string[]
   *   The identities; empty for a brand-new unnamed variant (a
   *   self-reference then already fails as dangling — the name resolves
   *   to nothing until the save lands).
   */
  protected function variantIdentity(array $options): array {
    $identity = [];
    $submitted = $options['fields']['machine_name'] ?? NULL;
    if (is_array($submitted) && isset($submitted['value'])) {
      $submitted = $submitted['value'];
    }
    if (is_string($submitted) && $submitted !== '') {
      $identity[] = $submitted;
    }
    $entity = $options['entity'] ?? NULL;
    if ($entity instanceof FieldableEntityInterface) {
      if ($entity->hasField('machine_name') && !$entity->get('machine_name')->isEmpty()) {
        $identity[] = (string) $entity->get('machine_name')->value;
      }
      if (is_string($entity->uuid()) && $entity->uuid() !== '') {
        $identity[] = $entity->uuid();
      }
    }
    return array_values(array_unique($identity));
  }

  /**
   * Checks a submitted sibling axis against the scope's axis vocabulary.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   The corrective error, or NULL when no axis was submitted or it is
   *   valid for the scope. An absent or empty axis is fine: the entity
   *   defaults it to the built-in "style" axis at save.
   */
  protected function checkSubmittedAxis(array $options, string $scope): ?BlueprintError {
    $axis = $options['fields']['axis'] ?? NULL;
    if (is_array($axis) && isset($axis['value'])) {
      $axis = $axis['value'];
    }
    if (!is_string($axis) || $axis === '') {
      return NULL;
    }
    $axes = $this->validator->scopeAxes($scope);
    if ($axes === NULL || in_array($axis, $axes, TRUE)) {
      return NULL;
    }
    return new BlueprintError('fields.axis', 'STYLE_VARIANT_AXIS_INVALID', sprintf('"%s" is not an axis of the "%s" scope.', $axis, $scope), ['valid_axes' => $axes], sprintf('Valid axes for this scope: %s. Omit the axis field for the built-in "style" axis.', implode(', ', $axes)));
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    $input_tier = $this->valueResolver->getHandlerGuidance() === []
      ? ''
      : ' The style map\'s input tier (handled-value objects and layers lists) applies to these values identically.';
    // The lock sentence follows the distro lock: on a development site
    // (the gate lifted) shipped variants update like any other, so the
    // guidance says nothing about them.
    $lock = $this->distroLock->holds()
      ? ' Module-shipped variants are READ-ONLY (the distro lock): updating their styles is refused; to change such a look, create a NEW variant with the styles you want (copying the shipped variant\'s styles as a starting point if useful) and apply that instead.'
      : '';
    return 'A variant\'s styles: an object of element-relative keys — "" for the variant\'s root element, "::.class-name" for a sub-element its scope declares — each mapping conditions to CSS properties, e.g. {"": {"default": {"padding": "2rem"}}, "::.cta-text": {"default": {"align-self": "center"}}}. Values are plain semantic CSS; "default" always applies. Media-capable properties accept url(\'media:<uuid>\') image media references here exactly as in the style map (store the token, never a file URL).' . $input_tier . ' The styles validate against the style guide declaration of the variant\'s scope field ("block_type:{bundle}", "block_plugin:{plugin_id}" for plugin blocks such as menus and site branding, "layout:{layout_id}", or "kind:{entity|container|field|block}"), so set scope in the same payload; the field schema\'s style_guide key says what is valid (its scopes catalog, bindings and deviations lines, guidance paragraph, and the condition names under conditions). An entry may also carry a "variants" reference list exactly like a style map entry ({"variants": [{"variant": "machine_name", "fragment": ""}]}, at most one reference per axis): applying the variant distributes each nested reference onto the element its entry\'s slot resolves to, stamped with this variant\'s id as its "via" (the style map\'s provenance: a later switch or removal of this variant removes what it distributed and nothing applied by hand); a nested reference here never carries "via" itself. A nested reference must name an EXISTING variant scoped to the slot\'s element kind, and reference chains must stay acyclic — a variant can never reference itself, directly or through other variants. A variant also carries an axis field, the axis it competes on within its scope: an element wears at most one variant per axis and composes variants across axes. Omit axis for the built-in "style" axis; a scope\'s style_guide.scopes entry lists its additional axes under variant_axes.' . $lock;
  }

  /**
   * {@inheritdoc}
   */
  protected function scopeGuidance(): string {
    return 'A variant\'s styles validate against the same guide as element styles, per its scope: the materials, bindings, deviations and stylable entries here are the one vocabulary. scopes is the catalog of valid scope field values; an entry listing variant_axes accepts those axes beside the built-in "style" axis.';
  }

  /**
   * {@inheritdoc}
   */
  protected function stylableGrammar(): string {
    return 'What each scope\'s element may style, by property name: an entry per scope value, its declared sub-elements ("{scope}::.class-name", the keys variant styles may carry) and its direct children ("{scope}::>"). A block type, block plugin, or layout scope absent here at one of those places styles exactly what its kind scope does there (kind:block; kind:container for layouts); a sub-element or children key absent here is not declared there.';
  }

  /**
   * {@inheritdoc}
   *
   * The variant lane's catalog is the write grammar of the scope field:
   * scope value => entry, unconditional, so a block type or layout with
   * nothing to say still appears. An entry carries the scope's variant
   * axes (the roster's, "style" first, matching class-emission order) when
   * the scope declares any beside the built-in axis; axis is authoring
   * vocabulary, not styling law. Only the roots are catalogued.
   */
  protected function catalogEntries(array $rosters): array {
    $catalog = [];
    foreach ($rosters as $scope => $roster) {
      if (!$roster->address()->isRoot()) {
        continue;
      }
      $entry = [];
      $axes = $roster->axisNames();
      if ($axes !== [StyleGuide::STYLE_AXIS]) {
        $entry['variant_axes'] = $axes;
      }
      $catalog[$scope] = $entry;
    }
    return ['scopes' => $catalog];
  }

}
