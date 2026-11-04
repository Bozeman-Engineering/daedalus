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
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Materials\ValueGrammar;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleEntry;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\SkinMaterialManager;
use Drupal\daedalus_stylish\VariantCapture;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\daedalus_stylish\VariantManager;
use Drupal\daedalus_stylish\VariantRepository;

/**
 * Daedalus Stylish's materials: tokens, variants, animations, fonts.
 *
 * The active skin's tokens, the variants, the animations and the fonts,
 * each carrying its design-system membership.
 *
 * The ONE place daedalus_stylish's four material kinds are read into roster
 * records; every material surface (catalog, prompt, schema, validators,
 * client payload, delivery CSS) projects from the roster instead of
 * walking the skin, the variant storage and the animation storage on its
 * own. Sources and orders are the ones their
 * consumers already agree on:
 * - tokens: the active PUBLISHED skin's minted tokens on the declared
 *   scales, in declared scale order then stored token order (the
 *   compiled skin file's order, deterministic regardless of the stored
 *   scale order), id "{scale}/{name}" (names repeat across scales),
 *   group the scale, value as stored; the style spelling is var() over
 *   the one variable derivation (StyleGuide::tokenVariable()). An
 *   unpublished active skin delivers nothing, so it rosters nothing. A
 *   palette color or gradient is a token on the color or gradient scale;
 *   there is no second named-value store.
 * - variants: VariantRepository::publishedVariants() (weight, label, then
 *   machine name) minus the ones the site's current law refuses
 *   (VariantConformance, see records()), group the axis, value the
 *   element-relative style map as stored (the client's layer math resolves a
 *   worn reference through it for live preview; the prompt and the schema never
 *   print it), scope as saved, applies {depth: root} when the axis is root-only
 *   in the scope's declaration, owned_by from the blueprint ownership fields
 *   when that module exists. Spellings derive from the scope's variant_axes
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
 * - animations: the active skin's animations as worn (stored order; see
 *   machine name, the compiled keyframes file's order, so delivery and
 *   validation cannot disagree), value the steps map as stored (canonical
 *   offsets; the editor and the preview print read it, the prompt and the
 *   schema project it), no group; the spelling is the keyframes name in
 *   the property-scoped animation slot (StyleGuide::animationName(),
 *   motion-{name}), never var(): a keyframes name is a custom-ident.
 *
 * A variant's or animation's origin is its entity's origin field
 * (shipped, stamped by the importer; user or ai, stamped by the lane that
 * minted it); a token's is its layer's: shipped when a module ships the
 * skin and its package layer holds the name, user otherwise (a token the
 * site minted into the site layer, or any token of the site's own
 * skin). The row resolution reads it (VocabularyRoster::scaleStops(),
 * the client's scaleRow): a site-minted token follows a curated row's
 * stops on open and guarded systems and is withheld there on strict.
 *
 * The token, animation and variant gestures (save_material /
 * update_material / remove_material with kind token, variant or
 * animation) dispatch here from the op spine; the palette's, the
 * Materials section's and the inspector's gestures and the token,
 * variant and animation routes (adapters) reach the same methods. The
 * provider owns the lane end to end: the permission grade per verb
 * (create / update / delete tokens, variants and animations, or the
 * administer grade; a token's administer grade is "administer skins",
 * the skin being its entity), capture from the host's staged state (a
 * token from the literal value an element wears for a property, landing
 * on the first declared scale whose shape fits it, a variant from the
 * element's whole look through VariantCapture; an animation is always
 * given outright, a keyframes block lives on no element), the
 * AI-minting governance gate for the AI's token, animation and font
 * mints (daedalus_stylish.settings ai_minting, the same
 * gate the blueprint create lane enforces) with its advertisement
 * (mintPosture(): the closed-gate sentence the brief's MINT line and
 * the JSON door's materials guidance both state), and the origin
 * stamp. The
 * managers are the one implementation behind both doors; they arrive as
 * closures because the dependency is circular by construction (they
 * validate through StyleMapValidator, whose universe is the roster this
 * provider feeds), as do the element read and the roster builder itself.
 * A token write lands in the active skin's SITE layer on a consuming
 * site, or in its one layer when the skin is the site's own or the
 * developer gate lifts the distro lock (SkinManager::writesSiteLayer(),
 * the one rule every skin write reads).
 *
 * A gesture that would share more with the AI answers the AI context
 * budget (MaterialsBudget::assertRoom(), one wording on every door): an
 * offered mint of any kind (the default; `offered: false` mints for the
 * UI alone and passes) and an update turning `offered` on where it was
 * off are refused while the site is full (requireRoom()). The guard
 * refuses sharing, never saving: a mint withheld from the AI, a face or
 * value edit, and an update re-stating a flag already on go through,
 * and the check reads the current reading, never a predicted delta. The
 * five Api controllers are adapters over these methods, so the cards'
 * toggle and the AI's op meet the same refusal.
 */
