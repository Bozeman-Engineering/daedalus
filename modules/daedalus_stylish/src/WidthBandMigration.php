<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * The base-at-top width-band style migration: fold wide into base.
 *
 * The width vocabulary is narrow / medium / base now: "wide" is deleted
 * because the unconditioned base layer IS the full-width look, and the new
 * "medium" range condition (768-1023px) covers the band the base look no
 * longer owns. This service owns every STYLE-DATA rewrite that follows
 * (the config reshape is daedalus_stylish_post_update_base_at_top_width_bands()),
 * so the host sweep, the post_update hooks, and the drush command all
 * speak through one implementation.
 *
 * The fold rule, per entry, per compound lane: a "wide"-carrying condition
 * folds into the same compound WITHOUT its wide part ("wide" -> default,
 * "wide+dark" -> "dark"), the wide values winning per property - base
 * takes the full-width look. For each folded property, the value that used
 * to render at 768-1023px in that lane (the fold target's own value,
 * falling through to default) spills into the lane's medium compound
 * FIRST ("medium", "medium+dark"), so 768-1023 rendering is preserved.
 * The spill is captured from a pre-fold snapshot: "wide" folding into
 * default must never feed "wide+dark"'s spill its own folded value (the
 * same read-before-overwrite hazard ContainerMigration's two-pass loop
 * solves). A property the lane never valued below wide has nothing to
 * spill: the full-width look extends to every width, reported loudly. A
 * medium value already present is USER data (authored after the
 * vocabulary landed, beside still-inert wide entries) and is never
 * clobbered. Below 768px the narrow band keeps only its own explicit
 * values - the base-at-top posture accepts that a wide-overridden
 * property without a narrow value now shows the folded base look there.
 *
 * Stores: host style maps (via daedalus_layout's transform spine - walkers plus
 * the presave/load-time safety net, so stale tempstore sessions keep
 * converging), daedalus_variant styles across ALL revisions, daedalus_skin
 * tokens and defaults across ALL revisions (the container migration never
 * covered skins; skins CAN store width-conditioned tokens and defaults),
 * and stored stencils. Entity view displays hold layout structure only,
 * never style data, so there is no display walk. File-shipped variants,
 * skins, and stencils are module code and never migrate; none ship "wide"
 * data. Unmigrated "wide" entries are inert, never fatal (the emit gate
 * skips unknown conditions), so partially-migrated data degrades
 * gracefully.
 *
 * History rhyme, documented lossiness: ContainerMigration::CONDITION_MAP
 * collapsed the mobile AND tablet viewport stops into narrow, betting that
 * "adding a third stop later is cheap, removing one is a migration". This
 * refactor calls that bet from the other side - medium (768-1023) is
 * roughly the band the tablet stop covered, but the flattened tablet
 * intent is unrecoverable: styles that were tablet-authored migrated to
 * narrow and stay narrow.
 *
 * Every notable rewrite is reported: returned as lines to the caller AND
 * logged to the daedalus_stylish channel, so batched post_update runs stay loud in
 * watchdog while the drush command prints everything.
 */
class WidthBandMigration {

  /**
   * The retired width condition.
   */
  protected const WIDE = 'wide';

  /**
   * The width condition the old default band's values spill into.
   */
  protected const MEDIUM = 'medium';

