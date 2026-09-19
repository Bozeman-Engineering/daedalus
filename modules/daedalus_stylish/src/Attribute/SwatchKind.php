<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The SwatchKind attribute.
 *
 * A swatch kind is a category of named CSS value the palette may hold — a
 * color, a gradient — and the contract splits like control widgets do: this
 * PHP plugin declares identity (id, label) and OWNS the value's shape
 * validation (SwatchKindInterface::validateValue()), while a JS registry
 * keyed by the same plugin id owns the face (how a swatch previews) and the
 * editor (the builder that authors the value).
 *
 * The PHP half is not ceremony: the kind is the one server-side gate on
 * what a swatch's value may be, because published swatches compile into a
 * site-wide stylesheet — the validation spine
 * (StyleMapValidator::validateSwatchValue()) dispatches every scalar value
 * to its kind, and the entity refuses to save under a kind no plugin
 * provides.
 *
 * @see \Drupal\daedalus_stylish\SwatchKindInterface
 * @see \Drupal\daedalus_stylish\StyleMapValidator::validateSwatchValue()
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SwatchKind extends AttributeBase {

  /**
   * Constructs a new SwatchKind instance.
   *
   * @param string $id
   *   The plugin ID — the value of the swatch entity's kind field and the
   *   id the client kind registry registers under.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The kind's human label, e.g. for the palette UI's kind filter.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
  ) {}

}
