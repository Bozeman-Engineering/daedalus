<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Capability\Requirement;
use Drupal\daedalus_blueprint\Dialect\PageLanguageOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\PageOnlyOperationsInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\daedalus_stylish\Plugin\PropertyGroup\GeneratedContent;

/**
 * Registers Daedalus Stylish's batch operations with daedalus_blueprint.
 *
 * Four element operations join the batch grammar, all built on the layered
 * entry model: an entry's own condition-keyed properties are OVERRIDES that layer
 * over the variants its "variants" list references and win on conflict, so
 * following a variant and tweaking on top are not mutually exclusive.
 * - update_style: a PATCH against one selectable key's styles. Values merge
 *   into the keyed entry under a condition, null values unset. The entry's
 *   variant references stay untouched — the write lands as overrides on
 *   top of them. The one birth default of the op spine lives here: a
 *   pseudo-element entry ("::&::before", "::> pb-text::after") that the
 *   patch creates is born with content: "" at default, the existence
 *   switch of a generated box, unless the write sets content there
 *   itself; an existing entry is never re-seeded.
 * - apply_variant: dresses the element in a variant. Every variant lives on
 *   one axis and an element wears at most one variant per axis, so the
 *   reference replaces the entry's existing same-axis reference and
 *   composes with references on other axes; the entry's overrides and its
 *   sub-element entries are untouched. A COMPOSITION variant (one whose
 *   styles hold slot paths) also distributes at apply time: each slot path
 *   resolves once against the target's current subtree and writes a
 *   {variant, fragment} reference onto the resolved child's own entry —
 *   position becomes identity at the mutation moment, and the references
 *   are bound once (they ride their elements anywhere, never re-resolve).
 *   Replacing a same-axis variant removes the references it distributed
 *   over the current subtree along with its root reference. Variant
 *   entries are ENTRY-SHAPED: a fragment (the root entry included) may
 *   carry a "variants" reference list, and apply copies each nested
 *   reference onto the element the carrying entry resolves to, as a real
 *   map reference on the nested variant's own axis STAMPED with its
 *   origin ({variant, fragment, via}: "via" names the variant that
 *   distributed it): bind-once, so render, compile, and delivery only
 *   ever see real map references, and the origin is what tells a wear
 *   the composition put there from one the editor applied by hand (a
 *   direct apply never writes "via"; re-applying the composition
 *   re-stamps). Replace and remove on a composition root reap, over the
 *   current subtree, every reference whose "via" names the leaving
 *   variant, recursively for each reaped reference that was itself a
 *   composition root, so a switch takes exactly what the leaving
 *   composition nested and nothing the editor authored. A nested
 *   reference sharing its carrier fragment's axis lands BESIDE the
 *   fragment reference (the composition pair: the fragment's values are
 *   the captured overrides that already beat it — placement spares the
 *   pair, delivery orders roots under fragments within an axis); a nested
 *   COMPOSITION distributes recursively over the resolved child's own
 *   subtree. Integrity is judged at save and at the roster, never at
 *   render: the judged roster is the only door to apply, so the one
 *   nested reference that can still fail to resolve here names a
 *   variant the roster withholds or excludes, and it is skipped with a
 *   warning on the result (distributeEntryRefs()).
 * - remove_variant: the undress gesture. One reference drops WITHOUT
 *   materializing, so the element stops rendering that variant's styles
 *   entirely (the look changes — that is the point). The entry's own
 *   overrides, its references on other axes, and its sub-element entries
 *   all stay: an override written while the variant was worn goes inert
 *   rather than lost, and re-applying the variant restores the whole
 *   layered look. The layout widget's mode-off flip is the canonical
 *   caller (Columns back to Stack removes the container-layout reference and
 *   leaves the children's flex-grow overrides inert in the map).
 * - detach_variant: the explicit unlink gesture. One reference's styles
 *   materialize into overrides (existing values win — they already beat
 *   the variant) and the reference drops, so the element keeps its look
 *   but stops following the variant's future updates. References are
 *   identity-bound, so materialization is purely LOCAL: the worn fragment
 *   is a lookup key into the variant, and its styles land on the carrying
 *   element's own keys with no slot resolution. Detaching a root wearing
 *   is additionally the group gesture (detach-all): the fragment
 *   references the variant distributed over the target's CURRENT subtree
 *   materialize onto their own carriers and drop with it. An entry-shaped
 *   fragment's nested references resolve to VALUES through the wearer's
 *   own map: apply distributed them as real references on the carrier,
 *   which are not the detached variant's and so survive the detach and
 *   keep delivering beneath the materialized values; detach RELEASES
 *   their provenance (the "via" naming the detached composition drops:
 *   the composition is gone, the child owns the wear now), the fragment
 *   lookup itself stays values-only, and no detach ever mints or strands
 *   a nested reference.
 *
 * A fifth operation is the site-scope twin of update_style:
 * - update_default: a PATCH against one SCOPE's default styles on the
 *   active skin ({scope, key, condition, properties}: "base:h2",
 *   "kind:container", "block_type:text", "block_plugin:system_menu_block",
 *   "layout:layout_onecol"; the key is "" for the scope's element or "::"
 *   plus a relative selector under it). Values merge into the scope's
 *   entry under a condition, null values unset. The write lands where
 *   every skin gesture lands (SkinManager::writesSiteLayer()): the skin's
 *   SITE layer on a consuming site, so a package update still shows
 *   through and unsetting a site value reveals the package's; the skin's
 *   one layer on the site's own skin or under the developer gate. Every
 *   property set is validated through the defaults seam
 *   (StyleMapValidator::validateDefaults()) against the skin's tokens AS
 *   WORN (the merge of both layers), the same gate the disk lane's sync
 *   takes. The gesture is a revisioned save of the skin, gated by "edit
 *   skin defaults"; the batch's own entity is only the page the gesture
 *   rides (a default has no home but the skin), exactly as a material
 *   gesture rides a page. It is a page-language op (page_edit admits it:
 *   the page language spells no skin default), while the four element ops
 *   are page-only (the page language spells them as CSS and class
 *   attributes).
 *
 * Both interfaces (the inspector panel's op queue and the AI's batch tool)
 * land here, and everything written is validated by the same
 * StyleMapValidator that guards whole-map writes through the field handler.
 * Only the values being set are validated — unsetting is always allowed, so
 * a tightened style guide never blocks cleaning up styles it no longer
 * permits.
 */
class DaedalusStylishOperationHandler implements PageOnlyOperationsInterface, PageLanguageOperationsInterface {

  /**
   * The site defaults op.
   */
  public const UPDATE_DEFAULT = 'update_default';

  /**
   * Constructs the operation handler.
   *
   * @param \Drupal\daedalus_stylish\StyleMapValidator $validator
   *   The style map validator.
   * @param \Drupal\daedalus_blueprint\Access\BlueprintAccessChecker $accessChecker
   *   The blueprint access checker.
   * @param \Drupal\daedalus_stylish\SubtreeSlotKeys $subtreeSlotKeys
   *   The subtree slot key resolver.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager.
   * @param \Drupal\daedalus_stylish\StyleValueResolver $valueResolver
   *   The style value resolver.
   * @param \Drupal\daedalus_stylish\SkinManager $skinManager
   *   The skin manager: update_default's active skin, layer rule and
   *   revisioned save.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user, update_default's permission gate.
   * @param iterable<\Drupal\daedalus_stylish\VariantApplicabilityCheckerInterface> $applicabilityCheckers
   *   The applicability seam (tag daedalus_stylish.variant_applicability_checker):
   *   cross-module vetoes consulted before a NEW apply of a variant.
   */
  public function __construct(
    protected StyleMapValidator $validator,
    protected BlueprintAccessChecker $accessChecker,
    protected SubtreeSlotKeys $subtreeSlotKeys,
    protected ConditionTypePluginManager $conditionTypes,
    protected StyleValueResolver $valueResolver,
    protected SkinManager $skinManager,
    protected AccountProxyInterface $currentUser,
    protected iterable $applicabilityCheckers = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return ['update_style', 'apply_variant', 'remove_variant', 'detach_variant', self::UPDATE_DEFAULT];
  }

