<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Auto-renders the schema anatomy's example markup.
 *
 * The AI-facing schema readers are small models, which follow concrete
 * examples better than abstract grammar, so each composed block
 * type's and each declared layout's anatomy fragment carries an `example`:
 * the markup this type actually renders, RESOLVED through the element
 * model from sample data, never a hand-written string that can drift.
 * The pipeline is the model's own: field_sample_value populates a
 * throwaway (never saved) block_content entity, the element resolver
 * computes the concrete tree from its data, and the HTML projector prints
 * it in the same print format the read tools use, so the example IS what
 * a read of a freshly composed element will show.
 *
 * The examples follow the data the way the render does: a sample with no
 * link renders the unlinked form, a site with no media renders the image
 * block's empty form. field_sample_value is an optional dependency: when
 * it is not installed the sample entity is created bare (every field its
 * default), which is exactly the markup a bare-created block renders, so
 * the example stays honest either way.
 *
 * Layouts have no entity: their examples resolve from declaration + depth
 * alone, one per depth when the two shapes differ, because a
 * depth-conditioned root form is precisely the kind of fact a small model
 * needs to SEE (the container's section root at root depth, div nested).
 */
class AnatomyExamples {

  /**
   * The sample entity generator, when field_sample_value is installed.
   *
   * Not type-hinted: the interface only exists while the module does.
   *
   * @var \Drupal\field_sample_value\SampleValueEntityGeneratorInterface|null
   */
  protected $sampleGenerator;

  public function __construct(
    protected PageBuilderDeclarations $declarations,
    protected ElementResolver $resolver,
    protected ElementModel $elementModel,
    protected HtmlProjector $projector,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    ?object $sample_generator = NULL,
  ) {
    $this->sampleGenerator = $sample_generator;
  }

  /**
   * Example markup per composed block type.
   *
   * @param string|null $theme
   *   The theme whose layered declarations apply.
   *
   * @return array<string, string>
   *   Example HTML keyed by block type, for every `composed: true` type
   *   whose bundle exists (a declaration without a bundle has nothing to
   *   sample and nothing placeable to exemplify).
   */
  public function blockTypeExamples(?string $theme = NULL): array {
    $bundles = $this->bundleInfo->getBundleInfo('block_content');
    $examples = [];
    foreach ($this->declarations->getBlockTypes($theme) as $block_type) {
      $declaration = $this->declarations->getBlockType($block_type, $theme);
      if (($declaration['composed'] ?? FALSE) !== TRUE || !isset($bundles[$block_type])) {
        continue;
      }
      $sample = $this->sampleEntity($block_type);
      $markup = $this->resolver->resolve($declaration, $sample);
      $examples[$block_type] = $this->projector->projectBlockTypeExample(
        $markup,
        $block_type,
        $this->sampleTexts($sample, $markup, $block_type),
      );
    }
    return $examples;
  }

  /**
   * Example markup per declared layout, per nesting depth.
   *
   * @param string|null $theme
   *   The theme whose layered declarations apply.
   *
   * @return array<string, array{root: string, nested?: string}>
   *   Example HTML keyed by layout id: the root-depth shape, plus the
   *   nested shape when the declaration's depth conditions make it
   *   differ.
   */
  public function layoutExamples(?string $theme = NULL): array {
    $examples = [];
    foreach ($this->declarations->getLayouts($theme) as $layout_id) {
      $declaration = $this->declarations->getLayout($layout_id, $theme);
      $root = $this->projector->projectLayoutExample(
        $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_ROOT),
        $layout_id,
      );
      $nested = $this->projector->projectLayoutExample(
        $this->resolver->resolve($declaration, NULL, ElementResolver::DEPTH_NESTED),
        $layout_id,
      );
      $examples[$layout_id] = ['root' => $root];
      if ($nested !== $root) {
        $examples[$layout_id]['nested'] = $nested;
      }
    }
    return $examples;
  }

  /**
   * A throwaway sample entity for one block type; never saved.
   */
  protected function sampleEntity(string $block_type): FieldableEntityInterface {
    if ($this->sampleGenerator !== NULL) {
      return $this->sampleGenerator->createWithSampleValues('block_content', $block_type);
    }
    /** @var \Drupal\Core\Entity\FieldableEntityInterface */
    return $this->entityTypeManager->getStorage('block_content')->create(['type' => $block_type]);
  }

  /**
   * The sample's text content, keyed by field name.
   *
   * The same fields a live component print mounts (HtmlProjector's
   * componentContent): the tree's slot-claimed field tokens plus the
   * bundle presentation's edit target, non-empty string values only.
   *
   * @return array<string, string>
   *   Field name => sample text.
   */
  protected function sampleTexts(FieldableEntityInterface $sample, array $markup, string $block_type): array {
    $names = [];
    foreach (array_keys($markup['slots'] ?? []) as $token) {
      if (str_starts_with((string) $token, 'field:')) {
        $names[] = substr((string) $token, strlen('field:'));
      }
    }
    $presentation = $this->elementModel->bundlePresentation('block_content', $block_type)->toArray();
    if (!empty($presentation['edit_target'])) {
      $names[] = $presentation['edit_target'];
    }
    $texts = [];
    foreach (array_unique($names) as $name) {
      if (!$sample->hasField($name)) {
        continue;
      }
      $value = $sample->get($name)->value;
      if (is_string($value) && trim($value) !== '') {
        $texts[$name] = $value;
      }
    }
    return $texts;
  }

}
