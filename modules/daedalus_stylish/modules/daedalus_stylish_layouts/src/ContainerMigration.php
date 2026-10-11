<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_stylish\ConditionTypePluginManager;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;

/**
 * The container-model data migration: one grammar, every store.
 *
 * The four multi-region layouts (daedalus_stylish_onecol..fourcol) retire in favor
 * of the one container layout, and the style grammar renamed section: keys
 * to container: keys and the width-axis vocabulary to narrow/wide (a
 * vocabulary WidthBandMigration has since folded to narrow/medium). This
 * service owns every data rewrite that follows from that, so the section
 * transform, the host sweep, the post_update hooks, and the drush command
 * all speak through one implementation:
 * - Style maps: section:{uuid} -> container:{uuid}; region:{uuid}:{name}
 *   entries move to the minted child container's key (the section
 *   transform's mapping) or are dropped loudly when no element exists for
 *   them anymore; mobile/tablet conditions -> narrow (compound-aware,
 *   loud about the lossy tablet stop); layout-exact shipped variant
 *   references re-point to the generic kind:container families.
 * - Variant entities: kind:section and layout:daedalus_stylish_* scopes ->
 *   kind:container; slot-path style keys lose their region()/section()
 *   segments; conditions rewrite like maps. Unmodified shipped
 *   daedalus_stylish_layouts variants are left alone: references re-point away from
 *   them and they retire with the four layouts.
 * - Proportions retirement (mode + share): worn split_* references and
 *   stepper-written explicit track overrides convert to the one `columns`
 *   arrangement reference plus per-child flex-grow overrides; track styles
 *   outside the stepper's own pattern stay Custom, loudly. Applies to
 *   style maps (migrateArrangement()).
 * - Axis rename: stored variants on the retired arrangement axis move to
 *   container_layout, and the shipped row variant relabels "Columns"
 *   (migrateContainerLayoutAxis()); style maps store axis-less references,
 *   so no map rewrite exists.
 *
 * Every lossy or notable rewrite is reported: returned as lines to the
 * caller AND logged to the daedalus_stylish_layouts channel, so batched post_update
 * runs stay loud in watchdog while the drush command prints everything.
 */
class ContainerMigration {

  /**
   * The retiring layouts and their region order.
   *
   * Hardcoded rather than read from the layout plugin manager: the
   * transform stays registered after the four layout plugins are deleted
   * (stale tempstore sessions keep converging), and a retired layout's
   * shape is frozen history, not live configuration.
   */
  public const LAYOUT_REGIONS = [
    'daedalus_stylish_onecol' => ['first'],
    'daedalus_stylish_twocol' => ['first', 'second'],
    'daedalus_stylish_threecol' => ['first', 'second', 'third'],
    'daedalus_stylish_fourcol' => ['first', 'second', 'third', 'fourth'],
  ];

  /**
   * The container layout every section converges on.
   */
  public const TARGET_LAYOUT = 'daedalus_stylish_container';

  /**
   * Layout-exact shipped variant names and their generic replacements.
   *
   * The fourcol_pairs maps to the plain four-way split: its paired-on-tablet
   * intermediate stop has no narrow/wide equivalent (the accepted tablet
   * lossiness), which the re-point reports loudly.
   */
  public const VARIANT_MAP = [
    'onecol_sm' => 'container_sm',
    'onecol_md' => 'container_md',
    'onecol_lg' => 'container_lg',
    'onecol_xl' => 'container_xl',
    'onecol_2xl' => 'container_2xl',
    'onecol_full' => 'container_full',
    'twocol_sm' => 'container_sm',
    'twocol_md' => 'container_md',
    'twocol_lg' => 'container_lg',
    'twocol_xl' => 'container_xl',
    'twocol_2xl' => 'container_2xl',
    'twocol_full' => 'container_full',
    'threecol_sm' => 'container_sm',
    'threecol_md' => 'container_md',
    'threecol_lg' => 'container_lg',
    'threecol_xl' => 'container_xl',
    'threecol_2xl' => 'container_2xl',
    'threecol_full' => 'container_full',
    'fourcol_sm' => 'container_sm',
    'fourcol_md' => 'container_md',
    'fourcol_lg' => 'container_lg',
    'fourcol_xl' => 'container_xl',
    'fourcol_2xl' => 'container_2xl',
    'fourcol_full' => 'container_full',
    'twocol_50_50' => 'split_50_50',
    'twocol_33_67' => 'split_33_67',
    'twocol_67_33' => 'split_67_33',
    'twocol_25_75' => 'split_25_75',
    'twocol_75_25' => 'split_75_25',
    'threecol_33_34_33' => 'split_33_34_33',
    'threecol_25_50_25' => 'split_25_50_25',
    'threecol_25_25_50' => 'split_25_25_50',
    'threecol_50_25_25' => 'split_50_25_25',
    'fourcol_equal' => 'split_25_25_25_25',
    'fourcol_pairs' => 'split_25_25_25_25',
  ];

