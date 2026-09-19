<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;

/**
 * The rewrite-then-delete Batch API job behind a value material's deletion.
 *
 * VariantDeleteBatch's twin at value scope, shared by every value-usage
 * table (ValueUsageBase). Staged by the material's manager for a
 * REFERENCED material: one operation per referencing source entity from
 * the usage table's work list, then the entity delete. Lazy repair is
 * impossible in principle (after deletion there is no value to materialize
 * from), so every reference is rewritten BEFORE the delete, through the
 * twin's one substitution rule (rewriter()), in whichever store carried it
 * (host map, variant styles, skin defaults, and the skin's tokens for a
 * twin whose reference form can live there), as a regular revisioned
 * save.
 *
 * A rewritten SHIPPED variant or skin reads 'modified' afterwards: an
 * honest divergence from its disk source, preferred over leaving a
 * reference that can no longer resolve.
 *
 * Static callbacks with service lookups, because batch operations
 * serialize between requests. Each callback re-loads its subjects and
 * skips what no longer applies: a source deleted mid-batch, a source that
 * stopped referencing (both harmless), or the material itself gone
 * (someone else finished the job). The final delete operation re-reads the
 * work list and sweeps references that appeared after the batch was
 * staged, so the delete never strands a reference it could still rewrite.
 *
 * A twin names its entity type and usage table, binds its rewrite to one
 * material (rewriter()), words its messages, and exposes the delete
 * operation under the name its manager stages.
 */
abstract class ValueDeleteBatchBase {

  /**
   * The material's entity type id, what the batch loads by id.
   */
  abstract protected static function entityTypeId(): string;

  /**
   * The material's usage table, the late work list's source.
   */
  abstract protected static function usage(): ValueUsageBase;

  /**
   * The value rewrite bound to one material.
   *
   * @return \Closure
   *   fn (string $property, string $value): string, the twin's
   *   rewriteValue() with the material's name (and whatever else the
   *   substitution needs) resolved once per source rather than per value.
   */
  abstract protected static function rewriter(MachineNameInterface $material): \Closure;

  /**
   * The revision log line a rewritten source carries.
   */
  abstract protected static function revisionLogMessage(string $name): string;

  /**
   * The summary when the batch failed or never reached the delete.
   */
  abstract protected static function failureMessage(): TranslatableMarkup;

  /**
   * The summary when the material was rewritten away and deleted.
   */
  abstract protected static function successMessage(string $label, int $rewritten): TranslatableMarkup;

  /**
   * Rewrites a skin's token values.
   *
   * Only a twin whose reference form can live in a property-less token
   * value rewrites there (ValueUsageBase::scanTokens()'s write twin); the
   * skeleton leaves tokens untouched.
   */
  protected static function rewriteTokens(array $tokens, \Closure $rewrite, bool &$changed): array {
    return $tokens;
  }

  /**
   * Batch operation: rewrites the material's references in one source.
   *
   * The source save is an ordinary default-revision save (a new revision
   * on revisionable sources, so the rewrite is one revision revert away),
   * which re-enters the usage table's chokepoint and drops the source's
   * rows.
   */
  public static function rewriteSource(int $id, string $source, string $entity_type, string $entity_id, array &$context): void {
    $material = static::load($id);
    if ($material === NULL) {
      return;
    }
    $context['message'] = new TranslatableMarkup('Rewriting %label references…', ['%label' => (string) $material->label()]);
    if (static::rewrite($material, $source, $entity_type, $entity_id)) {
      $context['results']['rewritten'] = ($context['results']['rewritten'] ?? 0) + 1;
    }
  }

  /**
   * Batch operation: sweeps any late references, then deletes the material.
   *
   * Each twin exposes it under the operation name its manager stages.
   */
  protected static function deleteMaterial(int $id, array &$context): void {
    $material = static::load($id);
    if ($material === NULL) {
      return;
    }
    foreach (static::usage()->references($material) as $reference) {
      if (static::rewrite($material, $reference['source'], $reference['entity_type'], $reference['entity_id'])) {
        $context['results']['rewritten'] = ($context['results']['rewritten'] ?? 0) + 1;
      }
    }
    $label = (string) $material->label();
    $material->delete();
    $context['results']['deleted'] = $label;
  }

