<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * Drops every stored style key its store's key grammar rejects.
 *
 * A key outside the grammar prints nothing: the grammar is the emit gate
 * of every printer, since a key's pieces land inside selectors. Such a
 * key is inert data that the stored styles validation still refuses, so
 * its host cannot be saved until the key is gone, and no surface shows
 * it. This convergence deletes it, entry and all, and says so: every
 * dropped key is reported with the entry it carried. Nothing is
 * rewritten: guessing what a retired spelling meant would make styles
 * print that have not printed for as long as the spelling has been
 * retired.
 *
 * "Rejected" is, per store, exactly what that store's printer skips by
 * the key alone, so a drop never changes a page:
 * - a host style map: StyleMapValidator::parseKey() answers NULL (the
 *   gate of CssPrinter::printMap() and VariantStylesheet::ruleWearCss());
 * - a daedalus_variant's styles: RelativeSelector::splitKey() answers
 *   NULL, a "::" suffix that is not a canonical selector (the gate of
 *   CssPrinter::printFragmentStyles()). A slot path the variant's scope
 *   does not contain is skipped by the printer too, but by the scope and
 *   not by the key alone; the validator refuses it at the next save and
 *   it stays;
 * - a daedalus_skin's defaults: RelativeSelector::splitKey() answers
 *   NULL or a root other than '' (the gate of
 *   SkinStylesheet::printDefaultsScope(): a default's key is '' or a
 *   "::" selector under the scope, never a path).
 *
 * Any other refusal the validator would make (a malformed entry, a
 * dangling reference, a value off its scale) may sit on a key that DOES
 * print and is left alone. Stores: host style maps (the KeyGrammarSweep,
 * riding daedalus_layout's transform spine last, so every sweep that can
 * rewrite a retired spelling into the grammar has had its turn first),
 * daedalus_variant styles and daedalus_skin defaults (all revisions,
 * through the base's walk). Idempotent: a map whose keys all parse
 * converges to itself and reports nothing.
 */
class KeyGrammarConvergence extends StyleStoreConvergenceBase {

  /**
   * The grammar of the store being converged, for report lines.
   */
  protected const HOST = 'style map';
  protected const VARIANT = 'variant style';
  protected const SKIN = 'skin default';

  /**
   * The store whose rule convergeEntries() applies.
   *
   * The base hands convergeEntries() a keyed entry map without saying
   * which store it came from, and the three stores reject by different
   * rules; each door names its store for the length of its walk.
   */
  protected string $store = self::HOST;

  public function __construct(
    protected StyleMapValidator $validator,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($entityTypeManager, $loggerFactory);
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
   *   The map without its rejected keys, or NULL when nothing changed.
   */
  public function convergeStyleMap(array $map, string $context, array &$lines): ?array {
    $changed = FALSE;
    $result = $this->inStore(self::HOST, function () use ($map, $context, &$lines, &$changed): array {
      return $this->convergeEntries($map, $context, $lines, $changed);
    });
    return $changed ? $result : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function convergeVariants(): array {
    return $this->inStore(self::VARIANT, fn (): array => parent::convergeVariants());
  }

  /**
   * {@inheritdoc}
   */
  public function convergeSkins(): array {
    return $this->inStore(self::SKIN, fn (): array => parent::convergeSkins());
  }

  /**
   * Runs one door's walk under its store's rule.
   */
  protected function inStore(string $store, callable $walk): array {
    $outer = $this->store;
    $this->store = $store;
    try {
      return $walk();
    }
    finally {
      $this->store = $outer;
    }
  }

  /**
   * Whether the current store's key grammar rejects a key.
   */
  protected function rejects(string $key): bool {
    if ($this->store === self::HOST) {
      return $this->validator->parseKey($key) === NULL;
    }
    $split = RelativeSelector::splitKey($key);
    return $split === NULL || ($this->store === self::SKIN && $split[0] !== '');
  }

  /**
   * {@inheritdoc}
   */
  protected function convergeEntries(array $entries, string $context, array &$lines, bool &$changed): array {
    $result = [];
    foreach ($entries as $key => $entry) {
      $key = (string) $key;
      if ($this->rejects($key)) {
        $changed = TRUE;
        $this->report($lines, sprintf('%s: dropped the "%s" entry; the key is outside the %s key grammar, so it printed nothing. It held %s', $context, $key, $this->store, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        continue;
      }
      $result[$key] = $entry;
    }
    return $result;
  }

}
