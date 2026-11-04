<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * Converges every stored position condition into a position qualifier.
 *
 * Position is structure: where an element sits among its siblings is
 * selector text (a position qualifier of the compound it refines), never
 * a condition the editor could pose. The fact era keyed it as a condition
 * ("first-child", "not-last-child", "odd", …) beside the posable states;
 * every store carrying such a condition is rewritten here into the
 * qualified key that says the same thing: an entry's "{c}" block at
 * "{key}" moves to "{key}::&{q}" (the owner's own position) or to
 * "{key}::{selector}{q}" (the qualifier appended to the last compound,
 * where the fact's wrapper printed), keeping the posable remainder of a
 * compound condition as the block's condition ("hover+first-child" at
 * "{key}" is "hover" at "{key}::&:first-child"). When the qualified key
 * already exists the two entries were one effective CSS rule from two
 * sources and merge; the later entry in map order wins a property both
 * set, as the cascade did, and the merged entry sits where the later
 * stood. Stores: host style maps (the FactConditionSweep, riding
 * daedalus_layout's transform spine after the relative selector sweep so
 * every key is canonical before it is qualified), daedalus_variant styles
 * (all revisions, composition slot paths and the entries carrying nested
 * references included), daedalus_skin defaults. Idempotent: a map without
 * a fact condition rewrites to itself and reports nothing.
 *
 * Shipped-unmodified variants and skins are SKIPPED: the sync lane owns
 * them (their disk sources speak the qualifier grammar; none ever shipped
 * a fact condition), and an in-place rewrite would flip their hash to
 * "modified" and cut them off from future shipped updates.
 * RelativeSelectorConvergence is the model.
 */
class FactConditionConvergence implements StyleStoreConvergenceInterface {

  /**
   * The ten retiring condition names and the qualifier each one means.
   *
   * Frozen history, kept as a table on purpose: the Structural condition
   * plugin that published these names is deleted, and this is the ONE
   * place the retired vocabulary is still written down. The spelling is
   * the wrapper the fact printed, which the relative selector grammar
   * reads as a position qualifier.
   */
  public const QUALIFIERS = [
    'first-child' => ':first-child',
    'last-child' => ':last-child',
    'only-child' => ':only-child',
    'empty' => ':empty',
    'odd' => ':nth-child(odd)',
    'even' => ':nth-child(even)',
    'not-first-child' => ':not(:first-child)',
    'not-last-child' => ':not(:last-child)',
    'not-only-child' => ':not(:only-child)',
    'not-empty' => ':not(:empty)',
  ];

  public function __construct(
    protected ConditionTypePluginManager $conditionTypes,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports one convergence line: collected for the caller AND logged.
   */
  protected function report(array &$lines, string $line): void {
    $lines[] = $line;
    $this->loggerFactory->get('daedalus_stylish')->notice($line);
  }

  /**
   * Splits a condition into its retiring facts and its posable remainder.
   *
   * @return array{0: string[], 1: string}
   *   The fact names in spelled order (empty when the condition carries
   *   none) and the remainder condition: "default" when nothing posable
   *   is left, else the remaining parts in canonical order.
   */
  protected function splitCondition(string $condition): array {
    $facts = [];
    $rest = [];
    foreach (ConditionTypePluginManager::compoundParts($condition) as $part) {
      if (isset(self::QUALIFIERS[$part])) {
        $facts[] = $part;
      }
      else {
        $rest[] = $part;
      }
    }
    $remainder = $rest === []
      ? 'default'
      : $this->conditionTypes->canonicalizeCondition(implode(ConditionTypePluginManager::COMPOUND_DELIMITER, $rest));
    return [$facts, $remainder];
  }

  /**
   * The key a fact's block moves to: the key with the qualifier appended.
   *
   * The root (everything before the first "::") never changes. A key with
   * no selector gains the qualified anchor ("{key}::&{q}"); a selector's
   * last compound takes the qualifier before the terminal pseudo-element,
   * and the owner's own box ("&::before") hangs on the qualified anchor.
   * The result re-prints through the grammar, so the qualifier sorts into
   * the compound's own. NULL when the key's selector is outside the
   * grammar: a sweep never invents a meaning, and the validator refuses
   * such a key loudly.
   *
   * @param string $key
   *   A style map key, an element-relative key or a slot key.
   * @param string $qualifier
   *   The qualifier text (one or more position pseudo-classes).
   *
   * @return string|null
   *   The qualified key, or NULL when the key cannot be qualified.
   */
  public static function qualifiedKey(string $key, string $qualifier): ?string {
    $pos = strpos($key, RelativeSelector::SUFFIX);
    if ($pos === FALSE) {
      $qualified = RelativeSelector::parse(RelativeSelector::ANCHOR . $qualifier);
      return $qualified === NULL ? NULL : $key . RelativeSelector::SUFFIX . $qualified;
    }
    $root = substr($key, 0, $pos);
    $selector = RelativeSelector::parse(substr($key, $pos + strlen(RelativeSelector::SUFFIX)));
    if ($selector === NULL) {
      return NULL;
    }
    $chain = $selector->compounds === [] ? RelativeSelector::ANCHOR : RelativeSelector::printCompounds($selector->compounds);
    $pseudo = $selector->pseudo === NULL ? '' : RelativeSelector::SUFFIX . $selector->pseudo;
    $qualified = RelativeSelector::parse($chain . $qualifier . $pseudo);
    return $qualified === NULL ? NULL : $root . RelativeSelector::SUFFIX . $qualified;
  }

  /**
   * Converges a whole host style map.
   *
   * @param array $map
   *   The style map.
   * @param string $context
   *   The map's host, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing changed.
   */
  public function convergeStyleMap(array $map, string $context, array &$lines): ?array {
    $changed = FALSE;
    $result = $this->convergeEntries($map, $context, $lines, $changed);
    return $changed ? $result : NULL;
  }

  /**
   * Converges one keyed entry map (a host map, or element-relative styles).
   *
   * Entries are walked in map order. A fact block leaves its entry and
   * lands on the qualified key right after the entry it came from (the
   * refinement follows its rule); an entry left with nothing is dropped.
   * When the qualified key is already in the result, or turns up later in
   * the map, the two merge with the later contributor winning a property
   * both set, and the merged entry takes the later contributor's place.
   *
   * @param array $entries
   *   The keyed entries.
   * @param string $context
   *   Where the entries live, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was rewritten.
   *
   * @return array
   *   The rewritten entries.
   */
  protected function convergeEntries(array $entries, string $context, array &$lines, bool &$changed): array {
    $result = [];
    foreach ($entries as $key => $entry) {
      $key = (string) $key;
      if (!is_array($entry)) {
        $result[$key] = $entry;
        continue;
      }
      $moved = [];
      foreach ($entry as $condition => $values) {
        $condition = (string) $condition;
        if (in_array($condition, StyleMapValidator::RESERVED_ENTRY_KEYS, TRUE) || !is_array($values)) {
          continue;
        }
        [$facts, $remainder] = $this->splitCondition($condition);
        if ($facts === []) {
          continue;
        }
        $qualifier = implode('', array_map(static fn (string $fact): string => self::QUALIFIERS[$fact], $facts));
        $target = self::qualifiedKey($key, $qualifier);
        if ($target === NULL) {
          $this->report($lines, sprintf('%s: left the "%s" block of "%s" in place; the key is outside the relative selector grammar, so it cannot take the "%s" qualifier (the validator refuses the key).', $context, $condition, $key, $qualifier));
          continue;
        }
        unset($entry[$condition]);
        $moved[$target][$remainder] = isset($moved[$target][$remainder])
          ? array_merge($moved[$target][$remainder], $values)
          : $values;
        $changed = TRUE;
        $this->report($lines, sprintf('%s: moved the "%s" block of "%s" to "%s" under "%s" (position is a qualifier, not a condition).', $context, $condition, $key, $target, $remainder));
      }
      if ($entry !== []) {
        $result = $this->land($result, $key, $entry, $context, $lines);
      }
      else {
        $changed = TRUE;
        $this->report($lines, sprintf('%s: dropped the emptied "%s" entry.', $context, $key));
      }
      foreach ($moved as $target => $blocks) {
        $result = $this->land($result, $target, $blocks, $context, $lines);
      }
    }
    return $result;
  }

  /**
   * Lands one entry at the end of the result, merging onto a prior one.
   *
   * The entry landing now is the later in map order: it wins a property
   * both set, and the merged entry takes its place.
   */
  protected function land(array $result, string $key, array $entry, string $context, array &$lines): array {
    if (isset($result[$key]) && is_array($result[$key])) {
      $prior = $result[$key];
      unset($result[$key]);
      $overlap = [];
      foreach ($entry as $condition => $values) {
        if (is_array($values) && !array_is_list($values) && is_array($prior[$condition] ?? NULL)) {
          foreach (array_intersect_key($values, $prior[$condition]) as $property => $value) {
            if ($prior[$condition][$property] !== $value) {
              $overlap[] = "$condition $property";
            }
          }
        }
      }
      $entry = StyleEntry::merge($entry, $prior);
      $this->report($lines, sprintf('%s: merged two entries at "%s" (one effective rule from two sources)%s.', $context, $key, $overlap === [] ? '' : sprintf('; the later entry in map order won %s', implode(', ', $overlap))));
    }
    $result[$key] = $entry;
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function convergeVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    assert($storage instanceof RevisionableStorageInterface);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      if ($variant->isShipped() && !$variant->isModified()) {
        continue;
      }
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $variant->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $styles = $this->convergeEntries($revision->getStyles(), sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id), $lines, $changed);
        if ($changed) {
          $revision->setStyles($styles);
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function convergeSkins(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    assert($storage instanceof RevisionableStorageInterface);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $skin) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
      // A shipped unmodified package layer is left to the sync lane; the
      // site layer over it is the site's and is converged either way.
      $package_swept = !($skin->isShipped() && !$skin->isModified());
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $skin->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $layers = ['site' => [$revision->siteDefaults(), $revision->setSiteDefaults(...)]];
        if ($package_swept) {
          $layers['package'] = [$revision->packageDefaults(), $revision->setPackageDefaults(...)];
        }
        foreach ($layers as $layer => [$defaults, $write]) {
          $layer_changed = FALSE;
          foreach ($defaults as $scope => $relatives) {
            if (is_array($relatives)) {
              $defaults[$scope] = $this->convergeEntries($relatives, sprintf('Skin "%s" (revision %s) %s defaults %s', $skin->getMachineName(), $revision_id, $layer, $scope), $lines, $layer_changed);
            }
          }
          if ($layer_changed) {
            $write($defaults);
            $changed = TRUE;
          }
        }
        if ($changed) {
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

}