  /**
   * The retired split_* proportions families and their child grow ratios.
   *
   * Mode + share retires the proportions axis: the ratio fact moves off the
   * parent's track string onto the children (flex-grow overrides under the
   * one `columns` arrangement variant). These ratios are FROZEN history - the
   * exact fr weights the shipped variants carried - so worn references
   * convert identically after the variant entities are deleted.
   */
  public const SPLIT_GROWS = [
    'split_50_50' => [1, 1],
    'split_33_67' => [1, 2],
    'split_67_33' => [2, 1],
    'split_25_75' => [1, 3],
    'split_75_25' => [3, 1],
    'split_33_34_33' => [1, 1, 1],
    'split_25_50_25' => [1, 2, 1],
    'split_25_25_50' => [1, 1, 2],
    'split_50_25_25' => [2, 1, 1],
    'split_25_25_25_25' => [1, 1, 1, 1],
  ];

  /**
   * The columns arrangement variant worn split references convert to.
   *
   * Machine name `columns` (label "Columns"); it was `row` until
   * daedalus_stylish_layouts_post_update_rename_row_to_columns() repointed
   * every stored wearing, so every historical one-shot and the permanent
   * RowArrangementSweep converge onto the current name.
   */
  public const COLUMNS_VARIANT = 'columns';

  /**
   * The container part suffix every proportions treatment styled.
   */
  public const CONTAINER_PART = '::.daedalus-stylish-layout-container';

  /**
   * The retired proportions axis (off the container declaration).
   */
  public const PROPORTIONS_AXIS = 'proportions';

  /**
   * The retired name of the container layout axis.
   *
   * Renamed so the wear bar row echoes the layout widget's own words
   * (Layout: Columns) instead of leaking the flex implementation
   * (Arrangement: Row); the shipped row variant relabeled "Columns" in the
   * same move, machine names unchanged.
   */
  public const ARRANGEMENT_AXIS = 'arrangement';

  /**
   * The container layout axis (label "Layout").
   */
  public const CONTAINER_LAYOUT_AXIS = 'container_layout';

  /**
   * Retiring variant scopes and their replacement.
   */
  protected const SCOPE_MAP = [
    'kind:section' => 'kind:container',
    'layout:daedalus_stylish_onecol' => 'kind:container',
    'layout:daedalus_stylish_twocol' => 'kind:container',
    'layout:daedalus_stylish_threecol' => 'kind:container',
    'layout:daedalus_stylish_fourcol' => 'kind:container',
  ];

  /**
   * Retiring media conditions and their container-condition replacement.
   *
   * Both old stops map to narrow: tablet's 1024 intermediate stop is lossy
   * by design (adding a third stop later is cheap, removing one is a
   * migration).
   */
  protected const CONDITION_MAP = [
    'mobile' => 'narrow',
    'tablet' => 'narrow',
  ];

  /**
   * The ordinal a retired region name maps to in slot paths, 1-based.
   */
  protected const REGION_ORDINALS = [
    'first' => 1,
    'second' => 2,
    'third' => 3,
    'fourth' => 4,
  ];

  /**
   * Per-request cache of variant re-point decisions, name -> target|NULL.
   *
   * @var array<string, string|null>
   */
  protected array $repointDecisions = [];

  /**
   * Per-request cache of split conversion decisions, name -> bool.
   *
   * @var array<string, bool>
   */
  protected array $splitDecisions = [];

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
   *   TRUE logs a warning (data was dropped or approximated), FALSE a
   *   notice.
   */
  protected function report(array &$lines, string $line, bool $lossy = FALSE): void {
    $lines[] = $line;
    $logger = $this->loggerFactory->get('daedalus_stylish_layouts');
    $lossy ? $logger->warning($line) : $logger->notice($line);
  }