  /**
   * {@inheritdoc}
   *
   * The four element ops ask style of the element their key addresses (a
   * rule key or a field key resolves to its element), so a foreign
   * element's styles, a translation's, or a host the actor may not update
   * are refused at the door before any map is read. update_default writes
   * the skin, not the page: nothing to judge on an element (its own gate
   * is the "edit skin defaults" permission).
   */
  public function getRequirements(): array {
    return [
      'update_style' => [Requirement::styleKey()],
      'apply_variant' => [Requirement::styleKey()],
      'remove_variant' => [Requirement::styleKey()],
      'detach_variant' => [Requirement::styleKey()],
      self::UPDATE_DEFAULT => [],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Styling targets the page's elements, and the page language spells the
   * whole lifecycle itself (styles are CSS, variant wear is set's class
   * attribute or an axis attribute, detach is set's "detach"), so on a
   * page-language site the JSON spellings leave eb_edit's roll-call.
   */
  public function getPageOnlyOperations(): array {
    return ['update_style', 'apply_variant', 'remove_variant', 'detach_variant'];
  }

  /**
   * {@inheritdoc}
   *
   * The site defaults op has no page-language spelling (a skin default is
   * no element's CSS), so page_edit admits the JSON op itself, riding a
   * page batch the way a material gesture does.
   */
  public function getPageLanguageOperations(): array {
    return [self::UPDATE_DEFAULT];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(HostInterface $host, array $operation, array $options = []): BlueprintResult {
    if ($operation['op'] === self::UPDATE_DEFAULT) {
      return $this->updateDefault($host, $operation, $options);
    }
    $key = $operation['key'] ?? NULL;
    if ($errors = $this->validateTarget($host, $key)) {
      return BlueprintResult::structuralFailure($errors);
    }

    return match ($operation['op']) {
      'update_style' => $this->updateStyle($host, $key, $operation, $options),
      'apply_variant' => $this->applyVariant($host, $key, $operation),
      'remove_variant' => $this->removeVariant($host, $key, $operation),
      'detach_variant' => $this->detachVariant($host, $key, $operation),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [
      'update_style' => 'with "key" (an element or rule key: "::" plus a relative selector, e.g. ::.class-name, ::> *, ::> pb-text) and "properties" {css-property: value; null unsets}, optional "condition" (the style op is the taught form)',
      'apply_variant' => 'with "key" (an element or rule key; a rule wears at its matches, never a composition or root-only axis) and "variant"; dresses it, replacing its same-axis reference (the class and axis attributes are the taught form)',
      'remove_variant' => 'with "key" (an element or rule key) and "variant" (a reference the entry carries); undresses it WITHOUT materializing, so the look changes (dropping the class or nulling the axis attribute is the taught form)',
      'detach_variant' => 'with "key" (an element or rule key) and "variant"; materializes its styles into the entry\'s own properties (a rule\'s parts continue under it) and unlinks it, keeping the look (set\'s detach list is the taught form)',
      self::UPDATE_DEFAULT => 'with "scope" (base:{tag}, kind:{kind}, block_type:{bundle}, block_plugin:{id}, layout:{id}) and "properties" {css-property: value; null unsets}, optional "key" ("::" plus a relative selector under the scope\'s element) and "condition"; the active skin\'s default look for every element of the scope',
    ];
  }

  /**
   * Applies an update_default operation: one scope's defaults, patched.
   *
   * @param \Drupal\Core\Entity\HostInterface $host
   *   The batch's entity: the page the gesture rides, untouched (the
   *   write is the active skin's).
   * @param array $operation
   *   The operation: scope, optional key ('' for the scope's element or
   *   "::" plus a relative selector) and condition, and properties.
   * @param array $options
   *   The batch options; 'conversation' reaches the style value seam so a
   *   handled value naming something the conversation holds resolves.
   */
  protected function updateDefault(HostInterface $host, array $operation, array $options = []): BlueprintResult {
    $errors = [];
    $scope = $operation['scope'] ?? NULL;
    if (!is_string($scope) || $scope === '') {
      $errors[] = new BlueprintError('scope', 'MISSING_PARAMETER', 'A non-empty scope is required.', [], 'Name the scope the default styles: "base:{tag}" (bare prose markup), "kind:{entity|container|field|block}", "block_type:{bundle}", "block_plugin:{plugin_id}" or "layout:{layout_id}".');
    }
    $key = $operation['key'] ?? '';
    if (!is_string($key) || ($key !== '' && !str_starts_with($key, RelativeSelector::SUFFIX))) {
      $errors[] = new BlueprintError('key', 'INVALID_STYLE_KEY', 'The key must be "" (the scope\'s element) or "::" plus a relative selector under it.', [], 'Omit the key to style the scope\'s element itself; "::.class-name" styles a declared part, "::p" its paragraphs, "::> *" its children. ' . RelativeSelector::GRAMMAR);
    }
    $condition = $operation['condition'] ?? 'default';
    if (!is_string($condition) || $condition === '') {
      $errors[] = new BlueprintError('condition', 'INVALID_STYLE_CONDITION', 'The condition must be a condition name.', [], 'Condition names are lowercase tokens such as "default", "hover", or "narrow"; omit the parameter for "default".');
    }
    $properties = $operation['properties'] ?? [];
    if (!is_array($properties) || (array_is_list($properties) && $properties !== [])) {
      $errors[] = new BlueprintError('properties', 'INVALID_STYLE_ENTRY', 'The properties parameter is not a property map.', [], 'Provide an object mapping CSS property names to values, e.g. {"font-size": "var(--text-2xl)"} — null values unset.');
      $properties = [];
    }
    if (!$errors && $properties === []) {
      $errors[] = new BlueprintError('properties', 'MISSING_PARAMETER', 'The operation changes nothing.', [], 'Provide "properties" values to set or unset.');
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }

    // The grade is the gesture's, not the page's: a default restyles every
    // element of the scope site-wide, whatever page the batch rides.
    if (!$this->currentUser->hasPermission('edit skin defaults') && !$this->currentUser->hasPermission('administer skins')) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('', 'PERMISSION_DENIED', 'Editing skin defaults requires the "edit skin defaults" permission.', ['permission' => 'edit skin defaults'], 'Ask an administrator for the permission, or style the element itself with update_style.'),
      ]);
    }
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('scope', 'STYLE_SKIN_NONE_ACTIVE', 'No skin is active: a site default is a fact of the active skin.', [], 'Activate a design system that carries a skin, or create a skin and make it the active system\'s default_skin, then set its defaults.'),
      ]);
    }

    // Validate exactly what is being set through the defaults seam, as a
    // one-scope map, against the skin's tokens AS WORN: a default delivers
    // only while its skin is active, so its var() references resolve
    // against that skin's merge of both layers, never the write-time
    // universe alone. The input tier resolves first (CHECK), the commit
    // waits until validation succeeded, exactly as update_style does.
    $resolution_warnings = [];
    $setting = array_filter($properties, static fn ($value) => $value !== NULL);
    if ($setting) {
      $flat = $scope . $key;
      $resolution = $this->valueResolver->check([$flat => [$condition => $setting]], $host, (string) ($options['conversation'] ?? ''));
      if ($resolution->getErrors()) {
        return BlueprintResult::semanticFailure(array_map(
          fn (BlueprintError $error) => $error->withPath($this->operationPath($error->getPath(), $flat, $condition)),
          $resolution->getErrors(),
        ));
      }
      $errors = $this->validator->validateDefaults([$scope => [$key => [$condition => $resolution->getMap()[$flat][$condition]]]], ['skin_tokens' => $skin->tokens()]);
      if ($errors) {
        return BlueprintResult::semanticFailure(array_map(
          fn (BlueprintError $error) => $error->withPath($this->defaultPath($error->getPath(), $scope, $key, $condition)),
          $errors,
        ));
      }
      $properties = array_replace($properties, $resolution->commit()[$flat][$condition] ?? []);
      $resolution_warnings = $resolution->getWarnings();
    }

