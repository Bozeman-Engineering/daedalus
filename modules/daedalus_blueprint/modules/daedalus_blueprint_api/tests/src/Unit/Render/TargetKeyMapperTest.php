<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Unit\Render;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\ElementContext;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_blueprint_api\Render\TargetKeyMapper;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the target-to-selectable-key mapping rules.
 */
#[CoversClass(TargetKeyMapper::class)]
#[Group('daedalus_blueprint')]
class TargetKeyMapperTest extends UnitTestCase {

  protected const ROOT_UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
  protected const COMPONENT_UUID = 'bbbbbbbb-1111-2222-3333-444444444444';
  protected const PARAGRAPH_UUID = 'cccccccc-1111-2222-3333-444444444444';
  protected const SECTION_UUID = 'dddddddd-1111-2222-3333-444444444444';

  /**
   * The root entity.
   *
   * @var \Drupal\Core\Entity\FieldableEntityInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  protected FieldableEntityInterface $rootEntity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->rootEntity = $this->createMock(FieldableEntityInterface::class);
    $this->rootEntity->method('uuid')->willReturn(self::ROOT_UUID);
  }

  /**
   * Builds a mapper whose locator resolves the given contexts by UUID.
   *
   * @param array<string, \Drupal\daedalus_blueprint\ElementContext|null> $contexts
   *   Contexts keyed by UUID; unlisted UUIDs resolve to NULL.
   * @param string|null $section_uuid
   *   What the daedalus_layout adapter reports as the section UUID.
   *
   * @return \Drupal\daedalus_blueprint_api\Render\TargetKeyMapper
   *   The mapper under test.
   */
  protected function buildMapper(array $contexts = [], ?string $section_uuid = NULL): TargetKeyMapper {
    $locator = $this->createMock(ElementLocator::class);
    $locator->method('locate')->willReturnCallback(
      fn(FieldableEntityInterface $entity, string $uuid) => $contexts[$uuid] ?? NULL,
    );
    $adapter = $this->createMock(DaedalusLayoutAdapter::class);
    $adapter->method('getSectionUuid')->willReturn($section_uuid);
    return new TargetKeyMapper($locator, $adapter);
  }

