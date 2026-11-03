<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's animations: name-keyed keyframes records in one JSON document.
 *
 * The motion section of the skin, the tokens section's sibling type
 * (SkinTokensItem): a skin is the site's look as data, and its motion is
 * part of that look, so a skin's animations swap with the skin exactly as
 * its tokens do. One level flatter than tokens (no scale: motion is one
 * namespace, the keyframes ident tail), the grammar is {name: {steps,
 * description, offered}} and the validation seam is
 * StyleMapValidator::validateAnimations(), which runs every record's
 * steps through the steps seam (validateAnimationSteps()) against the
 * skin's own tokens.
 *
 * A stored animation is a RECORD, {steps, description, offered}: an
 * animation is a material and its facts (its reasoning, whether the AI's
 * roster offers it) belong on the animation itself. The store admits
 * records only; the doors that take authored text (a hand-written skins
 * file, this setter) accept a BARE steps map as shorthand for {steps} and
 * canonicalize it (canonicalizeAnimations()). Steps are stored canonical
 * too (canonicalizeSteps()): `from` and `to` fold into `0%` and `100%`,
 * numbers normalize (`50.0%` is `50%`), and steps sort ascending, so the
 * printer, the roster record and the editor read one shape whatever
 * spelling the writer used.
 *
 * This type is a skin's PACKAGE layer of animations, the motion a module
 * ships and the one layer of a skin the site made itself. The site layer
 * over it is the sibling type SkinSiteAnimationsItem (partial records by
 * name), and the two meet in mergeLayers(), the read every consumer but
 * the disk lane takes.
 */