    // The map write lands under the canonical compound spelling, as every
    // defaults store holds it.
    $condition = $this->conditionTypes->canonicalizeCondition($condition);

    $site_layer = $this->skinManager->writesSiteLayer($skin);
    $layer = $site_layer ? $skin->siteDefaults() : $skin->packageDefaults();
    $patched = static::patchDefaults($layer, $scope, $key, $condition, $properties);
    if ($patched === $layer) {
      // An unset of what the layer never held: nothing to save, no
      // revision minted.
      return BlueprintResult::success($host, $resolution_warnings);
    }
    if ($site_layer) {
      $skin->setSiteDefaults($patched);
    }
    else {
      $skin->setPackageDefaults($patched);
    }
    $this->skinManager->saveRevision($skin, sprintf('%s%s: updated the %s defaults.', $scope, $key, $condition));

    return BlueprintResult::success($host, $resolution_warnings);
  }

  /**
   * One defaults layer with a patch applied at a scope, key and condition.
   *
   * Null values unset; an entry, key or scope the patch empties leaves
   * the layer, so on a consuming site the package's value at that place
   * shows through the merge again (SkinDefaultsItem::mergeLayers()).
   */
  protected static function patchDefaults(array $layer, string $scope, string $key, string $condition, array $properties): array {
    $entry = $layer[$scope][$key][$condition] ?? [];
    $entry = is_array($entry) ? $entry : [];
    foreach ($properties as $property => $value) {
      if ($value === NULL) {
        unset($entry[$property]);
      }
      else {
        $entry[$property] = $value;
      }
    }
    if ($entry !== []) {
      $layer[$scope][$key][$condition] = $entry;
      return $layer;
    }
    unset($layer[$scope][$key][$condition]);
    if (($layer[$scope][$key] ?? NULL) === []) {
      unset($layer[$scope][$key]);
    }
    if (($layer[$scope] ?? NULL) === []) {
      unset($layer[$scope]);
    }
    return $layer;
  }

  /**
   * Maps a defaults-seam error path onto update_default's parameter space.
   *
   * The seam's paths are "{scope}", "{scope}.{key}" and
   * "{scope}{key}.{condition}.{property}" ("{scope}.{condition}.{property}"
   * for the scope's own element); the operation names each as a
   * parameter, so errors point at the parameter carrying the bad value.
   */
  protected function defaultPath(string $path, string $scope, string $key, string $condition): string {
    if ($path === $scope) {
      return 'scope';
    }
    if (!str_starts_with($path, $scope)) {
      return $path;
    }
    $remainder = ltrim(substr($path, strlen($scope)), '.');
    if ($key !== '' && str_starts_with($remainder, $key)) {
      $remainder = ltrim(substr($remainder, strlen($key)), '.');
    }
    if ($remainder === '') {
      return 'key';
    }
    if ($remainder === $condition) {
      return 'condition';
    }
    if (str_starts_with($remainder, "$condition.")) {
      return 'properties.' . substr($remainder, strlen($condition) + 1);
    }
    return $path;
  }

  /**
   * Applies an update_style operation.
   *
   * @param \Drupal\Core\Entity\HostInterface $host
   *   The entity whose style map is patched.
   * @param string $key
   *   The element or rule key the patch lands on.
   * @param array $operation
   *   The operation.
   * @param array $options
   *   The batch options; 'conversation' reaches the style value seam so a
   *   handled value naming something the conversation holds resolves.
   */
  protected function updateStyle(HostInterface $host, string $key, array $operation, array $options = []): BlueprintResult {
    $errors = [];
    $condition = $operation['condition'] ?? 'default';
    if (!is_string($condition) || $condition === '') {
      $errors[] = new BlueprintError('condition', 'INVALID_STYLE_CONDITION', 'The condition must be a condition name.', [], 'Condition names are lowercase tokens such as "default", "hover", or "narrow"; omit the parameter for "default".');
    }
    $properties = $operation['properties'] ?? [];
    if (!is_array($properties) || (array_is_list($properties) && $properties !== [])) {
      $errors[] = new BlueprintError('properties', 'INVALID_STYLE_ENTRY', 'The properties parameter is not a property map.', [], 'Provide an object mapping CSS property names to values, e.g. {"padding": "2rem"} — null values unset.');
      $properties = [];
    }
    if (!$errors && $properties === []) {
      $errors[] = new BlueprintError('properties', 'MISSING_PARAMETER', 'The operation changes nothing.', [], 'Provide "properties" values to set or unset.');
    }
    if ($errors) {
      return BlueprintResult::structuralFailure($errors);
    }

    // The birth seed: a generated box exists only while the cascade sets
    // its content, so a pseudo-element entry this op CREATES is born with
    // content: "" at default unless the write itself sets content there.
    // Seeded here and nowhere else (no validator corrective, no client
    // seed), because this handler is the one door every lane's writes
    // pass; the seed validates with the write below, so a site that
    // refuses the box (strict, undeclared) refuses the whole patch and
    // mints nothing. An existing entry is never re-seeded: deleting the
    // content line later is a deliberate act, and the box honestly
    // disappears. Variant wear never births a box (a generated box lands
    // on no element scope, so no reference is accepted at its key) and
    // detach materializes a fragment's authored content verbatim.
    $map = $this->getStyleMap($host);
    $seed = !isset($map[$key]) && RelativeSelector::splitKey($key)[1]?->pseudo !== NULL
      ? ['default' => [GeneratedContent::CONTENT => GeneratedContent::BOX]]
      : [];

    // Validate exactly what is being set through the one validation engine,
    // as a one-key map rooted at this element. The input tier resolves
    // first (CHECK): handled-value objects and layers lists become sound
    // stand-ins so the validator runs unchanged and side-effect-free; the
    // commit below waits until validation succeeded, so a failed patch
    // mints nothing.
    $resolution_warnings = [];
    $setting = array_filter($properties, static fn ($value) => $value !== NULL);
    $entry_to_validate = $setting ? StyleEntry::merge([$condition => $setting], $seed) : [];
    if ($entry_to_validate) {
      $resolution = $this->valueResolver->check([$key => $entry_to_validate], $host, (string) ($options['conversation'] ?? ''));
      if ($resolution->getErrors()) {
        return BlueprintResult::semanticFailure(array_map(
          fn (BlueprintError $error) => $error->withPath($this->operationPath($error->getPath(), $key, $condition)),
          $resolution->getErrors(),
        ));
      }
      $owned_warnings = [];
      $errors = $this->validator->validate($resolution->getMap(), ['host' => $host], $owned_warnings);
      if ($errors) {
        return BlueprintResult::semanticFailure(array_map(
          fn (BlueprintError $error) => $error->withPath($this->operationPath($error->getPath(), $key, $condition)),
          $errors,
        ));
      }
      // COMMIT: the patch writes the canonical resolved strings. The
      // validator's observations (an owned property written instead of
      // worn) and handler observations made while committing (e.g. a
      // disabled capability's fallback) surface as warnings on the
      // successful result.
      $properties = array_replace($properties, $resolution->commit()[$key][$condition] ?? []);
      $resolution_warnings = [
        ...array_map(fn (BlueprintWarning $warning) => new BlueprintWarning($this->operationPath($warning->getPath(), $key, $condition), $warning->getCode(), $warning->getMessage()), $owned_warnings),
        ...$resolution->getWarnings(),
      ];
    }

    // A compound condition is accepted in any part order (the validation
    // above ran on the spelled order, so error paths matched the input);
    // the map write lands under the canonical spelling, so "dark+narrow"
    // and "narrow+dark" patch the same block.
    $condition = $this->conditionTypes->canonicalizeCondition($condition);

    // The patch lands as OVERRIDES (layered entry model): the entry's
    // variant references stay untouched, and the written properties layer
    // over them, winning on conflict. Detaching is its own explicit
    // gesture (detach_variant), never a side effect of an edit.
    $entry = $map[$key] ?? [];

    foreach ($properties as $property => $value) {
      if ($value === NULL) {
        unset($entry[$condition][$property]);
      }
      else {
        $entry[$condition][$property] = $value;
      }
    }
    if (isset($entry[$condition]) && $entry[$condition] === []) {
      unset($entry[$condition]);
    }
    // The box is born only when the write leaves an entry behind (an
    // unset-only patch at a key that never existed mints nothing); the
    // write's own content at default beats the seed.
    if ($entry !== []) {
      $entry = StyleEntry::merge($entry, $seed);
    }

    if ($entry === []) {
      unset($map[$key]);
    }
    else {
      $map[$key] = $entry;
    }

    return $this->setStyleMap($host, $map, $resolution_warnings);
  }

  /**
   * Applies an apply_variant operation.
   */
  protected function applyVariant(HostInterface $host, string $key, array $operation): BlueprintResult {
    $variant = $operation['variant'] ?? NULL;
    if (!is_string($variant) || $variant === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('variant', 'MISSING_PARAMETER', 'A non-empty variant is required.', [], 'Provide the variant id to apply.'),
      ]);
    }

    // Target-side validation: the variant must exist and its scope
    // must match this element's own — the same check the validator runs on
    // whole-map writes, so both write paths agree. The incoming reference
    // is validated alone: an axis conflict cannot arise, because the write
    // below replaces the entry's same-axis reference.
    $errors = $this->validator->validate([$key => ['variants' => [['variant' => $variant, 'fragment' => '']]]], ['host' => $host]);
    if ($errors) {
      return BlueprintResult::semanticFailure(array_map(
        static fn (BlueprintError $error) => $error->withPath('variant'),
        $errors,
      ));
    }

    // The applicability seam: cross-module facts (blueprint ownership first
    // among them) veto a NEW wearing that scope alone would allow. Gesture
    // target only — nested distribution references below are the variant's
    // own composition, and existing references never re-check here (they
    // keep validating and rendering wherever they are; remove/detach work
    // anywhere; depth is a move's business, see
    // ElementStylesSubscriber::onElementMoved). A raw whole-map write
    // through the field handler can still
    // smuggle a reference past this gate: StyleMapValidator keeps
    // scope-only reference validation because it cannot tell a new
    // reference from an existing one it must tolerate — the same accepted
    // soft posture as the base-tag offer gate (see
    // Address::pairsWith()), now with this gesture backstop.
    $loaded = $this->validator->loadVariant($variant);
    foreach ($this->applicabilityCheckers as $checker) {
      if ($checker_errors = $checker->check($loaded, $host, $key)) {
        return BlueprintResult::semanticFailure($checker_errors);
      }
    }

    $map = $this->getStyleMap($host);
    $warnings = [];
    $this->distributeVariant($host, $map, $key, $variant, $loaded, [], $warnings);

    return $this->setStyleMap($host, $map, $warnings);
  }

  /**
   * Applies one variant's whole dressing at a key, recursively.
   *
   * The one apply semantics, shared by the op's target and every nested
   * root reference a distribution reaches:
   * - The variant's own reference lands on the entry, within-axis (see
   *   placeReference()). Every other map entry, the entry's overrides,
   *   and its sub-element entries stay: the reference is one axis's
   *   treatment, not the element's entire styling. Same-axis replace is a
   *   GROUP gesture on a composition root: the variant leaving the axis
   *   takes the fragment references it distributed and the nested
   *   references it stamped ("via") over the CURRENT subtree with it; the
   *   enclosing chain and co-distributed siblings are spared, never
   *   casualties of their own distribution.
   * - Position resolves to identity NOW, and only now. Each slot path
   *   among the variant's styles resolves once against the key's staged
   *   subtree (SubtreeSlotKeys — the same walk capture inverts), and every
   *   resolved child's own entry receives a {variant, fragment} reference.
   *   Part suffixes ride their element: the element's single reference
   *   delivers its fragment's part styles too, so only element-level paths
   *   bind. A slot path that resolves to nothing binds nothing, forever
   *   (bind-once: no pending record, no re-resolution on later structure
   *   changes — only an explicit re-apply resolves fresh over the
   *   then-current structure).
   * - Entry-shaped fragments distribute their nested references onto the
   *   same element the entry resolves to (the root entry onto the key
   *   itself, part entries onto the carrier's own part key), each a real
   *   reference stamped with this variant as its origin, see
   *   distributeEntryRefs(). Nested references
   *   place BEFORE the variant's own, and the variant's own placement
   *   spares them: a nested reference sharing the variant's axis therefore
   *   sits BESIDE the fragment reference (the composition pair), and
   *   delivery orders it under the fragment's values structurally
   *   (StyleMapValidator::orderedEntryRefs()), so list order carries no
   *   meaning.
   *
   * @param \Drupal\Core\Entity\HostInterface $host
   *   The host entity.
   * @param array &$map
   *   The style map, modified in place.
   * @param string $key
   *   The style map key of the entry wearing the variant.
   * @param string $reference
   *   The reference string the wearer's entry stores: the op's submitted
   *   spelling at the target, a nested list's spelling in recursion.
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant to apply.
   * @param string[] $chain
   *   Machine names of the variants whose distribution encloses this one,
   *   outermost first: the sparing set, so the enclosing dressing is never
   *   replaced by what it distributes.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collects the loud skips (a nested reference the roster does not
   *   resolve).
   * @param string[] $spare
   *   Additional machine names the root placement must spare: siblings the
   *   caller already distributed onto the same entry.
   * @param string|null $via
   *   The origin the root placement records: the machine name of the
   *   variant whose nested reference this apply distributes, NULL at the
   *   op's own target (a direct apply is the editor's, never derived).
   */
  protected function distributeVariant(HostInterface $host, array &$map, string $key, string $reference, DaedalusStylishVariantInterface $variant, array $chain, array &$warnings, array $spare = [], ?string $via = NULL): void {
    $axis = $variant->getAxis();
    $chain[] = $variant->getMachineName();
    $styles = [];
    foreach ($variant->getStyles() as $relative => $entry) {
      $styles[(string) $relative] = $entry;
    }

    // The root entry's nested references land on the key itself, before
    // its own reference does (the names feed the sparing set; delivery
    // order is structural, never list order).
    $spared = [...$spare, ...$this->distributeEntryRefs($host, $map, $key, $styles[''] ?? NULL, $chain, $warnings)];

    $replaced = [];
    $map[$key] = $this->placeReference(is_array($map[$key] ?? NULL) ? $map[$key] : [], $reference, '', $axis, $replaced, [
      ...$chain,
      ...$spared,
    ], $via);
    if ($replaced !== []) {
      $this->removeDistributedReferences($host, $map, $key, $replaced);
    }

    // Slot paths resolve once, per element (part suffixes stripped). A
    // rule key distributes nothing: its matches are many and bind no
    // subtree (the validator refuses a composition at a rule by its keys,
    // and a nested composition reaching a rule binds nothing, forever).
    $is_rule = RelativeSelector::splitKey($key)[1] !== NULL;
    $fragments = [];
    foreach (array_keys($styles) as $relative) {
      $path = strstr($relative, '::', TRUE);
      $path = $path === FALSE ? $relative : $path;
      if ($path !== '') {
        $fragments[$path] = TRUE;
      }
    }
    $resolved = $fragments === [] || $is_rule
      ? []
      : $this->subtreeSlotKeys->resolveSlotKeys($host, $key, array_keys($fragments));

    // Each resolved child: the fragment entry's nested references first,
    // then the variant's own fragment reference beside them (the pair).
    foreach ($resolved as $fragment => $target) {
      $spared = $this->distributeEntryRefs($host, $map, $target, $styles[$fragment] ?? NULL, $chain, $warnings);
      $map[$target] = $this->placeReference(is_array($map[$target] ?? NULL) ? $map[$target] : [], $reference, $fragment, $axis, spare: [
        ...$chain,
        ...$spared,
      ]);
    }

    // Part entries carry nested references of their own; they land on the
    // carrier's part key (parts ride their element; under a rule the part
    // continues by descent, carrierKey()). An unresolved slot's part, or
    // a part the rule's grammar refuses, binds nothing, exactly like its
    // element.
    foreach ($styles as $relative => $entry) {
      $path = strstr($relative, '::', TRUE);
      if ($path === FALSE) {
        continue;
      }
      $suffix = substr($relative, strlen($path));
      $carrier = $path === '' ? static::carrierKey($key, $suffix) : (isset($resolved[$path]) ? $resolved[$path] . $suffix : NULL);
      if ($carrier !== NULL) {
        $this->distributeEntryRefs($host, $map, $carrier, $entry, $chain, $warnings);
      }
    }
  }

  /**
   * The map key a worn variant's suffix lands on under a wearing key.
   *
   * The one continuation shared by apply's part carrier and detach's
   * materialization: under an element key the suffix appends
   * ("{key}::.cls"); under a rule key the suffix continues the rule by
   * descent (RelativeSelector::under(): "container:X::> pb-text" wearing
   * a "::.link" entry lands at "container:X::> pb-text .link"), the same
   * rebase delivery prints (CssPrinter::printFragmentStyles() under the
   * rule), so what detach writes is exactly what the reference delivered.
   * A key never carries "::" twice. NULL when the rule's grammar refuses
   * the suffix (it delivered nothing, so nothing lands).
   *
   * @param string $key
   *   The wearing key, an element key or a rule key.
   * @param string $suffix
   *   The variant's suffix: '' (the root) or '::selector'.
   *
   * @return string|null
   *   The carrier key, or NULL when the suffix cannot continue the rule.
   */
  protected static function carrierKey(string $key, string $suffix): ?string {
    if ($suffix === '') {
      return $key;
    }
    [$root, $rule] = RelativeSelector::splitKey($key) ?? [$key, NULL];
    if ($rule === NULL) {
      return $key . $suffix;
    }
    $split = RelativeSelector::splitKey($suffix);
    if ($split === NULL || $split[1] === NULL) {
      return NULL;
    }
    $under = RelativeSelector::under($rule, $split[1]);
    return $under === NULL ? NULL : $root . RelativeSelector::SUFFIX . $under;
  }

  /**
   * Distributes one variant entry's nested references onto its carrier.
   *
   * The nested half of bind-once distribution: each well-formed reference
   * in the entry's "variants" list is copied onto the carrier as a real
   * map reference at apply time, stamped "via" the distributing variant
   * (this entry's owner, the IMMEDIATE distributor: a nested composition's
   * own distribution stamps its own name). Downstream (render, compile,
   * delivery, detach) only ever sees real map references, the origin is
   * the placement fact that lets replace and remove reap exactly what
   * this variant nested (removeDistributedReferences()), and re-pointing
   * a nested reference inside the variant reaches existing wearers only
   * through the explicit re-apply gesture. A root ('') reference to a
   * composition applies recursively (the child's own subtree resolves its
   * slot paths; only its root reference carries the origin, its own
   * fragment references are reaped by its name); a non-empty fragment
   * reference is a bound lookup key and places verbatim, never recursing:
   * distribution of THAT composition happened at its own apply.
   *
   * Integrity is not re-checked here. The applied variant reached apply
   * through the judged roster (VariantConformance), and the judge is the
   * whole save gate: a dangling or mis-scoped nested reference, a
   * reference cycle and a root-entry reference on the variant's own axis
   * each withhold the variant that carries them, so none of them can be
   * met in a variant being distributed. The judge does not cascade,
   * though (it validates in the unjudged universe), so the one reference
   * that can still fail to resolve names a variant the roster withholds
   * (its own values fail the current law) or excludes (an inactive
   * system's member); it cannot claim an axis and is skipped, loudly,
   * with a STYLE_VARIANT_NOT_FOUND warning, while everything resolvable
   * distributes.
   *
   * @return string[]
   *   Machine names of the references distributed — the sparing set for
   *   the owner's own placement beside them.
   */
  protected function distributeEntryRefs(HostInterface $host, array &$map, string $carrier, mixed $entry, array $chain, array &$warnings): array {
    $owner = $chain[array_key_last($chain)];
    $spared = [];
    foreach (StyleMapValidator::entryRefs($entry) as $ref) {
      $nested = $this->validator->loadVariant($ref['variant']);
      if ($nested === NULL) {
        $warnings[] = new BlueprintWarning('variant', 'STYLE_VARIANT_NOT_FOUND', sprintf('The "%s" variant\'s nested "%s" reference names a variant the site does not offer (withheld by the style guide, or a member of an inactive design system); it was skipped on "%s".', $owner, $ref['variant'], $carrier));
        continue;
      }
      $name = $nested->getMachineName();
      if ($ref['fragment'] !== '') {
        $map[$carrier] = $this->placeReference(is_array($map[$carrier] ?? NULL) ? $map[$carrier] : [], $ref['variant'], $ref['fragment'], $nested->getAxis(), spare: [
          ...$chain,
          ...$spared,
        ], via: $owner);
        $spared[] = $name;
        continue;
      }
      $this->distributeVariant($host, $map, $carrier, $ref['variant'], $nested, $chain, $warnings, $spared, $owner);
      $spared[] = $name;
    }
    return $spared;
  }

  /**
   * Places a {variant, fragment[, via]} reference on an entry, within-axis.
   *
   * The incoming reference takes the list position of the reference sharing
   * its axis (keeping that axis's precedence slot), or appends when the
   * axis was bare — an element wears at most one reference per axis, and
   * fragment references occupy their variant's axis like any other, so a
   * distributed fragment visibly replaces the same-axis treatment the
   * element wore, and vice versa (partial-wearing honesty, one rule
   * everywhere). References on other axes compose and stay untouched.
   * A stale reference (variant deleted) has no determinable axis and
   * stays. The reference is always written FRESH: re-placing a variant the
   * entry already wears rewrites its item, so the origin is exactly what
   * this placement says (a direct apply on a child wearing the variant via
   * a composition takes the axis over, and a composition re-apply
   * re-stamps).
   *
   * @param array $entry
   *   The map entry.
   * @param string $variant
   *   The variant's machine name.
   * @param string $fragment
   *   The fragment the reference wears ('' for the root).
   * @param string $axis
   *   The variant's axis.
   * @param string[] $replaced
   *   Filled with the OTHER variants the placement removed from the axis —
   *   the input to the group cleanup when the entry is a composition root.
   *   Re-placing the same variant (re-apply, fragment update) is not a
   *   replacement: its distributed references are bound and must survive.
   * @param string[] $spare
   *   Machine names never replaced, whatever their axis: the enclosing
   *   distribution chain and the co-distributed siblings of the reference
   *   being placed. Sparing is what lets a fragment reference and the
   *   nested references distributed FROM that fragment share an axis (the
   *   composition pair); a DIRECT apply passes none, so the gesture still
   *   replaces both halves of a worn pair (partial-wearing honesty).
   * @param string|null $via
   *   The origin the reference records: the machine name of the variant
   *   distributing it, or NULL for the op's own target, which writes none
   *   (the editor's own wear carries no provenance).
   *
   * @return array
   *   The entry with the reference placed.
   */
  protected function placeReference(array $entry, string $variant, string $fragment, string $axis, array &$replaced = [], array $spare = [], ?string $via = NULL): array {
    $reference = ['variant' => $variant, 'fragment' => $fragment] + ($via === NULL ? [] : ['via' => $via]);
    $references = [];
    $placed = FALSE;
    $items = is_array($entry['variants'] ?? NULL) ? $entry['variants'] : [];
    foreach ($items as $item) {
      $ref = StyleMapValidator::entryRefs(['variants' => [$item]])[0] ?? NULL;
      if ($ref === NULL) {
        // Malformed items drop on rewrite, the read normalization's posture.
        continue;
      }
      $loaded = $ref['variant'] === $variant ? NULL : $this->validator->loadVariant($ref['variant']);
      if ($loaded !== NULL && $spare !== [] && in_array($loaded->getMachineName(), $spare, TRUE)) {
        $references[] = $item;
        continue;
      }
      if ($ref['variant'] === $variant || $loaded?->getAxis() === $axis) {
        if ($ref['variant'] !== $variant) {
          $replaced[] = $ref['variant'];
        }
        if (!$placed) {
          $references[] = $reference;
          $placed = TRUE;
        }
        continue;
      }
      $references[] = $item;
    }
    if (!$placed) {
      $references[] = $reference;
    }
    $entry['variants'] = $references;
    return $entry;
  }

  /**
   * Removes replaced variants' distributed references from the subtree.
   *
   * The group half of the within-axis replace and of remove_variant, the
   * inverse of distribution: scoped to the CURRENT subtree (the root and
   * its part keys included, since the root entry's nested references land
   * on the root itself), because a composition's wearing is derived: its
   * root reference plus what it distributed onto current descendants. Two
   * kinds of reference leave with the variant: the non-empty fragment
   * references it distributed (its own name, a bound lookup key), and the
   * root ('' fragment) references of OTHER variants it nested, told apart
   * from the child's own wearing by their "via" naming it. Each reaped
   * nested root reference was a composition root in its own right (its
   * apply distributed over the carrier's subtree), so the reap recurses
   * for it by name: immediate origins, inverted level by level. A ''
   * reference with no origin, or an origin naming someone else, is the
   * child's direct wearing (the editor's, or another composition's) and
   * stays; references that rode out on moved elements are orphans owned by
   * their elements, untouched by gestures on this root.
   *
   * @param \Drupal\Core\Entity\HostInterface $host
   *   The host entity.
   * @param array &$map
   *   The style map, modified in place.
   * @param string $root_key
   *   The style map key of the composition root.
   * @param string[] $variants
   *   The variant references removed from the target's axis, as stored;
   *   origins match by machine name too, since distribution stamps the
   *   name whatever spelling the root reference used.
   */
  protected function removeDistributedReferences(HostInterface $host, array &$map, string $root_key, array $variants): void {
    $subtree = $this->subtreeSlotKeys->subtreeKeys($host, $root_key);
    if ($subtree === []) {
      return;
    }
    $origins = $variants;
    foreach ($variants as $variant) {
      $name = $this->validator->loadVariant($variant)?->getMachineName();
      if ($name !== NULL && !in_array($name, $origins, TRUE)) {
        $origins[] = $name;
      }
    }
    $reaped_roots = [];
    foreach (array_keys($map) as $key) {
      $key = (string) $key;
      $entry = $map[$key];
      if (!is_array($entry) || !is_array($entry['variants'] ?? NULL) || !$this->withinSubtree($key, $subtree)) {
        continue;
      }
      $kept = [];
      $changed = FALSE;
      foreach ($entry['variants'] as $item) {
        $ref = StyleMapValidator::entryRefs(['variants' => [$item]])[0] ?? NULL;
        if ($ref !== NULL && $ref['fragment'] !== '' && in_array($ref['variant'], $variants, TRUE)) {
          $changed = TRUE;
          continue;
        }
        if ($ref !== NULL && $ref['fragment'] === '' && $ref['via'] !== NULL && in_array($ref['via'], $origins, TRUE)) {
          $changed = TRUE;
          $reaped_roots[] = [$key, $ref['variant']];
          continue;
        }
        $kept[] = $item;
      }
      if (!$changed) {
        continue;
      }
      if ($kept === []) {
        unset($entry['variants']);
      }
      else {
        $entry['variants'] = $kept;
      }
      if ($entry === []) {
        unset($map[$key]);
      }
      else {
        $map[$key] = $entry;
      }
    }
    // Recurse after the sweep: the walk above must not re-read a stale
    // entry and resurrect what a nested reap took.
    foreach ($reaped_roots as [$key, $variant]) {
      $this->removeDistributedReferences($host, $map, $key, [$variant]);
    }
  }

  /**
   * Whether a map key's element lies within a subtree key set.
   *
   * The set holds element keys (SubtreeSlotKeys::subtreeKeys()); field and
   * part keys belong to their element, so block:{uuid}:{field} rides
   * block:{uuid} and a '::.class' suffix rides the key it extends.
   */
  protected function withinSubtree(string $key, array $subtree): bool {
    $base = strstr($key, '::', TRUE) ?: $key;
    if (in_array($base, $subtree, TRUE)) {
      return TRUE;
    }
    return preg_match('/^(block:[0-9a-fA-F-]{36}):[a-z0-9_]+$/', $base, $matches) === 1
      && in_array($matches[1], $subtree, TRUE);
  }

  /**
   * Applies a remove_variant operation.
   *
   * The undress gesture: the reference drops with NO materialization, so
   * the variant's styles stop rendering at this element. Deliberately the
   * exact "replaced" half of apply_variant's within-axis swap, without a
   * replacement: dropReference() takes the entry's reference, and a root
   * ('' fragment) wearing of a composition additionally sheds the fragment
   * references it distributed and the nested references it stamped over
   * the CURRENT subtree (removeDistributedReferences(), the same
   * current-subtree scoping, so orphaned refs on moved elements stay with
   * their elements, and the same provenance rule, so a child's own wear
   * survives). The
   * entry's own overrides survive untouched; where only the removed
   * variant made them meaningful they go inert rather than lost, which is
   * what lets a mode flip back restore them (the layout widget's
   * Columns -> Stack -> Columns round trip keeps its proportions).
   */
  protected function removeVariant(HostInterface $host, string $key, array $operation): BlueprintResult {
    $variant = $operation['variant'] ?? NULL;
    if (!is_string($variant) || $variant === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('variant', 'MISSING_PARAMETER', 'A non-empty variant is required.', [], 'Provide the variant to remove, exactly as the "variant" value of one of the entry\'s "variants" references.'),
      ]);
    }

    $map = $this->getStyleMap($host);
    $fragments = array_column(StyleMapValidator::entryRefs($map[$key] ?? NULL), 'fragment', 'variant');
    if (!array_key_exists($variant, $fragments)) {
      $references = array_keys($fragments);
      return BlueprintResult::semanticFailure([
        new BlueprintError('variant', 'STYLE_VARIANT_NOT_REFERENCED', sprintf('"%s" does not reference the "%s" variant.', $key, $variant), $references === [] ? [] : ['referenced_variants' => $references], 'Remove names a variant the element\'s entry currently references — read the entity\'s daedalus_stylish_styles field and use a "variant" value from the entry\'s "variants" list.'),
      ]);
    }

    $this->dropReference($map, $key, $variant);
    if ($fragments[$variant] === '') {
      $this->removeDistributedReferences($host, $map, $key, [$variant]);
    }

    return $this->setStyleMap($host, $map);
  }

  /**
   * Applies a detach_variant operation.
   */
  protected function detachVariant(HostInterface $host, string $key, array $operation): BlueprintResult {
    $variant = $operation['variant'] ?? NULL;
    if (!is_string($variant) || $variant === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('variant', 'MISSING_PARAMETER', 'A non-empty variant is required.', [], 'Provide the variant to detach, exactly as the "variant" value of one of the entry\'s "variants" references.'),
      ]);
    }

    $map = $this->getStyleMap($host);
    $fragments = array_column(StyleMapValidator::entryRefs($map[$key] ?? NULL), 'fragment', 'variant');
    if (!array_key_exists($variant, $fragments)) {
      $references = array_keys($fragments);
      return BlueprintResult::semanticFailure([
        new BlueprintError('variant', 'STYLE_VARIANT_NOT_REFERENCED', sprintf('"%s" does not reference the "%s" variant.', $key, $variant), $references === [] ? [] : ['referenced_variants' => $references], 'Detach names a variant the element\'s entry currently references — read the entity\'s daedalus_stylish_styles field and use a "variant" value from the entry\'s "variants" list.'),
      ]);
    }

    // Materialize first (existing entries win their merges), then drop the
    // reference — the element keeps its look, unlinked. References are
    // identity-bound, so this is purely local: the worn fragment is a
    // lookup key into the variant, never a position to resolve. A stale
    // reference (variant deleted) has nothing to look up and simply drops.
    $this->materializeReference($map, $key, $variant, $fragments[$variant]);
    $this->dropReference($map, $key, $variant);

    // Detaching a root wearing is the group gesture (detach-all): the
    // composition's distribution over the CURRENT subtree unlinks with it.
    // A fragment wearing distributed FROM elsewhere detaches alone; its
    // siblings belong to the root they were distributed onto.
    if ($fragments[$variant] === '') {
      $this->detachDistributedReferences($host, $map, $key, $variant);
    }

    return $this->setStyleMap($host, $map);
  }

  /**
   * Detaches EVERY wearing of a variant from one host's map.
   *
   * The site-gesture entry into the op's one detach math (the delete
   * batch's per-wearer step, VariantDeleteBatch): where detach_variant
   * unlinks one reference at one key, this sweeps the whole map, each
   * wearing materializing and dropping with exactly the operation's
   * semantics. Root wearings go first — detaching one is the group
   * gesture and takes the fragment references it distributed over the
   * current subtree with it — and each pass re-scans, so distributed
   * fragments never detach twice and orphaned fragment references (rode
   * out on moved elements, or a form the group sweep's verbatim string
   * match missed) detach individually. Both reference forms (machine name
   * and uuid) count as wearings; the map stores whichever was applied.
   * The variant is leaving the site, so every nested reference it stamped
   * anywhere in the map (orphans included) is released to its carrier:
   * the "via" naming it drops, and the wear reads as the element's own.
   *
   * Mutates the host's map in place; the CALLER saves (the batch
   * decides revision posture and log message). No access or key
   * validation happens here — the delete gesture's own permission is the
   * authority, exactly like push touching every wearer.
   *
   * @return bool
   *   TRUE when the host wore the variant and its map changed.
   */
  public function detachVariantEverywhere(HostInterface $host, DaedalusStylishVariantInterface $variant): bool {
    if (!$host->hasStyleStore()) {
      return FALSE;
    }
    $forms = array_filter([$variant->getMachineName(), (string) $variant->uuid()]);
    $map = $this->getStyleMap($host);
    $changed = FALSE;
    while (($wearing = $this->findWearing($map, $forms)) !== NULL) {
      [$key, $reference, $fragment] = $wearing;
      $this->materializeReference($map, $key, $reference, $fragment);
      $this->dropReference($map, $key, $reference);
      if ($fragment === '') {
        $this->detachDistributedReferences($host, $map, $key, $reference);
      }
      $changed = TRUE;
    }
    if ($this->releaseProvenance($map, $variant->getMachineName())) {
      $changed = TRUE;
    }
    if ($changed) {
      $this->setStyleMap($host, $map);
    }
    return $changed;
  }

  /**
   * Finds one wearing of a variant in a map: root wearings first.
   *
   * @param array $map
   *   The style map.
   * @param string[] $forms
   *   The variant's reference forms (machine name, uuid).
   *
   * @return array|null
   *   [key, reference string as stored, fragment], or NULL when the map
   *   holds no reference to the variant.
   */
  protected function findWearing(array $map, array $forms): ?array {
    $fallback = NULL;
    foreach ($map as $key => $entry) {
      foreach (StyleMapValidator::entryRefs($entry) as $ref) {
        if (!in_array($ref['variant'], $forms, TRUE)) {
          continue;
        }
        if ($ref['fragment'] === '') {
          return [(string) $key, $ref['variant'], ''];
        }
        $fallback ??= [(string) $key, $ref['variant'], $ref['fragment']];
      }
    }
    return $fallback;
  }

  /**
   * Detaches the fragment references a variant distributed over a subtree.
   *
   * The group half of detach-all, scoped exactly like
   * removeDistributedReferences(): a composition root's wearing is derived
   * (its root reference plus the fragment references surviving on current
   * descendants), so detaching the root wearing takes the CURRENT
   * subtree's fragment references with it, each materializing locally onto
   * the element carrying it. The nested references the variant stamped
   * over the same subtree (the root entry's own included) are not its
   * values and never materialize: detach keeps the look, so they stay
   * worn and delivering, RELEASED to their carriers (their "via" drops;
   * the composition is gone and the child owns the wear now, so a later
   * gesture on the root never reaps it). A descendant's own root ('' fragment)
   * reference to the same variant is the descendant's direct wearing and
   * stays; references that rode out on moved elements are orphans owned by
   * their elements and stay too (their own element-level detach
   * materializes them). A fragment the variant held but never bound has no
   * carrier and materializes nowhere.
   */
  protected function detachDistributedReferences(HostInterface $host, array &$map, string $root_key, string $variant): void {
    $subtree = $this->subtreeSlotKeys->subtreeKeys($host, $root_key);
    if ($subtree === []) {
      return;
    }
    // Snapshot the carriers first: materialization writes part keys into
    // the map while the walk is in flight.
    $carriers = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      if ($key === $root_key || !$this->withinSubtree($key, $subtree)) {
        continue;
      }
      foreach (StyleMapValidator::entryRefs($entry) as $ref) {
        if ($ref['variant'] === $variant && $ref['fragment'] !== '') {
          $carriers[$key] = $ref['fragment'];
          break;
        }
      }
    }
    foreach ($carriers as $key => $fragment) {
      $this->materializeReference($map, $key, $variant, $fragment);
      $this->dropReference($map, $key, $variant);
    }
    $this->releaseProvenance($map, $this->validator->loadVariant($variant)?->getMachineName() ?? $variant, $subtree);
  }

  /**
   * Drops the origin from every reference a variant stamped, in place.
   *
   * The release half of detach: a nested reference distributed "via" the
   * variant keeps delivering exactly as it is (its values were never the
   * detached variant's to materialize) but no longer records who put it
   * there, so it reads as its carrier's own wear from here on. Scoped to
   * a subtree for the group gesture (the root and its part keys included,
   * since the root entry's nested references land on the root itself), or
   * to the whole map when the variant leaves the site.
   *
   * @param array &$map
   *   The style map, modified in place.
   * @param string $origin
   *   The machine name distribution stamped.
   * @param string[]|null $subtree
   *   The element keys to release within, NULL for the whole map.
   *
   * @return bool
   *   TRUE when any reference released.
   */
  protected function releaseProvenance(array &$map, string $origin, ?array $subtree = NULL): bool {
    $released = FALSE;
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      if (!is_array($entry) || !is_array($entry['variants'] ?? NULL) || ($subtree !== NULL && !$this->withinSubtree($key, $subtree))) {
        continue;
      }
      foreach ($entry['variants'] as $index => $item) {
        $ref = StyleMapValidator::entryRefs(['variants' => [$item]])[0] ?? NULL;
        if ($ref === NULL || $ref['via'] !== $origin) {
          continue;
        }
        unset($item['via']);
        $map[$key]['variants'][$index] = $item;
        $released = TRUE;
      }
    }
    return $released;
  }

  /**
   * Drops an entry's reference to a variant, in place.
   *
   * The kept references stay untouched; malformed items drop on rewrite,
   * the read normalization's posture. A list (or entry) left empty
   * disappears.
   */
  protected function dropReference(array &$map, string $key, string $variant): void {
    $entry = is_array($map[$key] ?? NULL) ? $map[$key] : [];
    $kept = [];
    foreach (is_array($entry['variants'] ?? NULL) ? $entry['variants'] : [] as $item) {
      $ref = StyleMapValidator::entryRefs(['variants' => [$item]])[0] ?? NULL;
      if ($ref === NULL || $ref['variant'] === $variant) {
        continue;
      }
      $kept[] = $item;
    }
    if ($kept === []) {
      unset($entry['variants']);
    }
    else {
      $entry['variants'] = $kept;
    }
    if ($entry === []) {
      unset($map[$key]);
    }
    else {
      $map[$key] = $entry;
    }
  }

  /**
   * Materializes one worn reference's styles onto its element, in place.
   *
   * References are identity-bound (bind-once), so materialization is a
   * pure LOCAL lookup: the fragment names the variant entry the element
   * wears ('' for the root), and that entry's styles plus its '::.class'
   * part styles (parts ride their element) land on the element's own keys
   * ("{key}" and "{key}::.class"; under a rule key the part continues the
   * rule by descent, carrierKey()). No slot resolution happens here; slot
   * paths resolve to identity at APPLY time only, and a composition's
   * other fragments belong to the elements carrying them (see
   * detachDistributedReferences()). A fragment the variant no longer
   * holds is inert and materializes nothing, exactly like a stale
   * reference (variant deleted). An entry-shaped fragment's nested
   * "variants" list is not values and never materializes here
   * (fragmentStyles reads values-only): apply distributed those
   * references onto the carrier as real references stamped with their
   * origin, and dropping only the detached variant's reference leaves
   * them worn and delivering: their values keep reaching the page as live
   * layers beneath the materialized overrides, so nothing the reference
   * delivered is lost and nothing dangles (the group gesture then
   * releases their origin, see detachDistributedReferences()).
   *
   * The delivery cascade this snapshot replaces put the map's own values
   * over the variant's, so EXISTING map values win every merge here. Only
   * what the reference actually DELIVERED materializes: delivery emits
   * co-occurring references in axis-declaration order
   * (orderedEntryVariants), later beating earlier, so a property that a
   * later-delivered reference also defines was shadowed — materializing it
   * as an override would flip the rendered winner, changing the look the
   * detach promises to keep. Those properties stay with the reference that
   * won them.
   */
  protected function materializeReference(array &$map, string $key, string $reference, string $fragment): void {
    $styles = $this->deliveredStyles($this->fragmentStyles($reference, $fragment), $map[$key] ?? NULL, $reference);
    foreach ($styles as $suffix => $entry_styles) {
      // Under a rule the part suffix continues by descent, exactly as
      // delivery printed it; a suffix the rule refused delivered nothing
      // and materializes nothing.
      $target = static::carrierKey($key, (string) $suffix);
      if ($target === NULL) {
        continue;
      }
      $existing = $map[$target] ?? NULL;
      $map[$target] = is_array($existing) ? $this->mergeEntry($existing, $entry_styles) : $entry_styles;
    }
  }

  /**
   * A variant's styles at one fragment, keyed by part suffix.
   *
   * Resolves the reference and reads through SlotKey::fragmentStyles() —
   * the one fragment-lookup semantics, shared with delivery
   * (CssPrinter::printFragmentStyles()), so what detach materializes is
   * exactly what the reference delivered. A stale reference or an unheld
   * fragment reads empty.
   */
  protected function fragmentStyles(string $reference, string $fragment): array {
    return SlotKey::fragmentStyles($this->validator->loadVariant($reference)?->getStyles() ?? [], $fragment);
  }

  /**
   * Filters a reference's delivered styles down to what it delivered.
   *
   * The delivery cascade among an entry's co-occurring references is
   * axis-declaration order (later wins), NOT the stored list order: a
   * property that a reference delivering AFTER this one also defines never
   * reached the page from here, so it does not materialize. Each later
   * reference shadows in the space it delivers to: its OWN fragment's
   * styles at the same part suffix. A stale later reference (variant
   * deleted) delivers nothing and shadows nothing.
   *
   * @param array $styles
   *   The reference's fragment styles, keyed by part suffix
   *   (fragmentStyles()).
   * @param mixed $entry
   *   The map entry wearing the reference.
   * @param string $reference
   *   The reference as stored.
   */
  protected function deliveredStyles(array $styles, mixed $entry, string $reference): array {
    $ordered = $this->validator->orderedEntryVariants($entry);
    $position = array_search($reference, $ordered, TRUE);
    if ($position === FALSE) {
      return $styles;
    }
    $fragments = array_column(StyleMapValidator::entryRefs($entry), 'fragment', 'variant');
    foreach (array_slice($ordered, $position + 1) as $later_reference) {
      foreach ($this->fragmentStyles($later_reference, $fragments[$later_reference] ?? '') as $suffix => $later_entry) {
        if (!isset($styles[$suffix])) {
          continue;
        }
        foreach ($later_entry as $condition => $values) {
          if (!is_array($values) || !isset($styles[$suffix][$condition]) || !is_array($styles[$suffix][$condition])) {
            continue;
          }
          $styles[$suffix][$condition] = array_diff_key($styles[$suffix][$condition], $values);
          if ($styles[$suffix][$condition] === []) {
            unset($styles[$suffix][$condition]);
          }
        }
        if ($styles[$suffix] === []) {
          unset($styles[$suffix]);
        }
      }
    }
    return $styles;
  }

  /**
   * Merges two style entries, the winner's values taking precedence.
   *
   * The one entry merge (StyleEntry::merge(), the server twin of
   * mergeEntry() in js/src/variant-scope.js): condition maps merge per
   * property.
   */
  protected function mergeEntry(array $winner, array $loser): array {
    return StyleEntry::merge($winner, $loser);
  }

  /**
   * Validates that the host and key can hold styles at all.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Structural errors; empty when the target is sound.
   */
  protected function validateTarget(HostInterface $host, mixed $key): array {
    if (!$host->hasStyleStore()) {
      return [
        new BlueprintError('key', 'STYLING_NOT_ENABLED', sprintf('Styling is not enabled for %s %s entities.', $host->bundle(), $host->entityTypeId()), [], 'Enable styling for this bundle on its edit form, or style an entity of a bundle that has it enabled.'),
      ];
    }
    if (!is_string($key) || $this->validator->parseKey($key) === NULL) {
      return [
        new BlueprintError('key', 'INVALID_STYLE_KEY', sprintf('"%s" is not a valid style map key.', is_scalar($key) ? (string) $key : gettype($key)), [], StyleMapValidator::KEY_GRAMMAR_HINT),
      ];
    }
    // The store is a field of the host's fields entity: its edit access is
    // the field's.
    $store = $host->fields()?->get($host->styleMapDefinition()->getName());
    if ($store !== NULL && !$this->accessChecker->fieldEditable($store)) {
      return [
        new BlueprintError('key', 'ACCESS_DENIED_FIELD', 'Access denied: cannot edit the styles of this entity.', [], 'The current user lacks permission to modify this field.'),
      ];
    }
    return [];
  }

  /**
   * Maps a validator error path onto the operation's parameter space.
   *
   * The validator's paths are map-relative ("{key}.{condition}.{property}");
   * the operation names the key and condition as parameters, so errors point
   * at the parameter that carries the bad value.
   */
  protected function operationPath(string $path, string $key, string $condition): string {
    if ($path === $key) {
      return 'key';
    }
    $remainder = substr($path, strlen($key) + 1);
    if ($remainder === $condition) {
      return 'condition';
    }
    if (str_starts_with($remainder, "$condition.")) {
      return 'properties.' . substr($remainder, strlen($condition) + 1);
    }
    return $path;
  }

  /**
   * Reads the host's style map.
   */
  protected function getStyleMap(HostInterface $host): array {
    return $host->styleMap();
  }

  /**
   * Writes the style map back and builds the success result.
   *
   * @param \Drupal\Core\Entity\HostInterface $host
   *   The host entity.
   * @param array $map
   *   The style map to write.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Loud non-blocking observations (apply's integrity skips).
   */
  protected function setStyleMap(HostInterface $host, array $map, array $warnings = []): BlueprintResult {
    $host->setStyleMap($map);

    return BlueprintResult::success($host, $warnings)
      ->withTargetsFrom($host->uuid(), [$host->styleMapDefinition()->getName()]);
  }

}
