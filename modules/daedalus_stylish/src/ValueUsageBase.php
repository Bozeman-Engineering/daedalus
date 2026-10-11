<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;

/**
 * A value-usage table: which source entities reference which materials.
 *
 * A VALUE material (a swatch, an animation, a token) is referenced INSIDE
 * CSS VALUES, not by a structured list (a map entry's "variants" list, which
 * VariantUsage mirrors on its own narrower shape: map hosts only, two
 * lookup forms, no source column). CSS values live in THREE stores, so a
 * value-usage table carries a source-kind column and its save chokepoint
 * listens on three entity families:
 * - 'map': hosts' saved style maps (a content host's auto-managed map
 *   field, a display host's template map: the same host universe
 *   VariantUsage tracks, HostResolver's).
 * - 'variant': daedalus_variant styles (a variant's property values may
 *   reference the material).
 * - 'skin': daedalus_skin site defaults, plus its tokens for a material
 *   whose reference form can live in a property-less token value
 *   (scanTokens()). The skin is the entity a rewrite saves, whichever
 *   field carried the reference.
 *
 * Rows are (material, source, entity_type, entity_id): one row per
 * distinct referenced machine name per source entity. The material column
 * stores the name verbatim as the value spells it, never resolved at write
 * time: the table's content is a pure function of the saved values, and an
 * incremental save produces exactly what rebuild() produces. A reference to
 * a name no material bears (a dangling reference on a permissive site) is
 * tracked like any other; it simply matches no material's counts.
 *
 * Tracked against DEFAULT revisions only, matching what a material edit
 * restyles: the live delivery. Workspace publish promotes staged revisions
 * without firing per-entity update hooks, so the material's workspace
 * subscriber resyncs the published sources (resyncPublished()): the delete
 * batch's rewrite work list must be exact. One accepted staleness edge
 * remains, repaired by rebuild() (the drush usage rebuild commands): writes
 * that bypass the entity save API are invisible to any hook.
 *
 * A twin declares TABLE (its usage table) and COLUMN (the table's material
 * column), read here by late static binding, and implements scanValue():
 * the read of ONE value's references in the twin's reference form. The
 * skeleton (chokepoint, resync, counts, rebuild, the three-store walk) is
 * shared, so the tables can never disagree on what a source is. Counts and
 * work lists are keyed by the column's value (countReferencesTo(),
 * referencesTo()); the entity twins read them through the entity's machine
 * name, the token twin through the token's custom property.
 */
abstract class ValueUsageBase {

  /**
   * The source kinds: the three stores value references live in.
   */
  public const SOURCE_MAP = 'map';
  public const SOURCE_VARIANT = 'variant';
  public const SOURCE_SKIN = 'skin';

  /**
   * The var() reference scan the custom-property twins share.
   *
   * The ONE value-level grammar (the validator's reference checks spell
   * the same expression): every custom property a var() opens with,
   * fallbacks and whitespace tolerated. A twin's scan reads references
   * out of a value through it; rewriteVariable() writes them away.
   */
  protected const VAR_PATTERN = '/var\(\s*(--[A-Za-z0-9_-]+)/';

  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * The material names one CSS value references, for its property.
   *
   * The twin's reference grammar and namespace rule: a name is extracted
   * exactly the way validation and delivery would resolve it, so a
   * reference outside the material's namespace never counts.
   *
   * @return string[]
   *   The referenced machine names, verbatim as the value spells them.
   */
  abstract protected function scanValue(string $property, string $value): array;

  /**
   * The material names a skin's token values reference.
   *
   * Read only by a twin whose reference form can live in a property-less
   * value (a var() can; a keyframes name cannot, the property-scoped slot
   * decision), so the skeleton reads none.
   *
   * @return string[]
   *   The material names the tokens reference.
   */
  protected function scanTokens(array $tokens): array {
    return [];
  }