  public function __construct(
    protected ConditionTypePluginManager $conditionTypes,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports one migration line: collected for the caller AND logged.
   *
   * @param string[] $lines
   *   The caller's collected report.
   * @param string $line
   *   The line.
   * @param bool $lossy
   *   TRUE logs a warning (rendering changes somewhere), FALSE a notice.
   */
  protected function report(array &$lines, string $line, bool $lossy = FALSE): void {
    $lines[] = $line;
    $logger = $this->loggerFactory->get('daedalus_stylish');
    $lossy ? $logger->warning($line) : $logger->notice($line);
  }

  /**
   * Folds one style entry's wide conditions into base, spilling to medium.
   *
   * @param array $entry
   *   The style entry (condition-keyed property blocks plus an optional
   *   variants list).
   * @param string $context
   *   Where the entry lives, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was rewritten.
   *
   * @return array
   *   The rewritten entry.
   */
  public function migrateEntryConditions(array $entry, string $context, array &$lines, bool &$changed): array {
    // Spill sources read PRE-fold values: "wide" folding into default must
    // not feed "wide+dark"'s default fallback its own folded value.
    $original = $entry;
    foreach (array_keys($original) as $condition) {
      if (in_array($condition, StyleMapValidator::RESERVED_ENTRY_KEYS, TRUE) || !is_array($original[$condition])) {
        continue;
      }
      $parts = ConditionTypePluginManager::compoundParts((string) $condition);
      if (!in_array(self::WIDE, $parts, TRUE)) {
        continue;
      }
      [$target, $spill_key] = $this->foldKeys($parts);

      $values = $entry[$condition];
      unset($entry[$condition]);
      $spilled = [];
      $extended = [];
      foreach ($values as $property => $value) {
        // What rendered at 768-1023px in this lane before the fold: the
        // fold target's own value, falling through to default.
        $source = $original[$target][$property]
          ?? ($target === 'default' ? NULL : ($original['default'][$property] ?? NULL));
        $entry[$target][$property] = $value;
        if ($source === NULL) {
          $extended[] = $property;
        }
        elseif ($source !== $value && !isset($entry[$spill_key][$property])) {
          $entry[$spill_key][$property] = $source;
          $spilled[] = $property;
        }
      }
      $changed = TRUE;
      $this->report($lines, sprintf('%s: folded the "%s" condition into "%s" (base is the full-width look now)%s.', $context, $condition, $target, $spilled === [] ? '' : sprintf('; spilled the old %s value(s) of %s into "%s" to preserve 768-1023px rendering', $target, implode(', ', $spilled), $spill_key)));
      if ($extended !== []) {
        $this->report($lines, sprintf('%s: %s had no value below the retired "%s" band; the full-width value(s) now apply at every width.', $context, implode(', ', $extended), $condition), TRUE);
      }
    }
    return $entry;
  }

  /**
   * Folds one token's condition-keyed value map, same rule as entries.
   *
   * Token values are one scalar per condition instead of a property block;
   * the lanes and the spill work identically.
   *
   * @param array $value
   *   The condition-keyed token value map.
   * @param string $context
   *   Where the token lives, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was rewritten.
   *
   * @return array
   *   The rewritten value map.
   */
  public function migrateTokenConditions(array $value, string $context, array &$lines, bool &$changed): array {
    $original = $value;
    foreach (array_keys($original) as $condition) {
      $parts = ConditionTypePluginManager::compoundParts((string) $condition);
      if (!in_array(self::WIDE, $parts, TRUE)) {
        continue;
      }
      [$target, $spill_key] = $this->foldKeys($parts);
      $wide_value = $value[$condition];
      unset($value[$condition]);
      $source = $original[$target]
        ?? ($target === 'default' ? NULL : ($original['default'] ?? NULL));
      $spill = $source !== NULL && $source !== $wide_value && !isset($value[$spill_key]);
      $value[$target] = $wide_value;
      if ($spill) {
        $value[$spill_key] = $source;
      }
      $changed = TRUE;
      $this->report($lines, sprintf('%s: folded the "%s" token value into "%s"%s.', $context, $condition, $target, $spill ? sprintf('; spilled the old %s value into "%s"', $target, $spill_key) : ''));
    }
    return $value;
  }

  /**
   * The fold target and medium spill key for a wide-carrying compound.
   *
   * @param string[] $parts
   *   The compound's parts, including "wide".
   *
   * @return string[]
   *   The fold target ("default" for plain "wide") and the spill key, both
   *   canonicalized to axis-declaration order.
   */
  protected function foldKeys(array $parts): array {
    $rest = array_values(array_diff($parts, [self::WIDE]));
    $target = $rest === []
      ? 'default'
      : $this->conditionTypes->canonicalizeCondition(implode(ConditionTypePluginManager::COMPOUND_DELIMITER, $rest));
    $spill_key = $this->conditionTypes->canonicalizeCondition(implode(ConditionTypePluginManager::COMPOUND_DELIMITER, [
      ...$rest,
      self::MEDIUM,
    ]));
    return [$target, $spill_key];
  }

  /**
   * Sweeps a whole style map, folding every entry's wide conditions.
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
  public function migrateStyleMap(array $map, string $context, array &$lines): ?array {
    $changed = FALSE;
    foreach ($map as $key => $entry) {
      if (is_array($entry)) {
        $map[$key] = $this->migrateEntryConditions($entry, "$context $key", $lines, $changed);
      }
    }
    return $changed ? $map : NULL;
  }

  /**
   * Folds every daedalus_variant's styles, across ALL revisions.
   *
   * Workspace-staged and draft revisions included, in place (a grammar
   * migration is not an editorial change). Shipped variants need no skip
   * list: none ship wide data, so an unmodified shipped copy is a no-op
   * and a modified copy folds like any user variant.
   *
   * @return string[]
   *   Report lines.
   */
  public function migrateVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $variant->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $context = sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id);
        $styles = $revision->getStyles();
        foreach ($styles as $relative => $entry) {
          if (is_array($entry)) {
            $styles[$relative] = $this->migrateEntryConditions($entry, "$context $relative", $lines, $changed);
          }
        }
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
   * Folds every daedalus_skin's tokens and defaults, across ALL revisions.
   *
   * The container migration never covered skins; this one must, because
   * skins can store width-conditioned token values AND width-conditioned
   * site default styles.
   *
   * @return string[]
   *   Report lines.
   */
  public function migrateSkins(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $skin) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $skin->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $context = sprintf('Skin "%s" (revision %s)', $skin->getMachineName(), $revision_id);

