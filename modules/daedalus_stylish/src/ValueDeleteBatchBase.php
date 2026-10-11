<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

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
 * operation under the name its manager stages. The material the batch
 * carries between requests is its SUBJECT: the material's entity for the
 * entity twins (loaded by entity id), or the token's handle (SkinToken,
 * loaded by its batch id) for the twin whose material is a fact of a skin
 * rather than an entity; the subject hooks (load(), nameOf(), labelOf(),
 * remove()) default to the entity reading and the token twin overrides
 * them.
 */
abstract class ValueDeleteBatchBase {

  /**
   * The material's entity type id, what the entity twins load by id.
   */
  abstract protected static function entityTypeId(): string;

  /**
   * The material's usage table, the late work list's source.
   */
  abstract protected static function usage(): ValueUsageBase;

  /**
   * The value rewrite bound to one material.
   *
   * @param object $material
   *   The subject (see load()).
   *
   * @return \Closure
   *   fn (string $property, string $value): string, the twin's
   *   rewriteValue() with the material's name (and whatever else the
   *   substitution needs) resolved once per source rather than per value.
   */
  abstract protected static function rewriter(object $material): \Closure;

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
   * Rewrites a skin's token values through a property-blind rewrite.
   *
   * The var() twins' rewriteTokens(): token values are scalars or
   * condition-keyed scalar maps inside the stored records ({scale: {name:
   * {value, ...}}}), one level flatter than style entries; a token has no
   * property, and a var() needs none. The record's other facts
   * (description, offered) are untouched, and a site record that states
   * no value contributes nothing (SkinTokensItem::statedValues()).
   */
  protected static function rewriteTokenValues(array $tokens, \Closure $rewrite, bool &$changed): array {
    foreach (SkinTokensItem::statedValues($tokens) as $scale => $values) {
      foreach ($values as $token => $token_value) {
        if (is_array($token_value)) {
          foreach ($token_value as $condition => $conditioned) {
            $rewritten = static::rewriteScalar('', $conditioned, $rewrite);
            if ($rewritten !== NULL) {
              $changed = TRUE;
              $tokens[$scale][$token]['value'][$condition] = $rewritten;
            }
          }
          continue;
        }
        $rewritten = static::rewriteScalar('', $token_value, $rewrite);
        if ($rewritten !== NULL) {
          $changed = TRUE;
          $tokens[$scale][$token]['value'] = $rewritten;
        }
      }
    }
    return $tokens;
  }

  /**
   * Rewrites the material's references in one defaults layer.
   */
  protected static function rewriteDefaults(array $defaults, \Closure $rewrite, bool &$changed): array {
    foreach ($defaults as $scope => $styles) {
      if (is_array($styles)) {
        $defaults[$scope] = static::rewriteEntries($styles, $rewrite, $changed);
      }
    }
    return $defaults;
  }

  /**
   * Batch operation: rewrites the material's references in one source.
   *
   * The source save is an ordinary default-revision save (a new revision
   * on revisionable sources, so the rewrite is one revision revert away),
   * which re-enters the usage table's chokepoint and drops the source's
   * rows.
   */
  public static function rewriteSource(int|string $id, string $source, string $entity_type, string $entity_id, array &$context): void {
    $material = static::load($id);
    if ($material === NULL) {
      return;
    }
    $context['message'] = new TranslatableMarkup('Rewriting %label references…', ['%label' => static::labelOf($material)]);
    if (static::rewrite($material, $source, $entity_type, $entity_id)) {
      $context['results']['rewritten'] = ($context['results']['rewritten'] ?? 0) + 1;
    }
  }

  /**
   * Batch operation: sweeps any late references, then deletes the material.
   *
   * Each twin exposes it under the operation name its manager stages.
   */
  protected static function deleteMaterial(int|string $id, array &$context): void {
    $material = static::load($id);
    if ($material === NULL) {
      return;
    }
    static::sweep($material, $context);
    $label = static::labelOf($material);
    static::remove($material);
    $context['results']['deleted'] = $label;
  }

