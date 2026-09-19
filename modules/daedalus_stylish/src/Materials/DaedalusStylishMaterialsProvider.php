<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Materials;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialGestureResult;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Materials\ValueGrammar;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\AnimationManager;
use Drupal\daedalus_stylish\AnimationRepository;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleEntry;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\SwatchManager;
use Drupal\daedalus_stylish\SwatchRepository;
use Drupal\daedalus_stylish\VariantCapture;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\daedalus_stylish\VariantManager;
use Drupal\daedalus_stylish\VariantRepository;

/**
 * Daedalus Stylish's materials: tokens, swatches, variants and animations.
 *
 * The active skin's tokens, the swatches, the variants and the animations, each
 * carrying its design-system membership.
 *
 * The ONE place daedalus_stylish's four material kinds are read into roster
 * records; every material surface (catalog, prompt, schema, validators,
 * client payload, delivery CSS) projects from the roster instead of
 * walking the skin, the swatch storage, the variant storage and the
 * animation storage on its own. Sources and orders are the ones their
 * consumers already agree on:
 * - tokens: the active PUBLISHED skin's minted tokens on the declared
 *   scales, in declared scale order then stored token order (the
 *   compiled skin file's order, deterministic regardless of the stored
 *   scale order), id "{scale}/{name}" (names repeat across scales),
 *   group the scale, value as stored; the style spelling is var() over
 *   the one variable derivation (StyleGuide::tokenVariable()). An
 *   unpublished active skin delivers nothing, so it rosters nothing.
 * - swatches: SwatchRepository::publishedSwatches() (label, then machine
 *   name), group the swatch kind, value as stored; the style spelling is
 *   var() over the reserved namespace (StyleGuide::swatchVariable()).
 * - variants: VariantRepository::publishedVariants() (weight, label, then
 *   machine name) minus the ones the site's current law refuses
 *   (VariantConformance, see records()), group the axis, value the
 *   element-relative style map as stored (the client's layer math resolves a
 *   worn reference through it for live preview; the prompt and the schema never
 *   print it), scope as saved, applies {depth: root} when the axis is root-only
 *   in the scope's declaration, owned_by from the stencil ownership fields when
 *   that module exists. Spellings derive from the scope's variant_axes
 *   declaration through the ONE rule (spellings()), never hand-written, and the
 *   provider validates every published variant's axis against that declaration:
 *   a style-axis variant spells as its machine name in the class slot; a
 *   variant on a declared non-style axis of a scope the dialect has a tag for
 *   (kind:container, kind:block, block_type:*, block_plugin:*) spells as that
 *   axis's attribute (axisAttr()) on the element's tag with the value
 *   attrValue() derives, in the scope's own slot
 *   (MaterialRecord::attrSlot(scope, attribute): the value partitions per
 *   scope, so two families on one axis may share look names); a declared
 *   non-style axis on a scope with no tag of its own (layout:*, base:*) has no
 *   page-language spelling (the JSON lane speaks the machine name); an axis the
 *   scope's declaration does not carry is refused loudly (the variant entity
 *   refuses to save one, so only a declaration edit can orphan a variant, and
 *   declaration edits fail loud everywhere else in the style guide).
 * - animations: AnimationRepository::publishedAnimations() (label then
 *   machine name, the compiled keyframes file's order, so delivery and
 *   validation cannot disagree), value the steps map as stored (canonical
 *   offsets; the editor and the preview print read it, the prompt and the
 *   schema project it), no group; the spelling is the keyframes name in
 *   the property-scoped animation slot (StyleGuide::animationName(),
 *   motion-{name}), never var(): a keyframes name is a custom-ident.
 *
 * A swatch's, variant's or animation's origin is its entity's origin field
 * (shipped, stamped by the importer; user or ai, stamped by the lane that
 * minted it); a token's is its skin's (shipped when a module ships the
 * skin).
 *
 * The swatch, variant and animation gestures (save_material /
 * update_material / remove_material with kind swatch, variant or
 * animation) dispatch here from the op spine; the palette's, the
 * Materials section's and the inspector's gestures and the swatch,
 * variant and animation routes (adapters) reach the same methods. The
 * provider owns the lane end to end: the permission grade per verb
 * (create / update / delete swatches, variants and animations, or
 * administer), capture from the host's staged state (a swatch from the
 * literal value an element wears for a property, a variant from the
 * element's whole look through VariantCapture; an animation is always
 * given outright, a keyframes block lives on no element), the AI-minting
 * governance gate for the AI's swatch and animation mints
 * (daedalus_stylish.settings ai_minting, the same gate the blueprint create lane
 * enforces), and the origin stamp. The managers are the one
 * implementation behind both doors; they arrive as closures because the
 * dependency is circular by construction (they validate through
 * StyleMapValidator, whose universe is the roster this provider feeds), as
 * do the element read and the roster builder itself.
 */
final class DaedalusStylishMaterialsProvider implements MaterialsProviderInterface {

  /**
   * The keys update_material may change per kind.
   */
  private const SWATCH_UPDATE_KEYS = ['label', 'value', 'description', 'offered'];
  private const VARIANT_UPDATE_KEYS = ['label', 'description', 'weight', 'offered', 'element', 'styles'];
  private const ANIMATION_UPDATE_KEYS = ['label', 'description', 'steps', 'offered'];