final class DaedalusStylishMaterialsProvider implements MaterialsProviderInterface {

  /**
   * The keys update_material may change per kind.
   */
  private const TOKEN_UPDATE_KEYS = ['value', 'description', 'offered'];
  private const VARIANT_UPDATE_KEYS = ['label', 'description', 'weight', 'offered', 'element', 'styles'];
  private const ANIMATION_UPDATE_KEYS = ['description', 'steps', 'offered'];
  private const FONT_UPDATE_KEYS = ['family', 'faces', 'description', 'offered'];

  /**
   * The closed-minting posture, the one text both doors advertise.
   *
   * Names the three gated lanes and the entity shortcuts around them, and
   * what to offer instead, so the model is told before it is refused
   * (an honest advertisement beats discovery by refusal).
   */
  public const AI_MINTING_CLOSED = 'AI minting is DISABLED on this site (daedalus_stylish.settings ai_minting): never save_material a token, an animation or a font; offer the skin\'s minted tokens, animations and fonts or a literal value instead.';

  public function __construct(
    protected StyleGuide $styleGuide,
    protected SkinManager $skinManager,
    protected PageBuilderDeclarations $declarations,
    protected VariantRepository $variantRepository,
    protected \Closure $variantManager,
    protected \Closure $variantCapture,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected \Closure $elementMaterials,
    protected ValueGrammar $valueGrammar,
    protected \Closure $rosterBuilder,
    protected VariantConformance $conformance,
    protected DistroLock $distroLock,
    protected \Closure $skinMaterialManager,
    protected \Closure $budget,
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
    // default theme, the skin list: the skin's tokens, animations and
    // fonts ride it) plus the one entity list the records read: any
    // variant save, publish or delete busts the roster.
    return (new CacheableMetadata())->addCacheTags([
      ...ClientStyleGuide::CACHE_TAGS,
      'daedalus_variant_list',
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * The one per-request memo behind records() is the conformance verdict
   * (which variants the current law withholds), so the roster's reset
   * forgets it here: a skin write, an enforcement edit or a variant save
   * resets the roster, and the next records() judges afresh. Without the
   * cascade the verdict outlived a skin write and the next roster, and
   * the compiled variants file with it, printed the pre-skin verdict.
   */
  public function reset(): void {
    $this->conformance->reset();
  }

  /**
   * {@inheritdoc}
   *
   * A token's line names the scale (group) and the name (label): a token
   * is minted on a declared scale of the active skin, and its name is the
   * custom property's tail, never derived from a human label. Its value
   * is given outright or captured from an element (the literal it wears
   * for a property); the scale may be left to the value's shape.
   */
  public function gestureLines(): array {
    return [
      MaterialKind::Token->value => 'label (the token name: lowercase, digits, hyphens), value or element + property (the literal the element wears); group (the scale; omitted, the first declared scale whose shape fits the value), description, offered?; then reference it by its var() spelling',
      MaterialKind::Variant->value => 'element, description, group: axis?; then wear it',
      MaterialKind::Animation->value => 'label (the animation name: lowercase, digits, hyphens or underscores), steps {"0%": {...}, "100%": {...}}, description, offered?; then wear it as animation-name: motion-{name}',
      MaterialKind::Font->value => 'label (the font name: lowercase, digits, hyphens or underscores), family (the name stacks reference), faces [{weight, style, source: {file: uuid}}] (uploaded font files), description, offered?; then name the family in a font-family stack',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The closed-minting sentence, stated here and nowhere else: the
   * brief's MINT line carries it through the roster builder and
   * MaterialsGateHooks lands it on the JSON door's materials guidance.
   * The variant lane is not gated (a variant is a look over the
   * vocabulary, not an extension of it), so the sentence names the three
   * lanes aiMintingClosed() refuses.
   */
  public function mintPosture(): ?string {
    return $this->aiMintingClosed() ? self::AI_MINTING_CLOSED : NULL;
  }

  /**
   * Whether the site closed AI minting (daedalus_stylish.settings ai_minting).
   *
   * The one predicate behind the posture and the three gated lanes: only
   * an explicit FALSE closes the gate (a site predating the key keeps the
   * open lane until the post_update seeds it).
   */
  protected function aiMintingClosed(): bool {
    return $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function capture(MaterialKind $kind, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $origin = $options[MaterialGestureOperationHandler::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER;
    return match ($kind) {
      MaterialKind::Token => $this->captureToken($operation, $host, $origin),
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
      MaterialKind::Variant => $this->removeVariant($id, $stage_batch),
      MaterialKind::Animation => $this->removeAnimation($id, $stage_batch),
      MaterialKind::Font => $this->removeFont($id, $stage_batch),
      default => throw new \LogicException(sprintf('The daedalus_stylish provider removes no %s.', $kind->noun())),
    };
  }

  /**
   * Mints a token on the active skin (save_material).
   *
   * Operation shape: {kind: token, label, value | element + property,
   * group?, description?, offered?}.
   *
   * The label is the token's name (the custom property's tail,
   * StyleMapValidator::TOKEN_NAME): a token has no human label apart
   * from its name, and the name is data the writer means exactly, so
   * nothing is derived or suffixed. The value is given outright (a CSS
   * value or a condition-keyed map) or captured from the selection: the
   * literal an element wears for a property in its own default layer (a
   * reference is already a material, and a value the element inherits is
   * not the element's to save). The group is the declared scale the token
   * is minted on; left out, the token lands on the first declared scale
   * whose shape fits the value (color for a color, gradient for a
   * gradient), so a capture from the canvas needs no scale named. No
   * "global" key: a token's membership is its skin's. The AI's mint meets
   * the ai_minting governance gate exactly as the animation and font
   * mints do. Which layer the token lands in is the skin manager's call
   * (SkinManager::writesSiteLayer()).
   */
  protected function captureToken(array $operation, ?HostInterface $host, string $origin): MaterialGestureResult {
    $this->requirePermission('create skin materials', 'administer skins', 'Minting tokens requires the "create skin materials" permission.');
    if (array_key_exists('global', $operation)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'A token takes no "global" key: it belongs to the active skin, whose membership it shares.', [], 'Omit "global"; a token is offered whenever its skin is worn.'),
      ]);
    }
    if ($origin === MaterialRecord::ORIGIN_AI && $this->aiMintingClosed()) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'STYLE_TOKEN_AI_MINTING_DISABLED', 'AI token minting is disabled on this site.', [], 'Reference a minted token or use a literal value instead of saving a token; a person with the "create skin materials" permission can mint it in the skin tab, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $name = $operation['label'] ?? NULL;
    if (!is_string($name) || $name === '') {
      throw new MaterialGestureException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A token needs a name: pass "label".', [], 'Pass "label" as the token name, the tail of its custom property ("4", "2xl", "accent-hover": lowercase letters, digits, inner hyphens).'),
      ]);
    }
    $value = $operation['value'] ?? NULL;
    if ($value === NULL && isset($operation['element'])) {
      $value = $this->literalWorn($host, (string) $operation['element'], $operation['property'] ?? NULL);
    }
    if (!is_string($value) && !is_array($value)) {
      throw new MaterialGestureException([
        new BlueprintError('value', 'MISSING_PARAMETER', 'A token needs a value: pass "value", or "element" and "property" to capture the value an element wears.', [], 'Pass a CSS value ("1.25rem", "#e94560", or {"default": "#e94560", "dark": "#f87171"}) that fits the scale\'s shape, or name the element (id or alias) and the property whose literal value to save.'),
      ]);
    }
    $scale = $operation['group'] ?? NULL;
    if ($scale !== NULL && (!is_string($scale) || $scale === '')) {
      throw new MaterialGestureException([
        new BlueprintError('group', 'INVALID_PARAMETER', 'group must be a declared token scale name.', [], 'Pass "group" as a declared token scale (the roster groups tokens by it: space, color, gradient, ...), or omit it to mint on the first declared scale whose shape fits the value.'),
      ]);
    }
    $scale ??= $this->skinMaterialManager()->scaleFor($value) ?? throw new MaterialGestureException([
      new BlueprintError('group', 'STYLE_TOKEN_SCALE_UNFIT', 'No declared token scale\'s shape fits the value.', ['scales' => array_keys($this->styleGuide->getTokenScales($this->declarations->siteTheme()))], 'Pass "group" as the declared scale to mint on, with a value that fits its shape (a color on the color scale, a gradient on the gradient scale, a length on a dimension scale).'),
    ]);
    $description = $operation['description'] ?? '';
    if (!is_scalar($description)) {
      throw new MaterialGestureException([
        new BlueprintError('description', 'INVALID_PARAMETER', 'description must be text.', [], 'Pass "description" as a sentence on why the token exists and when to reach for it, or omit it.'),
      ]);
    }
    $offered = $this->offeredFlag($operation) ?? TRUE;
    $this->requireRoom($offered);
    $skin = $this->skinMaterialManager()->activeSkin();
    $this->skinMaterialManager()->mint($skin, $scale, $name, $value, (string) $description, $offered);
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
    $this->requirePermission('update skin materials', 'administer skins', 'Updating tokens requires the "update skin materials" permission.');
    [$skin, $scale, $name] = $this->tokenAddress($id);
    $changes = array_intersect_key($operation, array_flip(self::TOKEN_UPDATE_KEYS));
    $value = $changes['value'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    if (($value !== NULL && !is_string($value) && !is_array($value)) || ($description !== NULL && !is_scalar($description))) {
      throw new MaterialGestureException([
        new BlueprintError($description !== NULL && !is_scalar($description) ? 'description' : 'value', 'INVALID_PARAMETER', 'description must be text; value a CSS value or a condition-keyed map.', [], 'Pass "description" as text, "value" as "1.25rem" or {"default": ..., "dark": ...}.'),
      ]);
    }
    $offered = $this->offeredFlag($changes);
    $this->requireRoom($offered, (bool) $this->skinMaterialManager()->resolve($skin, $scale, $name)->record['offered']);
    $this->skinMaterialManager()->update($skin, $scale, $name, $value, $description === NULL ? NULL : (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Token, $id, [], $skin);
  }

  /**
   * Removes a token (remove_material).
   *
   * Operation shape: {kind: token, id: "{scale}/{name}"}. On a consuming site
   * the site's record goes (a site-minted token is deleted, a site override
   * reveals the package's token); a shipped token the site never touched
   * refuses. The result carries the skin while the token still exists
   * (revealed, or its removal staged) and nothing once it is gone. A REFERENCED
   * token is the usage lane's: the route lane stages the rewrite-then-delete
   * batch (SkinMaterialManager::deleteToken()), the op spine refuses with the
   * count.
   */
  protected function removeToken(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete skin materials', 'administer skins', 'Deleting tokens requires the "delete skin materials" permission.', 'delete skin materials in use');
    [$skin, $scale, $name] = $this->tokenAddress($id);
    $staged = $this->skinMaterialManager()->deleteToken($skin, $scale, $name, $stage_batch);
    $exists = $staged > 0 || $this->skinMaterialManager()->has($skin, $scale, $name);
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
    $skin = $this->skinMaterialManager()->activeSkin();
    $parts = SkinMaterialManager::parseId($id);
    if ($parts === NULL || !$this->skinMaterialManager()->has($skin, $parts[0], $parts[1])) {
      throw new MaterialGestureException([$this->notFound(MaterialKind::Token, $id)]);
    }
    return [$skin, $parts[0], $parts[1]];
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
    $offered = $this->offeredFlag($operation) ?? TRUE;
    $this->requireRoom($offered);
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
      $offered,
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
    $this->requireRoom($offered, $variant->isOffered());
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
   * Operation shape: {kind: animation, label, steps, description?,
   * offered?}. The token mint's twin for the skin's motion section: the
   * label IS the name (the keyframes ident tail, SkinAnimationsItem::NAME:
   * an animation has no human label apart from its name, exactly as a
   * token), the steps are always given outright (a keyframes block lives
   * on no element, so there is nothing to capture from a selection), and
   * the record lands on the ACTIVE skin in the layer the skin manager
   * names (SkinManager::writesSiteLayer()). No "global" key: an
   * animation's membership is its skin's. The AI's mint meets the
   * ai_minting governance gate exactly as the token and font mints do, and
   * so does the font mint.
   */
  protected function captureAnimation(array $operation, string $origin): MaterialGestureResult {
    $this->requirePermission('create skin materials', 'administer skins', 'Minting animations requires the "create skin materials" permission.');
    if (array_key_exists('global', $operation)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'An animation takes no "global" key: it belongs to the active skin, whose membership it shares.', [], 'Omit "global"; an animation is offered whenever its skin is worn.'),
      ]);
    }
    if ($origin === MaterialRecord::ORIGIN_AI && $this->aiMintingClosed()) {
      throw new MaterialGestureException([
        new BlueprintError('steps', 'STYLE_ANIMATION_AI_MINTING_DISABLED', 'AI animation minting is disabled on this site.', [], 'Wear an animation from the MATERIALS roster instead of saving one; a person with the "create skin materials" permission can mint it in the skin tab, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $name = $operation['label'] ?? NULL;
    if (!is_string($name) || $name === '') {
      throw new MaterialGestureException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'An animation needs a name: pass "label".', [], 'Pass "label" as the animation name, the tail of its keyframes name motion-{name} ("fade_in", "rise": lowercase letters, digits, inner hyphens or underscores).'),
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
    $offered = $this->offeredFlag($operation) ?? TRUE;
    $this->requireRoom($offered);
    $skin = $this->skinMaterialManager()->activeSkin();
    $this->skinMaterialManager()->mintAnimation($skin, $name, $steps, (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Animation, $name, [], $skin);
  }

  /**
   * Updates an animation (update_material).
   *
   * Operation shape: {kind: animation, id, steps?, description?,
   * offered?}. On a consuming site the facts given are exactly what the
   * site layer stores over the package's record. The name is identity
   * (the keyframes ident tail): renaming is the skin material route's
   * gesture, since a worn animation's rename stages a batch the op spine
   * cannot drive.
   */
  protected function updateAnimation(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update skin materials', 'administer skins', 'Updating animations requires the "update skin materials" permission.');
    $skin = $this->animationAddress($id);
    $changes = array_intersect_key($operation, array_flip(self::ANIMATION_UPDATE_KEYS));
    $description = $changes['description'] ?? NULL;
    $steps = $changes['steps'] ?? NULL;
    if (($description !== NULL && !is_scalar($description)) || ($steps !== NULL && !is_array($steps))) {
      throw new MaterialGestureException([
        new BlueprintError($description !== NULL && !is_scalar($description) ? 'description' : 'steps', 'INVALID_PARAMETER', 'description must be text; steps an object keyed by offset.', [], 'Pass "description" as text, "steps" as {"0%": {...}, "100%": {...}}.'),
      ]);
    }
    $offered = $this->offeredFlag($changes);
    $this->requireRoom($offered, (bool) $this->skinMaterialManager()->resolveAnimation($skin, $id)->record['offered']);
    $this->skinMaterialManager()->updateAnimation($skin, $id, $steps, $description === NULL ? NULL : (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Animation, $id, [], $skin);
  }

  /**
   * Removes an animation (remove_material).
   *
   * Operation shape: {kind: animation, id}. The token removal's twin: on a
   * consuming site the site's record goes (a site-minted animation is
   * deleted, a site override reveals the package's); a shipped animation
   * the site never touched refuses. A WORN animation is the usage lane's:
   * the route lane stages the rewrite-then-delete batch
   * (SkinMaterialManager::deleteAnimation()), the op spine refuses with
   * the count.
   */
  protected function removeAnimation(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete skin materials', 'administer skins', 'Deleting animations requires the "delete skin materials" permission.', 'delete skin materials in use');
    $skin = $this->animationAddress($id);
    $staged = $this->skinMaterialManager()->deleteAnimation($skin, $id, $stage_batch);
    $exists = $staged > 0 || $this->skinMaterialManager()->hasAnimation($skin, $id);
    return new MaterialGestureResult(MaterialKind::Animation, $id, [], $exists ? $skin : NULL, $staged);
  }

  /**
   * Resolves an animation id to the active skin that mints it.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When no skin is active, or the id names no animation the skin mints.
   */
  protected function animationAddress(string $id): DaedalusStylishSkinInterface {
    $skin = $this->skinMaterialManager()->activeSkin();
    if (!$this->skinMaterialManager()->hasAnimation($skin, $id)) {
      throw new MaterialGestureException([$this->notFound(MaterialKind::Animation, $id)]);
    }
    return $skin;
  }

  /**
   * Saves a font (save_material).
   *
   * Operation shape: {kind: font, label, family, faces, description?,
   * offered?}. The animation mint's twin for the skin's type section: the
   * label IS the name (the roster id, SkinFontsItem::NAME: a font has no
   * human label apart from its name, exactly as a token), the family is
   * what a font-family stack references ("Inter"), and the faces are
   * always given outright (a family lives on no element, so there is
   * nothing to capture from a selection), each naming an uploaded font
   * file through its typed source; the record lands on the ACTIVE skin in
   * the layer the skin manager names (SkinManager::writesSiteLayer()). No
   * "global" key: a font's membership is its skin's. The AI's mint meets
   * the ai_minting governance gate exactly as the other mints do.
   */
  protected function captureFont(array $operation, string $origin): MaterialGestureResult {
    $this->requirePermission('create skin materials', 'administer skins', 'Minting fonts requires the "create skin materials" permission.');
    if (array_key_exists('global', $operation)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'A font takes no "global" key: it belongs to the active skin, whose membership it shares.', [], 'Omit "global"; a font is offered whenever its skin is worn.'),
      ]);
    }
    if ($origin === MaterialRecord::ORIGIN_AI && $this->aiMintingClosed()) {
      throw new MaterialGestureException([
        new BlueprintError('faces', 'STYLE_FONT_AI_MINTING_DISABLED', 'AI font minting is disabled on this site.', [], 'Name a family from the FONTS roster instead of saving one; a person with the "create skin materials" permission can mint it in the skin tab, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }
    $name = $operation['label'] ?? NULL;
    if (!is_string($name) || $name === '') {
      throw new MaterialGestureException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A font needs a name: pass "label".', [], 'Pass "label" as the font name, the roster id ("inter", "display_serif": lowercase letters, digits, inner hyphens or underscores); the family stacks reference is "family".'),
      ]);
    }
    if (!array_key_exists('family', $operation)) {
      throw new MaterialGestureException([
        new BlueprintError('family', 'MISSING_PARAMETER', 'A font needs a family: the name font-family stacks reference.', [], 'Pass "family" as the typeface\'s name ("Inter", "Source Serif"); the compiled @font-face quotes it and a stack names it quoted.'),
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
    $offered = $this->offeredFlag($operation) ?? TRUE;
    $this->requireRoom($offered);
    $skin = $this->skinMaterialManager()->activeSkin();
    $this->skinMaterialManager()->mintFont($skin, $name, $operation['family'], $faces, (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Font, $name, [], $skin);
  }

  /**
   * Updates a font (update_material).
   *
   * Operation shape: {kind: font, id, family?, faces?, description?,
   * offered?}. On a consuming site the facts given are exactly what the
   * site layer stores over the package's record. The name is identity
   * (the roster id): renaming is the skin material route's gesture.
   */
  protected function updateFont(string $id, array $operation): MaterialGestureResult {
    $this->requirePermission('update skin materials', 'administer skins', 'Updating fonts requires the "update skin materials" permission.');
    $skin = $this->fontAddress($id);
    $changes = array_intersect_key($operation, array_flip(self::FONT_UPDATE_KEYS));
    $family = $changes['family'] ?? NULL;
    $description = $changes['description'] ?? NULL;
    $faces = $changes['faces'] ?? NULL;
    if (($family !== NULL && !is_scalar($family)) || ($description !== NULL && !is_scalar($description)) || ($faces !== NULL && !is_array($faces))) {
      $path = match (TRUE) {
        $family !== NULL && !is_scalar($family) => 'family',
        $description !== NULL && !is_scalar($description) => 'description',
        default => 'faces',
      };
      throw new MaterialGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', 'family and description must be text; faces a list of faces.', [], 'Pass "family" and "description" as text, "faces" as [{"weight", "style", "source": {"file": "<uuid>"}}].'),
      ]);
    }
    $offered = $this->offeredFlag($changes);
    $this->requireRoom($offered, (bool) $this->skinMaterialManager()->resolveFont($skin, $id)->record['offered']);
    $this->skinMaterialManager()->updateFont($skin, $id, $family === NULL ? NULL : (string) $family, $faces, $description === NULL ? NULL : (string) $description, $offered);
    return new MaterialGestureResult(MaterialKind::Font, $id, [], $skin);
  }

  /**
   * Removes a font (remove_material).
   *
   * Operation shape: {kind: font, id}. The animation removal's twin: on a
   * consuming site the site's record goes (a site-minted font is deleted,
   * a site override reveals the package's); a shipped font the site never
   * touched refuses. A NAMED font (its family in a stack) is the usage
   * lane's: the route lane stages the rewrite-then-delete batch
   * (SkinMaterialManager::deleteFont()), the op spine refuses with the
   * count.
   */
  protected function removeFont(string $id, bool $stage_batch): MaterialGestureResult {
    $this->requirePermission('delete skin materials', 'administer skins', 'Deleting fonts requires the "delete skin materials" permission.', 'delete skin materials in use');
    $skin = $this->fontAddress($id);
    $staged = $this->skinMaterialManager()->deleteFont($skin, $id, $stage_batch);
    $exists = $staged > 0 || $this->skinMaterialManager()->hasFont($skin, $id);
    return new MaterialGestureResult(MaterialKind::Font, $id, [], $exists ? $skin : NULL, $staged);
  }

  /**
   * Resolves a font id to the active skin that mints it.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When no skin is active, or the id names no font the skin mints.
   */
  protected function fontAddress(string $id): DaedalusStylishSkinInterface {
    $skin = $this->skinMaterialManager()->activeSkin();
    if (!$this->skinMaterialManager()->hasFont($skin, $id)) {
      throw new MaterialGestureException([$this->notFound(MaterialKind::Font, $id)]);
    }
    return $skin;
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
   * Refuses a gesture that turns `offered` on while the site is full.
   *
   * The AI context budget's guard (MaterialsBudget::assertRoom(), the
   * refusal at path `offered`): a mint asks with its flag (TRUE unless
   * the operation withholds), an update with the flag it carries against
   * the stored one, so only a change that shares MORE answers the
   * budget; NULL (an update leaving the flag alone) and FALSE never do.
   *
   * @param bool|null $offered
   *   The flag the gesture carries (offeredFlag(), TRUE by default on a
   *   mint).
   * @param bool $stored
   *   The flag as stored; FALSE for a mint, which has nothing stored yet.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   AI_CONTEXT_FULL, when the flag turns on and the site is full.
   */
  protected function requireRoom(?bool $offered, bool $stored = FALSE): void {
    if ($offered === TRUE && !$stored) {
      $this->budget()->assertRoom('offered');
    }
  }

  /**
   * The AI context budget: the guard every sharing gesture answers.
   */
  protected function budget(): MaterialsBudget {
    return ($this->budget)();
  }

  /**
   * The literal value an element wears for a property, from its own layer.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function literalWorn(?HostInterface $host, string $element, mixed $property): string {
    if (!is_string($property) || $property === '') {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MISSING_PARAMETER', 'Capturing a token from an element needs the CSS property to read.', [], 'Pass "property" (background-color, color, background-image, ...) beside "element".'),
      ]);
    }
    $key = $this->keyFor($host, $element);
    $map = $host->styleMap();
    $value = $map[$key]['default'][$property] ?? NULL;
    if (!is_string($value) || $value === '') {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MATERIAL_CAPTURE_FAILED', sprintf('The element wears no %s of its own.', $property), ['key' => $key], 'A capture saves a value the element itself declares in its default layer; style the element with the value first, or pass "value" outright.'),
      ]);
    }
    $reference = $this->valueGrammar->resolve($value, MaterialRecord::SLOT_STYLE, ($this->rosterBuilder)()->build());
    if ($reference->kind !== ValueKind::Literal) {
      throw new MaterialGestureException([
        new BlueprintError('property', 'MATERIAL_CAPTURE_FAILED', sprintf('The element\'s %s is %s, not a literal value.', $property, $value), [
          'key' => $key,
          'value' => $value,
        ], 'Only an inline literal becomes a token; this value already names a material (or a media/generated value).'),
      ]);
    }
    return $value;
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
  protected function skinMaterialManager(): SkinMaterialManager {
    return ($this->skinMaterialManager)();
  }

  /**
   * The variant manager (a closure: the dependency is circular).
   */
  protected function variantManager(): VariantManager {
    return ($this->variantManager)();
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
   * that tag: the container kind on <d-container>, the block kind on
   * every block form, a block type on its <d-{type}> (or its generic
   * form), a base block plugin on its <d-block plugin>. A layout scope
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
   * Records every published variant's id on the request's change set.
   *
   * The law-change sites' read (a skin write, an enforcement edit, a
   * skin choice, the design-system sweep): the conformance verdict is a
   * projection of the law, so a law change can flip any variant between
   * withheld and offered, and an open editor must learn the flips on the
   * response's materials delta the way it learns a variant save. The
   * ids are the judge's universe (unjudgedRecords(): every published
   * variant, members of inactive systems included), never the roster's
   * judged set, and the delta resolves each against the fresh roster
   * after the reset: offered to an upsert, withheld or inactive to a
   * remove. Broad on purpose: an unchanged verdict rides as identical
   * upserts, wire noise the client's resolver absorbs; diffing the
   * verdict before and after would need the pre-reset memo kept across
   * the reset, a refinement for when the noise matters.
   */
  public function touchVariants(MaterialsChangeSet $change_set): void {
    foreach ($this->unjudgedRecords() as $record) {
      if ($record->kind === MaterialKind::Variant) {
        $change_set->touch(MaterialKind::Variant, $record->id);
      }
    }
  }

  /**
   * The active published skin's minted tokens on declared scales.
   *
   * A token record is always `updatable`, shipped or not: a consuming site's
   * write to a shipped token lands in the skin's site layer over the package's
   * record, never in the package's, so the distro lock (updatable() for the
   * other kinds) refuses nothing here. What the lock does refuse for a token is
   * its removal (SkinMaterialManager::deleteToken()). The origin is per token:
   * shipped when the skin is shipped and its package layer holds the name (a
   * site override over it keeps the shipped origin, the package still ships the
   * token), user for a token the site minted into the site layer and for every
   * token of the site's own skin.
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
   * AI read it: a shipped variant is locked while the lock holds (a
   * consuming site) and updatable once the developer gate lifts it
   * (DistroLock); a skin material never reads it (see tokens(): its site
   * layer takes the write). Origin is the record fact the flag keys on
   * (the entity carries a provider), the same fact the client's shipped
   * gestures (revert, copy) read. Records are cached under the roster's
   * tags, so a flag flip needs a cache rebuild to reach the client.
   */
  protected function updatable(string $origin): bool {
    return !($origin === MaterialRecord::ORIGIN_SHIPPED && $this->distroLock->holds());
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
   * The active published skin's animations, in stored order.
   *
   * The tokens read's twin for the skin's motion section: the same read
   * the compiled skin file makes (the skin's animations as worn), so what
   * validates and what delivers are one set in one order. An animation
   * record is always `updatable`, shipped or not: a consuming site's
   * write lands in the skin's site layer over the package's record (see
   * tokens()). The origin is per animation: shipped when the skin is
   * shipped and its package layer holds the name, user otherwise. The
   * spelling is the property-scoped animation slot
   * (StyleGuide::animationName()), never var().
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per animation, stored order.
   */
  protected function animations(): array {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    $package = $skin->isShipped() ? $skin->packageAnimations() : [];
    $design_system = $skin->getDesignSystem();
    $records = [];
    foreach ($skin->animations() as $name => $record) {
      $name = (string) $name;
      if (!SkinAnimationsItem::isRecord($record)) {
        throw new \UnexpectedValueException(sprintf('The stored animation %s of the "%s" skin is not a record; a skin stores {steps, description, offered} per animation.', $name, $skin->getMachineName()));
      }
      $records[] = new MaterialRecord(
        kind: MaterialKind::Animation,
        id: $name,
        label: $name,
        description: $record['description'],
        value: $record['steps'],
        spellings: [MaterialRecord::SLOT_ANIMATION => StyleGuide::animationName($name)],
        origin: isset($package[$name]) ? MaterialRecord::ORIGIN_SHIPPED : MaterialRecord::ORIGIN_USER,
        designSystem: $design_system,
        offered: $record['offered'],
      );
    }
    return $records;
  }

  /**
   * The active published skin's fonts, in stored order.
   *
   * The animations read's twin for the skin's type section: the same read
   * the compiled skin file makes (the skin's fonts as worn), so what the
   * roster offers and what delivers are one set in one order. The value
   * is {family, faces} (the family a stack names, the faces the family
   * ships); the spelling is the quoted family in the property-scoped font
   * slot (StyleGuide::fontFamily()), never var(): a family is a string
   * component of a font-family stack. A font record is always
   * `updatable`, shipped or not (see tokens()); the origin is per font:
   * shipped when the skin is shipped and its package layer holds the name,
   * user otherwise.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   One record per font, stored order.
   */
  protected function fonts(): array {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    $package = $skin->isShipped() ? $skin->packageFonts() : [];
    $design_system = $skin->getDesignSystem();
    $records = [];
    foreach ($skin->fonts() as $name => $record) {
      $name = (string) $name;
      if (!SkinFontsItem::isRecord($record)) {
        throw new \UnexpectedValueException(sprintf('The stored font %s of the "%s" skin is not a record; a skin stores {family, faces, description, offered} per font.', $name, $skin->getMachineName()));
      }
      $records[] = new MaterialRecord(
        kind: MaterialKind::Font,
        id: $name,
        label: $name,
        description: (string) $record['description'],
        value: ['family' => $record['family'], 'faces' => $record['faces']],
        spellings: [MaterialRecord::SLOT_FONT => StyleGuide::fontFamily((string) $record['family'])],
        origin: isset($package[$name]) ? MaterialRecord::ORIGIN_SHIPPED : MaterialRecord::ORIGIN_USER,
        designSystem: $design_system,
        offered: (bool) $record['offered'],
      );
    }
    return $records;
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