  /**
   * A field change on the root entity maps to its field key.
   */
  public function testRootFieldMapsToFieldKey(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      ['uuid' => self::ROOT_UUID, 'field_name' => 'field_subtitle'],
    ]);
    $this->assertSame([SelectableKey::field(self::ROOT_UUID, 'field_subtitle')], $keys);
  }

  /**
   * A sentinel on the root entity falls back to the entity key.
   */
  public function testRootSentinelFallsBackToEntityKey(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      ['uuid' => self::ROOT_UUID, 'field_name' => TargetType::RESTRUCTURED],
    ]);
    $this->assertSame([SelectableKey::entity(self::ROOT_UUID)], $keys);
  }

  /**
   * A string target is already a selectable key and passes through verbatim.
   *
   * A style write names the styled element's selectable key directly (there
   * is no {uuid, field_name} model target for a style-map entry), so the
   * mapper must not touch it; deduplication against mapped keys still
   * applies.
   */
  public function testStringTargetPassesThroughVerbatim(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      'container:' . self::SECTION_UUID,
      ['uuid' => self::ROOT_UUID, 'field_name' => 'field_subtitle'],
      'container:' . self::SECTION_UUID,
    ]);
    $this->assertSame([
      'container:' . self::SECTION_UUID,
      SelectableKey::field(self::ROOT_UUID, 'field_subtitle'),
    ], $keys);
  }

  /**
   * An unlocatable UUID (e.g. a removed component) falls back.
   */
  public function testUnlocatableUuidFallsBackToEntityKey(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      ['uuid' => self::COMPONENT_UUID, 'field_name' => TargetType::REMOVED],
    ]);
    $this->assertSame([SelectableKey::entity(self::ROOT_UUID)], $keys);
  }

  /**
   * A field change on an inline block maps to the block field key.
   */
  public function testLbComponentFieldMapsToBlockFieldKey(): void {
    $mapper = $this->buildMapper([
      self::COMPONENT_UUID => $this->lbElementContext(),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::COMPONENT_UUID, 'field_name' => 'field_body'],
    ]);
    $this->assertSame([SelectableKey::blockField(self::COMPONENT_UUID, 'field_body')], $keys);
  }

  /**
   * A block field that appeared or disappeared re-renders the block root.
   *
   * Beside the field's own target, which the renderer's missing-subtree
   * fallback would also degrade to the block root; the sentinel makes the
   * containing subtree the answer whether the field filled or emptied.
   */
  public function testLbComponentPresenceMapsToBlockKey(): void {
    $mapper = $this->buildMapper([
      self::COMPONENT_UUID => $this->lbElementContext(),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::COMPONENT_UUID, 'field_name' => 'field_body'],
      ['uuid' => self::COMPONENT_UUID, 'field_name' => TargetType::PRESENCE],
    ]);
    $this->assertSame([
      SelectableKey::blockField(self::COMPONENT_UUID, 'field_body'),
      SelectableKey::block(self::COMPONENT_UUID),
    ], $keys);
  }

  /**
   * A root field that appeared or disappeared re-renders the whole entity.
   */
  public function testRootPresenceFallsBackToEntityKey(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      ['uuid' => self::ROOT_UUID, 'field_name' => 'field_subtitle'],
      ['uuid' => self::ROOT_UUID, 'field_name' => TargetType::PRESENCE],
    ]);
    $this->assertSame([
      SelectableKey::field(self::ROOT_UUID, 'field_subtitle'),
      SelectableKey::entity(self::ROOT_UUID),
    ], $keys);
  }

  /**
   * An added component re-renders its container when the section has a UUID.
   */
  public function testAddedLbComponentMapsToContainerKey(): void {
    $mapper = $this->buildMapper(
      [self::COMPONENT_UUID => $this->lbElementContext()],
      self::SECTION_UUID,
    );
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::COMPONENT_UUID, 'field_name' => TargetType::ADDED],
    ]);
    $this->assertSame([SelectableKey::container(self::SECTION_UUID)], $keys);
  }

  /**
   * Without daedalus_layout section UUIDs, an added component falls back.
   */
  public function testAddedLbComponentWithoutSectionUuidFallsBack(): void {
    $mapper = $this->buildMapper([
      self::COMPONENT_UUID => $this->lbElementContext(),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::COMPONENT_UUID, 'field_name' => TargetType::ADDED],
    ]);
    $this->assertSame([SelectableKey::entity(self::ROOT_UUID)], $keys);
  }

  /**
   * A field change on a paragraph maps to the paragraph's field key.
   */
  public function testParagraphFieldMapsToFieldKey(): void {
    $mapper = $this->buildMapper([
      self::PARAGRAPH_UUID => $this->paragraphContext($this->rootEntity),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::PARAGRAPH_UUID, 'field_name' => 'field_text'],
    ]);
    $this->assertSame([SelectableKey::field(self::PARAGRAPH_UUID, 'field_text')], $keys);
  }

  /**
   * An added paragraph re-renders the host field on the root entity.
   */
  public function testAddedParagraphMapsToHostFieldKey(): void {
    $mapper = $this->buildMapper([
      self::PARAGRAPH_UUID => $this->paragraphContext($this->rootEntity),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::PARAGRAPH_UUID, 'field_name' => TargetType::ADDED],
    ]);
    $this->assertSame([SelectableKey::field(self::ROOT_UUID, 'field_paragraphs')], $keys);
  }

  /**
   * A paragraph added under a non-root host falls back to the entity key.
   */
  public function testAddedParagraphUnderNestedHostFallsBack(): void {
    $nested_host = $this->createMock(FieldableEntityInterface::class);
    $nested_host->method('uuid')->willReturn('eeeeeeee-1111-2222-3333-444444444444');
    $mapper = $this->buildMapper([
      self::PARAGRAPH_UUID => $this->paragraphContext($nested_host),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::PARAGRAPH_UUID, 'field_name' => TargetType::ADDED],
    ]);
    $this->assertSame([SelectableKey::entity(self::ROOT_UUID)], $keys);
  }

  /**
   * A restructured section maps to its section key.
   */
  public function testSectionRestructureMapsToSectionKey(): void {
    $mapper = $this->buildMapper([
      self::SECTION_UUID => new ElementContext(
        type: ElementContext::TYPE_SECTION,
        target: new Section('layout_onecol'),
        parentEntity: $this->rootEntity,
        path: 'layout_builder__layout.0',
      ),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::SECTION_UUID, 'field_name' => TargetType::RESTRUCTURED],
    ]);
    $this->assertSame([SelectableKey::container(self::SECTION_UUID)], $keys);
  }

  /**
   * A section's own properties (update_element on a section) map to its key.
   *
   * Layout settings (column widths and the like) and the label render
   * inside the container, so the container is the subtree to swap, never
   * the whole entity.
   */
  public function testSectionPropertyMapsToSectionKey(): void {
    $mapper = $this->buildMapper([
      self::SECTION_UUID => new ElementContext(
        type: ElementContext::TYPE_SECTION,
        target: new Section('layout_twocol_section'),
        parentEntity: $this->rootEntity,
        path: 'layout_builder__layout.0',
      ),
    ]);
    $keys = $mapper->map($this->rootEntity, [
      ['uuid' => self::SECTION_UUID, 'field_name' => 'layout_settings'],
      ['uuid' => self::SECTION_UUID, 'field_name' => 'label'],
    ]);
    $this->assertSame([SelectableKey::container(self::SECTION_UUID)], $keys);
  }

  /**
   * Duplicate keys collapse: many targets can share one subtree.
   */
  public function testKeysAreDeduplicated(): void {
    $keys = $this->buildMapper()->map($this->rootEntity, [
      ['uuid' => self::ROOT_UUID, 'field_name' => 'field_subtitle'],
      ['uuid' => self::ROOT_UUID, 'field_name' => 'field_subtitle'],
      ['uuid' => self::ROOT_UUID, 'field_name' => TargetType::RESTRUCTURED],
      ['uuid' => self::COMPONENT_UUID, 'field_name' => TargetType::REMOVED],
    ]);
    $this->assertSame([
      SelectableKey::field(self::ROOT_UUID, 'field_subtitle'),
      SelectableKey::entity(self::ROOT_UUID),
    ], $keys);
  }

  /**
   * Builds an LB component context in the 'content' region.
   */
  protected function lbElementContext(): ElementContext {
    return new ElementContext(
      type: ElementContext::TYPE_LB_COMPONENT,
      target: new SectionComponent(self::COMPONENT_UUID, 'content', ['id' => 'system_powered_by_block']),
      parentEntity: $this->rootEntity,
      path: 'layout_builder__layout.0',
      section: new Section('layout_onecol'),
      sectionIndex: 0,
    );
  }

  /**
   * Builds a paragraph context hosted on the given parent's field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $parent
   *   The host entity.
   */
  protected function paragraphContext(FieldableEntityInterface $parent): ElementContext {
    $paragraph = $this->createMock(FieldableEntityInterface::class);
    $paragraph->method('uuid')->willReturn(self::PARAGRAPH_UUID);
    return new ElementContext(
      type: ElementContext::TYPE_PARAGRAPH,
      target: $paragraph,
      parentEntity: $parent,
      path: 'field_paragraphs.0',
      fieldName: 'field_paragraphs',
      delta: 0,
    );
  }

}
