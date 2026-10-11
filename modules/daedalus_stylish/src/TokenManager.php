<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\TokenValidationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The token gestures: mint, update, rename, revert and delete, one at a time.
 *
 * The swatch gesture manager's twin for the material kind that lives on
 * the skin: a token is a material (its record {value, description,
 * offered} is a fact of the skin, never of a guide file), so it is minted,
 * changed and removed by name through the material gesture family
 * (DaedalusStylishMaterialsProvider dispatches save_material /
 * update_material / remove_material with kind token here), never by
 * rewriting the skin's whole tokens map. Every gesture addresses the
 * ACTIVE skin: token ids are not skin-qualified ("{scale}/{name}" names
 * the active skin's token, the one the roster carries).
 *
 * WHICH LAYER a write lands in is the skin manager's one answer
 * (SkinManager::writesSiteLayer(), shared with the site defaults op):
 * - the SITE layer (site_tokens) when the skin is a package's (it has a
 *   provider) and the distro lock holds, the consuming site's posture: a
 *   mint adds a site-minted token, an update stores only the facts it
 *   changes over the package's record (the rest show through, so a
 *   package update to an unstated fact still lands), and a removal takes
 *   the site's record away, revealing the package's token whole or
 *   deleting a site-minted one; a package token the site never touched
 *   cannot be removed on a consuming site (the disk is its one author);
 * - the skin's ONE layer (the package layer, tokens) when the skin is the
 *   site's own (no provider) or the developer gate lifts the lock
 *   (DistroLock, the authoring site that ships the result back to disk):
 *   the write edits the record in place, and a removal deletes the token
 *   from both layers.
 *
 * Every write validates the skin's tokens AS WORN (the merge of both
 * layers) through the token seam (StyleMapValidator::validateTokens()),
 * the one gate the disk lane and the blueprint field handler converge on,
 * so a token minted on the canvas meets exactly the checks a shipped one
 * did; on strict sites that includes the map's self-consistency, so a
 * removal another token's var() reaches refuses. Errors re-root from the
 * map's path space onto the gesture's keys (group, label, value,
 * description, offered).
 *
 * A write is an ordinary revisioned save of the skin
 * (SkinManager::saveRevision(): a bad edit is one restore away,
 * workspace-staged inside a workspace), and it never reads
 * as a modification of what the package shipped: the site layer is
 * outside the shipped payload. The skin's entity hook records the token
 * ids on the request's MaterialsChangeSet, which is how the write reaches
 * every client.
 *
 * The usage lane is the swatch lane's twin: a token delivers as a custom
 * property (variable()), the usage table (TokenUsage) counts the sources
 * whose saved values reference it, and a REFERENCED token is never
 * deleted from under them: its removal stages the rewrite-then-delete
 * batch (TokenDeleteBatch) behind the "delete tokens in use" grade, and
 * its rename (the name IS the custom property, so a rename respells
 * every reference) stages the rewrite-then-rename batch. A package token
 * cannot be deleted or renamed on a consuming site: the disk is its one
 * author of both facts. Revert converges a token toward the disk (the
 * site's record over it dropped; under the developer gate the package
 * record rewritten from the file).
 */
class TokenManager {

  use StringTranslationTrait;

  public function __construct(
    protected StyleMapValidator $validator,
    protected SkinManager $skinManager,
    protected AccountProxyInterface $currentUser,
    protected ConditionTypePluginManager $conditionTypes,
    protected StyleGuide $styleGuide,
    protected TokenUsage $usage,
    protected SkinImporter $importer,
  ) {}

