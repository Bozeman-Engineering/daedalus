<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Materials;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
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
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\AnimationManager;
use Drupal\daedalus_stylish\AnimationRepository;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleEntry;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\daedalus_stylish\DaedalusStylishFontInterface;
use Drupal\daedalus_stylish\FontManager;
use Drupal\daedalus_stylish\FontRepository;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\SwatchManager;
use Drupal\daedalus_stylish\SwatchRepository;
use Drupal\daedalus_stylish\TokenManager;
use Drupal\daedalus_stylish\VariantCapture;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\daedalus_stylish\VariantManager;
use Drupal\daedalus_stylish\VariantRepository;

/**
 * Daedalus Stylish's materials: tokens, swatches, variants, animations, fonts.
 *
 * The active skin's tokens, the swatches, the variants, the animations and
 * the fonts, each carrying its design-system membership.
 *
 * The ONE place daedalus_stylish's five material kinds are read into roster
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
 *   in the scope's declaration, owned_by from the blueprint ownership fields when
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
 * minted it); a token's is its layer's: shipped when a module ships the
 * skin and its package layer holds the name, user otherwise (a token the
 * site minted into the site layer, or any token of the site's own
 * skin). The row resolution reads it (VocabularyRoster::scaleStops(),
 * the client's scaleRow): a site-minted token follows a curated row's
 * stops on open and guarded systems and is withheld there on strict.
 *
 * The token, swatch, variant and animation gestures (save_material /
 * update_material / remove_material with kind token, swatch, variant or
 * animation) dispatch here from the op spine; the palette's, the
 * Materials section's and the inspector's gestures and the swatch,
 * variant and animation routes (adapters) reach the same methods. The
 * provider owns the lane end to end: the permission grade per verb
 * (create / update / delete tokens, swatches, variants and animations,
 * or the administer grade; a token's administer grade is "administer
 * skins", the skin being its entity), capture from the host's staged
 * state (a swatch from the literal value an element wears for a
 * property, a variant from the element's whole look through
 * VariantCapture; a token and an animation are always given outright,
 * a token is a fact of the active skin and a keyframes block lives on no
 * element), the AI-minting governance gate for the AI's token, swatch
 * and animation mints (daedalus_stylish.settings ai_minting, the same
 * gate the blueprint create lane enforces), and the origin stamp. The
 * managers are the one implementation behind both doors; they arrive as
 * closures because the dependency is circular by construction (they
 * validate through StyleMapValidator, whose universe is the roster this
 * provider feeds), as do the element read and the roster builder itself.
 * A token write lands in the active skin's SITE layer on a consuming
 * site, or in its one layer when the skin is the site's own or the
 * developer gate lifts the distro lock (SkinManager::writesSiteLayer(),
 * the one rule every skin write reads).
 */
final class DaedalusStylishMaterialsProvider implements MaterialsProviderInterface {

