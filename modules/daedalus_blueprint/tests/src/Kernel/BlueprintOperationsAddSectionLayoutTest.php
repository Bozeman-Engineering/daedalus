<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\layout_builder\Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests add_section's explicit-layout door and its error grammar.
 *
 * A section is born with its real layout: without an explicit layout the
 * default ladder mints a container; an explicit layout names a named-slot
 * layout (or pins layout settings) at birth. change_section_layout is
 * retired — sections keep their layout for life — so this covers the
 * birth-layout validation the old op used to own, plus the retired-op
 * corrective. Nested happy paths (section UUID addressing needs daedalus_layout)
 * live in daedalus_layout's AddSectionExplicitLayoutTest.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsAddSectionLayoutTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Creates an entity with one section holding one inline block.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, with component 'faf95c53-35e7-74e1-184e-041152031df4'
   *   in the 'content' region.
   */
  protected function createLbEntity() {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 1',
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [
        $this->createSection('layout_onecol', [
          [
            'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
            'region' => 'content',
            'weight' => 0,
            'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block 1'),
          ],
        ]),
      ],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Tests an explicit layout is the section's birth layout.
   */
  public function testExplicitLayoutAtBirth(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      'layout_twocol_section',
      ['column_widths' => '33-67'],
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertSame('layout_twocol_section', $section->getLayoutId());
    // The provided settings patched over the plugin defaults.
    $this->assertSame('33-67', $section->getLayoutSettings()['column_widths']);
  }

  /**
   * Tests a root add_section onto a blank placeholder leaves one real section.
   *
   * Core seeds layout_builder_blank onto a fresh override and clears it on
   * the first insert; the spine's root append rides that path, so the page
   * never holds the placeholder beside its first section.
   */
  public function testFirstSectionReplacesBlankPlaceholder(): void {
    $entity = $this->createLbEntity();
    $entity->set('layout_builder__layout', [new Section('layout_builder_blank')]);
    $entity->save();

    $result = $this->operations->addSection($this->host($entity), ['anchor' => $entity->uuid(), 'placement' => 'inside']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertNotSame('layout_builder_blank', $sections[0]->getLayoutId());
  }

  /**
   * Tests an unknown layout plugin ID errors.
   */
  public function testUnknownLayoutRejected(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      'layout_bogus',
    );

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertEquals('INVALID_LAYOUT', $error->getCode());
    // The corrective context carries the legal set with spatial column
    // counts, mirroring the schema's available_layouts vocabulary.
    $available = $error->getContext()['available'];
    $ids = array_column($available, 'id');
    $this->assertContains('layout_onecol', $ids);
    $onecol = $available[array_search('layout_onecol', $ids, TRUE)];
    $this->assertSame(1, $onecol['columns']);
    $twocol = $available[array_search('layout_twocol_section', $ids, TRUE)];
    $this->assertSame(2, $twocol['columns']);
  }

  /**
   * Tests an empty layout plugin ID errors.
   */
  public function testEmptyLayoutRejected(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      '',
    );

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_LAYOUT', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests list-shaped layout settings error.
   */
  public function testListLayoutSettingsRejected(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      'layout_twocol_section',
      ['33-67'],
    );

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_LAYOUT_SETTINGS', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests layout settings without an explicit layout error.
   *
   * Settings belong to a plugin; the ladder's pick is the spine's business,
   * so an implicit add carrying settings is a grammar error, not a silent
   * patch onto whatever layout the ladder minted.
   */
  public function testLayoutSettingsRequireExplicitLayout(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      NULL,
      ['column_widths' => '33-67'],
    );

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_LAYOUT_SETTINGS', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests the batch dispatch wires the layout parameters through.
   */
  public function testAddSectionLayoutViaBatch(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'layout' => 'layout_twocol_section',
        'layout_settings' => ['column_widths' => '33-67'],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertSame('layout_twocol_section', $section->getLayoutId());
    $this->assertSame('33-67', $section->getLayoutSettings()['column_widths']);
  }

  /**
   * Tests the blueprint parameter stamps provenance at birth.
   *
   * Provenance is a birth fact: the root create op carries the source
   * blueprint ID into the section's third-party settings at creation, exactly
   * as uuid carries identity — never a post-batch fixup.
   */
  public function testBlueprintProvenanceAtBirth(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      'layout_twocol_section',
      [],
      'hero_band',
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertSame('hero_band', $section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));

    // A section that is not a blueprint placement carries no stamp.
    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      'layout_twocol_section',
    );
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertNull($section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));
  }

  /**
   * Tests the batch dispatch wires the blueprint parameter through.
   */
  public function testBlueprintProvenanceViaBatch(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'layout' => 'layout_onecol',
        'blueprint' => 'hero_band',
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertSame('hero_band', $section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'));
  }

  /**
   * Tests an empty blueprint ID errors.
   */
  public function testEmptyBlueprintRejected(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addSection(
      $this->host($entity),
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      NULL,
      NULL,
      [],
      '',
    );

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_BLUEPRINT', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests the default ladder prefers a container layout when one exists.
   *
   * With a container-flagged layout installed (eb_test_container), an
   * implicit add mints it — "add_section mints containers" — even though
   * other layouts sort first alphabetically and no default_section is
   * configured.
   */
  public function testDefaultLadderPrefersContainer(): void {
    $entity = $this->createLbEntity();
    $this->enableModules(['daedalus_blueprint_test']);
    $this->operations = $this->container->get('daedalus_blueprint.operations');

    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $entity->get('layout_builder__layout')->getSections();
    $section = end($sections);
    $this->assertSame('eb_test_container', $section->getLayoutId());
  }

  /**
   * Tests the retired change_section_layout op fails with a corrective.
   *
   * The op is vocabulary an older client or model may still speak; the
   * refusal points at the birth-layout grammar instead of a generic
   * unknown-operation shrug.
   */
  public function testChangeSectionLayoutRetired(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'change_section_layout',
        'element' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'layout' => 'layout_twocol_section',
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertEquals('INVALID_OPERATION', $error->getCode());
    $this->assertStringContainsString('retired', $error->getMessage());
    $this->assertStringContainsString('add_section', $error->getHint());
    $this->assertNotContains('change_section_layout', $error->getContext()['valid_operations']);
  }

}