  /**
   * The active skin, the one every token gesture addresses.
   *
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   *   When no skin is active: tokens are the active skin's materials, so
   *   there is nothing to mint on.
   */
  public function activeSkin(): DaedalusStylishSkinInterface {
    return $this->skinManager->loadActiveSkin() ?? throw new TokenValidationException([
      new BlueprintError('', 'STYLE_SKIN_NONE_ACTIVE', 'No skin is active: a token is a fact of the active skin.', [], 'Activate a design system that carries a skin, or create a skin and make it the active system\'s default_skin, then mint tokens on it.'),
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
   * Whether a skin mints a token (either layer) under a scale and name.
   */
  public function has(DaedalusStylishSkinInterface $skin, string $scale, string $name): bool {
    return isset($skin->tokens()[$scale][$name]);
  }

  /**
   * Whether a skin's writes land in its site layer: the skin manager's rule.
   *
   * Public as the package layer's lock at token grain (the usage route's
   * `locked`): TRUE on a consuming site, where a token's identity (its
   * name, its existence) belongs to the package and only its value,
   * description and offered flag take a site record; FALSE on the site's
   * own skin and under the developer gate.
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
   * How many source entities reference a token in their saved values.
   *
   * The usage table's count (see TokenUsage): host style maps, variant
   * styles, and skin tokens/defaults, distinct per source entity.
   */
  public function usages(string $scale, string $name): int {
    return $this->usage->countReferencesTo($this->variable($scale, $name));
  }

  /**
   * Which layer(s) of a skin hold a token's record.
   *
   * @return string
   *   'package' (the package's record alone), 'site' (site-minted) or
   *   'both' (the site's record over the package's).
   */
  public function layer(DaedalusStylishSkinInterface $skin, string $scale, string $name): string {
    $package = isset($skin->packageTokens()[$scale][$name]);
    $site = isset($skin->siteTokens()[$scale][$name]);
    return $package && $site ? 'both' : ($site ? 'site' : 'package');
  }

  /**
   * A token's shipped state against the CURRENT disk payload.
   *
   * The swatch vocabulary at token grain: NULL when no disk file ships
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
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  public function mint(DaedalusStylishSkinInterface $skin, string $scale, string $name, string|array $value, string $description = '', bool $offered = TRUE): DaedalusStylishSkinInterface {
    if ($this->has($skin, $scale, $name)) {
      throw new TokenValidationException([
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
      $this->validate($skin->packageTokens(), $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = $skin->packageTokens();
      $package[$scale][$name] = $record;
      $this->validate($package, $skin->siteTokens(), $scale, $name);
      $skin->setPackageTokens($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('Minted the %s/%s token.', $scale, $name));
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
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  public function update(DaedalusStylishSkinInterface $skin, string $scale, string $name, string|array|null $value = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishSkinInterface {
    if ($value === NULL && $description === NULL && $offered === NULL) {
      throw new TokenValidationException([
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
      $this->validate($skin->packageTokens(), $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = $skin->packageTokens();
      $package[$scale][$name] = $facts + $package[$scale][$name];
      $this->validate($package, $skin->siteTokens(), $scale, $name);
      $skin->setPackageTokens($package);
    }
    $this->skinManager->saveRevision($skin, sprintf('%s/%s: %s', $scale, $name, implode(' ', $log)));
    return $skin;
  }

  /**
   * Renames a token: the record moves, every reference follows.
   *
   * A token's name is its custom property's tail, so a rename respells
   * every var() of it (the swatch rename touches a label only; a token
   * has no label apart from its name). Unreferenced, the record moves in
   * this request, in place (row order is stored order). REFERENCED, the
   * rewrite-then-rename Batch API job is staged for the caller to drive
   * (TokenDeleteBatch::renameToken() is its tail); on the op spine
   * nothing drives a core Batch, so a referenced token refuses there with
   * its count. A package token cannot be renamed on a consuming site,
   * site override or not: the disk is the one author of its name. The
   * new name meets the token seam exactly as a mint's does (its grammar,
   * and on strict sites the map's self-consistency), errors re-rooted
   * onto "label".
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
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  public function rename(DaedalusStylishSkinInterface $skin, string $scale, string $name, string $new_name, bool $stage_batch = TRUE): int {
    $new_name = trim($new_name);
    if ($new_name === '' || $new_name === $name) {
      throw new TokenValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', $new_name === '' ? 'A token needs a name.' : sprintf('%s/%s already bears that name.', $scale, $name), [], 'Pass "label" as the new token name, the tail of its custom property ("4", "2xl", "accent-hover").'),
      ]);
    }
    $package = $skin->packageTokens();
    $site = $skin->siteTokens();
    if ($this->writesSiteLayer($skin) && isset($package[$scale][$name])) {
      throw new TokenValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_SHIPPED_LOCKED', sprintf('%s/%s is shipped by the "%s" module; a shipped token cannot be renamed on this site.', $scale, $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Mint a token under the name you want and reference that instead, or rename it in the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    if ($this->has($skin, $scale, $new_name)) {
      throw new TokenValidationException([
        new BlueprintError('label', 'STYLE_TOKEN_EXISTS', sprintf('The "%s" skin already mints %s/%s.', $skin->getMachineName(), $scale, $new_name), ['id' => "$scale/$new_name"], 'Pick another name.'),
      ]);
    }
    $package = self::renamed($package, $scale, $name, $new_name);
    $site = self::renamed($site, $scale, $name, $new_name);
    $this->validate($package, $site, $scale, $new_name);
    $references = $this->usage->referencesTo($this->variable($scale, $name));
    if ($references !== []) {
      if (!$stage_batch) {
        throw new TokenValidationException([
          new BlueprintError('id', 'STYLE_TOKEN_IN_USE', sprintf('%s/%s is referenced by %d place(s); it cannot be renamed from here.', $scale, $name, count($references)), ['usages' => count($references)], 'Rename it from the palette, which respells every reference before the record moves.'),
        ]);
      }
      $token = new SkinToken($skin, $scale, $name, $skin->tokens()[$scale][$name], $this->variable($scale, $name), $new_name);
      $batch = (new BatchBuilder())
        ->setTitle($this->t('Renaming the %id token', ['%id' => $token->id()]))
        ->setInitMessage($this->t('Respelling %id references in @count places…', [
          '%id' => $token->id(),
          '@count' => count($references),
        ]))
        ->setFinishCallback([TokenDeleteBatch::class, 'renameFinished']);
      foreach ($references as $reference) {
        $batch->addOperation([
          TokenDeleteBatch::class,
          'rewriteSource',
        ], [$token->batchId(), $reference['source'], $reference['entity_type'], $reference['entity_id']]);
      }
      $batch->addOperation([TokenDeleteBatch::class, 'renameToken'], [$token->batchId()]);
      batch_set($batch->toArray());
      return count($references);
    }
    $skin->setPackageTokens($package);
    $skin->setSiteTokens($site);
    $this->skinManager->saveRevision($skin, sprintf('Renamed %s/%s to %s/%s.', $scale, $name, $scale, $new_name));
    return 0;
  }

  /**
   * Reverts a token to what its skin's provider ships right now.
   *
   * The swatch revert at token grain, converging toward the disk: the
   * site's record over the token is dropped (the package's shows through
   * whole, and on a consuming site the package layer IS the disk's, sync
   * being its one writer), and under the developer gate the package
   * record is rewritten from the file too. An ordinary revisioned save,
   * one restore away from undo. A token no disk file ships refuses: the
   * site's own skin and a site-minted token have no shipped defaults, and
   * a provider that stopped shipping the name leaves it ordinary content.
   *
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  public function revert(DaedalusStylishSkinInterface $skin, string $scale, string $name): DaedalusStylishSkinInterface {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      throw new TokenValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_NOT_SHIPPED', sprintf('%s/%s is a token of a skin this site made; it has no shipped defaults.', $scale, $name), [], 'Only tokens of module-shipped skins can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->shippedRecord($skin, $scale, $name);
    if ($shipped === NULL) {
      throw new TokenValidationException([
        new BlueprintError('id', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module ships no %s/%s token.', $provider, $scale, $name), [], 'The token is site content now; edit or delete it instead.'),
      ]);
    }
    $package = $skin->packageTokens();
    $site = self::without($skin->siteTokens(), $scale, $name);
    if (!$this->writesSiteLayer($skin)) {
      $package[$scale][$name] = $shipped;
    }
    if ($package === $skin->packageTokens() && $site === $skin->siteTokens()) {
      return $skin;
    }
    $this->validate($package, $site, $scale, $name);
    $skin->setPackageTokens($package);
    $skin->setSiteTokens($site);
    $this->skinManager->saveRevision($skin, sprintf('Reverted %s/%s to the shipped token of the %s module.', $scale, $name, $provider));
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
   * swatch posture exactly: the route admits both, and the CONTEXTUAL
   * gate lives here, "delete tokens in use" for a referenced token. The
   * work list is the usage table's, each source's rewrite an ordinary
   * revisioned save (TokenDeleteBatch), then the removal. A referenced
   * token is NOT removed in this request: the Batch API job is staged
   * and the caller drives it; on the op spine nothing drives a core
   * Batch, so a referenced token refuses there with its count.
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
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  public function deleteToken(DaedalusStylishSkinInterface $skin, string $scale, string $name, bool $stage_batch = TRUE): int {
    $package = $skin->packageTokens();
    $site = $skin->siteTokens();
    $site_write = $this->writesSiteLayer($skin);
    if ($site_write && !isset($site[$scale][$name])) {
      throw new TokenValidationException([
        new BlueprintError('id', 'STYLE_TOKEN_SHIPPED_LOCKED', sprintf('%s/%s is shipped by the "%s" module; a shipped token cannot be removed on this site.', $scale, $name, (string) $skin->getProvider()), ['provider' => (string) $skin->getProvider()], 'Withhold it from rows instead (update_material with "offered": false), or remove it from the module\'s skins file. On the site authoring the package, $settings[\'daedalus_blueprint_developer\'] = TRUE lifts the lock.'),
      ]);
    }
    $remains = $site_write && isset($package[$scale][$name]);
    if (!$remains) {
      $references = $this->usage->referencesTo($this->variable($scale, $name));
      if ($references !== []) {
        if (!$this->currentUser->hasPermission('delete tokens in use') && !$this->currentUser->hasPermission('administer skins')) {
          throw new TokenValidationException([
            new BlueprintError('id', 'STYLE_TOKEN_IN_USE', sprintf('%s/%s is referenced by %d place(s); deleting it rewrites each reference to the literal value first, which needs the "delete tokens in use" permission.', $scale, $name, count($references)), ['usages' => count($references)], 'Ask an administrator, or remove the references first.'),
          ]);
        }
        if (!$stage_batch) {
          throw new TokenValidationException([
            new BlueprintError('id', 'STYLE_TOKEN_IN_USE', sprintf('%s/%s is referenced by %d place(s); it cannot be removed from here.', $scale, $name, count($references)), ['usages' => count($references)], 'Remove the references first (restyle those elements with another value), or delete it from the palette, which rewrites every reference to the literal value before deleting.'),
          ]);
        }
        $this->stageDelete($skin, $scale, $name, $references);
        return count($references);
      }
    }
    if ($site_write) {
      $site = self::without($site, $scale, $name);
      $this->validate($package, $site, $scale, $name);
      $skin->setSiteTokens($site);
    }
    else {
      $package = self::without($package, $scale, $name);
      $site = self::without($site, $scale, $name);
      $this->validate($package, $site, $scale, $name);
      $skin->setPackageTokens($package);
      $skin->setSiteTokens($site);
    }
    $this->skinManager->saveRevision($skin, sprintf($remains ? 'Reverted %s/%s to the shipped token.' : 'Deleted the %s/%s token.', $scale, $name));
    return 0;
  }

  /**
   * Stages the rewrite-then-delete batch for a referenced token.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin.
   * @param string $scale
   *   The token's scale.
   * @param string $name
   *   The token's name.
   * @param array[] $references
   *   The usage table's work list for the token.
   */
  protected function stageDelete(DaedalusStylishSkinInterface $skin, string $scale, string $name, array $references): void {
    $token = new SkinToken($skin, $scale, $name, $skin->tokens()[$scale][$name], $this->variable($scale, $name));
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the %id token', ['%id' => $token->id()]))
      ->setInitMessage($this->t('Rewriting %id references in @count places…', [
        '%id' => $token->id(),
        '@count' => count($references),
      ]))
      ->setFinishCallback([TokenDeleteBatch::class, 'finished']);
    foreach ($references as $reference) {
      $batch->addOperation([
        TokenDeleteBatch::class,
        'rewriteSource',
      ], [$token->batchId(), $reference['source'], $reference['entity_type'], $reference['entity_id']]);
    }
    $batch->addOperation([TokenDeleteBatch::class, 'deleteToken'], [$token->batchId()]);
    batch_set($batch->toArray());
  }

  /**
   * A tokens map with one token's key renamed in place, when it holds one.
   */
  protected static function renamed(array $tokens, string $scale, string $name, string $new_name): array {
    if (!isset($tokens[$scale][$name])) {
      return $tokens;
    }
    $names = [];
    foreach ($tokens[$scale] as $key => $record) {
      $names[(string) $key === $name ? $new_name : $key] = $record;
    }
    $tokens[$scale] = $names;
    return $tokens;
  }

  /**
   * A tokens map minus one token, its scale dropped when it empties.
   */
  protected static function without(array $tokens, string $scale, string $name): array {
    unset($tokens[$scale][$name]);
    if (isset($tokens[$scale]) && $tokens[$scale] === []) {
      unset($tokens[$scale]);
    }
    return $tokens;
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
   * @throws \Drupal\daedalus_stylish\Exception\TokenValidationException
   */
  protected function validate(array $package, array $site, string $scale, string $name): void {
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
    throw new TokenValidationException($rerooted);
  }

}
