<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Regression test: a layout-scoped root variant survives placement.
 *
 * The shipped CTA blueprint's exact shape: a container-rooted blueprint
 * whose root wears a variant scoped to its own layout
 * ("layout:layout_twocol_section"; under D, a section's declaration IS its
 * layout's declaration at every depth). add_section births the section as
 * the blueprint's layout_id, so the root apply_variant matches from the
 * first op. The expansion ordering contract (the whole subtree's
 * structural ops first, then the style stream in tree order) still
 * matters: a composition variant's slot paths bind over the staged
 * subtree once, so the whole tree must exist before the first style op
 * runs.
 *
 * Everything real is in play: daedalus_stylish's actual apply_variant
 * handler and scope capture, daedalus_layout sections, and the spine's
 * batch ordering. The blueprint is a CONTENT blueprint (its document
 * instantiated the way a capture writes it) so no file fixture is
 * needed; the same expansion serves both lanes.
 */
#[CoversClass(BlueprintOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintLayoutScopedVariantTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);

    // The variant the blueprint's root wears, scoped to the blueprint's
    // OWN layout (the shipped cta_columns shape). It can only apply to a
    // section that already IS that layout.
    DaedalusStylishVariant::create([
      'label' => 'Panel',
      'machine_name' => 'panel',
      'scope' => 'layout:layout_twocol_section',
    ])->save();
  }

  /**
   * A layout-scoped root variant applies when the blueprint is placed.
   */
  public function testLayoutScopedRootVariantApplies(): void {
    $this->createBlueprintFromTree([
      'label' => 'Panel CTA',
      'machine_name' => 'panel_cta',
      'description' => 'A section carrying a layout-scoped root variant.',
    ], [
      [
        'kind' => 'container',
        'layout_id' => 'layout_twocol_section',
        'styles' => ['' => ['variants' => ['panel']]],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [['kind' => 'block', 'block_type' => 'basic']],
          ],
        ],
      ],
    ]);

    ['entity' => $entity] = $this->createHost();

    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'panel_cta',
        'uuid' => $root,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // Non-vacuous: the placed root's captured scope IS its layout, the ONE
    // resolution rule (a section's declaration is its layout's
    // declaration). With the section still on the birth layout the
    // assertion above would have failed with STYLE_VARIANT_SCOPE_MISMATCH;
    // this pins the reason.
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertSame('layout:layout_twocol_section', (string) $validator->captureScope("container:$root", ['host' => $this->host($entity)]));

    $list = $entity->get('layout_builder__layout');
    assert($list instanceof SectionListInterface);
    $this->assertSame('layout_twocol_section', $list->getSection(1)->getLayoutId());

    // The reference landed in the host's style map through
    // daedalus_stylish's real apply_variant handler, in its canonical
    // structured form (a '' fragment is the root wearing).
    $map = json_decode((string) $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame(['variants' => [['variant' => 'panel', 'fragment' => '']]], $map["container:$root"]);
  }

}