  public function __construct(
    protected StyleGuide $styleGuide,
    protected SkinManager $skinManager,
    protected PageBuilderDeclarations $declarations,
    protected SwatchRepository $swatchRepository,
    protected VariantRepository $variantRepository,
    protected AnimationRepository $animationRepository,
    protected \Closure $swatchManager,
    protected \Closure $variantManager,
    protected \Closure $animationManager,
    protected \Closure $variantCapture,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected \Closure $elementMaterials,
    protected ValueGrammar $valueGrammar,
    protected \Closure $rosterBuilder,
    protected VariantConformance $conformance,
    protected DistroLock $distroLock,
  ) {}

  /**
   * {@inheritdoc}
   *
   * The unjudged records minus the variants the current law refuses
   * (VariantConformance::withheld()): a nonconforming variant leaves the
   * roster exactly as an inactive system's member does, so every offer
   * and delivery surface, the reference validation and the capture walk
   * all follow from this one read. The judge validates the unjudged set
   * against itself, never against the roster built here.
   */
  public function records(): array {
    $withheld = $this->conformance->withheld();
    return array_values(array_filter(
      $this->unjudgedRecords(),
      static fn (MaterialRecord $record): bool => $record->kind !== MaterialKind::Variant || !isset($withheld[$record->id]),
    ));
  }

  /**
   * Every published material as a record, judged by nothing.
   *
   * The conformance judge's universe (what the site would offer if every
   * published variant conformed to its law), never a roster consumer's
   * read: the roster is records(), the judged set.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records, in roster order.
   */
  public function unjudgedRecords(): array {
    $theme = $this->declarations->siteTheme();
    return [...$this->tokens($theme), ...$this->swatches(), ...$this->variants($theme), ...$this->animations()];
  }

