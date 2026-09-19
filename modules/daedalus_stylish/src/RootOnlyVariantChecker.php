<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The depth half of the variant applicability seam.
 *
 * Placement curates offers and birth, never legality: a container's grant
 * is its layout's grant at every depth, and a variant validates wherever
 * it is worn. What placement DOES curate is the offer of a root-only axis
 * (width on the shipped container: a page band's content column, a
 * doubled gutter on a nested one), and the picker's skip of that axis at
 * nested depth is only curation while every apply door respects it. This
 * checker is the op spine's half: a NEW wearing of a root-only variant on
 * a container the ElementLocator places inside another section is refused
 * in the dialect's own words (AXIS_NOT_TOP_LEVEL, the attribute-side twin
 * in DomOperationHandler), so the AI's op lane and raw batches answer the
 * same rule the picker and the dialect do.
 *
 * The depth fact is the roster record's (MaterialRecord::rootOnly(), the
 * applies.depth stamp DaedalusStylishMaterialsProvider writes from the scope
 * declaration's root-only axes), never re-derived from StyleGuide here:
 * the picker, the dialect catalog and this gate read one stamp. A record
 * the roster does not carry is no opinion (the validator's existence
 * check ran first); a container the locator cannot place reads as root
 * (isNestedSection() degrades to the ungated posture).
 *
 * Gesture only, like the seam: the validator stays depth-blind on
 * references (a whole-map write cannot tell a new reference from an old
 * one; the validator's scopeMatches() doc names the split), so an existing
 * nested reference keeps validating, rendering and stays removable. Depth
 * itself is not tolerated: root-only wear is a fact of depth, never a
 * memory, and a move that nests a container strips it
 * (ElementStylesSubscriber::onElementMoved) while a move back to root
 * redresses with the layout's defaults. Refuse, never translate, at THIS
 * gate: a band captured with its width and placed nested fails loudly
 * with the axis named rather than silently losing its width.
 */
class RootOnlyVariantChecker implements VariantApplicabilityCheckerInterface {

  public function __construct(
    protected StyleMapValidator $validator,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected ElementLocator $elementLocator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(DaedalusStylishVariantInterface $variant, FieldableEntityInterface $entity, string $key): array {
    $parsed = $this->validator->parseKey($key);
    if ($parsed === NULL || $parsed['kind'] !== 'container') {
      return [];
    }
    $name = $variant->getMachineName();
    $record = $this->rosterBuilder->build()->get(MaterialKind::Variant, $name);
    if ($record === NULL || !$record->rootOnly()) {
      return [];
    }
    if (!$this->elementLocator->isNestedSection($entity, $parsed['uuid'])) {
      return [];
    }
    return [
      new BlueprintError('variant', 'VARIANT_ROOT_ONLY', sprintf('The "%s" variant applies to top-level containers only, never nested inside another container.', $name), [
        'variant' => $name,
        'axis' => $variant->getAxis(),
      ], 'Set it on the outermost container.'),
    ];
  }

}
