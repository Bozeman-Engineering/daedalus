<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\SkinMaterialValidationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The skin material gestures: mint, update, rename, revert and delete.
 *
 * The variant gesture manager's twin for the material kinds that live on
 * the skin, in three SECTIONS: TOKENS (a record {value, description,
 * offered} under a declared scale, delivered as a custom property),
 * ANIMATIONS (a record {steps, description, offered} under a name,
 * delivered as @keyframes motion-{name}) and FONTS (a record {family,
 * faces, description, offered} under a name, delivered as one @font-face
 * per face and named in a stack by its quoted family). A skin material is
 * a fact of the skin, never of a guide file or an entity of its own, so it
 * is minted, changed and removed by name through the material gesture
 * family (DaedalusStylishMaterialsProvider dispatches save_material /
 * update_material / remove_material with kind token, animation or font
 * here), never by rewriting the skin's whole map. Every gesture addresses
 * the ACTIVE skin: ids are not skin-qualified ("{scale}/{name}" names the
 * active skin's token, "{name}" its animation or font, the ids the roster
 * carries).
 *
 * WHICH LAYER a write lands in is the skin manager's one answer
 * (SkinManager::writesSiteLayer(), shared with the site defaults op):
 * - the SITE layer (site_tokens, site_animations, site_fonts) when the skin is
 *   a package's (it has a provider) and the distro lock holds, the consuming
 *   site's posture: a mint adds a site-minted record, an update stores only the
 *   facts it changes over the package's record (the rest show through, so a
 *   package update to an unstated fact still lands), and a removal takes the
 *   site's record away, revealing the package's whole or deleting a site-minted
 *   one; a package record the site never touched cannot be removed on a
 *   consuming site (the disk is its one author);
 * - the skin's ONE layer (the package layer) when the skin is the site's
 *   own (no provider) or the developer gate lifts the lock (DistroLock,
 *   the authoring site that ships the result back to disk): the write
 *   edits the record in place, and a removal deletes it from both layers.
 *
 * Every write validates the section AS WORN (the merge of both layers)
 * through its seam (StyleMapValidator::validateTokens() /
 * validateAnimations(), FontValidator::validateFonts(), the gates the disk
 * lane converges on), so a material minted on the canvas meets exactly
 * the checks a shipped one did; on strict sites that includes the map's
 * self-consistency, so a removal another token's var() reaches refuses.
 * Errors re-root from the map's path space onto the gesture's keys (group,
 * label, value, steps, family, faces, description, offered).
 *
 * A write is an ordinary revisioned save of the skin
 * (SkinManager::saveRevision(): a bad edit is one restore away,
 * workspace-staged inside a workspace; the skin's revision IS the
 * lifecycle, no material carries revisions of its own), and it never
 * reads as a modification of what the package shipped: the site layer is
 * outside the shipped payload. The skin's entity hook records the
 * material ids on the request's MaterialsChangeSet, which is how the
 * write reaches every client.
 *
 * The usage lane is the variant lane's twin at value grain, one spine for
 * all three sections (SkinMaterialUsage): a token is referenced as var()
 * of its custom property, an animation as its keyframes name in a motion
 * property, a font as its quoted family in a font-family stack (and in
 * the tokens of the scale the guide binds to font-family), the table
 * counts the sources whose saved values reference any, and a REFERENCED
 * material is never deleted from under them: its removal stages the
 * rewrite-then-delete batch (SkinMaterialDeleteBatch: a var() becomes
 * the token's literal, a keyframes name becomes `none`, a family leaves
 * its stacks) behind the "delete skin materials in use" grade, and a
 * token's or animation's rename (the name IS the reference's tail, so a
 * rename respells every reference) stages the rewrite-then-rename batch;
 * a font's name is its roster id alone (stacks name the family), so its
 * rename moves the record and touches no reference. A package material
 * cannot be deleted or renamed on a consuming site: the disk is its one
 * author of both facts. Revert converges a material toward the disk (the
 * site's record over it dropped; under the developer gate the package
 * record rewritten from the file, a font's faces landed again from the
 * module's assets).
 */
class SkinMaterialManager {

  use StringTranslationTrait;

  /**
   * The skin's sections this manager serves.
   */
  public const TOKENS = 'tokens';
  public const ANIMATIONS = 'animations';
  public const FONTS = 'fonts';

  public function __construct(
    protected StyleMapValidator $validator,
    protected SkinManager $skinManager,
    protected AccountProxyInterface $currentUser,
    protected ConditionTypePluginManager $conditionTypes,
    protected StyleGuide $styleGuide,
    protected SkinMaterialUsage $usage,
    protected SkinImporter $importer,
    protected FontValidator $fontValidator,
    protected FontFiles $fontFiles,
  ) {}

  /**
   * The active skin, the one every skin material gesture addresses.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   *   When no skin is active: tokens and animations are the active skin's
   *   materials, so there is nothing to mint on.
   */
  public function activeSkin(): DaedalusStylishSkinInterface {
    return $this->skinManager->loadActiveSkin() ?? throw new SkinMaterialValidationException([
      new BlueprintError('', 'STYLE_SKIN_NONE_ACTIVE', 'No skin is active: a token, an animation or a font is a fact of the active skin.', [], 'Activate a design system that carries a skin, or create a skin and make it the active system\'s default_skin, then mint materials on it.'),
    ]);
  }

  /**
   * Splits a token's roster id into its scale and name.
   *
   * @return array{0: string, 1: string}|null
   *   [scale, name], or NULL when the id is not "{scale}/{name}".
   */
  public static function parseId(string $id): ?array {
    $parts = explode('/', $id);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      return NULL;
    }
    return [$parts[0], $parts[1]];
  }

  /**
   * The first declared scale whose shape fits a value, or NULL for none.
   *
   * The capture rule: a value saved from the canvas (the literal an
   * element wears, the gradient builder's "Save as token") names no
   * scale, so it lands on the first scale, in declared order, whose shape
   * admits it through the token seam (StyleMapValidator::fitsTokenShape():
   * the shape grammar and the condition grammar, references unchecked).
   * A color lands on the color scale and a gradient on the gradient
   * scale under the suite's declarations; a caller wanting another scale
   * names it.
   *
   * @param string|array $value
   *   A CSS value or a condition-keyed map.
   * @param string|null $theme
   *   The theme whose declarations to read, NULL for the default.
   */
  public function scaleFor(string|array $value, ?string $theme = NULL): ?string {
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      if ($this->validator->fitsTokenShape($value, $declaration)) {
        return (string) $scale;
      }
    }
    return NULL;
  }

  /**
   * Whether a skin mints a token (either layer) under a scale and name.
   */
  public function has(DaedalusStylishSkinInterface $skin, string $scale, string $name): bool {
    return isset($skin->tokens()[$scale][$name]);
  }

  /**
   * Whether a skin mints an animation (either layer) under a name.
   */
  public function hasAnimation(DaedalusStylishSkinInterface $skin, string $name): bool {
    return isset($skin->animations()[$name]);
  }

  /**
   * Whether a skin mints a font (either layer) under a name.
   */
  public function hasFont(DaedalusStylishSkinInterface $skin, string $name): bool {
    return isset($skin->fonts()[$name]);
  }

  /**
   * Whether a skin's writes land in its site layer: the skin manager's rule.
   *
   * Public as the package layer's lock at material grain (the usage
   * route's `locked`): TRUE on a consuming site, where a material's
   * identity (its name, its existence) belongs to the package and only
   * its facts take a site record; FALSE on the site's own skin and under
   * the developer gate.
   */
  public function writesSiteLayer(DaedalusStylishSkinInterface $skin): bool {
    return $this->skinManager->writesSiteLayer($skin);
  }

  /**
   * The roster record a token will be once renamed.
   *
   * The batched rename's response states the outcome the batch is
   * committed to before the record exists: every fact of the current
   * record with the new identity (id, label, the custom property spelled
   * under the scale's prefix, the one derivation delivery and validation
   * spell).
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord $record
   *   The token's current roster record.
   * @param string $new_name
   *   The new name.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord
   *   The renamed record.
   */
  public function renamedRecord(MaterialRecord $record, string $new_name): MaterialRecord {
    $scale = (string) $record->group;
    return new MaterialRecord(
      kind: $record->kind,
      id: "$scale/$new_name",
      label: $new_name,
      description: $record->description,
      value: $record->value,
      group: $record->group,
      spellings: [MaterialRecord::SLOT_STYLE => 'var(' . $this->variable($scale, $new_name) . ')'],
      origin: $record->origin,
      updatable: $record->updatable,
      designSystem: $record->designSystem,
      offered: $record->offered,
    );
  }

  /**
   * The roster record an animation will be once renamed.
   *
   * `renamedRecord()`'s twin for the motion section: the new identity is
   * the name, and the spelling the keyframes name under the motion
   * prefix (StyleGuide::animationName()).
   */
  public function renamedAnimationRecord(MaterialRecord $record, string $new_name): MaterialRecord {
    return new MaterialRecord(
      kind: $record->kind,
      id: $new_name,
      label: $new_name,
      description: $record->description,
      value: $record->value,
      spellings: [MaterialRecord::SLOT_ANIMATION => StyleGuide::animationName($new_name)],
      origin: $record->origin,
      updatable: $record->updatable,
      designSystem: $record->designSystem,
      offered: $record->offered,
    );
  }

  /**
   * The roster record a font will be once renamed.
   *
   * `renamedRecord()`'s twin for the type section: the new identity is the
   * name alone; the spelling (the quoted family) is untouched, since a
   * stack names the family, never the name.
   */
  public function renamedFontRecord(MaterialRecord $record, string $new_name): MaterialRecord {
    return new MaterialRecord(
      kind: $record->kind,
      id: $new_name,
      label: $new_name,
      description: $record->description,
      value: $record->value,
      spellings: $record->spellings,
      origin: $record->origin,
      updatable: $record->updatable,
      designSystem: $record->designSystem,
      offered: $record->offered,
    );
  }

  /**
   * The custom property a token delivers as, the reference form.
   *
   * StyleGuide::tokenVariable() under the scale's declared prefix, the
   * one derivation delivery and validation spell; an undeclared scale
   * derives under its own name, which is what the guide answers for a
   * scale without a prefix.
   */
  public function variable(string $scale, string $name): string {
    $scales = $this->styleGuide->getTokenScales();
    $declaration = $scales[$scale] ?? [];
    return StyleGuide::tokenVariable($scale, is_array($declaration) ? $declaration : [], $name);
  }

  /**
   * Resolves a token of a skin to its handle, or NULL when the skin lacks it.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $scale
   *   The token's scale.
   * @param string $name
   *   The token's name.
   * @param string|null $rename_to
   *   The new name a rename batch carries, or NULL.
   */
  public function resolve(DaedalusStylishSkinInterface $skin, string $scale, string $name, ?string $rename_to = NULL): ?SkinToken {
    $record = $skin->tokens()[$scale][$name] ?? NULL;
    if (!is_array($record)) {
      return NULL;
    }
    return new SkinToken($skin, $scale, $name, $record, $this->variable($scale, $name), $rename_to);
  }

  /**
   * Resolves a skin's animation to its handle, or NULL when the skin lacks it.
   */
  public function resolveAnimation(DaedalusStylishSkinInterface $skin, string $name, ?string $rename_to = NULL): ?SkinAnimation {
    $record = $skin->animations()[$name] ?? NULL;
    if (!is_array($record)) {
      return NULL;
    }
    return new SkinAnimation($skin, $name, $record, StyleGuide::animationName($name), $rename_to);
  }

  /**
   * Resolves a font of a skin to its handle, or NULL when the skin lacks it.
   */
  public function resolveFont(DaedalusStylishSkinInterface $skin, string $name): ?SkinFont {
    $record = $skin->fonts()[$name] ?? NULL;
    if (!is_array($record)) {
      return NULL;
    }
    return new SkinFont($skin, $name, $record, StyleGuide::fontFamily((string) ($record['family'] ?? '')));
  }

  /**
   * How many source entities reference a token in their saved values.
   *
   * The usage table's count (see SkinMaterialUsage): host style maps,
   * variant styles, and skin tokens, animations and defaults, distinct
   * per source entity.
   */
  public function usages(string $scale, string $name): int {
    return $this->usage->countReferencesTo($this->variable($scale, $name));
  }

  /**
   * How many source entities wear an animation in their saved values.
   */
  public function animationUsages(string $name): int {
    return $this->usage->countReferencesTo(StyleGuide::animationName($name));
  }

  /**
   * How many source entities name a font's family in their saved values.
   *
   * Keyed by the quoted family (StyleGuide::fontFamily()), the reference
   * form a font-family stack spells and the usage table stores: host
   * style maps, variant styles, skin defaults and the skin's tokens on
   * the font-bound scale, distinct per source entity.
   */
  public function fontUsages(DaedalusStylishSkinInterface $skin, string $name): int {
    $font = $this->resolveFont($skin, $name);
    return $font === NULL ? 0 : $this->usage->countReferencesTo($font->spelling);
  }

  /**
   * Which layer(s) of a skin hold a token's record.
   *
   * @return string
   *   'package' (the package's record alone), 'site' (site-minted) or
   *   'both' (the site's record over the package's).
   */
  public function layer(DaedalusStylishSkinInterface $skin, string $scale, string $name): string {
    return self::layerOf(isset($skin->packageTokens()[$scale][$name]), isset($skin->siteTokens()[$scale][$name]));
  }

  /**
   * Which layer(s) of a skin hold an animation's record; see layer().
   */
  public function animationLayer(DaedalusStylishSkinInterface $skin, string $name): string {
    return self::layerOf(isset($skin->packageAnimations()[$name]), isset($skin->siteAnimations()[$name]));
  }

  /**
   * Which layer(s) of a skin hold a font's record; see layer().
   */
  public function fontLayer(DaedalusStylishSkinInterface $skin, string $name): string {
    return self::layerOf(isset($skin->packageFonts()[$name]), isset($skin->siteFonts()[$name]));
  }

  /**
   * The layer word for a package and a site presence.
   */
  protected static function layerOf(bool $package, bool $site): string {
    return $package && $site ? 'both' : ($site ? 'site' : 'package');
  }

  /**
   * A token's shipped state against the CURRENT disk payload.
   *
   * The shipped state at material grain: NULL when no disk file ships
   * the token (a site-minted token, the site's own skin, or a provider
   * that stopped shipping the name), so no revert is ever offered;
   * 'unmodified' when the record as worn is the file's; 'modified' when
   * the site's record over it, or the package record under the developer
   * gate, diverges from the file.
   */
  public function shippedState(DaedalusStylishSkinInterface $skin, string $scale, string $name): ?string {
    $shipped = $this->shippedRecord($skin, $scale, $name);
    if ($shipped === NULL) {
      return NULL;
    }
    return ($skin->tokens()[$scale][$name] ?? NULL) === $shipped ? 'unmodified' : 'modified';
  }

  /**
   * An animation's shipped state against the CURRENT disk payload.
   *
   * See shippedState().
   */
  public function animationShippedState(DaedalusStylishSkinInterface $skin, string $name): ?string {
    $shipped = $this->shippedAnimationRecord($skin, $name);
    if ($shipped === NULL) {
      return NULL;
    }
    return ($skin->animations()[$name] ?? NULL) === $shipped ? 'unmodified' : 'modified';
  }

  /**
   * A font's shipped state against the CURRENT disk payload.
   *
   * See shippedState().
   *
   * The comparison is content-addressed on both sides (a face names its
   * file by uuid on the site and by basename on disk; the hash of the
   * bytes is what both sides share), so a face whose bytes changed in the
   * module's assets reads as modified, and a swapped file on the site
   * likewise.
   */
  public function fontShippedState(DaedalusStylishSkinInterface $skin, string $name): ?string {
    $shipped = $this->shippedFontRecord($skin, $name);
    if ($shipped === NULL) {
      return NULL;
    }
    $worn = $skin->fonts()[$name] ?? NULL;
    if (!is_array($worn)) {
      return 'modified';
    }
    return $this->fontFiles->contentAddressed([$name => $worn])[$name] === $shipped ? 'unmodified' : 'modified';
  }

  /**
   * The record the skin's provider ships for a token right now, or NULL.
   */
  protected function shippedRecord(DaedalusStylishSkinInterface $skin, string $scale, string $name): ?array {
    if ($skin->getProvider() === NULL) {
      return NULL;
    }
    $record = $this->importer->getShippedData($skin)['tokens'][$scale][$name] ?? NULL;
    return is_array($record) ? $record : NULL;
  }

  /**
   * The record the skin's provider ships for an animation right now, or NULL.
   */
  protected function shippedAnimationRecord(DaedalusStylishSkinInterface $skin, string $name): ?array {
    if ($skin->getProvider() === NULL) {
      return NULL;
    }
    $record = $this->importer->getShippedData($skin)['animations'][$name] ?? NULL;
    return is_array($record) ? $record : NULL;
  }

  /**
   * The record the skin's provider ships for a font right now, or NULL.
   *
   * Content-addressed (SkinImporter::shippedFonts()): faces name their
   * files by the sha256 of the module's assets.
   */
  protected function shippedFontRecord(DaedalusStylishSkinInterface $skin, string $name): ?array {
    if ($skin->getProvider() === NULL) {
      return NULL;
    }
    $record = $this->importer->getShippedData($skin)['fonts'][$name] ?? NULL;
    return is_array($record) ? $record : NULL;
  }

  /**
   * Mints a token on the skin: the palette's and the AI's save gesture.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin (the active one, see activeSkin()).
   * @param string $scale
   *   The declared scale the token is minted on (the record's group).
   * @param string $name
   *   The token name, the custom property's tail (StyleMapValidator::
   *   TOKEN_NAME): "4", "2xl", "accent-hover".
   * @param string|array $value
   *   The CSS value: a string, or a condition-keyed map with a 'default'
   *   entry; compound conditions store canonical spellings.
   * @param string $description
   *   The token's reasoning (why it exists, when to reach for it), the
   *   prose the AI and the palette read; '' for none.
   * @param bool $offered
   *   Whether rows bound to the scale offer the token as an everyday step
   *   and the AI's roster advertises it; TRUE by default.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface
   *   The saved skin, the token in the layer the write landed in.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function mint(DaedalusStylishSkinInterface $skin, string $scale, string $name, string|array $value, string $description = '', bool $offered = TRUE): DaedalusStylishSkinInterface {
    if ($this->has($skin, $scale, $name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_TOKEN_EXISTS', sprintf('The "%s" skin already mints %s/%s.', $skin->getMachineName(), $scale, $name), ['id' => "$scale/$name"], 'Pick another name, or update_material the existing token (kind token, id "{scale}/{name}") to change its value, description or offered flag.'),
      ]);
    }
    $record = [
      'value' => $this->conditionTypes->normalizeValueConditions($value),
      'description' => trim($description),
      'offered' => $offered,
    ];
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteTokens();
      $site[$scale][$name] = $record;
      $this->validateTokens($skin->packageTokens(), $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = $skin->packageTokens();
      $package[$scale][$name] = $record;
      $this->validateTokens($package, $skin->siteTokens(), $scale, $name);
      $skin->setPackageTokens($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('Minted the %s/%s token.', $scale, $name));
    return $skin;
  }

  /**
   * Mints an animation on the skin: the skin tab's and the AI's save gesture.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin (the active one, see activeSkin()).
   * @param string $name
   *   The animation name, the keyframes ident tail
   *   (SkinAnimationsItem::NAME): "fade_in", "rise".
   * @param mixed $steps
   *   The keyframe steps: offsets (`from`, `to`, `N%`) to property maps;
   *   stored canonical (SkinAnimationsItem::canonicalizeSteps()).
   * @param string $description
   *   When to reach for the motion, the prose the AI and the skin tab
   *   read; '' for none.
   * @param bool $offered
   *   Whether the AI's roster advertises the animation; TRUE by default.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface
   *   The saved skin, the animation in the layer the write landed in.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function mintAnimation(DaedalusStylishSkinInterface $skin, string $name, mixed $steps, string $description = '', bool $offered = TRUE): DaedalusStylishSkinInterface {
    if ($this->hasAnimation($skin, $name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_ANIMATION_EXISTS', sprintf('The "%s" skin already mints the %s animation.', $skin->getMachineName(), $name), ['id' => $name], 'Pick another name, or update_material the existing animation (kind animation, id "{name}") to change its steps, description or offered flag.'),
      ]);
    }
    $record = SkinAnimationsItem::canonicalizeAnimation([
      'steps' => $steps,
      'description' => trim($description),
      'offered' => $offered,
    ]);
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteAnimations();
      $site[$name] = $record;
      $this->validateAnimations($skin, $skin->packageAnimations(), $site, $name);
      $skin->setSiteAnimations($site);
    }
    else {
      $package = $skin->packageAnimations();
      $package[$name] = $record;
      $this->validateAnimations($skin, $package, $skin->siteAnimations(), $name);
      $skin->setPackageAnimations($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('Minted the %s animation.', $name));
    return $skin;
  }

  /**
   * Mints a font on the skin: the skin tab's and the AI's save gesture.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin (the active one, see activeSkin()).
   * @param string $name
   *   The font name, the roster id (SkinFontsItem::NAME): "inter",
   *   "display_serif".
   * @param mixed $family
   *   The family name font-family values reference ("Inter").
   * @param mixed $faces
   *   The faces: a list of {weight?, style?, source: {file: uuid}}, each
   *   naming an uploaded font file; stored canonical
   *   (SkinFontsItem::canonicalizeFaces()).
   * @param string $description
   *   When to reach for the family, the prose the AI and the skin tab
   *   read; '' for none.
   * @param bool $offered
   *   Whether the AI's roster advertises the font; TRUE by default.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface
   *   The saved skin, the font in the layer the write landed in.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function mintFont(DaedalusStylishSkinInterface $skin, string $name, mixed $family, mixed $faces, string $description = '', bool $offered = TRUE): DaedalusStylishSkinInterface {
    if ($this->hasFont($skin, $name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_FONT_EXISTS', sprintf('The "%s" skin already mints the %s font.', $skin->getMachineName(), $name), ['id' => $name], 'Pick another name, or update_material the existing font (kind font, id "{name}") to change its faces, family, description or offered flag.'),
      ]);
    }
    $record = SkinFontsItem::canonicalizeFont([
      'family' => $family,
      'faces' => $faces,
      'description' => trim($description),
      'offered' => $offered,
    ]);
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteFonts();
      $site[$name] = $record;
      $this->validateFonts($skin->packageFonts(), $site, $name);
      $skin->setSiteFonts($site);
    }
    else {
      $package = $skin->packageFonts();
      $package[$name] = $record;
      $this->validateFonts($package, $skin->siteFonts(), $name);
      $skin->setPackageFonts($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('Minted the %s font.', $name));
    return $skin;
  }

  /**
   * Changes a token's facts in place: value, description, offered, any of them.
   *
   * The one write behind update_material (kind token) and the palette's
   * editors. On a consuming site the site layer stores exactly the facts
   * given (a re-valued package token keeps its shipped description unless
   * the site describes it too); on the site's own skin and under the
   * developer gate the record itself changes. The value IS a delivered
   * custom property, so one save restyles every referencing element.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $scale
   *   The token's scale.
   * @param string $name
   *   The token's name.
   * @param string|array|null $value
   *   The new value, or NULL to leave it.
   * @param string|null $description
   *   The new description ('' clears it), or NULL to leave it.
   * @param bool|null $offered
   *   The new offered flag, or NULL to leave it.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function update(DaedalusStylishSkinInterface $skin, string $scale, string $name, string|array|null $value = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishSkinInterface {
    if ($value === NULL && $description === NULL && $offered === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a value, a description, offered, or several.', [], 'Pass "value" to change what the token delivers, "description" to say when to reach for it, "offered" to show or hide it as one of the scale\'s everyday steps.'),
      ]);
    }
    $facts = [];
    $log = [];
    if ($value !== NULL) {
      $facts['value'] = $this->conditionTypes->normalizeValueConditions($value);
      $log[] = 'Updated the value.';
    }
    if ($description !== NULL) {
      $facts['description'] = trim($description);
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL) {
      $facts['offered'] = $offered;
      $log[] = $offered ? 'Offered in rows.' : 'Withheld from rows.';
    }
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteTokens();
      $site[$scale][$name] = $facts + ($site[$scale][$name] ?? []);
      $this->validateTokens($skin->packageTokens(), $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = $skin->packageTokens();
      $package[$scale][$name] = $facts + $package[$scale][$name];
      $this->validateTokens($package, $skin->siteTokens(), $scale, $name);
      $skin->setPackageTokens($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('%s/%s: %s', $scale, $name, implode(' ', $log)));
    return $skin;
  }

  /**
   * Changes an animation's facts in place: steps, description, offered.
   *
   * `update()`'s twin for the motion section, the one write behind
   * update_material (kind animation) and the keyframes editor's commit.
   * The steps ARE the delivered @keyframes body, so one save re-animates
   * every wearer site-wide.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $name
   *   The animation's name.
   * @param mixed $steps
   *   The new steps, or NULL to leave them.
   * @param string|null $description
   *   The new description ('' clears it), or NULL to leave it.
   * @param bool|null $offered
   *   The new offered flag, or NULL to leave it.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function updateAnimation(DaedalusStylishSkinInterface $skin, string $name, mixed $steps = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishSkinInterface {
    if ($steps === NULL && $description === NULL && $offered === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass steps, a description, offered, or several.', [], 'Pass "steps" to change the keyframes, "description" to say when to reach for the motion, "offered" to share it with the AI or not.'),
      ]);
    }
    $facts = [];
    $log = [];
    if ($steps !== NULL) {
      $facts['steps'] = is_array($steps) ? SkinAnimationsItem::canonicalizeSteps($steps) : $steps;
      $log[] = 'Updated the steps.';
    }
    if ($description !== NULL) {
      $facts['description'] = trim($description);
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL) {
      $facts['offered'] = $offered;
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteAnimations();
      $site[$name] = $facts + ($site[$name] ?? []);
      $this->validateAnimations($skin, $skin->packageAnimations(), $site, $name);
      $skin->setSiteAnimations($site);
    }
    else {
      $package = $skin->packageAnimations();
      $package[$name] = $facts + $package[$name];
      $this->validateAnimations($skin, $package, $skin->siteAnimations(), $name);
      $skin->setPackageAnimations($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('%s animation: %s', $name, implode(' ', $log)));
    return $skin;
  }

  /**
   * Changes a font's facts in place: family, faces, description, offered.
   *
   * `update()`'s twin for the type section, the one write behind
   * update_material (kind font) and the faces editor's commit. The faces
   * ARE the delivered at-rules and the family IS the reference: one save
   * restyles every element set in the family. A family change respells no
   * stack (a stack naming the old family falls through to its next
   * family, as it would under a deleted font); the stacks are the
   * author's to re-point.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function updateFont(DaedalusStylishSkinInterface $skin, string $name, mixed $family = NULL, mixed $faces = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishSkinInterface {
    if ($family === NULL && $faces === NULL && $description === NULL && $offered === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a family, faces, a description, offered, or several.', [], 'Pass "family" to change the name stacks reference, "faces" to change its files, "description" to say when to reach for it, "offered" to share it with the AI or not.'),
      ]);
    }
    $facts = [];
    $log = [];
    if ($family !== NULL) {
      $facts['family'] = is_string($family) ? trim($family) : $family;
      $log[] = 'Changed the family.';
    }
    if ($faces !== NULL) {
      $facts['faces'] = is_array($faces) && array_is_list($faces) ? SkinFontsItem::canonicalizeFaces($faces) : $faces;
      $log[] = 'Updated the faces.';
    }
    if ($description !== NULL) {
      $facts['description'] = trim($description);
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL) {
      $facts['offered'] = $offered;
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($this->writesSiteLayer($skin)) {
      $site = $skin->siteFonts();
      $site[$name] = $facts + ($site[$name] ?? []);
      $this->validateFonts($skin->packageFonts(), $site, $name);
      $skin->setSiteFonts($site);
    }
    else {
      $package = $skin->packageFonts();
      $package[$name] = $facts + $package[$name];
      $this->validateFonts($package, $skin->siteFonts(), $name);
      $skin->setPackageFonts($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('%s font: %s', $name, implode(' ', $log)));
    return $skin;
  }

  /**
   * Renames a token: the record moves, every reference follows.
   *
   * A token's name is its custom property's tail, so a rename respells
   * every var() of it (a variant rename touches a label only; a token
   * has no label apart from its name). Unreferenced, the record moves in
   * this request, in place (row order is stored order). REFERENCED, the
   * rewrite-then-rename Batch API job is staged for the caller to drive
   * (SkinMaterialDeleteBatch::renameMaterial() is its tail); on the op
   * spine nothing drives a core Batch, so a referenced token refuses
   * there with its count. A package token cannot be renamed on a
   * consuming site, site override or not: the disk is the one author of
   * its name. The new name meets the token seam exactly as a mint's does
   * (its grammar, and on strict sites the map's self-consistency), errors
   * re-rooted onto "label".
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $scale
   *   The token's scale.
   * @param string $name
   *   The token's name.
   * @param string $new_name
   *   The new name.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged rewrite-then-rename batch.
   *
   * @return int
   *   0 when the token was renamed in this request (unreferenced);
   *   otherwise the number of source entities the staged batch will
   *   respell before renaming.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function rename(DaedalusStylishSkinInterface $skin, string $scale, string $name, string $new_name, bool $stage_batch = TRUE): int {
    $new_name = trim($new_name);
    if ($new_name === '' || $new_name === $name) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', $new_name === '' ? 'A token needs a name.' : sprintf('%s/%s already bears that name.', $scale, $name), [], 'Pass "label" as the new token name, the tail of its custom property ("4", "2xl", "accent-hover").'),
      ]);
    }
    $package = $skin->packageTokens();
    $site = $skin->siteTokens();
    if ($this->writesSiteLayer($skin) && isset($package[$scale][$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_SHIPPED_LOCKED', sprintf('%s/%s is shipped by the "%s" module; a shipped token cannot be renamed on this site.', $scale, $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Mint a token under the name you want and reference that instead, or rename it in the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    if ($this->has($skin, $scale, $new_name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_TOKEN_EXISTS', sprintf('The "%s" skin already mints %s/%s.', $skin->getMachineName(), $scale, $new_name), ['id' => "$scale/$new_name"], 'Pick another name.'),
      ]);
    }
    $package = self::renamed($package, [$scale, $name], $new_name);
    $site = self::renamed($site, [$scale, $name], $new_name);
    $this->validateTokens($package, $site, $scale, $new_name);
    $references = $this->usage->referencesTo($this->variable($scale, $name));
    if ($references !== []) {
      if (!$stage_batch) {
        throw new SkinMaterialValidationException([
          new BlueprintError('id', 'STYLE_TOKEN_IN_USE', sprintf('%s/%s is referenced by %d place(s); it cannot be renamed from here.', $scale, $name, count($references)), ['usages' => count($references)], 'Rename it from the skin tab, which respells every reference before the record moves.'),
        ]);
      }
      $token = new SkinToken($skin, $scale, $name, $skin->tokens()[$scale][$name], $this->variable($scale, $name), $new_name);
      $this->stageRename($token->batchId(), $token->id(), $references);
      return count($references);
    }
    $skin->setPackageTokens($package);
    $skin->setSiteTokens($site);
    $this->skinManager->saveRevision($skin, sprintf('Renamed %s/%s to %s/%s.', $scale, $name, $scale, $new_name));
    return 0;
  }

  /**
   * Renames an animation: the record moves, every reference follows.
   *
   * `rename()`'s twin for the motion section: the name is the keyframes
   * ident tail, so a rename respells every motion-{name} in the wearers'
   * animation-name and animation values (KeyframesNames::replaceName()),
   * staged as the rewrite-then-rename batch when the animation is worn.
   *
   * @return int
   *   0 when renamed in this request; otherwise the number of source
   *   entities the staged batch will respell before renaming.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function renameAnimation(DaedalusStylishSkinInterface $skin, string $name, string $new_name, bool $stage_batch = TRUE): int {
    $new_name = trim($new_name);
    if ($new_name === '' || $new_name === $name) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', $new_name === '' ? 'An animation needs a name.' : sprintf('The %s animation already bears that name.', $name), [], 'Pass "label" as the new animation name, the tail of its keyframes name ("fade_in", "rise").'),
      ]);
    }
    $package = $skin->packageAnimations();
    $site = $skin->siteAnimations();
    if ($this->writesSiteLayer($skin) && isset($package[$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_ANIMATION_SHIPPED_LOCKED', sprintf('The %s animation is shipped by the "%s" module; a shipped animation cannot be renamed on this site.', $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Mint an animation under the name you want and wear that instead, or rename it in the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    if ($this->hasAnimation($skin, $new_name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_ANIMATION_EXISTS', sprintf('The "%s" skin already mints the %s animation.', $skin->getMachineName(), $new_name), ['id' => $new_name], 'Pick another name.'),
      ]);
    }
    $package = self::renamed($package, [$name], $new_name);
    $site = self::renamed($site, [$name], $new_name);
    $this->validateAnimations($skin, $package, $site, $new_name);
    $references = $this->usage->referencesTo(StyleGuide::animationName($name));
    if ($references !== []) {
      if (!$stage_batch) {
        throw new SkinMaterialValidationException([
          new BlueprintError('id', 'STYLE_ANIMATION_IN_USE', sprintf('The %s animation is worn by %d place(s); it cannot be renamed from here.', $name, count($references)), ['usages' => count($references)], 'Rename it from the skin tab, which respells every reference before the record moves.'),
        ]);
      }
      $animation = new SkinAnimation($skin, $name, $skin->animations()[$name], StyleGuide::animationName($name), $new_name);
      $this->stageRename($animation->batchId(), $animation->id(), $references);
      return count($references);
    }
    $skin->setPackageAnimations($package);
    $skin->setSiteAnimations($site);
    $this->skinManager->saveRevision($skin, sprintf('Renamed the %s animation to %s.', $name, $new_name));
    return 0;
  }

  /**
   * Renames a font: the record moves; no reference follows, none names it.
   *
   * A font's name is its roster id and nothing else (a stack names the
   * FAMILY), so the rename is always immediate and stages no batch. A
   * package font cannot be renamed on a consuming site; the new name
   * meets the font seam exactly as a mint's does.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function renameFont(DaedalusStylishSkinInterface $skin, string $name, string $new_name): DaedalusStylishSkinInterface {
    $new_name = trim($new_name);
    if ($new_name === '' || $new_name === $name) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', $new_name === '' ? 'A font needs a name.' : sprintf('The %s font already bears that name.', $name), [], 'Pass "label" as the new font name ("inter", "display_serif": lowercase letters, digits, inner hyphens or underscores).'),
      ]);
    }
    $package = $skin->packageFonts();
    $site = $skin->siteFonts();
    if ($this->writesSiteLayer($skin) && isset($package[$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_FONT_SHIPPED_LOCKED', sprintf('The %s font is shipped by the "%s" module; a shipped font cannot be renamed on this site.', $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Rename it in the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    if ($this->hasFont($skin, $new_name)) {
      throw new SkinMaterialValidationException([
        new BlueprintError('label', 'STYLE_FONT_EXISTS', sprintf('The "%s" skin already mints the %s font.', $skin->getMachineName(), $new_name), ['id' => $new_name], 'Pick another name.'),
      ]);
    }
    $package = self::renamed($package, [$name], $new_name);
    $site = self::renamed($site, [$name], $new_name);
    $this->validateFonts($package, $site, $new_name);
    $skin->setPackageFonts($package);
    $skin->setSiteFonts($site);
    $this->skinManager->saveRevision($skin, sprintf('Renamed the %s font to %s.', $name, $new_name));
    return $skin;
  }

  /**
   * Reverts a token to what its skin's provider ships right now.
   *
   * The variant revert at material grain, converging toward the disk: the
   * site's record over the token is dropped (the package's shows through
   * whole, and on a consuming site the package layer IS the disk's, sync
   * being its one writer), and under the developer gate the package
   * record is rewritten from the file too. An ordinary revisioned save,
   * one restore away from undo. A token no disk file ships refuses: the
   * site's own skin and a site-minted token have no shipped defaults, and
   * a provider that stopped shipping the name leaves it ordinary content.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function revert(DaedalusStylishSkinInterface $skin, string $scale, string $name): DaedalusStylishSkinInterface {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_NOT_SHIPPED', sprintf('%s/%s is a token of a skin this site made; it has no shipped defaults.', $scale, $name), [], 'Only tokens of module-shipped skins can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->shippedRecord($skin, $scale, $name);
    if ($shipped === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module ships no %s/%s token.', $provider, $scale, $name), [], 'The token is site content now; edit or delete it instead.'),
      ]);
    }
    $package = $skin->packageTokens();
    $site = self::without($skin->siteTokens(), [$scale, $name]);
    if (!$this->writesSiteLayer($skin)) {
      $package[$scale][$name] = $shipped;
    }
    if ($package === $skin->packageTokens() && $site === $skin->siteTokens()) {
      return $skin;
    }
    $this->validateTokens($package, $site, $scale, $name);
    $skin->setPackageTokens($package);
    $skin->setSiteTokens($site);
    $this->skinManager->saveRevision($skin, sprintf('Reverted %s/%s to the shipped token of the %s module.', $scale, $name, $provider));
    return $skin;
  }

  /**
   * Reverts an animation to what its skin's provider ships now; see revert().
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function revertAnimation(DaedalusStylishSkinInterface $skin, string $name): DaedalusStylishSkinInterface {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_ANIMATION_NOT_SHIPPED', sprintf('The %s animation belongs to a skin this site made; it has no shipped defaults.', $name), [], 'Only animations of module-shipped skins can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->shippedAnimationRecord($skin, $name);
    if ($shipped === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module ships no %s animation.', $provider, $name), [], 'The animation is site content now; edit or delete it instead.'),
      ]);
    }
    $package = $skin->packageAnimations();
    $site = self::without($skin->siteAnimations(), [$name]);
    if (!$this->writesSiteLayer($skin)) {
      $package[$name] = $shipped;
    }
    if ($package === $skin->packageAnimations() && $site === $skin->siteAnimations()) {
      return $skin;
    }
    $this->validateAnimations($skin, $package, $site, $name);
    $skin->setPackageAnimations($package);
    $skin->setSiteAnimations($site);
    $this->skinManager->saveRevision($skin, sprintf('Reverted the %s animation to the shipped record of the %s module.', $name, $provider));
    return $skin;
  }

  /**
   * Reverts a font to what its skin's provider ships right now; see revert().
   *
   * Under the developer gate the package record is landed again from the
   * module's assets (SkinImporter::landShippedFont(): a face whose bytes
   * the skin already holds keeps its file, the rest land as new files).
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function revertFont(DaedalusStylishSkinInterface $skin, string $name): DaedalusStylishSkinInterface {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_FONT_NOT_SHIPPED', sprintf('The %s font belongs to a skin this site made; it has no shipped defaults.', $name), [], 'Only fonts of module-shipped skins can revert to their disk defaults.'),
      ]);
    }
    if ($this->shippedFontRecord($skin, $name) === NULL) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module ships no %s font.', $provider, $name), [], 'The font is site content now; edit or delete it instead.'),
      ]);
    }
    $package = $skin->packageFonts();
    $site = self::without($skin->siteFonts(), [$name]);
    if (!$this->writesSiteLayer($skin)) {
      $package[$name] = $this->importer->landShippedFont($skin, $name);
    }
    if ($package === $skin->packageFonts() && $site === $skin->siteFonts()) {
      return $skin;
    }
    $this->validateFonts($package, $site, $name);
    $skin->setPackageFonts($package);
    $skin->setSiteFonts($site);
    $this->skinManager->saveRevision($skin, sprintf('Reverted the %s font to the shipped record of the %s module.', $name, $provider));
    return $skin;
  }

  /**
   * Removes a token: the site's record of it, or the token itself.
   *
   * On a consuming site the removal takes the SITE's record away: a
   * site-minted token is deleted, and a site override over a package
   * token is dropped so the package's token shows through whole (the
   * palette's "unset" gesture, which touches no reference: the token
   * lives on); a package token the site never touched refuses, since the
   * disk is its one author. On the site's own skin and under the
   * developer gate the token goes from both layers.
   *
   * A removal that would leave references dangling never happens: lazy
   * repair is impossible in principle (the rewrite materializes the
   * token's literal value, and after deletion there is nothing to
   * materialize from), so a REFERENCED token always has every var() of
   * it rewritten to the literal first, per source entity, and "delete
   * anyway" does not exist. Two permission grades gate that gesture, the
   * variant posture exactly: the route admits both, and the CONTEXTUAL
   * gate lives here, "delete skin materials in use" for a referenced
   * token. The work list is the usage table's, each source's rewrite an
   * ordinary revisioned save (SkinMaterialDeleteBatch), then the removal.
   * A referenced token is NOT removed in this request: the Batch API job
   * is staged and the caller drives it; on the op spine nothing drives a
   * core Batch, so a referenced token refuses there with its count.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $scale
   *   The token's scale.
   * @param string $name
   *   The token's name.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged rewrite-then-delete batch.
   *
   * @return int
   *   0 when the removal completed in this request (has() answers whether
   *   the package's token was revealed or the token is gone); otherwise
   *   the number of source entities the staged batch will rewrite before
   *   deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function deleteToken(DaedalusStylishSkinInterface $skin, string $scale, string $name, bool $stage_batch = TRUE): int {
    $package = $skin->packageTokens();
    $site = $skin->siteTokens();
    $site_write = $this->writesSiteLayer($skin);
    if ($site_write && !isset($site[$scale][$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_SHIPPED_LOCKED', sprintf('%s/%s is shipped by the "%s" module; a shipped token cannot be removed on this site.', $scale, $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Withhold it from rows instead (update_material with "offered": false), or remove it from the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    $remains = $site_write && isset($package[$scale][$name]);
    if (!$remains) {
      $references = $this->usage->referencesTo($this->variable($scale, $name));
      if ($references !== []) {
        $this->refuseReferenced("$scale/$name", 'STYLE_TOKEN_IN_USE', 'token', 'the literal value', count($references), $stage_batch);
        $token = new SkinToken($skin, $scale, $name, $skin->tokens()[$scale][$name], $this->variable($scale, $name));
        $this->stageDelete($token->batchId(), $token->id(), $references);
        return count($references);
      }
    }
    if ($site_write) {
      $site = self::without($site, [$scale, $name]);
      $this->validateTokens($package, $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = self::without($package, [$scale, $name]);
      $site = self::without($site, [$scale, $name]);
      $this->validateTokens($package, $site, $scale, $name);
      $skin->setPackageTokens($package);
      $skin->setSiteTokens($site);
    }
    $this->skinManager->saveRevision($skin, sprintf($remains ? 'Reverted %s/%s to the shipped token.' : 'Deleted the %s/%s token.', $scale, $name));
    return 0;
  }

  /**
   * Removes an animation: the site's record of it, or the animation itself.
   *
   * `deleteToken()`'s twin for the motion section: a WORN animation has
   * every motion-{name} reference rewritten to `none` first (a keyframes
   * block has no literal form inside a value; the wearer keeps its timing
   * declarations and animates nothing, exactly what its stale reference
   * would have rendered), behind the "delete skin materials in use" grade.
   *
   * @return int
   *   0 when the removal completed in this request; otherwise the number
   *   of source entities the staged batch will rewrite before deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function deleteAnimation(DaedalusStylishSkinInterface $skin, string $name, bool $stage_batch = TRUE): int {
    $package = $skin->packageAnimations();
    $site = $skin->siteAnimations();
    $site_write = $this->writesSiteLayer($skin);
    if ($site_write && !isset($site[$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_ANIMATION_SHIPPED_LOCKED', sprintf('The %s animation is shipped by the "%s" module; a shipped animation cannot be removed on this site.', $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Withhold it from the AI instead (update_material with "offered": false), or remove it from the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    $remains = $site_write && isset($package[$name]);
    if (!$remains) {
      $references = $this->usage->referencesTo(StyleGuide::animationName($name));
      if ($references !== []) {
        $this->refuseReferenced($name, 'STYLE_ANIMATION_IN_USE', 'animation', '"none"', count($references), $stage_batch);
        $animation = new SkinAnimation($skin, $name, $skin->animations()[$name], StyleGuide::animationName($name));
        $this->stageDelete($animation->batchId(), $animation->id(), $references);
        return count($references);
      }
    }
    if ($site_write) {
      $site = self::without($site, [$name]);
      $this->validateAnimations($skin, $package, $site, $name);
      $skin->setSiteAnimations($site);
    }
    else {
      $package = self::without($package, [$name]);
      $site = self::without($site, [$name]);
      $this->validateAnimations($skin, $package, $site, $name);
      $skin->setPackageAnimations($package);
      $skin->setSiteAnimations($site);
    }
    $this->skinManager->saveRevision($skin, sprintf($remains ? 'Reverted the %s animation to the shipped record.' : 'Deleted the %s animation.', $name));
    return 0;
  }

  /**
   * Removes a font: the site's record of it, or the font itself.
   *
   * `deleteToken()`'s twin for the type section: a NAMED font (its quoted
   * family in a stack, a font-bound token among them) has the family
   * removed from every stack first (a stack is its own fallback list, so
   * the element lands on the next family the author wrote; a stack that
   * named nothing else becomes `initial`, the user agent's default, which
   * is what the stale reference rendered), behind the "delete skin
   * materials in use" grade. The font's files stay held by the skin for
   * its revisions (FontFiles).
   *
   * @return int
   *   0 when the removal completed in this request; otherwise the number
   *   of source entities the staged batch will rewrite before deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  public function deleteFont(DaedalusStylishSkinInterface $skin, string $name, bool $stage_batch = TRUE): int {
    $package = $skin->packageFonts();
    $site = $skin->siteFonts();
    $site_write = $this->writesSiteLayer($skin);
    if ($site_write && !isset($site[$name])) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', 'STYLE_FONT_SHIPPED_LOCKED', sprintf('The %s font is shipped by the "%s" module; a shipped font cannot be removed on this site.', $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Withhold it from the AI instead (update_material with "offered": false), or remove it from the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    $remains = $site_write && isset($package[$name]);
    if (!$remains) {
      $font = $this->resolveFont($skin, $name);
      $references = $font === NULL ? [] : $this->usage->referencesTo($font->spelling);
      if ($references !== []) {
        $this->refuseReferenced($name, 'STYLE_FONT_IN_USE', 'font', 'the stack without the family', count($references), $stage_batch);
        $this->stageDelete($font->batchId(), $font->id(), $references);
        return count($references);
      }
    }
    if ($site_write) {
      $site = self::without($site, [$name]);
      $this->validateFonts($package, $site, $name);
      $skin->setSiteFonts($site);
    }
    else {
      $package = self::without($package, [$name]);
      $site = self::without($site, [$name]);
      $this->validateFonts($package, $site, $name);
      $skin->setPackageFonts($package);
      $skin->setSiteFonts($site);
    }
    $this->skinManager->saveRevision($skin, sprintf($remains ? 'Reverted the %s font to the shipped record.' : 'Deleted the %s font.', $name));
    return 0;
  }

  /**
   * Refuses a referenced material's removal where the caller may not stage it.
   *
   * The contextual grade ("delete skin materials in use") and the op
   * spine's no-batch posture, one wording per section.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  protected function refuseReferenced(string $id, string $code, string $noun, string $residue, int $count, bool $stage_batch): void {
    if (!$this->currentUser->hasPermission('delete skin materials in use') && !$this->currentUser->hasPermission('administer skins')) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', $code, sprintf('%s is referenced by %d place(s); deleting the %s rewrites each reference to %s first, which needs the "delete skin materials in use" permission.', $id, $count, $noun, $residue), ['usages' => $count], 'Ask an administrator, or remove the references first.'),
      ]);
    }
    if (!$stage_batch) {
      throw new SkinMaterialValidationException([
        new BlueprintError('id', $code, sprintf('%s is referenced by %d place(s); it cannot be removed from here.', $id, $count), ['usages' => $count], sprintf('Remove the references first (restyle those elements with another value), or delete the %s from the skin tab, which rewrites every reference to %s before deleting.', $noun, $residue)),
      ]);
    }
  }

  /**
   * Stages the rewrite-then-delete batch for a referenced material.
   *
   * @param string $batch_id
   *   The handle's batch id (SkinToken / SkinAnimation ::batchId()).
   * @param string $id
   *   The material's roster id, for the batch's titles.
   * @param array[] $references
   *   The usage table's work list for the material.
   */
  protected function stageDelete(string $batch_id, string $id, array $references): void {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting %id', ['%id' => $id]))
      ->setInitMessage($this->t('Rewriting %id references in @count places…', [
        '%id' => $id,
        '@count' => count($references),
      ]))
      ->setFinishCallback([SkinMaterialDeleteBatch::class, 'finished']);
    foreach ($references as $reference) {
      $batch->addOperation([SkinMaterialDeleteBatch::class, 'rewriteSource'], [
        $batch_id,
        $reference['source'],
        $reference['entity_type'],
        $reference['entity_id'],
      ]);
    }
    $batch->addOperation([SkinMaterialDeleteBatch::class, 'deleteSkinMaterial'], [$batch_id]);
    batch_set($batch->toArray());
  }

  /**
   * Stages the rewrite-then-rename batch for a referenced material.
   */
  protected function stageRename(string $batch_id, string $id, array $references): void {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Renaming %id', ['%id' => $id]))
      ->setInitMessage($this->t('Respelling %id references in @count places…', [
        '%id' => $id,
        '@count' => count($references),
      ]))
      ->setFinishCallback([SkinMaterialDeleteBatch::class, 'renameFinished']);
    foreach ($references as $reference) {
      $batch->addOperation([SkinMaterialDeleteBatch::class, 'rewriteSource'], [
        $batch_id,
        $reference['source'],
        $reference['entity_type'],
        $reference['entity_id'],
      ]);
    }
    $batch->addOperation([SkinMaterialDeleteBatch::class, 'renameSkinMaterial'], [$batch_id]);
    batch_set($batch->toArray());
  }

  /**
   * A section map with one record's key renamed in place, when it holds one.
   *
   * @param array $map
   *   The layer's map.
   * @param string[] $path
   *   The record's path: [scale, name] for tokens, [name] for animations
   *   and fonts.
   * @param string $new_name
   *   The new last key.
   */
  protected static function renamed(array $map, array $path, string $new_name): array {
    $name = array_pop($path);
    $parent = &$map;
    foreach ($path as $key) {
      if (!isset($parent[$key]) || !is_array($parent[$key])) {
        return $map;
      }
      $parent = &$parent[$key];
    }
    if (!isset($parent[$name])) {
      return $map;
    }
    $renamed = [];
    foreach ($parent as $key => $record) {
      $renamed[(string) $key === $name ? $new_name : $key] = $record;
    }
    $parent = $renamed;
    return $map;
  }

  /**
   * A section map minus one record, its scale dropped when it empties.
   *
   * @param array $map
   *   The layer's map.
   * @param string[] $path
   *   The record's path: [scale, name] for tokens, [name] for animations
   *   and fonts.
   */
  protected static function without(array $map, array $path): array {
    if (count($path) === 1) {
      unset($map[$path[0]]);
      return $map;
    }
    [$scale, $name] = $path;
    unset($map[$scale][$name]);
    if (isset($map[$scale]) && $map[$scale] === []) {
      unset($map[$scale]);
    }
    return $map;
  }

  /**
   * Validates the skin's tokens as worn, errors re-rooted onto the gesture.
   *
   * The merge of the layers as the write would leave them runs through
   * StyleMapValidator::validateTokens(); an error at the written token's
   * own path re-roots onto the gesture key it concerns (its scale onto
   * "group", its name onto "label", its facts onto "value", "description"
   * and "offered"), and an error elsewhere in the map (a strict site's
   * self-consistency, another token's reference broken by a removal)
   * keeps its map path under "tokens".
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  protected function validateTokens(array $package, array $site, string $scale, string $name): void {
    $errors = $this->validator->validateTokens(SkinTokensItem::mergeLayers($package, $site));
    if ($errors === []) {
      return;
    }
    $own = "$scale.$name";
    $rerooted = [];
    foreach ($errors as $error) {
      $path = $error->getPath();
      if ($path === $scale) {
        $rerooted[] = $error->withPath('group');
      }
      elseif ($path === $own) {
        $rerooted[] = $error->withPath('label');
      }
      elseif (str_starts_with($path, "$own.")) {
        $rerooted[] = $error->withPath(substr($path, strlen($own) + 1));
      }
      else {
        $rerooted[] = $error->withPath("tokens.$path");
      }
    }
    throw new SkinMaterialValidationException($rerooted);
  }

  /**
   * Validates the skin's animations as worn, errors re-rooted onto the gesture.
   *
   * `validateTokens()`'s twin: the merge runs through
   * StyleMapValidator::validateAnimations() against the skin's OWN tokens
   * as worn (a skin's motion resolves var() against the skin it rides
   * with, the self-containment the disk lane keeps too); the written
   * animation's own path re-roots onto "label", its facts onto "steps"
   * (offsets and properties beneath), "description" and "offered", and
   * any other animation's error keeps its map path under "animations".
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  protected function validateAnimations(DaedalusStylishSkinInterface $skin, array $package, array $site, string $name): void {
    $errors = $this->validator->validateAnimations(SkinAnimationsItem::mergeLayers($package, $site), ['skin_tokens' => $skin->tokens()]);
    if ($errors === []) {
      return;
    }
    $rerooted = [];
    foreach ($errors as $error) {
      $path = $error->getPath();
      if ($path === $name) {
        $rerooted[] = $error->withPath('label');
      }
      elseif (str_starts_with($path, "$name.")) {
        $rerooted[] = $error->withPath(substr($path, strlen($name) + 1));
      }
      else {
        $rerooted[] = $error->withPath("animations.$path");
      }
    }
    throw new SkinMaterialValidationException($rerooted);
  }

  /**
   * Validates the skin's fonts as worn, errors re-rooted onto the gesture.
   *
   * `validateAnimations()`'s twin for the type section: the merge runs
   * through FontValidator::validateFonts() (the name grammar, the record
   * shape, the family and faces grammars, every face's file resolved, one
   * font per family); the written font's own path re-roots onto "label",
   * its facts onto "family", "faces" (faces.{i}.… beneath), "description"
   * and "offered", and any other font's error keeps its map path under
   * "fonts".
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinMaterialValidationException
   */
  protected function validateFonts(array $package, array $site, string $name): void {
    $errors = $this->fontValidator->validateFonts(SkinFontsItem::mergeLayers($package, $site));
    if ($errors === []) {
      return;
    }
    $rerooted = [];
    foreach ($errors as $error) {
      $path = $error->getPath();
      if ($path === $name) {
        $rerooted[] = $error->withPath('label');
      }
      elseif (str_starts_with($path, "$name.")) {
        $rerooted[] = $error->withPath(substr($path, strlen($name) + 1));
      }
      else {
        $rerooted[] = $error->withPath("fonts.$path");
      }
    }
    throw new SkinMaterialValidationException($rerooted);
  }

}
