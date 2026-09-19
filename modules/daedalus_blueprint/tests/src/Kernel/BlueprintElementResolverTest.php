<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintElementResolver;
use Drupal\daedalus_blueprint\ResolvedElement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the unified element resolver across every element type and dispatch.
 *
 * BlueprintElementResolver is the entire implementation behind the
 * eb_read_element AI tool: it is the AI's only way to inspect a single page
 * element (a field, an inline block, a paragraph, a Layout Builder section, or
 * a region) together with the guidance it needs before editing. It is also the
 * most heavily branched untested surface in the module: resolve() first decides
 * UUID vs field-name, then routes to one of five element builders, merges YAML
 * field-type guidance over the field handler's own guidance, and gates field
 * data behind the access checker. A break anywhere here silently feeds the AI
 * an empty or wrong picture of the element it is about to change, so this test
 * pins each branch.
 *
 * It runs the real assembled Daedalus Layout daedalus_layout stack rather than
 * mocking, because section and region resolution genuinely depend on daedalus_layout:
 * sections only carry a UUID (an daedalus_layout third-party setting) when daedalus_layout is
 * installed, and ElementLocator::locateSection matches on that UUID. The
 * fixture module ships scoped context YAML (layout_onecol guidance for
 * entity_test.entity_test), which lets the test prove the
 * YAML-over-handler/derived guidance merge is wired all the way through to the
 * resolved element.
 *
 * The five scenarios live in one fat method on purpose: every kernel test
 * method reinstalls Drupal and re-enables this module stack, which is the slow
 * part of a run, and all five assertions share one fixture entity. The
 * paragraph (ERR) branch is split into its own method so it can skip gracefully
 * when entity_reference_revisions is not in the codebase, per the suite's
 * convention; the main method does not depend on it.
 *
 * It deliberately does not re-test ElementLocator (covered by
 * ElementLocatorTest) or the LayoutSerializer block/section shapes (covered by
 * LayoutSerializerTest); it asserts only the resolver's identity/type/guidance
 * decisions and that it threads serialized data through from those services.
 */