  /**
   * Rewrites the references that appeared after the batch was staged.
   *
   * The late work list, re-read from the usage table at the batch's tail
   * so its final operation never strands a reference it could still
   * rewrite.
   */
  protected static function sweep(object $material, array &$context): void {
    foreach (static::usage()->referencesTo(static::nameOf($material)) as $reference) {
      if (static::rewrite($material, $reference['source'], $reference['entity_type'], $reference['entity_id'])) {
        $context['results']['rewritten'] = ($context['results']['rewritten'] ?? 0) + 1;
      }
    }
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
   * Loads the subject, or NULL when it is gone.
   *
   * The entity reading: the material's entity by id.
   *
   * @return object|null
   *   The subject the other hooks read.
   */
  protected static function load(int|string $id): ?object {
    $material = \Drupal::entityTypeManager()->getStorage(static::entityTypeId())->load($id);
    return $material instanceof MachineNameInterface ? $material : NULL;
  }

  /**
   * The subject's name as the usage table's column spells it.
   */
  protected static function nameOf(object $material): string {
    assert($material instanceof MachineNameInterface);
    return $material->getMachineName();
  }

  /**
   * The subject's human label, for the batch's messages.
   */
  protected static function labelOf(object $material): string {
    assert($material instanceof MachineNameInterface);
    return (string) $material->label();
  }

  /**
   * Removes the subject once every reference is rewritten.
   */
  protected static function remove(object $material): void {
    assert($material instanceof MachineNameInterface);
    $material->delete();
  }

  /**
   * Rewrites every reference in one source entity's store and saves.
   *
   * The source kind (the usage table's column) picks the store: 'map' the
   * host's style map (wherever the host keeps it: a content host's field, a
   * display host's template setting), 'variant' the variant's styles,
   * 'skin' the skin's defaults and (rewriteTokens()) its tokens, one
   * entity, both fields swept in one save. A store the rewrite did not
   * change is never set.
   *
   * @return bool
   *   TRUE when the source carried references and was saved rewritten;
   *   FALSE when there was nothing to do (source gone, no longer
   *   referencing).
   */
  protected static function rewrite(object $material, string $source, string $entity_type, string $entity_id): bool {
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity instanceof EntityInterface) {
      return FALSE;
    }
    $rewrite = static::rewriter($material);

    $changed = FALSE;
    switch ($source) {
      case ValueUsageBase::SOURCE_MAP:
        $resolver = \Drupal::service('daedalus_blueprint.host_resolver');
        if (!$resolver->hosts($entity)) {
          return FALSE;
        }
        $host = $resolver->fromEntity($entity);
        if (!$host->hasStyleStore()) {
          return FALSE;
        }
        // The batch rewrites stored references and saves the host: the
        // read declines the template seed a content host runs on the
        // first read of either document door, or the save would persist a
        // never-edited page's seed beside the rewrite.
        $host->declineSeed();
        $map = static::rewriteEntries($host->styleMap(), $rewrite, $changed);
        if ($changed) {
          $host->setStyleMap($map);
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
        // Each layer is rewritten as stored: a package value a site
        // override hides is a reference too (ValueUsageBase::sourceNames()).
        $tokens_changed = FALSE;
        $tokens = static::rewriteTokens($entity->packageTokens(), $rewrite, $tokens_changed);
        if ($tokens_changed) {
          $entity->setPackageTokens($tokens);
        }
        $site_tokens_changed = FALSE;
        $tokens = static::rewriteTokens($entity->siteTokens(), $rewrite, $site_tokens_changed);
        if ($site_tokens_changed) {
          $entity->setSiteTokens($tokens);
        }
        $defaults = static::rewriteDefaults($entity->packageDefaults(), $rewrite, $changed);
        if ($changed) {
          $entity->setPackageDefaults($defaults);
        }
        $site_changed = FALSE;
        $defaults = static::rewriteDefaults($entity->siteDefaults(), $rewrite, $site_changed);
        if ($site_changed) {
          $entity->setSiteDefaults($defaults);
        }
        $changed = $changed || $site_changed || $tokens_changed || $site_tokens_changed;
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
        $entity->setRevisionLogMessage(static::revisionLogMessage(static::nameOf($material)));
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