  /**
   * Batch finish callback: one loud summary of what happened.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success || !isset($results['deleted'])) {
      $messenger->addError(static::failureMessage());
      return;
    }
    $messenger->addStatus(static::successMessage($results['deleted'], $results['rewritten'] ?? 0));
  }

  /**
   * Loads the material, or NULL when it is gone.
   */
  protected static function load(int $id): ?MachineNameInterface {
    $material = \Drupal::entityTypeManager()->getStorage(static::entityTypeId())->load($id);
    return $material instanceof MachineNameInterface ? $material : NULL;
  }

  /**
   * Rewrites every reference in one source entity's store and saves.
   *
   * The source kind (the usage table's column) picks the store: 'map' the
   * host's style map, 'variant' the variant's styles, 'skin' the skin's
   * defaults and (rewriteTokens()) its tokens, one entity, both fields
   * swept in one save. A store the rewrite did not change is never set.
   *
   * @return bool
   *   TRUE when the source carried references and was saved rewritten;
   *   FALSE when there was nothing to do (source gone, no longer
   *   referencing).
   */
  protected static function rewrite(MachineNameInterface $material, string $source, string $entity_type, string $entity_id): bool {
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity instanceof EntityInterface) {
      return FALSE;
    }
    $rewrite = static::rewriter($material);

    $changed = FALSE;
    switch ($source) {
      case ValueUsageBase::SOURCE_MAP:
        if (!$entity instanceof FieldableEntityInterface || !$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
          return FALSE;
        }
        $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
        if (!$item instanceof StyleMapItem) {
          return FALSE;
        }
        $map = static::rewriteEntries($item->getStyleMap(), $rewrite, $changed);
        if ($changed) {
          $item->setStyleMap($map);
        }
        break;

      case ValueUsageBase::SOURCE_VARIANT:
        if (!$entity instanceof DaedalusStylishVariantInterface) {
          return FALSE;
        }
        $styles = static::rewriteEntries($entity->getStyles(), $rewrite, $changed);
        if ($changed) {
          $entity->setStyles($styles);
        }
        break;

      case ValueUsageBase::SOURCE_SKIN:
        if (!$entity instanceof DaedalusStylishSkinInterface) {
          return FALSE;
        }
        $tokens_changed = FALSE;
        $tokens = static::rewriteTokens($entity->getTokens(), $rewrite, $tokens_changed);
        if ($tokens_changed) {
          $entity->setTokens($tokens);
        }
        $defaults = $entity->getDefaults();
        foreach ($defaults as $scope => $styles) {
          if (is_array($styles)) {
            $defaults[$scope] = static::rewriteEntries($styles, $rewrite, $changed);
          }
        }
        if ($changed) {
          $entity->setDefaults($defaults);
        }
        $changed = $changed || $tokens_changed;
        break;

      default:
        return FALSE;
    }

    if (!$changed) {
      return FALSE;
    }
    if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage(static::revisionLogMessage($material->getMachineName()));
      }
    }
    $entity->save();
    return TRUE;
  }

  /**
   * Rewrites the material's references across a set of style entries.
   *
   * The write twin of ValueUsageBase::scanEntries(): condition blocks'
   * scalar property values are the only place references live, and the
   * twin's rewrite decides, per property, what a value can carry; the
   * "variants" reference list is structural and passes through untouched.
   * A value the rewrite leaves alone is never reassigned.
   */
  protected static function rewriteEntries(array $entries, \Closure $rewrite, bool &$changed): array {
    foreach ($entries as $key => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      foreach ($entry as $condition => $properties) {
        if ($condition === 'variants' || !is_array($properties)) {
          continue;
        }
        foreach ($properties as $property => $value) {
          $rewritten = static::rewriteScalar((string) $property, $value, $rewrite);
          if ($rewritten !== NULL) {
            $changed = TRUE;
            $entries[$key][$condition][$property] = $rewritten;
          }
        }
      }
    }
    return $entries;
  }

  /**
   * Rewrites one stored value, or NULL when the rewrite leaves it alone.
   *
   * Only scalars can carry a reference; a value the rewrite returns
   * unchanged answers NULL so the caller never reassigns (and never
   * retypes) what it did not touch.
   */
  protected static function rewriteScalar(string $property, mixed $value, \Closure $rewrite): ?string {
    if (!is_scalar($value)) {
      return NULL;
    }
    $rewritten = $rewrite($property, (string) $value);
    return $rewritten === (string) $value ? NULL : $rewritten;
  }

}
