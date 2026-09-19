<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Plugin\Field\FieldType\JsonBlobItemBase;

/**
 * A stencil's element subtree: one JSON document.
 *
 * The daedalus_variant_styles pattern applied to stencils: ONE serialized
 * blob (a JSON text column, array in / array out) carrying the SAME grammar
 * as a stencil file's element key. One grammar, two sources — discovery
 * serves file and content stencils uniformly, and the save path validates
 * the same shape the file grammar does.
 */
#[FieldType(
  id: 'stencil_element',
  label: new TranslatableMarkup('Stencil element'),
  description: new TranslatableMarkup('The blueprint element subtree a stencil stamps at placement, as one JSON document.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class StencilElementItem extends JsonBlobItemBase {

  /**
   * {@inheritdoc}
   */
  protected static function valueLabel(): TranslatableMarkup {
    return new TranslatableMarkup('Element JSON');
  }

  /**
   * Returns the decoded element subtree.
   *
   * @return array
   *   The element subtree in the stencil file grammar. Empty array when
   *   no element is stored.
   */
  public function getElement(): array {
    $element = $this->getJson();
    return is_array($element) ? $element : [];
  }

  /**
   * Sets the element subtree.
   *
   * An empty subtree empties the item so "no element" and "no field
   * value" are the same state.
   */
  public function setElement(array $element): void {
    $this->setJson($element);
  }

}
