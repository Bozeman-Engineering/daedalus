<?php

/**
 * @file
 * The site migration's translator: one phase per run, as a drush script.
 *
 * From the project root, after the composer swap (the old packages removed,
 * drupal/daedalus required):
 *
 *   drush php:script \
 *     web/modules/contrib/daedalus/migrate/plus_suite/translate.php -- <phase>
 *
 * Phases, in the order migrate.sh runs them:
 *   audit    read-only, repeatable: what the site stores in the old suite's
 *            vocabulary; refuses (exit 1) a site below the mapping's floor.
 *   rename   installs the foundation modules, then translates every store
 *            the audit reads into daedalus's vocabulary and flushes the
 *            caches; the site is then ready for `drush updb`.
 *   content  after `drush updb` and the migration recipe: every old heading
 *            component in the default revision of every layout host becomes
 *            a text block component over a new text block.
 *
 * Each phase prints its report and a one-line summary and exits 1 when it
 * refuses or fails. A phase that changes the site is recorded in state
 * under daedalus.migrate.<phase> when it succeeds and is skipped on a rerun.
 *
 * The daedalus module is not installed when the first phases run, so the
 * package's namespaces are registered on the class loader here, ahead of
 * core doing so at install: Drupal\daedalus\ for the phases, and
 * Drupal\daedalus_layout\ for the nested-section shape the audit reads.
 */

declare(strict_types=1);

use Drupal\daedalus\Migrate\PlusSuite\Audit;
use Drupal\daedalus\Migrate\PlusSuite\HeadingToText;
use Drupal\daedalus\Migrate\PlusSuite\Renamer;

$loader = \Drupal::service('class_loader');
$loader->addPsr4('Drupal\\daedalus\\', __DIR__ . '/../../src');
$loader->addPsr4('Drupal\\daedalus_layout\\', __DIR__ . '/../../modules/daedalus_layout/src');

// Phase name to its class; every class implements PhaseInterface.
$phases = [
  'audit' => Audit::class,
  'rename' => Renamer::class,
  'content' => HeadingToText::class,
];

// Drush's php:script leaves the arguments after the script name in $extra.
$arguments = $extra ?? [];
$phase = $arguments[0] ?? '';
if (!isset($phases[$phase])) {
  fwrite(STDERR, sprintf("Usage: drush php:script %s -- <phase>\nPhases: %s\n", __FILE__, implode(', ', array_keys($phases))));
  exit(1);
}

$class = $phases[$phase];
$state = \Drupal::state();
$key = "daedalus.migrate.$phase";
$done = $class::REPEATABLE ? NULL : $state->get($key);
if ($done) {
  print sprintf("%s: already done at %s; nothing to do.\n", $phase, date('c', $done['time']));
}
else {
  $report = $class::create(\Drupal::getContainer())->run();
  print $report->render();
  print $report->summary() . "\n";
  if (!$report->ok()) {
    exit(1);
  }
  if (!$class::REPEATABLE) {
    $state->set($key, ['time' => \Drupal::time()->getRequestTime()]);
  }
}