  /**
   * Rewrites one style entry's retired conditions to the container grammar.
   *
   * Compound-aware: any part spelled mobile or tablet becomes narrow and
   * the compound re-canonicalizes. When the rewritten condition collides
   * with one the entry already carries, the two merge with the REWRITTEN
   * side winning per property; tablet-carrying conditions rewrite before
   * mobile-carrying ones so the mobile values (the closest old analogue of
   * narrow) win a three-way collision.
   *
   * @param array $entry
   *   The style entry (condition-keyed blocks plus an optional variants
   *   list).
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
    // Tablet-carrying conditions first (see the docblock).
    foreach ([TRUE, FALSE] as $tablet_pass) {
      foreach (array_keys($entry) as $condition) {
        if (in_array($condition, StyleMapValidator::RESERVED_ENTRY_KEYS, TRUE) || !is_array($entry[$condition])) {
          continue;
        }
        $parts = ConditionTypePluginManager::compoundParts((string) $condition);
        $has_tablet = in_array('tablet', $parts, TRUE);
        if ($tablet_pass !== $has_tablet) {
          continue;
        }
        $rewritten = [];
        $hit = FALSE;
        foreach ($parts as $part) {
          $rewritten[] = self::CONDITION_MAP[$part] ?? $part;
          $hit = $hit || isset(self::CONDITION_MAP[$part]);
        }
        if (!$hit) {
          continue;
        }
        $target = $this->conditionTypes->canonicalizeCondition(implode(ConditionTypePluginManager::COMPOUND_DELIMITER, array_unique($rewritten)));
        if ($target === $condition) {
          continue;
        }
        $values = $entry[$condition];
        unset($entry[$condition]);
        if (isset($entry[$target]) && is_array($entry[$target])) {
          $entry[$target] = $values + $entry[$target];
          $this->report($lines, sprintf('%s: merged the "%s" condition into "%s" (the "%s" values win per property).', $context, $condition, $target, $condition), $has_tablet);
        }
        else {
          $entry[$target] = $values;
          $this->report($lines, sprintf('%s: rewrote the "%s" condition to "%s"%s.', $context, $condition, $target, $has_tablet ? ' (the tablet 1024px stop is retired; narrow applies at 767px)' : ''), $has_tablet);
        }
        $changed = TRUE;
      }
    }
    return $entry;
  }

  /**
   * Decides where a variant reference by this name should point now.
   *
   * Only unmodified shipped daedalus_stylish_layouts variants re-point: a modified
   * or user-owned variant keeps its references and migrates as an entity
   * instead (migrateVariants()), so user tweaks are never silently
   * replaced by the shipped generic. A missing variant re-points too - the
   * reference was already dangling.
   *
   * @return string|null
   *   The generic variant name to re-point to, or NULL to keep.
   */
  public function repointTarget(string $name): ?string {
    if (!isset(self::VARIANT_MAP[$name])) {
      return NULL;
    }
    if (!array_key_exists($name, $this->repointDecisions)) {
      $storage = $this->entityTypeManager->getStorage('daedalus_variant');
      $variants = $storage->loadByProperties(['machine_name' => $name]);
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null $variant */
      $variant = $variants ? reset($variants) : NULL;
      $keep = $variant && (!$variant->isShipped() || $variant->getProvider() !== 'daedalus_stylish_layouts' || $variant->isModified());
      $this->repointDecisions[$name] = $keep ? NULL : self::VARIANT_MAP[$name];
    }
    return $this->repointDecisions[$name];
  }

  /**
   * Re-points an entry's {variant, fragment} references, deduplicating.
   *
   * @param array $entry
   *   The style entry.
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
  public function repointEntryRefs(array $entry, string $context, array &$lines, bool &$changed): array {
    if (empty($entry['variants']) || !is_array($entry['variants'])) {
      return $entry;
    }
    $seen = [];
    $refs = [];
    foreach ($entry['variants'] as $ref) {
      $name = is_array($ref) ? ($ref['variant'] ?? NULL) : NULL;
      if (is_string($name)) {
        if ($target = $this->repointTarget($name)) {
          $ref['variant'] = $target;
          $this->report($lines, sprintf('%s: re-pointed the "%s" variant reference to "%s"%s.', $context, $name, $target, $name === 'fourcol_pairs' ? ' (the paired-on-tablet stop is retired)' : ''), $name === 'fourcol_pairs');
          $changed = TRUE;
        }
        // A fragment slot path may still spell retired segments.
        if (isset($ref['fragment']) && is_string($ref['fragment']) && $ref['fragment'] !== '') {
          $ok = TRUE;
          $fragment = $this->migrateSlotPath($ref['fragment'], $ok);
          if ($ok && $fragment !== $ref['fragment']) {
            $this->report($lines, sprintf('%s: rewrote the "%s" fragment reference to "%s".', $context, $ref['fragment'], $fragment));
            $ref['fragment'] = $fragment;
            $changed = TRUE;
          }
        }
        if (isset($seen[$ref['variant']])) {
          $this->report($lines, sprintf('%s: dropped a duplicate reference to "%s" after re-pointing.', $context, $ref['variant']));
          $changed = TRUE;
          continue;
        }
        $seen[$ref['variant']] = TRUE;
      }
      $refs[] = $ref;
    }
    $entry['variants'] = $refs;
    return $entry;
  }

  /**
   * Rewrites a slot path's retired region()/section() segments.
   *
   * The region(NAME) maps to container(ordinal) by the retired layouts' region
   * order (first..fourth); section(n) renames to container(n). An unknown
   * region name cannot be mapped and fails the path.
   *
   * @param string $path
   *   The slot path, optionally carrying a ::part suffix.
   * @param bool $ok
   *   Set FALSE when the path cannot be mapped.
   *
   * @return string
   *   The rewritten path (meaningless when $ok comes back FALSE).
   */
  public function migrateSlotPath(string $path, bool &$ok): string {
    $part = '';
    if (($pos = strpos($path, '::')) !== FALSE) {
      $part = substr($path, $pos);
      $path = substr($path, 0, $pos);
    }
    $segments = explode('/', $path);
    foreach ($segments as $i => $segment) {
      if (preg_match('/^region\(([a-zA-Z0-9_-]+)\)$/', $segment, $matches)) {
        $ordinal = self::REGION_ORDINALS[$matches[1]] ?? NULL;
        if ($ordinal === NULL) {
          $ok = FALSE;
          return $path . $part;
        }
        $segments[$i] = "container($ordinal)";
      }
      elseif (preg_match('/^section\((\d+)\)$/', $segment, $matches)) {
        $segments[$i] = "container({$matches[1]})";
      }
    }
    return implode('/', $segments) . $part;
  }

