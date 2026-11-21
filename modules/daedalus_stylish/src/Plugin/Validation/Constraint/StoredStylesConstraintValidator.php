<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\VariantStylesItem;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Runs the style map validator over a stored style document.
 *
 * The value is the field item. Its context is derived from the entity it
 * sits on, never handed in: a style map validates on its host (the
 * entity itself, whose layout places the keys' blocks and containers), a
 * variant's styles at the variant's own scope, with the identity, axis
 * and design system membership the variant carries. Both run on the
 * landing lane (see StoredStylesConstraint for why).
 */
final class StoredStylesConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected StyleMapValidator $styleMapValidator,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.style_map_validator'),
      $container->get('daedalus_blueprint.host_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof StoredStylesConstraint);
    if (!$value instanceof StyleMapItem || $value->isEmpty()) {
      return;
    }
    $document = json_decode((string) $value->value, TRUE);
    if (!is_array($document) || ($document !== [] && array_is_list($document))) {
      $this->context->addViolation($constraint->notAnObject);
      return;
    }

    $errors = $value instanceof VariantStylesItem
      ? $this->variantErrors($value, $document, $constraint)
      : $this->styleMapValidator->withLandingLane(fn (): array => $this->styleMapValidator->validate($document, [
        'host' => $this->hostResolver->fromEntity($value->getEntity()),
      ]));
    foreach ($errors as $error) {
      $path = $error->getPath();
      $this->context->addViolation($path === '' ? $constraint->error : $constraint->errorAt, [
        '@path' => $path,
        '@message' => $error->getMessage(),
      ]);
    }
  }

  /**
   * The validator's errors for a variant's stored styles.
   *
   * The context is the one the variant's write doors hand
   * (VariantStylesFieldHandler, VariantManager::validateStyles()), read
   * from the variant itself.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors, paths relative to the styles; empty when the scope is
   *   no address (that violation is reported here).
   */
  protected function variantErrors(VariantStylesItem $item, array $document, StoredStylesConstraint $constraint): array {
    $variant = $item->getEntity();
    if (!$variant instanceof DaedalusStylishVariantInterface) {
      throw new \LogicException(sprintf('Variant styles validate at a variant\'s scope; the "%s" entity carrying them is not a variant.', $variant->getEntityTypeId()));
    }
    $address = StyleMapValidator::variantScope($variant->getScope());
    if ($address === NULL) {
      $this->context->addViolation($constraint->noScope, [
        '@message' => StyleMapValidator::invalidScopeError($variant->getScope())->getMessage(),
      ]);
      return [];
    }
    return $this->styleMapValidator->withLandingLane(fn (): array => $this->styleMapValidator->validateAt($document, $address, [
      'variant_identity' => array_values(array_filter([$variant->getMachineName(), (string) $variant->uuid()])),
      'variant_axis' => $variant->getAxis() ?: StyleGuide::STYLE_AXIS,
      'design_system' => $variant->getDesignSystem(),
    ]));
  }

}