#[FieldType(
  id: 'daedalus_skin_animations',
  label: new TranslatableMarkup('Skin animations'),
  description: new TranslatableMarkup('Keyframes animation records carried by a skin, keyed by animation name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinAnimationsItem extends StyleMapItem {

  /**
   * The keys of an animation record, in stored order.
   *
   * - steps: the keyframe steps, canonical offsets ("0%", "50%", "100%")
   *   mapping CSS properties to values, at least one step.
   * - description: when to reach for the motion, the prose the AI and the
   *   skin tab read; '' when nobody wrote one.
   * - offered: whether the AI's roster advertises the animation; TRUE by
   *   default. Legality never reads it: motion-{name} resolves either way.
   */
  public const RECORD_KEYS = ['steps', 'description', 'offered'];

  /**
   * The shape of an animation name.
   *
   * Locked down hard because the name prints into the compiled skin file
   * as the keyframes ident tail (motion-{name}): lowercase letters,
   * digits, and inner hyphens or underscores.
   */
  public const NAME = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

  /**
   * {@inheritdoc}
   *
   * Canonicalizes on write: bare steps become records and offsets settle,
   * so the store never holds two shapes of one animation.
   */
  public function setStyleMap(array $map): void {
    parent::setStyleMap(static::canonicalizeLayer($map));
  }

  /**
   * Canonicalizes an authored map to this layer's stored shape.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeAnimations($map);
  }

  /**
   * Canonicalizes an authored animations map: every entry becomes a record.
   *
   * The one door grammar every authored surface shares (the skins disk
   * lane, the entity setter): each named animation passes through
   * canonicalizeAnimation(); a non-map entry passes through verbatim for
   * the validator to refuse (canonicalization never silently drops one).
   */
  public static function canonicalizeAnimations(array $animations): array {
    foreach ($animations as $name => $animation) {
      if (is_array($animation)) {
        $animations[$name] = static::canonicalizeAnimation($animation);
      }
    }
    return $animations;
  }

  /**
   * Canonicalizes one authored animation: bare steps become {steps}.
   *
   * A map carrying a 'steps' key IS a record (no offset is spelled
   * "steps"): its missing facts fill with their defaults, its keys settle
   * into stored order, and any key outside RECORD_KEYS rides along for
   * the validator to refuse. Anything else is a bare steps map and wraps.
   * Either way the steps pass through canonicalizeSteps() when they are a
   * map; a malformed steps value is left for the validator.
   */
  public static function canonicalizeAnimation(array $animation): array {
    if (array_key_exists('steps', $animation)) {
      return [
        'steps' => is_array($animation['steps']) ? static::canonicalizeSteps($animation['steps']) : $animation['steps'],
        'description' => $animation['description'] ?? '',
        'offered' => $animation['offered'] ?? TRUE,
      ] + $animation;
    }
    return ['steps' => static::canonicalizeSteps($animation), 'description' => '', 'offered' => TRUE];
  }

  /**
   * Whether a stored animation entry is a record.
   */
  public static function isRecord(mixed $animation): bool {
    return is_array($animation) && array_key_exists('steps', $animation);
  }

  /**
   * The canonical percentage spelling of a keyframe offset, or NULL.
   *
   * The one offset grammar, shared by the store (which keeps it) and the
   * validator (which refuses what it cannot canonicalize): `from` is `0%`,
   * `to` is `100%`, and `N%` with N in [0, 100] normalizes its number
   * (`050%` and `50.0%` are `50%`). Anything else (a bare number, a
   * percentage outside the range, a keyword, a comma list of selectors) is
   * not an offset; comma lists are refused on purpose, one offset per
   * step keeps the map a map.
   */
  public static function canonicalOffset(string $offset): ?string {
    $offset = strtolower(trim($offset));
    if ($offset === 'from') {
      return '0%';
    }
    if ($offset === 'to') {
      return '100%';
    }
    if (!preg_match('/^(\d+(?:\.\d+)?|\.\d+)%$/', $offset, $matches)) {
      return NULL;
    }
    $number = (float) $matches[1];
    if ($number > 100) {
      return NULL;
    }
    // A trimmed decimal rendering: no trailing zeros, no dangling point.
    $rendered = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    return ($rendered === '' ? '0' : $rendered) . '%';
  }

  /**
   * Canonicalizes a steps map: offsets folded and sorted ascending.
   *
   * Offsets that do not canonicalize are kept verbatim (the validator
   * refuses them; the store never silently drops a step), and two
   * spellings of one offset (`from` beside `0%`) collapse with the later
   * winning (the validator refuses that too, DUPLICATE_ANIMATION_OFFSET).
   */
  public static function canonicalizeSteps(array $steps): array {
    $canonical = [];
    foreach ($steps as $offset => $declarations) {
      $key = static::canonicalOffset((string) $offset) ?? (string) $offset;
      $canonical[$key] = $declarations;
    }
    uksort($canonical, static function (string $a, string $b): int {
      $a_offset = static::canonicalOffset($a);
      $b_offset = static::canonicalOffset($b);
      if ($a_offset === NULL || $b_offset === NULL) {
        // Uncanonical keys sort last, stably among themselves.
        return ($a_offset === NULL ? 1 : 0) <=> ($b_offset === NULL ? 1 : 0) ?: strcmp($a, $b);
      }
      return (float) $a_offset <=> (float) $b_offset;
    });
    return $canonical;
  }

  /**
   * Projects a stored animations map to its steps: {name: steps}.
   *
   * The read every steps-only consumer shares (the printer, usage scans
   * over step values): stored data is canonical by construction, so an
   * entry that is not a record is a corrupt store and fails loudly.
   *
   * @throws \UnexpectedValueException
   *   When an entry is not a record.
   */
  public static function steps(array $animations): array {
    $steps = [];
    foreach ($animations as $name => $animation) {
      if (!static::isRecord($animation)) {
        throw new \UnexpectedValueException(sprintf('The stored animation %s is not a record; a skin stores {steps, description, offered} per animation.', $name));
      }
      $steps[$name] = $animation['steps'];
    }
    return $steps;
  }

  /**
   * Projects an animations map to the steps it STATES: {name: steps}.
   *
   * The read that serves both layers: a site layer record may change one
   * fact of a package animation and state no steps at all, so such a
   * record contributes nothing here. An entry that is not a map at all is
   * a corrupt store.
   *
   * @throws \UnexpectedValueException
   *   When an entry is not a record.
   */
  public static function statedSteps(array $animations): array {
    $steps = [];
    foreach ($animations as $name => $animation) {
      if (!is_array($animation)) {
        throw new \UnexpectedValueException(sprintf('The stored animation %s is not a record; a skin stores {steps, description, offered} per animation.', $name));
      }
      if (array_key_exists('steps', $animation) && is_array($animation['steps'])) {
        $steps[$name] = $animation['steps'];
      }
    }
    return $steps;
  }

  /**
   * Merges a skin's site layer over its package layer: records per name.
   *
   * The one read of a skin's animations as worn, SkinTokensItem::
   * mergeLayers()'s rule one level flatter: a site record over a package
   * animation replaces the facts it states and keeps the rest; a site
   * record naming an animation the package lacks mints it, its unstated
   * facts at their defaults. Order is package order with the site's
   * additions after. Both inputs are stores, canonical by construction: a
   * site entry that is not a record, or a site-minted animation that
   * states no steps, is corrupt and fails loudly.
   *
   * @param array $package
   *   The package layer, full records (this type's store).
   * @param array $site
   *   The site layer, partial records (SkinSiteAnimationsItem's store).
   *
   * @return array
   *   {name: {steps, description, offered}}, full records.
   *
   * @throws \UnexpectedValueException
   *   On a corrupt layer.
   */
  public static function mergeLayers(array $package, array $site): array {
    $merged = $package;
    foreach ($site as $name => $record) {
      if (!is_array($record)) {
        throw new \UnexpectedValueException(sprintf('The site animation %s is not a record; the site layer stores the facts it changes per animation.', $name));
      }
      if (isset($merged[$name]) && static::isRecord($merged[$name])) {
        $merged[$name] = static::canonicalizeAnimation($record + $merged[$name]);
        continue;
      }
      if (!array_key_exists('steps', $record)) {
        throw new \UnexpectedValueException(sprintf('The site animation %s states no steps and the package mints no such animation; a site-minted animation carries its steps.', $name));
      }
      $merged[$name] = static::canonicalizeAnimation($record);
    }
    return $merged;
  }

}
