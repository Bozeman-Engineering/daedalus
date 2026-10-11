<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\DialectParser;
use Drupal\daedalus_blueprint\Dialect\PageLanguageOperationsInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;

/**
 * The material gesture family.
 *
 * The ops save_material, update_material and remove_material.
 *
 * One page-language op per verb for every material kind, dispatched by
 * `kind` to the provider that owns the kind (MaterialsProviderInterface::
 * capture / update / remove). The UI verbs and dialogs submit the same ops
 * and the admin routes are adapters over the same provider methods, so
 * adding a kind adds no op, no verb and no route, and the AI learns one
 * vocabulary ("save this as a material of kind X"). Removal is a first-
 * class verb so "get rid of that purple" works through the AI too.
 *
 * Every verb is a page-language op (page_edit admits them): a capture has
 * no home but a page, so `element` takes what the page language spells, a
 * printed uuid or a live data-alias, resolved here through the spine's
 * alias map for the batch's conversation before the provider sees it. A
 * gesture that names no element (a swatch minted from a value, a rename,
 * a removal) rides a page batch all the same: the page is where the
 * materials are used, and the batch's response is how the client learns
 * the mint (the materials delta).
 *
 * SIDE-EFFECT SEMANTICS, deliberate and documented: these operations write
 * a material ENTITY (never the batch's staged host, which a capture only
 * reads). Batch rollback restores the staged host but does not undo a
 * material written by an earlier operation in a failed batch: a material
 * is ordinary site-wide content, one admin-listing delete or revision
 * revert away, which is its reversibility net everywhere else too. Mints
 * stay immediate and site-wide by decision (another editor must see the
 * swatch), so undo never covers one; the delta channel makes every mint
 * visible in the palette at once and remove_material is the honest undo.
 *
 * The permission a verb needs is the kind's (the provider checks its own
 * grade); the origin a mint carries is the LANE's: the AI tools pass
 * ORIGIN_OPTION = ai on every batch, every other lane stamps user, the
 * importers stamp shipped themselves.
 */
final class MaterialGestureOperationHandler implements PageLanguageOperationsInterface {

  public const SAVE = 'save_material';
  public const UPDATE = 'update_material';
  public const REMOVE = 'remove_material';
  public const OPS = [self::SAVE, self::UPDATE, self::REMOVE];

  /**
   * The request option naming the writer's origin (MaterialRecord::ORIGIN_*).
   *
   * Absent means a person (ORIGIN_USER); the AI tools set ORIGIN_AI on
   * every batch they run.
   */
  public const ORIGIN_OPTION = 'material_origin';

  /**
   * The request option a caller sets when it drives a staged Batch API job.
   *
   * A referenced material's removal rewrites every reference first; only
   * a lane that drives core's Batch (the admin routes) may stage that job,
   * the op spine refuses the removal with the reference count instead.
   */
  public const BATCH_OPTION = 'material_batch';

  /**
   * The warning code a capture's dropped styling rides the batch with.
   */
  public const CAPTURE_WARNING = 'MATERIAL_CAPTURE_WARNING';

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
    protected DialectAliasMap $aliasMap,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getPageLanguageOperations(): array {
    return self::OPS;
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return self::OPS;
  }

  /**
   * {@inheritdoc}
   *
   * A material gesture writes a material entity, never the page: a
   * capture only reads the element it names, and the kind's provider
   * checks the material permission itself. Nothing to judge on an
   * element.
   */
  public function getRequirements(): array {
    return array_fill_keys(self::OPS, []);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [
      self::SAVE => sprintf('"kind" (%s), "label" + the kind\'s keys (the design system brief\'s MINT line); "global": true opts the mint out of the active design system; %s', implode('|', array_keys($this->rosterBuilder->gestureLines())), rtrim(MaterialsProse::mintGateSentence(), '.')),
      self::UPDATE => '"kind", "id" + the keys to change (label, description, value, "element" pushes a capture)',
      self::REMOVE => '"kind", "id"; deletes an unreferenced material',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(HostInterface $host, array $operation, array $options = []): BlueprintResult {
    $op = (string) $operation['op'];
    $kinds = array_keys($this->rosterBuilder->gestureLines());
    $kind = is_string($operation['kind'] ?? NULL) ? MaterialKind::tryFrom($operation['kind']) : NULL;
    $provider = $kind !== NULL ? $this->rosterBuilder->gestureProvider($kind) : NULL;
    if ($kind === NULL || $provider === NULL) {
      $kind_prose = $kinds === [] ? 'no material kind has a gesture on this site' : 'the kinds with a gesture are ' . implode(', ', $kinds);
      return BlueprintResult::structuralFailure([
        new BlueprintError('kind', $kind === NULL ? 'MISSING_PARAMETER' : 'NO_GESTURE', $kind === NULL
          ? sprintf('%s needs a material "kind".', $op)
          : sprintf('No gesture writes a %s; %s.', $kind->noun(), $kind_prose), ['kinds' => $kinds], sprintf('Pass "kind" as one of: %s.', implode(', ', $kinds))),
      ]);
    }

    $id = NULL;
    if ($op !== self::SAVE) {
      $id = $operation['id'] ?? NULL;
      if (!is_string($id) || $id === '') {
        return BlueprintResult::structuralFailure([
          new BlueprintError('id', 'MISSING_PARAMETER', sprintf('%s needs the %s\'s "id".', $op, $kind->noun()), [], sprintf('Name the %s by its id from the MATERIALS roster.', $kind->noun())),
        ]);
      }
    }

    $gesture = array_diff_key($operation, array_flip(['op', 'kind', 'id']));
    if (array_key_exists('element', $gesture)) {
      $element = $gesture['element'];
      if (!is_string($element) || $element === '') {
        return BlueprintResult::structuralFailure([
          new BlueprintError('element', 'MISSING_PARAMETER', 'A non-empty element reference is required.', [], 'Name the element to capture: its id in the page print, or the alias you gave it.'),
        ]);
      }
      if (!DialectParser::isUuid($element)) {
        $resolved = $this->aliasMap->resolve($host, (string) ($options['conversation'] ?? ''), $element);
        if ($resolved === NULL) {
          return BlueprintResult::structuralFailure([
            new BlueprintError('element', 'UNKNOWN_REF', sprintf('"%s" is not a live alias or uuid.', $element), [], 'Refs are the ids in the page print (uuids or data-alias values); aliases are minted by inserting elements with ids.'),
          ]);
        }
        $gesture['element'] = $resolved;
      }
    }

    $origin = $options[self::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER;
    if (!in_array($origin, MaterialRecord::ORIGINS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown material origin "%s" on the request options; one of %s.', is_scalar($origin) ? (string) $origin : gettype($origin), implode(', ', MaterialRecord::ORIGINS)));
    }
    $options[self::ORIGIN_OPTION] = $origin;

    try {
      $result = match ($op) {
        self::SAVE => $provider->capture($kind, $gesture, $host, $options),
        self::UPDATE => $provider->update($kind, $id, $gesture, $host, $options),
        self::REMOVE => $provider->remove($kind, $id, $options),
      };
    }
    catch (MaterialGestureException $exception) {
      return BlueprintResult::semanticFailure($exception->getErrors());
    }

    // Capture warnings (styling the material's grammar cannot express) ride
    // the batch's ordinary warning channel.
    $warnings = array_map(
      static fn (string $warning) => new BlueprintWarning('element', self::CAPTURE_WARNING, $warning),
      $result->warnings,
    );
    return BlueprintResult::success($host, $warnings);
  }

}