  /**
   * The keys update_material may change per kind.
   */
  private const TOKEN_UPDATE_KEYS = ['value', 'description', 'offered'];
  private const SWATCH_UPDATE_KEYS = ['label', 'value', 'description', 'offered'];
  private const VARIANT_UPDATE_KEYS = ['label', 'description', 'weight', 'offered', 'element', 'styles'];
  private const ANIMATION_UPDATE_KEYS = ['label', 'description', 'steps', 'offered'];
  private const FONT_UPDATE_KEYS = ['label', 'family', 'faces', 'description', 'offered'];

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
    protected \Closure $tokenManager,
    protected FontRepository $fontRepository,
    protected \Closure $fontManager,
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
    return [
      ...$this->tokens($theme),
      ...$this->swatches(),
      ...$this->variants($theme),
      ...$this->animations(),
      ...$this->fonts(),
    ];
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
      'daedalus_font_list',
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * A token's line names the scale (group) and the name (label): a token
   * is minted on a declared scale of the active skin, and its name is the
   * custom property's tail, never derived from a human label.
   */
  public function gestureLines(): array {
    return [
      MaterialKind::Token->value => 'group (the scale), label (the token name: lowercase, digits, hyphens), value; description, offered?; then reference it by its var() spelling',
      MaterialKind::Swatch->value => 'value, or element + property; description',
      MaterialKind::Variant->value => 'element, description, group: axis?; then wear it',
      MaterialKind::Animation->value => 'steps {"0%": {...}, "100%": {...}}, description; then wear it as animation-name',
      MaterialKind::Font->value => 'family, faces [{weight, style, source: {file: uuid}}] (uploaded font files), description; then name the family in a font-family stack',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function capture(MaterialKind $kind, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $origin = $options[MaterialGestureOperationHandler::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER;
    return match ($kind) {
      MaterialKind::Token => $this->captureToken($operation, $origin),
      MaterialKind::Swatch => $this->captureSwatch($operation, $host, $origin),
      MaterialKind::Variant => $this->captureVariant($operation, $host, $origin),
      MaterialKind::Animation => $this->captureAnimation($operation, $origin),
      MaterialKind::Font => $this->captureFont($operation, $origin),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider mints no %s.', $kind->noun())),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    return match ($kind) {
      MaterialKind::Token => $this->updateToken($id, $operation),
      MaterialKind::Swatch => $this->updateSwatch($id, $operation),
      MaterialKind::Variant => $this->updateVariant($id, $operation, $host),
      MaterialKind::Animation => $this->updateAnimation($id, $operation),
      MaterialKind::Font => $this->updateFont($id, $operation),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider updates no %s.', $kind->noun())),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult {
    $stage_batch = (bool) ($options[MaterialGestureOperationHandler::BATCH_OPTION] ?? FALSE);
    return match ($kind) {
      MaterialKind::Token => $this->removeToken($id, $stage_batch),
      MaterialKind::Swatch => $this->removeSwatch($id, $stage_batch),
      MaterialKind::Variant => $this->removeVariant($id, $stage_batch),
      MaterialKind::Animation => $this->removeAnimation($id, $stage_batch),
      MaterialKind::Font => $this->removeFont($id, $stage_batch),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider removes no %s.', $kind->noun())),
    };
  }

  /**
   * Mints a token on the active skin (save_material).
   *
   * Operation shape: {kind: token, group, label, value, description?,
   * offered?}.
   *
   * The group is the declared scale the token is minted on and the label
   * its name (the custom property's tail, StyleMapValidator::TOKEN_NAME):
   * a token has no human label apart from its name, and the name is data
   * the writer means exactly, so nothing is derived or suffixed. The
   * value is always given outright (a token is a fact of the skin, not of
   * any element). No "global" key: a token's membership is its skin's.
   * The AI's mint meets the ai_minting governance gate exactly as the
   * swatch and animation mints do. Which layer the token lands in is the
   * skin manager's call (SkinManager::writesSiteLayer()).
   */
  protected function captureToken(array $operation, string $origin): MaterialGestureResult {
    $this->requirePermission('create tokens', 'administer skins', 'Minting tokens requires the "create tokens" permission.');
    if (array_key_exists('global', $operation)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'A token takes no "global" key: it belongs to the active skin, whose membership it shares.', [], 'Omit "global"; a token is offered whenever its skin is worn.'),
      ]);
    }
    if ($origin === MaterialRecord::ORIGIN_AI && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'STYLE_TOKEN_AI_MINTING_DISABLED', 'AI token minting is disabled on this site.', [], 'Reference a minted token or use a literal value instead of saving a token; a person with the "create tokens" permission can mint it in the palette, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $scale = $operation['group'] ?? NULL;
    if (!is_string($scale) || $scale === '') {
      throw new MaterialGestureException([
        new BlueprintError('group', 'MISSING_PARAMETER', 'A token is minted on a scale: pass "group".', [], 'Pass "group" as a declared token scale (the roster groups tokens by it: space, color, text, ...).'),
      ]);
    }
    $name = $operation['label'] ?? NULL;
    if (!is_string($name) || $name === '') {
      throw new MaterialGestureException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A token needs a name: pass "label".', [], 'Pass "label" as the token name, the tail of its custom property ("4", "2xl", "accent-hover": lowercase letters, digits, inner hyphens).'),
      ]);
    }
    $value = $operation['value'] ?? NULL;
    if (!is_string($value) && !is_array($value)) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'MISSING_PARAMETER', 'A token needs a value: pass "value".', [], 'Pass a CSS value ("1.25rem", or {"default": "#e94560", "dark": "#f87171"}) that fits the scale\'s shape.'),
      ]);
    }
    $description = $operation['description'] ?? '';
    if (!is_scalar($description)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text.', [], 'Pass "description" as a sentence on why the token exists and when to reach for it, or omit it.'),
      ]);
    }
    $offered = $this->offeredFlag($operation) ?? TRUE;
    $skin = $this->tokenManager()->activeSkin();
    $this->tokenManager()->mint($skin, $scale, $name, $value, (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Token, "$scale/$name", [], $skin);
  }

  /**
   * Changes a token's facts (update_material).
   *
   * Operation shape: {kind: token, id: "{scale}/{name}", value?,
   * description?, offered?}. On a consuming site the facts given are
   * exactly what the site layer stores over the package's record.
   */
  protected function updateToken(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update tokens', 'administer skins', 'Updating tokens requires the "update tokens" permission.');
    [$skin, $scale, $name] = $this->tokenAddress($id);
    $changes = array_intersect_key($operation, array_flip(self::TOKEN_UPDATE_KEYS));
    $value = $changes['value'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    if (($value !== NULL && !is_string($value) && !is_array($value)) || ($description !== NULL && !is_scalar($description))) {
      throw new MaterialGestureException([
        new BlueprintError($description !== NULL && !is_scalar($description) ? 'description' : 'value', 'INVALID_PARAMETER', 'description must be text; value a CSS value or a condition-keyed map.', [], 'Pass "description" as text, "value" as "1.25rem" or {"default": ..., "dark": ...}.'),
      ]);
    }
    $this->tokenManager()->update($skin, $scale, $name, $value, $description === NULL ? NULL : (string) $description, $this->offeredFlag($changes));
    return new MaterialGestureResult(MaterialKind::Token, $id, [], $skin);
  }

  /**
   * Removes a token (remove_material).
   *
   * Operation shape: {kind: token, id: "{scale}/{name}"}. On a consuming
   * site the site's record goes (a site-minted token is deleted, a site
   * override reveals the package's token); a shipped token the site never
   * touched refuses. The result carries the skin while the token still
   * exists (revealed, or its removal staged) and nothing once it is gone.
   * A REFERENCED token is the usage lane's: the route lane stages the
   * rewrite-then-delete batch (TokenManager::deleteToken()), the op spine
   * refuses with the count.
   */
  protected function removeToken(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete tokens', 'administer skins', 'Deleting tokens requires the "delete tokens" permission.', 'delete tokens in use');
    [$skin, $scale, $name] = $this->tokenAddress($id);
    $staged = $this->tokenManager()->deleteToken($skin, $scale, $name, $stage_batch);
    $exists = $staged > 0 || $this->tokenManager()->has($skin, $scale, $name);
    return new MaterialGestureResult(MaterialKind::Token, $id, [], $exists ? $skin : NULL, $staged);
  }

  /**
   * Resolves a token id to the active skin and the token's scale and name.
   *
   * @return array{0: \Drupal\daedalus_stylish\DaedalusStylishSkinInterface, 1: string, 2: string}
   *   The active skin, the scale, the name.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When no skin is active, or the id names no token the skin mints.
   */
  protected function tokenAddress(string $id): array {
    $skin = $this->tokenManager()->activeSkin();
    $parts = TokenManager::parseId($id);
    if ($parts === NULL || !$this->tokenManager()->has($skin, $parts[0], $parts[1])) {
      throw new MaterialGestureException([$this->notFound(MaterialKind::Token, $id)]);
    }
    return [$skin, $parts[0], $parts[1]];
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
  protected function captureSwatch(array $operation, ?HostInterface $host, string $origin): MaterialGestureResult {
    $this->requirePermission('create swatches', 'administer swatches', 'Minting swatches requires the "create swatches" permission.');
    $global = $this->globalOptOut($operation);
    if ($origin === MaterialRecord::ORIGIN_AI && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'STYLE_SWATCH_AI_MINTING_DISABLED', 'AI swatch minting is disabled on this site.', [], 'Reference a published palette swatch or use a literal value instead of saving a swatch; a person with the "create swatches" permission can mint it in the palette, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $value = $operation['value'] ?? NULL;
    if ($value === NULL && isset($operation['element'])) {
      $value = $this->literalWorn($host, (string) $operation['element'], $operation['property'] ?? NULL);
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
   * is the axis (the record's group), `owned_by` a blueprint whose
   * placements alone offer it, `description` and `weight` the roster face,
   * `global` the opt-out from the active design system (globalOptOut()),
   * `offered` the AI opt-out (offeredFlag(): false keeps the look for UI
   * use only).
   */
  protected function captureVariant(array $operation, ?HostInterface $host, string $origin): MaterialGestureResult {
    $this->requirePermission('create variants', 'administer variants', 'Minting variants requires the "create variants" permission.');
    $global = $this->globalOptOut($operation);
    $key = $this->variantKey($operation, $host);
    $axis = $this->axis($operation);
    $styles = $operation['styles'] ?? NULL;
    if ($styles !== NULL && !is_array($styles)) {
      throw new MaterialGestureException([
        new BlueprintError('styles', 'INVALID_PARAMETER', 'styles must be an element-relative style map.', [], 'Omit "styles" to capture the element\'s look, or pass {"": {...}, "::.class": {...}, "::> *": {...}}.'),
      ]);
    }
    $styles ??= $this->capturer()->capture($host, $key, $axis ?? StyleGuide::STYLE_AXIS);
    $owned_by = $operation['owned_by'] ?? NULL;
    $description = $operation['description'] ?? '';
    $weight = $operation['weight'] ?? 0;
    if (($owned_by !== NULL && !is_string($owned_by)) || !is_scalar($description) || !is_numeric($weight)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text, weight a number, owned_by a blueprint id.', [], 'Pass "description" as a sentence on when to reach for the look, "weight" as an integer, "owned_by" as a blueprint id or omit it.'),
      ]);
    }
    $variant = $this->variantManager()->saveAsVariant(
      is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '',
      $key,
      $styles,
      $host,
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
  protected function updateVariant(string $id, array $operation, ?HostInterface $host): MaterialGestureResult {
    $this->requirePermission('update variants', 'administer variants', 'Updating variants requires the "update variants" permission.');
    $variant = $this->variantManager()->loadVariant($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Variant, $id)]);
    $changes = array_intersect_key($operation, array_flip(self::VARIANT_UPDATE_KEYS));
    if ($changes === []) {
      throw new MaterialGestureException([
        new BlueprintError('', 'MISSING_PARAMETER', 'update_material changes nothing: pass at least one key.', ['keys' => self::VARIANT_UPDATE_KEYS], 'Pass "element" (or "key" and "styles") to push a fresh look, or "label" / "description" / "weight" for the face.'),
      ]);
    }
    if (isset($changes['element']) || isset($changes['styles'])) {
      $key = isset($operation['element']) || isset($operation['key']) ? $this->variantKey($operation, $host) : '';
      $styles = $changes['styles'] ?? NULL;
      if ($styles !== NULL && !is_array($styles)) {
        throw new MaterialGestureException([
          new BlueprintError('styles', 'INVALID_PARAMETER', 'styles must be an element-relative style map.', [], 'Omit "styles" to capture the element\'s look, or pass {"": {...}, "::.class": {...}, "::> *": {...}}.'),
        ]);
      }
      if ($styles === NULL) {
        $styles = $this->capturer()->capture($host, $key, $variant->getAxis());
        if ($this->wears($host, $key, $variant)) {
          $merged = $variant->getStyles();
          foreach ($styles as $relative => $entry) {
            $merged[$relative] = isset($merged[$relative]) && is_array($merged[$relative]) ? StyleEntry::merge($entry, $merged[$relative]) : $entry;
          }
          $styles = $merged;
        }
      }
      $this->variantManager()->push($variant, $key, $styles, $host);
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
   * Saves a font (save_material).
   *
   * Operation shape: {kind: font, label, family?, faces, description?,
   * offered?, global?}.
   *
   * The faces are always given outright (a family lives on no element,
   * so there is nothing to capture from a selection), each naming an
   * uploaded font file through its typed source. The family defaults to
   * the label: a font called "Inter" is the family "Inter" unless the
   * writer says otherwise. The AI's mint meets the ai_minting governance
   * gate exactly as the other mints do, and joins the active design
   * system unless "global": true opts it out.
   */
  protected function captureFont(array $operation, string $origin): MaterialGestureResult {
    $this->requirePermission('create fonts', 'administer fonts', 'Minting fonts requires the "create fonts" permission.');
    $global = $this->globalOptOut($operation);
    if ($origin === MaterialRecord::ORIGIN_AI && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      throw new MaterialGestureException([
        new BlueprintError('faces', 'STYLE_FONT_AI_MINTING_DISABLED', 'AI font minting is disabled on this site.', [], 'Name a published family from the FONTS roster instead of saving one; a person with the "create fonts" permission can mint it in the Materials section, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $faces = $operation['faces'] ?? NULL;
    if (!is_array($faces)) {
      throw new MaterialGestureException([
        new BlueprintError('faces', 'MISSING_PARAMETER', 'A font needs faces: a list of {weight, style, source: {file: uuid}}.', [], 'Pass "faces" as [{"weight": 400, "style": "normal", "source": {"file": "<uuid of an uploaded font file>"}}] (at least one face).'),
      ]);
    }
    $description = $operation['description'] ?? '';
    if (!is_scalar($description)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text.', [], 'Pass "description" as a sentence on when to reach for the family, or omit it.'),
      ]);
    }
    $label = is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '';
    $family = array_key_exists('family', $operation) ? $operation['family'] : $label;
    $font = $this->fontManager()->mint($label, $family, $faces, (string) $description, $origin, $global, $this->offeredFlag($operation) ?? TRUE);
    return new MaterialGestureResult(MaterialKind::Font, $font->getMachineName(), [], $font);
  }

  /**
   * Updates a font (update_material).
   *
   * Operation shape: {kind: font, id, label?, family?, faces?,
   * description?, offered?}.
   */
  protected function updateFont(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update fonts', 'administer fonts', 'Updating fonts requires the "update fonts" permission.');
    $font = $this->fontManager()->loadFont($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Font, $id)]);
    $changes = array_intersect_key($operation, array_flip(self::FONT_UPDATE_KEYS));
    $label = $changes['label'] ?? NULL;
    $family = $changes['family'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    $faces = $changes['faces'] ?? NULL;
    if (($label !== NULL && !is_string($label)) || ($family !== NULL && !is_scalar($family)) || ($description !== NULL && !is_scalar($description)) || ($faces !== NULL && !is_array($faces))) {
      $path = match (TRUE) {
        $label !== NULL && !is_string($label) => 'label',
        $family !== NULL && !is_scalar($family) => 'family',
        $description !== NULL && !is_scalar($description) => 'description',
        default => 'faces',
      };
      throw new MaterialGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', 'label, family and description must be text; faces a list of faces.', [], 'Pass "label", "family" and "description" as text, "faces" as [{"weight", "style", "source": {"file": "<uuid>"}}].'),
      ]);
    }
    $this->fontManager()->update($font, $label, $family === NULL ? NULL : (string) $family, $faces, $description === NULL ? NULL : (string) $description, $this->offeredFlag($changes));
    return new MaterialGestureResult(MaterialKind::Font, $font->getMachineName(), [], $font);
  }

  /**
   * Removes a font (remove_material).
   *
   * Operation shape: {kind: font, id}.
   */
  protected function removeFont(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete fonts', 'administer fonts', 'Deleting fonts requires the "delete fonts" permission.', 'delete fonts in use');
    $font = $this->fontManager()->loadFont($id) ?? throw new MaterialGestureException([$this->notFound(MaterialKind::Font, $id)]);
    $machine_name = $font->getMachineName();
    $staged = $this->fontManager()->deleteFont($font, $stage_batch);
    return new MaterialGestureResult(MaterialKind::Font, $machine_name, [], $staged > 0 ? $font : NULL, $staged);
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
  protected function literalWorn(?HostInterface $host, string $element, mixed $property): string {
    if (!is_string($property) || $property === '') {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MISSING_PARAMETER', 'Capturing a swatch from an element needs the CSS property to read.', [], 'Pass "property" (background-color, color, background-image, ...) beside "element".'),
      ]);
    }
    $key = $this->keyFor($host, $element);
    $map = $host->styleMap();
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
  protected function variantKey(array $operation, ?HostInterface $host): string {
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
    return $this->keyFor($host, $element);
  }

  /**
   * The style key of a placed element, from the host's staged tree.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function keyFor(?HostInterface $host, string $element): string {
    if ($host === NULL) {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A capture reads an element of a page; this lane has no page behind it.', [], 'Run the gesture as a page_edit op on the page the element sits on.'),
      ]);
    }
    return ($this->elementMaterials)()->keyFor($host, $element) ?? throw new MaterialGestureException([
      new BlueprintError('element', 'UNKNOWN_REF', sprintf('"%s" is not an element of this page.', $element), [], 'Refs are the ids in the page print (uuids or data-alias values).'),
    ]);
  }

  /**
   * Whether an element's own entry references a variant (any fragment).
   */
  protected function wears(?HostInterface $host, string $key, DaedalusStylishVariantInterface $variant): bool {
    if ($host === NULL || $key === '' || !$host->hasStyleStore()) {
      return FALSE;
    }
    $map = $host->styleMap();
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
      MaterialKind::Token => 'STYLE_TOKEN_NOT_FOUND',
      MaterialKind::Swatch => 'STYLE_SWATCH_NOT_FOUND',
      MaterialKind::Variant => 'STYLE_VARIANT_NOT_FOUND',
      MaterialKind::Animation => 'STYLE_ANIMATION_NOT_FOUND',
      MaterialKind::Font => 'STYLE_FONT_NOT_FOUND',
      default => throw new \LogicException(sprintf('The daedalus_stylish provider owns no %s.', $kind->noun())),
    };
    return new BlueprintError('id', $code, sprintf('"%s" is not a published %s.', $id, $kind->noun()), [], sprintf('Reference a %s by its id from the MATERIALS roster.', $kind->noun()));
  }

  /**
   * The token manager (a closure: the dependency is circular).
   */
  protected function tokenManager(): TokenManager {
    return ($this->tokenManager)();
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
   * The font manager (a closure: the dependency is circular).
   */
  protected function fontManager(): FontManager {
    return ($this->fontManager)();
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
   * A token record is always `updatable`, shipped or not: a consuming
   * site's write to a shipped token lands in the skin's site layer over
   * the package's record, never in the package's, so the distro lock
   * (updatable() for the other kinds) refuses nothing here. What the lock
   * does refuse for a token is its removal (TokenManager::deleteToken()).
   * The origin is per token: shipped when the skin is shipped and its
   * package layer holds the name (a site override over it keeps the
   * shipped origin, the package still ships the token), user for a
   * token the site minted into the site layer and for every token of
   * the site's own skin.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per token, declared scale order then stored order.
   */
  protected function tokens(?string $theme): array {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    $package = $skin->isShipped() ? $skin->packageTokens() : [];
    // A token is its skin's: a member skin's tokens are that system's
    // materials, offered only while it is active.
    $design_system = $skin->getDesignSystem();
    return array_map(
      static fn (array $token): MaterialRecord => new MaterialRecord(
        kind: MaterialKind::Token,
        id: $token['scale'] . '/' . $token['name'],
        label: $token['name'],
        description: $token['description'],
        value: $token['value'],
        group: $token['scale'],
        spellings: [MaterialRecord::SLOT_STYLE => 'var(' . StyleGuide::tokenVariable($token['scale'], $token['declaration'], $token['name']) . ')'],
        origin: isset($package[$token['scale']][$token['name']]) ? MaterialRecord::ORIGIN_SHIPPED : MaterialRecord::ORIGIN_USER,
        designSystem: $design_system,
        offered: $token['offered'],
      ),
      $this->tokenWalk($skin, $theme),
    );
  }

  /**
   * A skin's tokens on the theme's declared scales, in roster order.
   *
   * Each entry carries the stored record's facts (value, description,
   * offered; SkinTokensItem::RECORD_KEYS) beside the scale and its
   * declaration, so the record and the change hooks read one walk.
   *
   * @return array<int, array{scale: string, declaration: array, name: string, value: mixed, description: string, offered: bool}>
   *   One entry per token, declared scale order then stored order.
   */
  protected function tokenWalk(DaedalusStylishSkinInterface $skin, ?string $theme): array {
    $tokens = $skin->tokens();
    $walk = [];
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      $scale = (string) $scale;
      $names = $tokens[$scale] ?? NULL;
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      foreach ($names as $name => $record) {
        if (!SkinTokensItem::isRecord($record)) {
          throw new \UnexpectedValueException(sprintf('The stored token %s.%s of the "%s" skin is not a record; a skin stores {value, description, offered} per token.', $scale, $name, $skin->getMachineName()));
        }
        $walk[] = [
          'scale' => $scale,
          'declaration' => $declaration,
          'name' => (string) $name,
          'value' => $record['value'],
          'description' => $record['description'],
          'offered' => $record['offered'],
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
   * lifts it (DistroLock); a token never reads it (see tokens(): its
   * site layer takes the write). Origin is the record fact the flag keys on
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
   * The published fonts, label-then-machine-name order.
   *
   * The same read the compiled @font-face file makes
   * (FontRepository::publishedFonts()), so what the roster offers and
   * what delivers are one set in one order. The value is {family, faces}
   * (the family a stack names, the faces the family ships); the spelling
   * is the quoted family in the property-scoped font slot
   * (StyleGuide::fontFamily()), never var(): a family is a string
   * component of a font-family stack.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per published font.
   */
  protected function fonts(): array {
    return array_map(fn (DaedalusStylishFontInterface $font): MaterialRecord => new MaterialRecord(
      kind: MaterialKind::Font,
      id: $font->getMachineName(),
      label: (string) $font->label(),
      description: $font->getDescription(),
      value: ['family' => $font->getFamily(), 'faces' => $font->getFaces()],
      spellings: [MaterialRecord::SLOT_FONT => StyleGuide::fontFamily($font->getFamily())],
      origin: $font->getOrigin(),
      updatable: $this->updatable($font->getOrigin()),
      designSystem: $font->getDesignSystem(),
      offered: $font->isOffered(),
    ), $this->fontRepository->publishedFonts());
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
   * Resolves a variant's owning blueprint's machine name, when owned.
   *
   * The ownership fields live in daedalus_blueprint_library (optional), one
   * flat machine-name space across both lanes: the blueprint_file value IS
   * a file-shipped blueprint's machine name, and a content blueprint resolves
   * through the entity reference (see VariantImporter::syncOwner()).
   */
  protected function ownerName(DaedalusStylishVariantInterface $variant): ?string {
    if (!$variant->hasField('blueprint')) {
      return NULL;
    }
    $file_id = $variant->get('blueprint_file')->value ?? NULL;
    if (is_string($file_id) && $file_id !== '') {
      return $file_id;
    }
    $blueprint = $variant->get('blueprint')->entity ?? NULL;
    if ($blueprint !== NULL && $blueprint->hasField('machine_name')) {
      $name = $blueprint->get('machine_name')->value;
      return is_string($name) && $name !== '' ? $name : NULL;
    }
    return NULL;
  }

}
