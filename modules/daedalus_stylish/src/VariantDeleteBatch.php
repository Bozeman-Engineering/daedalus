<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;

/**
 * The detach-then-delete Batch API job behind variant deletion.
 *
 * Staged by VariantManager::deleteVariant() for a WORN variant: one
 * operation per wearer from the usage table's work list, then the entity
 * delete. Lazy detach is impossible in principle (after deletion there is
 * nothing to materialize from), so every wearer detaches BEFORE the delete,
 * each keeping the variant's delivered look as its own styles through the
 * op spine's one detach math
 * (DaedalusStylishOperationHandler::detachVariantEverywhere()).
 *
 * Static callbacks with service lookups, because batch operations serialize
 * between requests. Each callback re-loads its subjects and skips what no
 * longer applies: a wearer deleted mid-batch, a wearer that stopped wearing
 * (both harmless), or the variant itself gone (someone else finished the
 * job). The final delete operation re-reads the work list and sweeps
 * wearers that appeared after the batch was staged, so the delete never
 * strands a reference it could still materialize.
 */
final class VariantDeleteBatch {

  /**
   * Batch operation: detaches the variant from one wearing host entity.
   *
   * The host save is an ordinary default-revision save (a new revision on
   * revisionable hosts, so the detach is one revision revert away), which
   * re-enters the usage table's chokepoint and drops the wearer's rows.
   */
  public static function detachWearer(int $variant_id, string $entity_type, string $entity_id, array &$context): void {
    $variant = DaedalusStylishVariant::load($variant_id);
    if (!$variant instanceof DaedalusStylishVariantInterface) {
      return;
    }
    $context['message'] = new TranslatableMarkup('Detaching %label…', ['%label' => (string) $variant->label()]);
    if (self::detach($variant, $entity_type, $entity_id)) {
      $context['results']['detached'] = ($context['results']['detached'] ?? 0) + 1;
    }
  }

  /**
   * Batch operation: sweeps any late wearers, then deletes the variant.
   */
  public static function deleteVariant(int $variant_id, array &$context): void {
    $variant = DaedalusStylishVariant::load($variant_id);
    if (!$variant instanceof DaedalusStylishVariantInterface) {
      return;
    }
    foreach (\Drupal::service('daedalus_stylish.variant_usage')->wearers($variant) as $wearer) {
      if (self::detach($variant, $wearer['entity_type'], $wearer['entity_id'])) {
        $context['results']['detached'] = ($context['results']['detached'] ?? 0) + 1;
      }
    }
    $label = (string) $variant->label();
    $variant->delete();
    $context['results']['deleted'] = $label;
  }

  /**
   * Batch finish callback: one loud summary of what happened.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success || !isset($results['deleted'])) {
      $messenger->addError(new TranslatableMarkup('Deleting the style variant failed; its wearers keep rendering it. Re-run the delete to finish.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('Detached %label from @count pieces of content and deleted it.', [
      '%label' => $results['deleted'],
      '@count' => $results['detached'] ?? 0,
    ]));
  }

  /**
   * Detaches every wearing of the variant from one host entity and saves.
   *
   * @return bool
   *   TRUE when the host wore the variant and was saved detached; FALSE
   *   when there was nothing to do (host gone, no longer wearing).
   */
  protected static function detach(DaedalusStylishVariantInterface $variant, string $entity_type, string $entity_id): bool {
    $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
    $entity = $storage->load($entity_id);
    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($entity);
    if (!\Drupal::service('daedalus_stylish.operation_handler')->detachVariantEverywhere($host, $variant)) {
      return FALSE;
    }
    if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
      $entity->setNewRevision(TRUE);
      if ($entity instanceof RevisionLogInterface) {
        $entity->setRevisionLogMessage(sprintf('Detached the "%s" style variant before its deletion.', $variant->getMachineName()));
      }
    }
    $entity->save();
    return TRUE;
  }

}