  /**
   * {@inheritdoc}
   */
  public function cacheMetadata(): CacheableMetadata {
    // The client guide's domain (the declarations the spellings and scales
    // derive from, daedalus_stylish.settings with the active-skin pointer, the
    // default theme, the skin list) plus the three entity lists the
    // records read: any swatch, variant or animation save, publish or
    // delete busts the roster.
    return (new CacheableMetadata())->addCacheTags([
      ...ClientStyleGuide::CACHE_TAGS,
      'daedalus_swatch_list',
      'daedalus_variant_list',
      'daedalus_animation_list',
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * Tokens have no gesture here: minting one is a skin write (the skins
   * skill's lane), not a material of its own.
   */
  public function gestureLines(): array {
    return [
      MaterialKind::Swatch->value => 'value, or element + property; description',
      MaterialKind::Variant->value => 'element, description, group: axis?; then wear it',
      MaterialKind::Animation->value => 'steps {"0%": {...}, "100%": {...}}, description; then wear it as animation-name',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function capture(MaterialKind $kind, array $operation, ?FieldableEntityInterface $entity, array $options): MaterialGestureResult {
    $origin = $options[MaterialGestureOperationHandler::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER;
    return match ($kind) {
      MaterialKind::Swatch => $this->captureSwatch($operation, $entity, $origin),
      MaterialKind::Variant => $this->captureVariant($operation, $entity, $origin),
      MaterialKind::Animation => $this->captureAnimation($operation, $origin),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider mints no %s.', $kind->noun())),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?FieldableEntityInterface $entity, array $options): MaterialGestureResult {
    return match ($kind) {
      MaterialKind::Swatch => $this->updateSwatch($id, $operation),
      MaterialKind::Variant => $this->updateVariant($id, $operation, $entity),
      MaterialKind::Animation => $this->updateAnimation($id, $operation),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider updates no %s.', $kind->noun())),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult {
    $stage_batch = (bool) ($options[MaterialGestureOperationHandler::BATCH_OPTION] ?? FALSE);
    return match ($kind) {
      MaterialKind::Swatch => $this->removeSwatch($id, $stage_batch),
      MaterialKind::Variant => $this->removeVariant($id, $stage_batch),
      MaterialKind::Animation => $this->removeAnimation($id, $stage_batch),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider removes no %s.', $kind->noun())),
    };
  }

  /**
   * Saves a swatch (save_material).
   *
   * Operation shape: {kind: swatch, label, value | element + property, group?,
   * description?}.
   *
   * The value is given outright (a CSS value or a condition-keyed map) or
   * captured from the selection: the literal an element wears for a
   * property in its own default layer (a reference is already a material,
   * and a value the element inherits is not the element's to save). The
   * group is the swatch kind: color by default, gradient when named or
   * when the value is a gradient function. The description is the
   * swatch's face in the design system brief (when to reach for it). The
   * AI's mint meets the ai_minting governance gate exactly as the
   * blueprint create lane does.
   * The mint joins the active design system unless "global": true opts
   * it out (the membership rule every capture shares, see globalOptOut()).
   */
  protected function captureSwatch(array $operation, ?FieldableEntityInterface $entity, string $origin): MaterialGestureResult {
    $this->requirePermission('create swatches', 'administer swatches', 'Minting swatches requires the "create swatches" permission.');
    $global = $this->globalOptOut($operation);
    if ($origin === MaterialRecord::ORIGIN_AI && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'STYLE_SWATCH_AI_MINTING_DISABLED', 'AI swatch minting is disabled on this site.', [], 'Reference a published palette swatch or use a literal value instead of saving a swatch; a person with the "create swatches" permission can mint it in the palette, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $value = $operation['value'] ?? NULL;
    if ($value === NULL && isset($operation['element'])) {
      $value = $this->literalWorn($entity, (string) $operation['element'], $operation['property'] ?? NULL);
    }
    if (!is_string($value) && !is_array($value)) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'MISSING_PARAMETER', 'A swatch needs a value: pass "value", or "element" and "property" to capture the value an element wears.', [], 'Pass a CSS value ("#e94560", or {"default": "#e94560", "dark": "#f87171"}), or name the element (id or alias) and the property whose literal value to save.'),
      ]);
    }
    $group = $operation['group'] ?? NULL;
    if ($group !== NULL && !is_string($group)) {
      throw new MaterialGestureException([
        new BlueprintError('group', 'INVALID_PARAMETER', 'group must be a swatch kind name.', [], 'Pass "color" (the default) or "gradient", or omit the key.'),
      ]);
    }
    $group ??= $this->inferSwatchKind($value);
    $description = $operation['description'] ?? '';
    if (!is_scalar($description)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text.', [], 'Pass "description" as a sentence on when to reach for the value, or omit it.'),
      ]);
    }
    $swatch = $this->swatchManager()->mint(is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '', $group, $value, $origin, $global, (string) $description, $this->offeredFlag($operation) ?? TRUE);
    return new MaterialGestureResult(MaterialKind::Swatch, $swatch->getMachineName(), [], $swatch);
  }

  /**
   * Updates a swatch (update_material).
   *
   * Operation shape: {kind: swatch, id, label?, value?, description?,
   * offered?}.
   */
  protected function updateSwatch(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update swatches', 'administer swatches', 'Updating swatches requires the "update swatches" permission.');
    $swatch = $this->swatchManager()->loadSwatch($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Swatch, $id)]);
    $changes = array_intersect_key($operation, array_flip(self::SWATCH_UPDATE_KEYS));
    $label = $changes['label'] ?? NULL;
    $value = $changes['value'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    if (($label !== NULL && !is_string($label)) || ($value !== NULL && !is_string($value) && !is_array($value)) || ($description !== NULL && !is_scalar($description))) {
      throw new MaterialGestureException([
        new BlueprintError($label !== NULL && !is_string($label) ? 'label' : ($description !== NULL && !is_scalar($description) ? 'description' : 'value'), 'INVALID_PARAMETER', 'label and description must be text; value a CSS value or a condition-keyed map.', [], 'Pass "label" and "description" as text, "value" as "#e94560" or {"default": ..., "dark": ...}.'),
      ]);
    }
    $this->swatchManager()->update($swatch, $label, $value, $description === NULL ? NULL : (string) $description, $this->offeredFlag($changes));
    return new MaterialGestureResult(MaterialKind::Swatch, $swatch->getMachineName(), [], $swatch);
  }

  /**
   * Removes a swatch (remove_material).
   *
   * Operation shape: {kind: swatch, id}.
   */
  protected function removeSwatch(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete swatches', 'administer swatches', 'Deleting swatches requires the "delete swatches" permission.', 'delete swatches in use');
    $swatch = $this->swatchManager()->loadSwatch($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Swatch, $id)]);
    $machine_name = $swatch->getMachineName();
    $staged = $this->swatchManager()->deleteSwatch($swatch, $stage_batch);
    return new MaterialGestureResult(MaterialKind::Swatch, $machine_name, [], $staged > 0 ? $swatch : NULL, $staged);
  }

  /**
   * Saves a variant (save_material).
   *
   * Operation shape: {kind: variant, element | key + styles, label, ...}.
   *
   * The page language names the element by id (the AI's door); the
   * inspector names it by selectable key and sends the capture it already
   * made for live preview (`key` + `styles`, the push bar's save session,
   * so the shadow-clearing ops read the same walk). Without `styles` the
   * server captures the element's look itself (VariantCapture). `group`
   * is the axis (the record's group), `owned_by` a stencil whose
   * placements alone offer it, `description` and `weight` the roster face,
   * `global` the opt-out from the active design system (globalOptOut()),
   * `offered` the AI opt-out (offeredFlag(): false keeps the look for UI
   * use only).
   */
  protected function captureVariant(array $operation, ?FieldableEntityInterface $entity, string $origin): MaterialGestureResult {
    $this->requirePermission('create variants', 'administer variants', 'Minting variants requires the "create variants" permission.');
    $global = $this->globalOptOut($operation);
    $key = $this->variantKey($operation, $entity);
    $axis = $this->axis($operation);
    $styles = $operation['styles'] ?? NULL;
    if ($styles !== NULL && !is_array($styles)) {
      throw new MaterialGestureException([
        new BlueprintError('styles', 'INVALID_PARAMETER', 'styles must be an element-relative style map.', [], 'Omit "styles" to capture the element\'s look, or pass {"": {...}, "::.class": {...}}.'),
      ]);
    }
    $styles ??= $this->capturer()->capture($entity, $key, $axis ?? StyleGuide::STYLE_AXIS);
    $owned_by = $operation['owned_by'] ?? NULL;
    $description = $operation['description'] ?? '';
    $weight = $operation['weight'] ?? 0;
    if (($owned_by !== NULL && !is_string($owned_by)) || !is_scalar($description) || !is_numeric($weight)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text, weight a number, owned_by a stencil id.', [], 'Pass "description" as a sentence on when to reach for the look, "weight" as an integer, "owned_by" as a stencil id or omit it.'),
      ]);
    }
    $variant = $this->variantManager()->saveAsVariant(
      is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '',
      $key,
      $styles,
      $entity,
      $axis,
      $owned_by,
      $origin,
      (string) $description,
      (int) $weight,
      $global,
      $this->offeredFlag($operation) ?? TRUE,
    );
    return new MaterialGestureResult(MaterialKind::Variant, $variant->getMachineName(), [], $variant);
  }

  /**
   * Updates a variant (update_material).
   *
   * Operation shape: {kind: variant, id, element | key + styles, label,
   * description, weight, offered}.
   *
   * A push (element or styles) and the face each land as their own
   * revision; a push from an element WEARING the variant merges the
   * capture over the variant's current styles (bake my tweaks in), from
   * an unattached element it replaces them wholesale. `styles` with no
   * `key` and no `element` is the MASTER push (the push bar's update
   * session: the variant edited in isolation, validated against its own
   * scope).
   */
  protected function updateVariant(string $id, array $operation, ?FieldableEntityInterface $entity): MaterialGestureResult {
    $this->requirePermission('update variants', 'administer variants', 'Updating variants requires the "update variants" permission.');
    $variant = $this->variantManager()->loadVariant($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Variant, $id)]);
    $changes = array_intersect_key($operation, array_flip(self::VARIANT_UPDATE_KEYS));
    if ($changes === []) {
      throw new MaterialGestureException([
        new BlueprintError('', 'MISSING_PARAMETER', 'update_material changes nothing: pass at least one key.', ['keys' => self::VARIANT_UPDATE_KEYS], 'Pass "element" (or "key" and "styles") to push a fresh look, or "label" / "description" / "weight" for the face.'),
      ]);
    }
    if (isset($changes['element']) || isset($changes['styles'])) {
      $key = isset($operation['element']) || isset($operation['key']) ? $this->variantKey($operation, $entity) : '';
      $styles = $changes['styles'] ?? NULL;
      if ($styles !== NULL && !is_array($styles)) {
        throw new MaterialGestureException([
          new BlueprintError('styles', 'INVALID_PARAMETER', 'styles must be an element-relative style map.', [], 'Omit "styles" to capture the element\'s look, or pass {"": {...}, "::.class": {...}}.'),
        ]);
      }
      if ($styles === NULL) {
        $styles = $this->capturer()->capture($entity, $key, $variant->getAxis());
        if ($this->wears($entity, $key, $variant)) {
          $merged = $variant->getStyles();
          foreach ($styles as $relative => $entry) {
            $merged[$relative] = isset($merged[$relative]) && is_array($merged[$relative]) ? StyleEntry::merge($entry, $merged[$relative]) : $entry;
          }
          $styles = $merged;
        }
      }
      $this->variantManager()->push($variant, $key, $styles, $entity);
    }
    $label = $changes['label'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    $weight = $changes['weight'] ?? NULL;
    $offered = $this->offeredFlag($changes);
    if ($label !== NULL || $description !== NULL || $weight !== NULL || $offered !== NULL) {
      if (($label !== NULL && !is_string($label)) || ($description !== NULL && !is_scalar($description)) || ($weight !== NULL && !is_numeric($weight))) {
        throw new MaterialGestureException([
          new BlueprintError('label', 'INVALID_PARAMETER', 'label and description must be text, weight a number.', [], 'Pass "label" and "description" as text, "weight" as an integer.'),
        ]);
      }
      $this->variantManager()->updateDetails($variant, $label, $description === NULL ? NULL : (string) $description, $weight === NULL ? NULL : (int) $weight, $offered);
    }
    return new MaterialGestureResult(MaterialKind::Variant, $variant->getMachineName(), [], $variant);
  }

  /**
   * Removes a variant (remove_material).
   *
   * Operation shape: {kind: variant, id}.
   */
  protected function removeVariant(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete variants', 'administer variants', 'Deleting variants requires the "delete variants" permission.', 'delete variants in use');
    $variant = $this->variantManager()->loadVariant($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Variant, $id)]);
    $machine_name = $variant->getMachineName();
    $staged = $this->variantManager()->deleteVariant($variant, $stage_batch);
    return new MaterialGestureResult(MaterialKind::Variant, $machine_name, [], $staged > 0 ? $variant : NULL, $staged);
  }

  /**
   * Saves an animation (save_material).
   *
   * Operation shape: {kind: animation, label, steps, description?, offered?}.
   *
   * The steps are always given outright: a keyframes block lives on no
   * element, so there is nothing to capture from a selection. The AI's
   * mint meets the ai_minting governance gate exactly as the blueprint
   * create lane does (the same setting gates swatches), and joins the
   * active design system unless "global": true opts it out.
   */
  protected function captureAnimation(array $operation, string $origin): MaterialGestureResult {
    $this->requirePermission('create animations', 'administer animations', 'Minting animations requires the "create animations" permission.');
    $global = $this->globalOptOut($operation);
    if ($origin === MaterialRecord::ORIGIN_AI && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      throw new MaterialGestureException([
        new BlueprintError('steps', 'STYLE_ANIMATION_AI_MINTING_DISABLED', 'AI animation minting is disabled on this site.', [], 'Wear a published animation from the MATERIALS roster instead of saving one; a person with the "create animations" permission can mint it in the Materials section, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $steps = $operation['steps'] ?? NULL;
    if (!is_array($steps)) {
      throw new MaterialGestureException([
        new BlueprintError('steps', 'MISSING_PARAMETER', 'An animation needs steps: an object keyed by offset mapping CSS properties to values.', [], 'Pass "steps" as {"0%": {"opacity": "0"}, "100%": {"opacity": "1"}} (offsets "from"/"to" or percentages, at least one step).'),
      ]);
    }
    $description = $operation['description'] ?? '';
    if (!is_scalar($description)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text.', [], 'Pass "description" as a sentence on when to reach for the motion, or omit it.'),
      ]);
    }
    $animation = $this->animationManager()->mint(is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '', $steps, (string) $description, $origin, $global, $this->offeredFlag($operation) ?? TRUE);
    return new MaterialGestureResult(MaterialKind::Animation, $animation->getMachineName(), [], $animation);
  }

  /**
   * Updates an animation (update_material).
   *
   * Operation shape: {kind: animation, id, label?, description?, steps?,
   * offered?}.
   */
  protected function updateAnimation(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update animations', 'administer animations', 'Updating animations requires the "update animations" permission.');
    $animation = $this->animationManager()->loadAnimation($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Animation, $id)]);
    $changes = array_intersect_key($operation, array_flip(self::ANIMATION_UPDATE_KEYS));
    $label = $changes['label'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    $steps = $changes['steps'] ?? NULL;
    if (($label !== NULL && !is_string($label)) || ($description !== NULL && !is_scalar($description)) || ($steps !== NULL && !is_array($steps))) {
      throw new MaterialGestureException([
        new BlueprintError($label !== NULL && !is_string($label) ? 'label' : ($description !== NULL && !is_scalar($description) ? 'description' : 'steps'), 'INVALID_PARAMETER', 'label and description must be text; steps an object keyed by offset.', [], 'Pass "label" and "description" as text, "steps" as {"0%": {...}, "100%": {...}}.'),
      ]);
    }
    $this->animationManager()->update($animation, $label, $steps, $description === NULL ? NULL : (string) $description, $this->offeredFlag($changes));
    return new MaterialGestureResult(MaterialKind::Animation, $animation->getMachineName(), [], $animation);
  }

  /**
   * Removes an animation (remove_material).
   *
   * Operation shape: {kind: animation, id}.
   */
  protected function removeAnimation(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete animations', 'administer animations', 'Deleting animations requires the "delete animations" permission.', 'delete animations in use');
    $animation = $this->animationManager()->loadAnimation($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Animation, $id)]);
    $machine_name = $animation->getMachineName();
    $staged = $this->animationManager()->deleteAnimation($animation, $stage_batch);
    return new MaterialGestureResult(MaterialKind::Animation, $machine_name, [], $staged > 0 ? $animation : NULL, $staged);
  }

  /**
   * Reads a capture's "global" key: the opt-out from the active design system.
   *
   * Affiliation is the default and global the explicit exception (a mint
   * happens against the vocabulary the editor is looking at, so that is
   * the membership they mean); the managers resolve the active system,
   * this only reads the flag. Absent means FALSE.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When the key is present and not a boolean.
   */
  protected function globalOptOut(array $operation): bool {
    $global = $operation['global'] ?? FALSE;
    if (!is_bool($global)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'global must be true or false.', [], 'Pass "global": true to mint a material that belongs to no design system (offered under every one), or omit the key to join the active design system.'),
      ]);
    }
    return $global;
  }

  /**
   * The AI opt-out an operation carries, or NULL when it names none.
   *
   * "offered": false keeps the material for UI use only (the roster's
   * offered view omits it; legality never reads it); true, or an absent
   * key on a mint, shares it, the default every material has
   * (MaterialRecord::$offered). An update passes NULL through to leave
   * the stored flag alone.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When the key is present and not a boolean.
   */
  protected function offeredFlag(array $operation): ?bool {
    if (!array_key_exists('offered', $operation)) {
      return NULL;
    }
    if (!is_bool($operation['offered'])) {
      throw new MaterialGestureException([
        new BlueprintError('offered', 'INVALID_PARAMETER', 'offered must be true or false.', [], 'Pass "offered": false to keep the material out of the AI\'s roster (it still applies everywhere), or omit the key to share it.'),
      ]);
    }
    return $operation['offered'];
  }

  /**
   * The literal value an element wears for a property, from its own layer.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function literalWorn(?FieldableEntityInterface $entity, string $element, mixed $property): string {
    if (!is_string($property) || $property === '') {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MISSING_PARAMETER', 'Capturing a swatch from an element needs the CSS property to read.', [], 'Pass "property" (background-color, color, background-image, ...) beside "element".'),
      ]);
    }
    $key = $this->keyFor($entity, $element);
    $map = $entity->hasField(DaedalusStylishFieldManager::FIELD_NAME) ? $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first() : NULL;
    $map = $map instanceof StyleMapItem ? $map->getStyleMap() : [];
    $value = $map[$key]['default'][$property] ?? NULL;
    if (!is_string($value) || $value === '') {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MATERIAL_CAPTURE_FAILED', sprintf('The element wears no %s of its own.', $property), ['key' => $key], 'A swatch captures a value the element itself declares in its default layer; style the element with the value first, or pass "value" outright.'),
      ]);
    }
    $reference = $this->valueGrammar->resolve($value, MaterialRecord::SLOT_STYLE, ($this->rosterBuilder)()->build());
    if ($reference->kind !== ValueKind::Literal) {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MATERIAL_CAPTURE_FAILED', sprintf('The element\'s %s is %s, not a literal value.', $property, $value), [
          'key' => $key,
          'value' => $value,
        ], 'Only an inline literal becomes a swatch; this value already names a material (or a media/generated value).'),
      ]);
    }
    return $value;
  }

  /**
   * The swatch kind a value implies: gradient functions, else color.
   */
  protected function inferSwatchKind(string|array $value): string {
    $sample = is_array($value) ? ($value['default'] ?? reset($value)) : $value;
    return is_string($sample) && preg_match('/-gradient\s*\(/i', $sample) === 1 ? 'gradient' : 'color';
  }

  /**
   * The style key a variant gesture addresses.
   *
   * The client's key or the page language's element.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function variantKey(array $operation, ?FieldableEntityInterface $entity): string {
    $key = $operation['key'] ?? NULL;
    if (is_string($key) && $key !== '') {
      return $key;
    }
    $element = $operation['element'] ?? NULL;
    if (!is_string($element) || $element === '') {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A variant is saved from an element.', [], 'Name the element (its id in the page print, or the alias you gave it) whose look to save.'),
      ]);
    }
    return $this->keyFor($entity, $element);
  }

  /**
   * The style key of a placed element, from the host's staged tree.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function keyFor(?FieldableEntityInterface $entity, string $element): string {
    if ($entity === NULL) {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A capture reads an element of a page; this lane has no page behind it.', [], 'Run the gesture as a page_edit op on the page the element sits on.'),
      ]);
    }
    return ($this->elementMaterials)()->keyFor($entity, $element) ?? throw new MaterialGestureException([
      new BlueprintError('element', 'UNKNOWN_REF', sprintf('"%s" is not an element of this page.', $element), [], 'Refs are the ids in the page print (uuids or data-alias values).'),
    ]);
  }

  /**
   * Whether an element's own entry references a variant (any fragment).
   */
  protected function wears(?FieldableEntityInterface $entity, string $key, DaedalusStylishVariantInterface $variant): bool {
    if ($entity === NULL || $key === '' || !$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return FALSE;
    }
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $item instanceof StyleMapItem ? $item->getStyleMap() : [];
    $forms = [$variant->getMachineName(), $variant->uuid()];
    foreach (StyleMapValidator::entryRefs($map[$key] ?? NULL) as $ref) {
      if (in_array($ref['variant'], $forms, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The variant axis a gesture names through `group`, or NULL for style.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function axis(array $operation): ?string {
    $group = $operation['group'] ?? NULL;
    if ($group !== NULL && !is_string($group)) {
      throw new MaterialGestureException([
        new BlueprintError('group', 'INVALID_PARAMETER', 'group must be an axis name.', [], 'Pass the axis the variant competes on (the record\'s group), or omit it for the built-in "style" axis.'),
      ]);
    }
    return $group === '' ? NULL : $group;
  }

  /**
   * A permission gate per verb: the grade, or the administer grade.
   *
   * The delete verbs admit the in-use grade too (the route posture): the
   * manager decides whether a REFERENCED material's removal needs it.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requirePermission(string $grade, string $administer, string $message, ?string $stronger = NULL): void {
    if (!$this->currentUser->hasPermission($grade) && !$this->currentUser->hasPermission($administer) && ($stronger === NULL || !$this->currentUser->hasPermission($stronger))) {
      throw new MaterialGestureException([
        new BlueprintError('', 'PERMISSION_DENIED', $message, ['permission' => $grade], 'Ask an administrator for the permission, or use the published materials as they are.'),
      ]);
    }
  }

  /**
   * The not-found error for a material id, in the gesture's parameter space.
   */
  protected function notFound(MaterialKind $kind, string $id): BlueprintError {
    $code = match ($kind) {
      MaterialKind::Swatch => 'STYLE_SWATCH_NOT_FOUND',
      MaterialKind::Variant => 'STYLE_VARIANT_NOT_FOUND',
      MaterialKind::Animation => 'STYLE_ANIMATION_NOT_FOUND',
      default => throw new \LogicException(sprintf('The daedalus_stylish provider owns no %s.', $kind->noun())),
    };
    return new BlueprintError('id', $code, sprintf('"%s" is not a published %s.', $id, $kind->noun()), [], sprintf('Reference a %s by its id from the MATERIALS roster.', $kind->noun()));
  }

  /**
   * The swatch manager (a closure: the dependency is circular).
   */
  protected function swatchManager(): SwatchManager {
    return ($this->swatchManager)();
  }

  /**
   * The variant manager (a closure: the dependency is circular).
   */
  protected function variantManager(): VariantManager {
    return ($this->variantManager)();
  }

  /**
   * The animation manager (a closure: the dependency is circular).
   */
  protected function animationManager(): AnimationManager {
    return ($this->animationManager)();
  }

  /**
   * The server capture walk (a closure: the dependency is circular).
   */
  protected function capturer(): VariantCapture {
    return ($this->variantCapture)();
  }

  /**
   * The page-language spellings of a variant, from its scope's axes.
   *
   * The one declaration behind attr vs class: the scope's variant_axes
   * (StyleGuide::axes() of the effective declaration, the style axis
   * first) decides everything, so the catalog's axis attributes, the
   * printer's wear and the parser's acceptance cannot drift from what the
   * variant entity was saved against.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $scope
   *   The variant's scope ("kind:container", "block_type:text", ...).
   * @param string $axis
   *   The variant's axis machine name.
   * @param string $machine_name
   *   The variant's machine name.
   * @param string $label
   *   The variant's label (an unprefixed axis value spells as its label).
   * @param string[] $declared_axes
   *   The scope's axis vocabulary, StyleGuide::axes() of its declaration.
   *
   * @return array<string, string>
   *   slot => spelling: the class slot for the style axis, the scope's own
   *   attr:{scope}:{attribute} slot for a declared axis of a scope with a
   *   tag (hasTag(); the value partitions per scope, so block_fade_in and
   *   container_fade_in both spell animation="fade_in"), [] for a
   *   declared axis on any other scope AND for an axis the scope no longer
   *   declares. The entity refuses to save onto an undeclared axis, so such
   *   a row is a legacy one a declaration change left behind (the retired
   *   proportions and arrangement axes) that a post_update migration moves
   *   one save at a time; each of those saves compiles the roster while
   *   its siblings still sit on the retired axis, so the roster carries the
   *   row spelling-less (its wearers keep their CSS, existing references
   *   stay valid, the JSON door still addresses it by id) instead of
   *   bricking every roster build until the migration that cannot run.
   */
  public static function spellings(Address $scope, string $axis, string $machine_name, string $label, array $declared_axes): array {
    if (!in_array($axis, $declared_axes, TRUE)) {
      return [];
    }
    if ($axis === StyleGuide::STYLE_AXIS) {
      return [MaterialRecord::SLOT_CLASS => $machine_name];
    }
    if (self::hasTag($scope)) {
      return [MaterialRecord::attrSlot((string) $scope, self::axisAttr($scope, $axis)) => self::attrValue($scope, $machine_name, $label)];
    }
    return [];
  }

  /**
   * Whether a variant scope's elements have a dialect tag of their own.
   *
   * A declared non-style axis on such a scope spells as an attribute on
   * that tag: the container kind on <pb-container>, the block kind on
   * every block form, a block type on its <pb-{type}> (or its generic
   * form), a base block plugin on its <pb-block plugin>. A layout scope
   * (a section layout beside the container) and a base:{tag} treatment
   * have no tag of their own, so their axes stay JSON-only.
   */
  public static function hasTag(Address $scope): bool {
    return match ($scope->family) {
      Address::FAMILY_KIND => in_array($scope->id, [Address::KIND_CONTAINER, Address::KIND_BLOCK], TRUE),
      Address::FAMILY_BLOCK_TYPE, Address::FAMILY_BLOCK_PLUGIN => TRUE,
      default => FALSE,
    };
  }

  /**
   * The axis attribute spelling for an axis machine name on a scope.
   *
   * The dialect's attribute is the axis minus the scope's own prefix (the
   * scope id and an underscore): container_layout -> layout on
   * kind:container, width -> width, testimonial_emphasis -> emphasis on
   * block_type:testimonial.
   */
  public static function axisAttr(Address $scope, string $axis): string {
    return self::stripScopePrefix($scope, $axis);
  }

  /**
   * The attribute value spelling for an axis variant on a scope.
   *
   * The machine name minus the scope's own prefix when prefixed
   * (container_full -> full on kind:container, testimonial_emphatic ->
   * emphatic on block_type:testimonial), else the label lowercased with
   * spaces as hyphens (row, labeled "Columns" -> columns).
   */
  public static function attrValue(Address $scope, string $machine_name, string $label): string {
    $value = self::stripScopePrefix($scope, $machine_name);
    if ($value !== $machine_name) {
      return $value;
    }
    return strtolower(preg_replace('/\s+/', '-', trim($label)));
  }

  /**
   * A name minus the scope's id prefix ("container_", "testimonial_").
   */
  protected static function stripScopePrefix(Address $scope, string $name): string {
    $prefix = $scope->id . '_';
    return str_starts_with($name, $prefix) && strlen($name) > strlen($prefix) ? substr($name, strlen($prefix)) : $name;
  }

  /**
   * The roster ids a skin's tokens carry, whether or not it is active.
   *
   * The change hooks' read: a skin write or an active-pointer change
   * records every id the skin carried before and carries now on the
   * request's MaterialsChangeSet, and the delta resolves each against the
   * fresh roster. Declared scales only, the same walk the records make, so
   * an undeclared scale never yields a spurious remove.
   *
   * @return string[]
   *   The "{scale}/{name}" ids, declared scale order then stored order.
   */
  public function tokenIds(DaedalusStylishSkinInterface $skin): array {
    return array_map(
      static fn (array $token): string => $token['scale'] . '/' . $token['name'],
      $this->tokenWalk($skin, $this->declarations->siteTheme()),
    );
  }

  /**
   * The active published skin's minted tokens on declared scales.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per token, declared scale order then stored order.
   */
  protected function tokens(?string $theme): array {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    $origin = $skin->isShipped() ? MaterialRecord::ORIGIN_SHIPPED : MaterialRecord::ORIGIN_USER;
    // A token is its skin's: a member skin's tokens are that system's
    // materials, offered only while it is active.
    $design_system = $skin->getDesignSystem();
    return array_map(
      static fn (array $token): MaterialRecord => new MaterialRecord(
        kind: MaterialKind::Token,
        id: $token['scale'] . '/' . $token['name'],
        label: $token['name'],
        value: $token['value'],
        group: $token['scale'],
        spellings: [MaterialRecord::SLOT_STYLE => 'var(' . StyleGuide::tokenVariable($token['scale'], $token['declaration'], $token['name']) . ')'],
        origin: $origin,
        designSystem: $design_system,
      ),
      $this->tokenWalk($skin, $theme),
    );
  }

  /**
   * A skin's tokens on the theme's declared scales, in roster order.
   *
   * @return array<int, array{scale: string, declaration: array, name: string, value: mixed}>
   *   One entry per token, declared scale order then stored order.
   */
  protected function tokenWalk(DaedalusStylishSkinInterface $skin, ?string $theme): array {
    $tokens = $skin->getTokens();
    $walk = [];
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      $scale = (string) $scale;
      $names = $tokens[$scale] ?? NULL;
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      foreach ($names as $name => $value) {
        $walk[] = [
          'scale' => $scale,
          'declaration' => $declaration,
          'name' => (string) $name,
          'value' => $value,
        ];
      }
    }
    return $walk;
  }

  /**
   * Whether a record of the given origin may be written by the update gesture.
   *
   * The `updatable` record flag is the distro lock as the client and the
   * AI read it: a shipped swatch, variant or animation is locked while the
   * lock holds (a consuming site) and updatable once the developer gate
   * lifts it (DistroLock). Origin is the record fact the flag keys on
   * (the entity carries a provider), the same fact the client's shipped
   * gestures (revert, copy) read. Records are cached under the roster's
   * tags, so a flag flip needs a cache rebuild to reach the client.
   */
  protected function updatable(string $origin): bool {
    return !($origin === MaterialRecord::ORIGIN_SHIPPED && $this->distroLock->holds());
  }

  /**
   * The published swatches, label order.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per published swatch.
   */
  protected function swatches(): array {
    return array_map(fn (DaedalusStylishSwatchInterface $swatch): MaterialRecord => new MaterialRecord(
      kind: MaterialKind::Swatch,
      id: $swatch->getMachineName(),
      label: (string) $swatch->label(),
      description: $swatch->getDescription(),
      value: $swatch->getSwatchValue(),
      group: $swatch->getKind(),
      spellings: [MaterialRecord::SLOT_STYLE => 'var(' . StyleGuide::swatchVariable($swatch->getMachineName()) . ')'],
      origin: $swatch->getOrigin(),
      updatable: $this->updatable($swatch->getOrigin()),
      designSystem: $swatch->getDesignSystem(),
      offered: $swatch->isOffered(),
    ), $this->swatchRepository->publishedSwatches());
  }

  /**
   * The published variants, in weight, label, then machine name order.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per published variant.
   */
  protected function variants(?string $theme): array {
    $declarations = [];
    $records = [];
    foreach ($this->variantRepository->publishedVariants() as $variant) {
      $name = $variant->getMachineName();
      $scope = Address::fromString($variant->getScope());
      $axis = $variant->getAxis();
      $declaration = $declarations[(string) $scope] ??= $this->scopeDeclaration($scope, $theme);
      $applies = [];
      if (in_array($axis, StyleGuide::rootOnlyAxes($declaration), TRUE)) {
        $applies[MaterialRecord::APPLIES_DEPTH] = MaterialRecord::DEPTH_ROOT;
      }
      $records[] = new MaterialRecord(
        kind: MaterialKind::Variant,
        id: $name,
        label: (string) $variant->label(),
        description: $variant->getDescription(),
        value: $variant->getStyles(),
        group: $axis,
        spellings: self::spellings($scope, $axis, $name, (string) $variant->label(), StyleGuide::axes($declaration)),
        scope: $scope,
        applies: $applies,
        ownedBy: $this->ownerName($variant),
        weight: $variant->getWeight(),
        origin: $variant->getOrigin(),
        updatable: $this->updatable($variant->getOrigin()),
        designSystem: $variant->getDesignSystem(),
        offered: $variant->isOffered(),
      );
    }
    return $records;
  }

  /**
   * The published animations, label-then-machine-name order.
   *
   * The same read the compiled keyframes file makes
   * (AnimationRepository::publishedAnimations()), so what validates and
   * what delivers are one set in one order.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per published animation.
   */
  protected function animations(): array {
    return array_map(fn (DaedalusStylishAnimationInterface $animation): MaterialRecord => new MaterialRecord(
      kind: MaterialKind::Animation,
      id: $animation->getMachineName(),
      label: (string) $animation->label(),
      description: $animation->getDescription(),
      value: $animation->getSteps(),
      spellings: [MaterialRecord::SLOT_ANIMATION => StyleGuide::animationName($animation->getMachineName())],
      origin: $animation->getOrigin(),
      updatable: $this->updatable($animation->getOrigin()),
      designSystem: $animation->getDesignSystem(),
      offered: $animation->isOffered(),
    ), $this->animationRepository->publishedAnimations());
  }

  /**
   * The style guide declaration a variant scope resolves to.
   */
  protected function scopeDeclaration(Address $scope, ?string $theme): array {
    return match ($scope->family) {
      Address::FAMILY_KIND => $this->styleGuide->getKind($scope->id, $theme),
      Address::FAMILY_BLOCK_TYPE => $this->styleGuide->getBlockType($scope->id, $theme),
      Address::FAMILY_BLOCK_PLUGIN => $this->styleGuide->getBlockPlugin($scope->id, $theme),
      Address::FAMILY_LAYOUT => $this->styleGuide->getLayout($scope->id, $theme),
      Address::FAMILY_BASE => [],
    };
  }

  /**
   * Resolves a variant's owning stencil's machine name, when owned.
   *
   * The ownership fields live in daedalus_blueprint_stencil (optional), one
   * flat machine-name space across both lanes: the stencil_file value IS
   * a file-shipped stencil's machine name, and a content stencil resolves
   * through the entity reference (see VariantImporter::syncOwner()).
   */
  protected function ownerName(DaedalusStylishVariantInterface $variant): ?string {
    if (!$variant->hasField('stencil')) {
      return NULL;
    }
    $file_id = $variant->get('stencil_file')->value ?? NULL;
    if (is_string($file_id) && $file_id !== '') {
      return $file_id;
    }
    $stencil = $variant->get('stencil')->entity ?? NULL;
    if ($stencil !== NULL && $stencil->hasField('machine_name')) {
      $name = $stencil->get('machine_name')->value;
      return is_string($name) && $name !== '' ? $name : NULL;
    }
    return NULL;
  }

}