        $tokens = $revision->getTokens();
        foreach ($tokens as $scale => $names) {
          if (!is_array($names)) {
            continue;
          }
          foreach ($names as $name => $value) {
            if (is_array($value)) {
              $tokens[$scale][$name] = $this->migrateTokenConditions($value, "$context token $scale.$name", $lines, $changed);
            }
          }
        }
        if ($tokens !== $revision->getTokens()) {
          $revision->setTokens($tokens);
        }

        $defaults = $revision->getDefaults();
        foreach ($defaults as $scope => $relatives) {
          if (!is_array($relatives)) {
            continue;
          }
          foreach ($relatives as $relative => $entry) {
            if (is_array($entry)) {
              $defaults[$scope][$relative] = $this->migrateEntryConditions($entry, "$context defaults $scope $relative", $lines, $changed);
            }
          }
        }
        if ($defaults !== $revision->getDefaults()) {
          $revision->setDefaults($defaults);
        }

        if ($changed) {
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

  /**
   * Folds every stored stencil's style conditions.
   *
   * File-shipped stencils are module code and cannot be rewritten here;
   * one carrying the retired condition would fail loudly at instantiate
   * time with the width-vocabulary correctives (none ship wide data).
   *
   * @return string[]
   *   Report lines.
   */
  public function migrateStencils(): array {
    $lines = [];
    if (!$this->entityTypeManager->hasDefinition('stencil')) {
      return $lines;
    }
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $stencil) {
      /** @var \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil */
      $changed = FALSE;
      $element = $this->migrateStencilNode($stencil->getElement(), sprintf('Stencil "%s"', $stencil->getMachineName()), $lines, $changed);
      if ($changed) {
        $stencil->setElement($element);
        $stencil->save();
      }
    }
    return $lines;
  }

  /**
   * Recursively folds one stencil node's styles (section or block).
   */
  protected function migrateStencilNode(array $node, string $context, array &$lines, bool &$changed): array {
    if (!empty($node['regions']) && is_array($node['regions'])) {
      foreach ($node['regions'] as $region => $children) {
        if (!is_array($children)) {
          continue;
        }
        foreach ($children as $i => $child) {
          if (is_array($child)) {
            $node['regions'][$region][$i] = $this->migrateStencilNode($child, $context, $lines, $changed);
          }
        }
      }
    }
    if (!empty($node['styles']) && is_array($node['styles'])) {
      foreach ($node['styles'] as $relative => $entry) {
        if (is_array($entry)) {
          $node['styles'][$relative] = $this->migrateEntryConditions($entry, "$context styles $relative", $lines, $changed);
        }
      }
    }
    return $node;
  }

}