  /**
   * Merges a moved style entry into an existing one.
   *
   * The layout-block migration's rule: when either side holds a variant
   * reference the EXISTING entry wins wholesale (a reference is a whole
   * entry); otherwise the two merge per condition with the existing entry's
   * properties winning.
   *
   * @return array
   *   The merged entry.
   */
  public function mergeEntries(array $existing, array $incoming): array {
    if (array_key_exists('variants', $existing) || array_key_exists('variants', $incoming)) {
      return $existing;
    }
    foreach ($incoming as $condition => $values) {
      $existing[$condition] = ($existing[$condition] ?? []) + $values;
    }
    return $existing;
  }

  /**
   * Sweeps a whole style map to the container grammar.
   *
   * Key renames (section: -> container:), loud drops of unaddressable
   * region: keys, condition rewrites, and fragment-reference rewrites.
   * Region keys the section transform mapped onto minted children are gone
   * before this runs (the host sweep runs after the section walk); whatever
   * region keys remain have no element to address anymore.
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
    $result = [];
    foreach ($map as $key => $entry) {
      $new_key = $key;
      if (str_starts_with($key, 'section:')) {
        $new_key = 'container:' . substr($key, strlen('section:'));
        $this->report($lines, sprintf('%s: renamed the "%s" style key to "%s".', $context, $key, $new_key));
        $changed = TRUE;
      }
      elseif (str_starts_with($key, 'region:')) {
        $this->report($lines, sprintf('%s: dropped the styles keyed "%s" - regions are not addressable under the container model and no element remains for them.', $context, $key), TRUE);
        $changed = TRUE;
        continue;
      }
      if (is_array($entry)) {
        $entry = $this->migrateEntryConditions($entry, "$context $new_key", $lines, $changed);
        $entry = $this->repointEntryRefs($entry, "$context $new_key", $lines, $changed);
      }
      $result[$new_key] = array_key_exists($new_key, $result) && is_array($result[$new_key]) && is_array($entry)
        ? $this->mergeEntries($result[$new_key], $entry)
        : $entry;
    }
    return $changed ? $result : NULL;
  }

  /**
   * Migrates every daedalus_variant entity to the container grammar.
   *
   * Unmodified shipped daedalus_stylish_layouts variants are skipped: the generic
   * families replace them (references re-point) and they retire with the
   * four layouts. Everything else rewrites in place across ALL revisions
   * (workspace-staged and draft revisions included), mirroring the host
   * walk's in-place posture: a grammar migration is not an editorial
   * change.
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
      $scope = $variant->getScope();
      $new_scope = self::SCOPE_MAP[$scope] ?? NULL;
      if ($variant->isShipped() && $variant->getProvider() === 'daedalus_stylish_layouts' && !$variant->isModified()) {
        if ($new_scope !== NULL) {
          $this->report($lines, sprintf('Left the shipped "%s" variant on its %s scope: references re-point to the generic families and it retires with the four layouts.', $variant->getMachineName(), $scope));
        }
        continue;
      }

      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $variant->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $context = sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id);

        if ($new_scope !== NULL && $revision->getScope() !== $new_scope) {
          $revision->setScope($new_scope);
          $this->report($lines, sprintf('%s: rewrote the "%s" scope to "%s".', $context, $scope, $new_scope));
          $changed = TRUE;
        }

        $styles = $revision->getStyles();
        $rewritten = [];
        foreach ($styles as $relative => $entry) {
          $new_relative = $relative;
          if ($relative !== '' && !str_starts_with($relative, '::')) {
            $ok = TRUE;
            $new_relative = $this->migrateSlotPath($relative, $ok);
            if (!$ok) {
              $this->report($lines, sprintf('%s: dropped the "%s" slot-path styles - the region name has no container ordinal.', $context, $relative), TRUE);
              $changed = TRUE;
              continue;
            }
            if ($new_relative !== $relative) {
              $this->report($lines, sprintf('%s: rewrote the "%s" slot path to "%s".', $context, $relative, $new_relative));
              $changed = TRUE;
            }
          }
          if (is_array($entry)) {
            $entry = $this->migrateEntryConditions($entry, "$context $new_relative", $lines, $changed);
          }
          $rewritten[$new_relative] = array_key_exists($new_relative, $rewritten) && is_array($rewritten[$new_relative]) && is_array($entry)
            ? $this->mergeEntries($rewritten[$new_relative], $entry)
            : $entry;
        }
        if ($rewritten !== $styles) {
          $revision->setStyles($rewritten);
          $changed = TRUE;
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
   * Deletes the retired shipped layout-exact variant entities.
   *
   * The onecol_*..fourcol_* families retired with the four multi-region
   * layouts: every reference re-pointed to the generic kind:container
   * families (migrateStyleMap()), so an unmodified
   * shipped copy is dead weight whose layout:daedalus_stylish_* scope no longer
   * resolves to a layout anyone can place. Modified copies are USER work:
   * migrateVariants() already rewrote them to the container grammar and
   * they live on as ordinary variants (their shipped source is gone from
   * disk, so sync never touches them again).
   *
   * @return string[]
   *   Report lines.
   */
  public function retireShippedLayoutVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    foreach ($storage->loadByProperties(['machine_name' => array_keys(self::VARIANT_MAP)]) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      if (!$variant->isShipped() || $variant->getProvider() !== 'daedalus_stylish_layouts') {
        continue;
      }
      if ($variant->isModified()) {
        $this->report($lines, sprintf('Kept the modified "%s" variant: it carries user changes and migrated to the container grammar as an ordinary variant.', $variant->getMachineName()));
        continue;
      }
      $variant->delete();
      $this->report($lines, sprintf('Deleted the retired shipped "%s" variant (references re-pointed to "%s").', $variant->getMachineName(), self::VARIANT_MAP[$variant->getMachineName()]));
    }
    // Deleted variants must not satisfy this request's later re-point
    // lookups as if they still existed.
    $this->repointDecisions = [];
    return $lines;
  }

  /**
   * Whether a reference by this split_* name converts to the columns variant.
   *
   * The repointTarget() decision gate, applied to the split families: only
   * unmodified shipped daedalus_stylish_layouts variants convert (a missing variant
   * converts too - the reference was already dangling, and on a site that
   * never shipped the family the frozen ratios are still the reference's
   * one meaning). A modified or user-owned variant keeps its references
   * and survives as an ordinary variant.
   */
  public function splitConverts(string $name): bool {
    if (!isset(self::SPLIT_GROWS[$name])) {
      return FALSE;
    }
    if (!array_key_exists($name, $this->splitDecisions)) {
      $storage = $this->entityTypeManager->getStorage('daedalus_variant');
      $variants = $storage->loadByProperties(['machine_name' => $name]);
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null $variant */
      $variant = $variants ? reset($variants) : NULL;
      $keep = $variant && (!$variant->isShipped() || $variant->getProvider() !== 'daedalus_stylish_layouts' || $variant->isModified());
      $this->splitDecisions[$name] = !$keep;
    }
    return $this->splitDecisions[$name];
  }

  /**
   * Parses a grid-template-columns value the retired stepper could write.
   *
   * The tracks stepper only ever wrote two spellings: 'none' (the stacked
   * flow, also overriding a worn proportions variant) and a space-separated
   * list of minmax(0, Nfr) tracks (equal tracks appended; variant-derived
   * tracks expanded through trackList(), so repeat() never survived).
   * Anything else was written by hand and stays Custom.
   *
   * @return int[]|null
   *   The fr weights in track order ([] for 'none'), or NULL when the
   *   value is not a stepper spelling.
   */
  public function parseStepperTracks(string $value): ?array {
    $value = trim($value);
    if ($value === 'none') {
      return [];
    }
    if (!preg_match('/^minmax\(0,\s*[1-9]\d*fr\)(\s+minmax\(0,\s*[1-9]\d*fr\))*$/', $value)) {
      return NULL;
    }
    preg_match_all('/minmax\(0,\s*([1-9]\d*)fr\)/', $value, $matches);
    return $this->normalizeGrows(array_map('intval', $matches[1]));
  }

  /**
   * Reduces grow weights to their simplest ratio (the widget's spelling).
   *
   * The layout widget writes flex-grow only where a child deviates from
   * equal (weight 1 is spelled by NOT writing), so 2fr/2fr must read as
   * equal and 2fr/4fr as 1:2 before grows are written.
   *
   * @param int[] $weights
   *   Positive integer weights.
   *
   * @return int[]
   *   The reduced weights.
   */
  protected function normalizeGrows(array $weights): array {
    $gcd = 0;
    foreach ($weights as $weight) {
      // Euclid; gcd(0, n) = n seeds the fold.
      for ($b = $weight; $b !== 0;) {
        [$gcd, $b] = [$b, $gcd % $b];
      }
    }
    return $gcd > 1 ? array_map(static fn (int $weight) => intdiv($weight, $gcd), $weights) : $weights;
  }

  /**
   * Converts a map's proportions grammar to mode + share (the columns variant).
   *
   * Per container: worn references to retiring split_* variants drop for
   * one `columns` arrangement reference plus per-child flex-grow overrides
   * carrying the frozen ratio (children in order); stepper-written explicit
   * track overrides on the container part (display grid + minmax tracks +
   * the narrow 'none' stacking) parse the same way - the override was the
   * effective truth, so its ratios win over a shadowed reference - and the
   * stacked spelling ('none') converges to a plain stacked flow. Track
   * styles that do not match the stepper's own pattern are left alone,
   * LOUDLY: they are Custom styling and the layout widget shows them as
   * such (a worn split reference above them materializes its frozen grid
   * styles underneath first, per-property, existing values winning, so
   * dropping the retired reference cannot change rendering).
   *
   * @param array $map
   *   The style map.
   * @param array<string, string[]> $children_by_container
   *   Ordered child element keys (container:{uuid} / block:{uuid}) per
   *   container section uuid, from the host's section tree.
   * @param string $context
   *   The map's host, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing changed.
   */
  public function migrateArrangement(array $map, array $children_by_container, string $context, array &$lines): ?array {
    $changed = FALSE;
    $uuids = [];
    foreach (array_keys($map) as $key) {
      if (preg_match('/^container:([^:\/]+)(?:::\.daedalus-stylish-layout-container)?$/', (string) $key, $matches)) {
        $uuids[$matches[1]] = TRUE;
      }
    }
    foreach (array_keys($uuids) as $uuid) {
      $this->migrateContainerArrangement($map, (string) $uuid, $children_by_container[$uuid] ?? NULL, $context, $lines, $changed);
    }
    return $changed ? $map : NULL;
  }

  /**
   * Converts one container's entry pair (base + part) to mode + share.
   *
   * @param array $map
   *   The style map, rewritten in place.
   * @param string $uuid
   *   The container section uuid.
   * @param string[]|null $child_keys
   *   Ordered child element keys, or NULL when the container's children
   *   are unknown (a dangling entry).
   * @param string $context
   *   The map's host, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was rewritten.
   */
  protected function migrateContainerArrangement(array &$map, string $uuid, ?array $child_keys, string $context, array &$lines, bool &$changed): void {
    $base_key = "container:$uuid";
    $part_key = $base_key . self::CONTAINER_PART;
    $entry = is_array($map[$base_key] ?? NULL) ? $map[$base_key] : [];
    $part = is_array($map[$part_key] ?? NULL) ? $map[$part_key] : [];

    $converting = [];
    if (!empty($entry['variants']) && is_array($entry['variants'])) {
      foreach ($entry['variants'] as $ref) {
        $name = is_array($ref) ? ($ref['variant'] ?? NULL) : NULL;
        if (is_string($name) && $this->splitConverts($name)) {
          $converting[] = $name;
        }
      }
    }

    $default = is_array($part['default'] ?? NULL) ? $part['default'] : [];
    $display = $default['display'] ?? NULL;
    $gtc = $default['grid-template-columns'] ?? NULL;
    $narrow = is_array($part['narrow'] ?? NULL) ? $part['narrow'] : [];
    $narrow_gtc = $narrow['grid-template-columns'] ?? NULL;
    $tracks = is_string($gtc) ? $this->parseStepperTracks($gtc) : NULL;
    $has_override = $gtc !== NULL || $display !== NULL;
    // Whatever layout properties the part carries must all be stepper
    // spellings for the override to convert; a worn reference tolerates
    // absent ones (the variant supplied those).
    $stepper_compatible = ($gtc === NULL || $tracks !== NULL)
      && ($display === NULL || $display === 'grid')
      && ($narrow_gtc === NULL || $narrow_gtc === 'none');
    $full_stepper = $gtc !== NULL && $tracks !== NULL && $display === 'grid'
      && ($narrow_gtc === NULL || $narrow_gtc === 'none');

    if ($converting === [] && !$has_override) {
      return;
    }

    if ($converting === [] && !$full_stepper) {
      // Hand-written layout styles with no retiring material: Custom, and
      // said loudly - the layout widget shows the same honest state.
      $this->report($lines, sprintf('%s: left the "%s" layout styles as Custom - they do not match the retired stepper\'s track pattern (display grid + minmax(0, Nfr) tracks).', $context, $part_key), TRUE);
      return;
    }

    if ($converting !== [] && !$stepper_compatible) {
      // The reference retires either way; its frozen grid styles land
      // underneath the custom override (per property, present values win)
      // so rendering cannot change, and the element stays honestly Custom.
      $frozen = self::SPLIT_GROWS[$converting[0]];
      if (!array_key_exists('display', $default)) {
        $map[$part_key]['default']['display'] = 'grid';
      }
      if (!array_key_exists('grid-template-columns', $default)) {
        $map[$part_key]['default']['grid-template-columns'] = implode(' ', array_map(static fn (int $weight) => "minmax(0, {$weight}fr)", $frozen));
      }
      if (!array_key_exists('grid-template-columns', $narrow)) {
        $map[$part_key]['narrow']['grid-template-columns'] = 'none';
      }
      $entry['variants'] = array_values(array_filter($entry['variants'], static fn ($ref) => !is_array($ref) || !in_array($ref['variant'] ?? NULL, $converting, TRUE)));
      if ($entry['variants'] === []) {
        unset($entry['variants']);
      }
      if ($entry === []) {
        unset($map[$base_key]);
      }
      else {
        $map[$base_key] = $entry;
      }
      $this->report($lines, sprintf('%s: "%s" carries custom layout styles that do not match the retired stepper\'s track pattern; materialized the retiring "%s" variant\'s grid styles beneath them, dropped the reference, and left the container Custom.', $context, $part_key, $converting[0]), TRUE);
      $changed = TRUE;
      return;
    }

    // Converts: the override (if any) is the effective truth - it shadowed
    // any worn reference - otherwise the reference's frozen ratio.
    $grows = $tracks ?? self::SPLIT_GROWS[$converting[0]];
    $stacked = $grows === [];

    // Strip the stepper's own properties; everything else the part
    // carries (widths, spacing, other conditions) stays.
    if ($display === 'grid' || ($gtc !== NULL && $tracks !== NULL) || $narrow_gtc === 'none') {
      if ($display === 'grid') {
        unset($map[$part_key]['default']['display']);
      }
      if ($gtc !== NULL && $tracks !== NULL) {
        unset($map[$part_key]['default']['grid-template-columns']);
      }
      if ($narrow_gtc === 'none') {
        unset($map[$part_key]['narrow']['grid-template-columns']);
      }
      // The stepper only ever wrote default and narrow, but the entry may
      // carry any of the three width bands by the time a stale session
      // converges; drop whichever ended up empty.
      foreach (['default', 'narrow', 'medium'] as $condition) {
        if (isset($map[$part_key][$condition]) && $map[$part_key][$condition] === []) {
          unset($map[$part_key][$condition]);
        }
      }
      if ($map[$part_key] === []) {
        unset($map[$part_key]);
      }
      $this->report($lines, sprintf('%s: removed the stepper-written column tracks from "%s"%s.', $context, $part_key, $stacked ? ' (a stacked flow needs no styles)' : ' (the columns arrangement replaces them)'));
      $changed = TRUE;
    }

    foreach ($converting as $name) {
      $this->report($lines, sprintf('%s: converted the worn "%s" reference on "%s" to the "%s" arrangement.', $context, $name, $base_key, self::COLUMNS_VARIANT));
    }
    if ($converting !== []) {
      $entry['variants'] = array_values(array_filter($entry['variants'], static fn ($ref) => !is_array($ref) || !in_array($ref['variant'] ?? NULL, $converting, TRUE)));
      $changed = TRUE;
    }

    if (!$stacked) {
      $wears_row = FALSE;
      foreach ($entry['variants'] ?? [] as $ref) {
        $wears_row = $wears_row || (is_array($ref) && ($ref['variant'] ?? NULL) === self::COLUMNS_VARIANT);
      }
      if (!$wears_row) {
        $entry['variants'][] = ['variant' => self::COLUMNS_VARIANT, 'fragment' => ''];
        $changed = TRUE;
      }
      $map[$base_key] = $entry;
      $this->writeChildGrows($map, $grows, $child_keys, "$context $base_key", $lines, $changed);
    }
    elseif ($converting !== []) {
      if (($entry['variants'] ?? NULL) === []) {
        unset($entry['variants']);
      }
      if ($entry === []) {
        unset($map[$base_key]);
      }
      else {
        $map[$base_key] = $entry;
      }
    }
  }

  /**
   * Writes the unequal share halves onto the children, in order.
   *
   * Weight 1 is spelled by writing nothing (the layout widget's rule: the
   * columns variant's `> *` equal fill is the resting state), and a child's
   * pre-existing flex-grow override wins - it was already beating the
   * variant's tracks per the cascade, so the migration must not replace it.
   *
   * @param array $map
   *   The style map, rewritten in place.
   * @param int[] $grows
   *   The reduced grow weights, in child order.
   * @param string[]|null $child_keys
   *   Ordered child element keys, or NULL when unknown.
   * @param string $context
   *   Where the grows come from, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was written.
   */
  protected function writeChildGrows(array &$map, array $grows, ?array $child_keys, string $context, array &$lines, bool &$changed): void {
    if (array_unique($grows) === [1]) {
      return;
    }
    if ($child_keys === NULL) {
      $this->report($lines, sprintf('%s: the container\'s children are unknown (no section matches this key), so its unequal ratio could not become child grows; the columns render equal.', $context), TRUE);
      return;
    }
    if (count($child_keys) !== count($grows)) {
      $this->report($lines, sprintf('%s: the ratio names %d columns but the container has %d children; grows were written in order and the rest render equal.', $context, count($grows), count($child_keys)), TRUE);
    }
    foreach ($child_keys as $i => $child_key) {
      $weight = $grows[$i] ?? 1;
      if ($weight === 1) {
        continue;
      }
      $existing = is_array($map[$child_key] ?? NULL) ? ($map[$child_key]['default']['flex-grow'] ?? NULL) : NULL;
      if ($existing !== NULL) {
        if ((string) $existing !== (string) $weight) {
          $this->report($lines, sprintf('%s: kept the existing flex-grow %s on "%s" (the ratio said %d, but a hand-set grow already won the cascade).', $context, $existing, $child_key, $weight), TRUE);
        }
        continue;
      }
      if (isset($map[$child_key]) && !is_array($map[$child_key])) {
        $this->report($lines, sprintf('%s: could not write flex-grow %d on "%s" - the entry is malformed.', $context, $weight, $child_key), TRUE);
        continue;
      }
      $map[$child_key]['default']['flex-grow'] = (string) $weight;
      $this->report($lines, sprintf('%s: wrote flex-grow %d on "%s" (the share half of the converted ratio).', $context, $weight, $child_key));
      $changed = TRUE;
    }
  }

  /**
   * Retires the shipped split_* proportions variants and their axis.
   *
   * The migrate_0006 delete-pass precedent: every reference converted to
   * the columns arrangement (migrateArrangement()), so an unmodified shipped
   * copy is dead weight and deletes; a MODIFIED copy is user work and
   * survives as an ordinary variant. The proportions axis itself is off
   * the container declaration, so every surviving kind:container variant
   * still on it (kept-modified splits, user-saved proportions variants)
   * moves to the built-in style axis - across all revisions - or it could
   * never be saved again.
   *
   * @return string[]
   *   Report lines.
   */
  public function retireShippedSplitVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    foreach ($storage->loadByProperties(['machine_name' => array_keys(self::SPLIT_GROWS)]) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      if (!$variant->isShipped() || $variant->getProvider() !== 'daedalus_stylish_layouts') {
        continue;
      }
      if ($variant->isModified()) {
        $this->report($lines, sprintf('Kept the modified "%s" variant: it carries user changes and lives on as an ordinary variant.', $variant->getMachineName()));
        continue;
      }
      $variant->delete();
      $this->report($lines, sprintf('Deleted the retired shipped "%s" variant (references converted to the "%s" arrangement).', $variant->getMachineName(), self::COLUMNS_VARIANT));
    }
    // Deleted variants must not satisfy this request's later conversion
    // lookups as if they still existed.
    $this->splitDecisions = [];

    // The axis is a non-revisionable identity field, so one save moves the
    // variant (every revision reads the base-table value).
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('scope', 'kind:container')
      ->condition('axis', self::PROPORTIONS_AXIS)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      $variant->setAxis(StyleGuide::STYLE_AXIS);
      $variant->setNewRevision(FALSE);
      $variant->save();
      $this->report($lines, sprintf('Moved the "%s" variant to the built-in style axis: the proportions axis retired with the split families.', $variant->getMachineName()));
    }
    return $lines;
  }

  /**
   * Moves stored variants from the arrangement axis to container_layout.
   *
   * The axis is a non-revisionable identity field (the
   * retireShippedSplitVariants() precedent): one save moves the variant and
   * every revision reads the base-table value. The shipped row variant also
   * relabels "Row" → "Columns": the sync lane cannot deliver that (the
   * source hash covers styles only, so a label/axis-only disk change reads
   * UNCHANGED), but only where the label still IS "Row", so a user rename
   * survives. Old revisions keep their stored label: a restore restores
   * what it said, and the label is presentation, not semantics.
   *
   * @return string[]
   *   Report lines.
   */
  public function migrateContainerLayoutAxis(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('axis', self::ARRANGEMENT_AXIS)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      $variant->setAxis(self::CONTAINER_LAYOUT_AXIS);
      if ($variant->getMachineName() === self::COLUMNS_VARIANT
        && $variant->getProvider() === 'daedalus_stylish_layouts'
        && $variant->label() === 'Row') {
        $variant->set('label', 'Columns');
        $this->report($lines, sprintf('Relabeled the shipped "%s" variant "Columns" (the wear bar echoes the layout widget\'s Stack | Columns words).', $variant->getMachineName()));
      }
      $variant->setNewRevision(FALSE);
      $variant->save();
      $this->report($lines, sprintf('Moved the "%s" variant to the container_layout axis (the arrangement axis renamed).', $variant->getMachineName()));
    }
    return $lines;
  }






}