#[CoversClass(BlueprintElementResolver::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintElementResolverTest extends BlueprintKernelTestBase {

  /**
   * The element resolver under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintElementResolver
   */
  protected BlueprintElementResolver $resolver;

  /**
   * Enables the full daedalus_layout + Layout Builder stack and the fixture module.
   *
   * Lb_plus is required so sections carry a UUID; daedalus_tempstore and
   * daedalus_ui are its hard dependencies. The fixture module supplies the
   * scoped context YAML used to prove the guidance merge.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_layout',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    // The fixture's scoped context YAML is cached lazily; clear so the first
    // resolve picks up the YAML we depend on for the guidance-merge assertion.
    \Drupal::cache()->deleteAll();

    $this->resolver = $this->container->get('daedalus_blueprint.element_resolver');
  }

  /**
   * Tests field, missing-field, block, section, and region resolution.
   */
  public function testResolvesEachElementTypeAndDispatch(): void {
    // Build one entity carrying: a stock string field value, an inline block
    // in a section, and an daedalus_layout section UUID so the section is addressable.
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => '<p>Block body</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    $component_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $section_uuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $component_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Welcome Block'),
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'field_subtitle' => 'A subtitle value',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // (1) Resolve by a real field name on the root entity. The name-vs-UUID
    // dispatch must take the field branch, return a 'field' element with the
    // field type, label, and the serialized value.
    $fieldElement = $this->resolver->resolve($entity, 'field_subtitle');
    $this->assertInstanceOf(ResolvedElement::class, $fieldElement);
    $this->assertSame('field', $fieldElement->elementType);
    $this->assertSame('string', $fieldElement->typeId);
    $this->assertSame('Subtitle', $fieldElement->label);
    $this->assertSame('field_subtitle', $fieldElement->fieldName);
    $this->assertSame('A subtitle value', $fieldElement->data);

    // (2) A field name that does not exist resolves to NULL, so the tool can
    // report "no such element" rather than fabricate one.
    $this->assertNull($this->resolver->resolve($entity, 'field_nope'));

    // (3) Resolve by the component UUID. The UUID pattern routes to
    // resolveByUuid -> buildBlockElement, returning a 'block' element with the
    // inline block type, its admin label, and the serialized component data
    // (proving it threads through serializeElement).
    $blockElement = $this->resolver->resolve($entity, $component_uuid);
    $this->assertInstanceOf(ResolvedElement::class, $blockElement);
    $this->assertSame('block', $blockElement->elementType);
    $this->assertSame('text_section', $blockElement->typeId);
    $this->assertSame('Welcome Block', $blockElement->label);
    $this->assertSame($component_uuid, $blockElement->uuid);
    $this->assertIsArray($blockElement->data);
    $this->assertSame('text_section', $blockElement->data['block_type']);
    $this->assertArrayHasKey('block_fields', $blockElement->data);

    // (4) Resolve by the section UUID. resolveByUuid -> buildSectionElement
    // returns a 'section' element keyed by the layout id, and carries the
    // layout guidance from the fixture's scoped context YAML, proving the
    // YAML-over-handler/derived guidance merge reaches the resolved element.
    $sectionElement = $this->resolver->resolve($entity, $section_uuid);
    $this->assertInstanceOf(ResolvedElement::class, $sectionElement);
    $this->assertSame('section', $sectionElement->elementType);
    $this->assertSame('layout_onecol', $sectionElement->typeId);
    $this->assertSame($section_uuid, $sectionElement->uuid);
    $this->assertSame(
      'Scoped one-column guidance for entity_test.',
      $sectionElement->guidance,
    );

    // (5) Resolve a region by name with the section UUID as parent. This goes
    // through resolveByName -> resolveNamedOnParent -> buildRegionElement and
    // lists the region's elements. An unknown region on the same section
    // returns NULL.
    $regionElement = $this->resolver->resolve($entity, 'content', $section_uuid);
    $this->assertInstanceOf(ResolvedElement::class, $regionElement);
    $this->assertSame('region', $regionElement->elementType);
    $this->assertSame('content', $regionElement->regionName);
    $this->assertSame($section_uuid, $regionElement->parentUuid);
    $this->assertIsArray($regionElement->data);
    $elementUuids = array_column($regionElement->data['elements'], 'uuid');
    $this->assertContains($component_uuid, $elementUuids);

    $this->assertNull($this->resolver->resolve($entity, 'no_such_region', $section_uuid));
  }

  /**
   * Tests YAML field-type guidance overrides the handler's own guidance.
   *
   * The text handler returns its own guidance for a text_long field. The
   * fixture's hook-driven context override supplies a field_types[text_long]
   * guidance string. The resolver must return the YAML/hook value, not the
   * handler value: that precedence is what lets a site tailor the AI's
   * field-format instructions per bundle without rewriting a handler.
   */
  public function testYamlFieldTypeGuidanceWinsOverHandler(): void {
    // Confirm the handler actually has guidance, so "YAML wins" is meaningful.
    $handlerGuidance = $this->container
      ->get('daedalus_blueprint.field_handler_manager')
      ->getHandler($this->createTextLongDefinition())
      ->getGuidance();
    $this->assertNotNull($handlerGuidance);

    \Drupal::state()->set('daedalus_blueprint_test.context', [
      'field_types' => [
        'text_long' => ['guidance' => 'OVERRIDE: write a single short paragraph.'],
      ],
    ]);
    \Drupal::cache()->deleteAll();
    $resolver = $this->container->get('daedalus_blueprint.element_resolver');

    $entity = $this->createTestEntity([
      'field_description' => ['value' => 'Some text', 'format' => 'plain_text'],
    ]);

    $element = $resolver->resolve($entity, 'field_description');
    $this->assertInstanceOf(ResolvedElement::class, $element);
    $this->assertSame('text_long', $element->typeId);
    $this->assertSame('OVERRIDE: write a single short paragraph.', $element->guidance);
    $this->assertNotSame($handlerGuidance, $element->guidance);
  }

  /**
   * Tests paragraph resolution by UUID (skips when ERR is unavailable).
   *
   * The paragraph branch (resolveByUuid -> buildParagraphElement) only exists
   * for entity_reference_revisions fields, which require the contrib ERR
   * module. That module is not installed here, so the test skips rather than
   * asserting against an absent code path.
   */
  public function testResolvesParagraphByUuid(): void {
    $this->enableEntityReferenceRevisions();
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $resolver = $this->container->get('daedalus_blueprint.element_resolver');

    $child = $this->createRevEntity([
      'name' => 'Child paragraph',
      'field_text' => 'Paragraph text',
    ]);
    $entity = $this->createTestEntity([
      'field_paragraphs' => [['entity' => $child]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $element = $resolver->resolve($entity, $child->uuid());
    $this->assertInstanceOf(ResolvedElement::class, $element);
    $this->assertSame('paragraph', $element->elementType);
    $this->assertSame('entity_test_rev', $element->typeId);
    $this->assertSame($child->uuid(), $element->uuid);
    $this->assertIsArray($element->data);
  }

  /**
   * Builds a text_long field definition for the handler-guidance assertion.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The field_description (text_long) definition on entity_test.
   */
  protected function createTextLongDefinition(): FieldDefinitionInterface {
    return $this->entityTypeManager
      ->getStorage('field_config')
      ->load('entity_test.entity_test.field_description');
  }

}
