<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rewrite-then-delete and rewrite-then-rename batches behind a token.
 *
 * ValueDeleteBatchBase's twin for the material that is a fact of a skin,
 * staged by TokenManager::deleteToken() for a REFERENCED token and by
 * TokenManager::rename() for a referenced token's rename. The subject is
 * the token's handle (SkinToken), loaded by its batch id (the skin's
 * entity id, the token's roster id and, for a rename, the new name), so
 * the token is re-read from the skin each operation and the job ends
 * harmlessly when someone else finished it.
 *
 * Delete: each var() of the token's custom property, fallback included,
 * becomes the token's literal value (SkinToken::literal(), the default
 * entry of a conditioned value) in the host map, variant styles, and the
 * skin's tokens AND defaults; then the token goes through the token
 * manager, in the layer the site may write. Rename: each var() of the
 * old custom property is respelled to the new one, fallbacks kept (the
 * reference lives on under its new name); then the record moves.
 *
 * A rewritten SHIPPED variant or skin reads 'modified' afterwards: an
 * honest divergence from its disk source, preferred over leaving a
 * reference that can no longer resolve.
 */
final class TokenDeleteBatch extends ValueDeleteBatchBase {

  /**
   * Batch operation: sweeps any late references, then deletes the token.
   */
  public static function deleteToken(string $id, array &$context): void {
    static::deleteMaterial($id, $context);
  }

  /**
   * Batch operation: sweeps any late references, then renames the token.
   *
   * The rename batch's tail, deleteMaterial()'s twin: the late work list
   * is respelled, then the record moves under its new name.
   */
  public static function renameToken(string $id, array &$context): void {
    $token = static::load($id);
    if (!$token instanceof SkinToken || $token->renameTo === NULL) {
      return;
    }
    static::sweep($token, $context);
    \Drupal::service('daedalus_stylish.token_manager')->rename($token->skin, $token->scale, $token->name, $token->renameTo, FALSE);
    $context['results']['renamed'] = $token->scale . '/' . $token->renameTo;
  }

  /**
   * Batch finish callback of the rename job: one loud summary.
   */
  public static function renameFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success || !isset($results['renamed'])) {
      $messenger->addError(new TranslatableMarkup('Renaming the token failed; its references keep the old name. Re-run the rename to finish.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('Renamed the token to %id and respelled its references in @count places.', [
      '%id' => $results['renamed'],
      '@count' => $results['rewritten'] ?? 0,
    ]));
  }

  /**
   * {@inheritdoc}
   *
   * The entity the batch loads by id is the SKIN; the token is picked
   * from it (load()).
   */
  protected static function entityTypeId(): string {
    return 'daedalus_skin';
  }

  /**
   * {@inheritdoc}
   */
  protected static function usage(): ValueUsageBase {
    return \Drupal::service('daedalus_stylish.token_usage');
  }

  /**
   * {@inheritdoc}
   *
   * The token's handle from its batch id: NULL when the skin is gone or
   * no longer mints the token (someone else finished the job).
   */
  protected static function load(int|string $id): ?object {
    $address = SkinToken::parseBatchId((string) $id);
    if ($address === NULL) {
      return NULL;
    }
    [$skin_id, $scale, $name, $rename_to] = $address;
    $skin = \Drupal::entityTypeManager()->getStorage(static::entityTypeId())->load($skin_id);
    if (!$skin instanceof DaedalusStylishSkinInterface) {
      return NULL;
    }
    return \Drupal::service('daedalus_stylish.token_manager')->resolve($skin, $scale, $name, $rename_to);
  }

  /**
   * {@inheritdoc}
   */
  protected static function nameOf(object $material): string {
    assert($material instanceof SkinToken);
    return $material->variable;
  }

  /**
   * {@inheritdoc}
   */
  protected static function labelOf(object $material): string {
    assert($material instanceof SkinToken);
    return $material->id();
  }

  /**
   * {@inheritdoc}
   *
   * The token goes through the manager, which decides the layer and
   * finds no reference left to refuse over.
   */
  protected static function remove(object $material): void {
    assert($material instanceof SkinToken);
    \Drupal::service('daedalus_stylish.token_manager')->deleteToken($material->skin, $material->scale, $material->name, FALSE);
  }

  /**
   * {@inheritdoc}
   *
   * The literal rewrite for a delete, the respelling for a rename; a
   * token has no property, and a var() needs none.
   */
  protected static function rewriter(object $material): \Closure {
    assert($material instanceof SkinToken);
    $variable = $material->variable;
    if ($material->renameTo !== NULL) {
      $replacement = \Drupal::service('daedalus_stylish.token_manager')->variable($material->scale, $material->renameTo);
      return static fn (string $property, string $value): string => TokenUsage::renameValue($value, $variable, $replacement);
    }
    $literal = $material->literal();
    return static fn (string $property, string $value): string => ValueUsageBase::rewriteVariable($value, $variable, $literal);
  }

  /**
   * {@inheritdoc}
   *
   * A var() lives in a token value as it does anywhere: the shared
   * property-blind walk.
   */
  protected static function rewriteTokens(array $tokens, \Closure $rewrite, bool &$changed): array {
    return static::rewriteTokenValues($tokens, $rewrite, $changed);
  }

  /**
   * {@inheritdoc}
   */
  protected static function revisionLogMessage(string $name): string {
    return sprintf('Rewrote var(%s) token references before the token changed.', $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function failureMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Deleting the token failed; its references keep resolving it. Re-run the delete to finish.');
  }

  /**
   * {@inheritdoc}
   */
  protected static function successMessage(string $label, int $rewritten): TranslatableMarkup {
    return new TranslatableMarkup('Rewrote %label to its literal value in @count places and deleted it.', [
      '%label' => $label,
      '@count' => $rewritten,
    ]);
  }

}
