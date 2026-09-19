<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A swatch kind: one category of named CSS value the palette holds.
 *
 * The kind owns the value's SHAPE validation — what one scalar CSS string
 * must look like to be a value of this kind. Everything around the scalar
 * is the spine's, shared across kinds so it can never fork per kind:
 * condition keying (a swatch value may vary on the width/scheme/motion
 * axes, exactly like a skin token), the required "default" entry, and the
 * strict-site var() reference checks all live in
 * StyleMapValidator::validateSwatchValue(), which dispatches each scalar
 * here.
 */
interface SwatchKindInterface extends PluginInspectionInterface {

  /**
   * Returns the kind's human label.
   */
  public function label(): TranslatableMarkup;

  /**
   * Validates one scalar value against this kind's shape.
   *
   * The posture is the wide tier's: hygiene and structure the server can
   * honestly check (the ;{}<> ban, the script-scheme scan, "is this a
   * gradient function at all"), while the precise CSS grammar is the
   * client's csstree lexer — an ungrammatical value that passes here is
   * inert, never unsafe. var() references pass structurally (swatches may
   * reference skin tokens and other swatches); whether a reference resolves
   * is the spine's strict-site check, not the kind's.
   *
   * @param string $path
   *   The error path to report at ("value", "value.dark").
   * @param string $value
   *   The scalar CSS value.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty when the value fits the kind.
   */
  public function validateValue(string $path, string $value): array;

}