  /**
   * Syncs a saved source entity's rows with its values (the chokepoint).
   *
   * Called from daedalus_stylish_entity_insert()/daedalus_stylish_entity_update() for every
   * source entity: map hosts, variants, and skins (one dispatcher, three
   * stores). Non-default revision saves (workspace staging, pending
   * revisions) are skipped: the table mirrors the default revisions the
   * live delivery serves. Saves that neither carry nor previously carried
   * references never touch the database.
   */
  public function track(EntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision()) {
      return;
    }
    $source = $this->sourceKind($entity);
    if ($source === NULL) {
      return;
    }
    $names = $this->sourceNames($entity);
    $original = $entity->getOriginal();
    $previous = $original instanceof EntityInterface ? $this->sourceNames($original) : [];
    if ($names === $previous) {
      return;
    }
    $this->deleteRows($entity);
    $this->insertNames($source, $entity->getEntityTypeId(), (string) $entity->id(), $names);
  }

  /**
   * Drops a deleted source entity's rows.
   *
   * Gated on the deleted entity's own values carrying references: track()'s
   * invariant is that rows exist exactly for reference-carrying default
   * revisions, so a reference-free source has nothing to drop.
   */
  public function untrack(EntityInterface $entity): void {
    if ($this->sourceKind($entity) === NULL || $this->sourceNames($entity) === []) {
      return;
    }
    $this->deleteRows($entity);
  }

  /**
   * Re-mirrors ONE source entity's rows from its saved values.
   *
   * The single-source repair: unlike track(), no original-comparison gate.
   * This is for sources whose default revision changed WITHOUT a save
   * (workspace publish promotes staged revisions), so there is no original
   * to compare against and the rows must simply match the values now.
   */
  public function resync(EntityInterface $entity): void {
    if ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision()) {
      return;
    }
    $source = $this->sourceKind($entity);
    if ($source === NULL) {
      return;
    }
    $this->deleteRows($entity);
    $this->insertNames($source, $entity->getEntityTypeId(), (string) $entity->id(), $this->sourceNames($entity));
  }

  /**
   * Resyncs the source entities a workspace publish just promoted.
   *
   * VariantUsage::resyncHosts()'s twin, over the wider source universe:
   * publish fires no per-entity update hooks, so without this the
   * just-published references would be invisible to every usage consumer,
   * and the delete batch's rewrite work list must be exact, because a
   * source it misses keeps a reference that no longer delivers. Called from
   * the material's workspace subscriber with the publish event's payload;
   * map-field carriers, variants, and skins are touched, everything else
   * skipped.
   *
   * @param array $published_revision_ids
   *   Entity ids keyed by revision id, keyed by entity type id (the
   *   WorkspacePostPublishEvent payload shape).
   */
  public function resyncPublished(array $published_revision_ids): void {
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map');
    foreach ($published_revision_ids as $entity_type_id => $entity_ids) {
      $is_source = in_array($entity_type_id, ['daedalus_variant', 'daedalus_skin'], TRUE)
        || isset($field_map[$entity_type_id][DaedalusStylishFieldManager::FIELD_NAME]);
      if (!$is_source) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach (array_chunk(array_unique($entity_ids), 50) as $chunk) {
        foreach ($storage->loadMultiple($chunk) as $entity) {
          $this->resync($entity);
        }
        $storage->resetCache($chunk);
      }
    }
  }

  /**
   * Counts a material entity's referencing source entities.
   *
   * The count keyed by the entity's machine name, the name the entity
   * twins' reference forms spell (countReferencesTo()).
   */
  public function countReferences(MachineNameInterface $material): int {
    return $this->countReferencesTo($material->getMachineName());
  }

  /**
   * Counts the source entities referencing a name.
   *
   * DISTINCT over (entity_type, entity_id): a source naming the material in
   * two places (a skin's token and default, two entries) counts once (one
   * entity to rewrite), and there is only one reference form, so no
   * cross-form collapse is needed.
   *
   * @param string $name
   *   The material column's value: the name as the reference spells it.
   */
  public function countReferencesTo(string $name): int {
    $query = $this->database->select(static::TABLE, 'u')
      ->fields('u', ['entity_type', 'entity_id'])
      ->distinct()
      ->condition('u.' . static::COLUMN, $name);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Lists a material entity's referencing sources.
   *
   * The work list keyed by the entity's machine name (referencesTo()).
   */
  public function references(MachineNameInterface $material): array {
    return $this->referencesTo($material->getMachineName());
  }

  /**
   * Lists the sources referencing a name, the rewrite batch's work list.
   *
   * @param string $name
   *   The material column's value: the name as the reference spells it.
   *
   * @return array[]
   *   ['source' => string, 'entity_type' => string, 'entity_id' => string]
   *   per referencing source entity, ordered for stable batches. The
   *   source kind tells the rewrite WHICH store to rewrite in the entity.
   */
  public function referencesTo(string $name): array {
    return $this->database->select(static::TABLE, 'u')
      ->fields('u', ['source', 'entity_type', 'entity_id'])
      ->condition('u.' . static::COLUMN, $name)
      ->orderBy('u.source')
      ->orderBy('u.entity_type')
      ->orderBy('u.entity_id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * Replaces one custom property's var() expressions in a CSS value.
   *
   * The write direction of the var() grammar the two var() twins share
   * (swatches and tokens both deliver as custom properties), the
   * delete-in-use batch's one substitution rule: every var() whose custom
   * property is the given variable is replaced WHOLE (fallback included,
   * since the reference is gone, not unresolved) by the literal, and
   * var() expressions referencing anything else are preserved
   * byte-for-byte, their fallbacks rewritten recursively (a fallback may
   * itself reference the variable). Unbalanced parentheses end the scan
   * and the remainder passes through verbatim: a malformed value is the
   * author's, never silently reshaped.
   *
   * @param string $value
   *   The CSS value.
   * @param string $variable
   *   The custom property, leading dashes included ("--swatch-hero").
   * @param string $literal
   *   What each var() of it becomes.
   */
  public static function rewriteVariable(string $value, string $variable, string $literal): string {
    if (!str_contains($value, 'var(')) {
      return $value;
    }
    $result = '';
    $offset = 0;
    $length = strlen($value);
    while (($position = strpos($value, 'var(', $offset)) !== FALSE) {
      $depth = 0;
      $close = NULL;
      for ($i = $position + 3; $i < $length; $i++) {
        if ($value[$i] === '(') {
          $depth++;
        }
        elseif ($value[$i] === ')' && --$depth === 0) {
          $close = $i;
          break;
        }
      }
      if ($close === NULL) {
        break;
      }
      $inner = substr($value, $position + 4, $close - $position - 4);
      $result .= substr($value, $offset, $position - $offset);
      $head = trim(explode(',', $inner, 2)[0]);
      $result .= $head === $variable
        ? $literal
        : 'var(' . self::rewriteVariable($inner, $variable, $literal) . ')';
      $offset = $close + 1;
    }
    return $result . substr($value, $offset);
  }

  /**
   * Rebuilds the whole table from the saved default-revision values.
   *
   * The repair path (the drush usage rebuild commands) for the staleness
   * edges the class docblock names, and the seed for sites updating into
   * the table. Every map host store is enumerated (the entity types
   * carrying the auto-managed map field, then every Layout Builder enabled
   * display), then the variants and the skins. Runs in a transaction so
   * readers never see a half-built table (delete, not truncate: truncate
   * commits implicitly on MySQL).
   *
   * @return int
   *   The number of reference-carrying source entities tracked.
   */
  public function rebuild(): int {
    // Held until the method returns: the transaction commits when the object
    // is destroyed, so the rebuild lands whole.
    // phpcs:ignore DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
    $transaction = $this->database->startTransaction();
    $this->database->delete(static::TABLE)->execute();
    $tracked = 0;
    foreach ($this->entityFieldManager->getFieldMapByFieldType('daedalus_stylish_style_map') as $entity_type_id => $fields) {
      if (!isset($fields[DaedalusStylishFieldManager::FIELD_NAME])) {
        // Tracking covers the auto-managed field only; a foreign field of
        // the map type is outside the module's delivery and its counting.
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->exists(DaedalusStylishFieldManager::FIELD_NAME)
        ->execute();
      $tracked += $this->rebuildChunked($entity_type_id, $ids);
    }
    $tracked += $this->rebuildChunked('entity_view_display', VariantUsage::templateDisplayIds($this->entityTypeManager));
    $tracked += $this->rebuildChunked(FrameInterface::ENTITY_TYPE_ID, VariantUsage::frameIds($this->entityTypeManager));
    foreach (['daedalus_variant', 'daedalus_skin'] as $entity_type_id) {
      $ids = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
        ->accessCheck(FALSE)
        ->execute();
      $tracked += $this->rebuildChunked($entity_type_id, $ids);
    }
    return $tracked;
  }

  /**
   * Rebuild's inner loop: inserts one entity type's rows, chunked.
   *
   * @return int
   *   The number of reference-carrying entities inserted.
   */
  protected function rebuildChunked(string $entity_type_id, array $ids): int {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $tracked = 0;
    foreach (array_chunk($ids, 50) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $entity) {
        $source = $this->sourceKind($entity);
        if ($source === NULL) {
          continue;
        }
        $names = $this->sourceNames($entity);
        if ($names !== []) {
          $this->insertNames($source, $entity_type_id, (string) $entity->id(), $names);
          $tracked++;
        }
      }
      $storage->resetCache($chunk);
    }
    return $tracked;
  }

  /**
   * The source kind of an entity, or NULL for non-source entities.
   *
   * The one dispatch rule behind the three-store chokepoint: variants and
   * skins by interface, map hosts by keeping a style store (HostResolver).
   * _daedalus_stylish_value_usage_source() mirrors it as the cheap pre-service gate.
   */
  protected function sourceKind(EntityInterface $entity): ?string {
    if ($entity instanceof DaedalusStylishVariantInterface) {
      return self::SOURCE_VARIANT;
    }
    if ($entity instanceof DaedalusStylishSkinInterface) {
      return self::SOURCE_SKIN;
    }
    if ($this->hostResolver->hosts($entity) && $this->hostResolver->fromEntity($entity)->hasStyleStore()) {
      return self::SOURCE_MAP;
    }
    return NULL;
  }

  /**
   * The distinct material names a source entity's values reference, sorted.
   *
   * Sorted so track()'s changed-or-not comparison is order-insensitive.
   * A skin is scanned per LAYER as stored, package and site both: a
   * package value a site override hides is still a reference the delete
   * batch must rewrite, or unsetting the override would reveal a
   * dangling one.
   *
   * @return string[]
   *   The material names, sorted.
   */
  protected function sourceNames(EntityInterface $entity): array {
    $names = match ($this->sourceKind($entity)) {
      self::SOURCE_VARIANT => $this->scanEntries($entity->getStyles()),
      self::SOURCE_SKIN => [
        ...$this->scanTokens($entity->packageTokens()),
        ...$this->scanTokens($entity->siteTokens()),
        ...$this->scanDefaults($entity->packageDefaults()),
        ...$this->scanDefaults($entity->siteDefaults()),
      ],
      self::SOURCE_MAP => $this->scanEntries($this->hostMap($entity)),
      default => [],
    };
    $names = array_keys(array_flip($names));
    sort($names);
    return $names;
  }

  /**
   * The material names one defaults layer's entries reference.
   */
  protected function scanDefaults(array $defaults): array {
    return array_merge([], ...array_map(
      fn (mixed $styles): array => is_array($styles) ? $this->scanEntries($styles) : [],
      array_values($defaults),
    ));
  }

  /**
   * A map host's saved style map, wherever the host keeps it.
   *
   * The map as SAVED: usage counts what a host stores, so the read
   * declines the template seed a content host runs on the first read of
   * either document door (a never-edited page would otherwise count as
   * wearing its template's references, and the seed would sit on the
   * just-saved entity in memory).
   */
  protected function hostMap(EntityInterface $entity): array {
    $host = $this->hostResolver->fromEntity($entity);
    $host->declineSeed();
    return $host->styleMap();
  }

  /**
   * The material names referenced across a set of style entries.
   *
   * One walk for every entry store (style maps keyed by selectable key,
   * variant styles keyed by relative key, skin defaults' per-scope
   * styles): each entry's condition blocks map properties to scalar CSS
   * values, and the values are where references live; the twin decides,
   * per property, what a value can reference. The "variants" reference
   * list is structural, never a value, so it is skipped.
   *
   * @return string[]
   *   The material names the entries reference.
   */
  protected function scanEntries(array $entries): array {
    $names = [];
    foreach ($entries as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      foreach ($entry as $condition => $properties) {
        if ($condition === 'variants' || !is_array($properties)) {
          continue;
        }
        foreach ($properties as $property => $value) {
          if (is_scalar($value)) {
            array_push($names, ...$this->scanValue((string) $property, (string) $value));
          }
        }
      }
    }
    return $names;
  }

  /**
   * Drops a source entity's rows.
   */
  protected function deleteRows(EntityInterface $entity): void {
    $this->database->delete(static::TABLE)
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (string) $entity->id())
      ->execute();
  }

  /**
   * Inserts one row per referenced material name for a source entity.
   *
   * @param string $source
   *   The usage source (one of the SOURCE_* constants).
   * @param string $entity_type_id
   *   The source entity's type id.
   * @param string $entity_id
   *   The source entity's id.
   * @param string[] $names
   *   The referenced material names.
   */
  protected function insertNames(string $source, string $entity_type_id, string $entity_id, array $names): void {
    if ($names === []) {
      return;
    }
    $insert = $this->database->insert(static::TABLE)->fields([static::COLUMN, 'source', 'entity_type', 'entity_id']);
    foreach ($names as $name) {
      $insert->values([
        static::COLUMN => $name,
        'source' => $source,
        'entity_type' => $entity_type_id,
        'entity_id' => $entity_id,
      ]);
    }
    $insert->execute();
  }

}
