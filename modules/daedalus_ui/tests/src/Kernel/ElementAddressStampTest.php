<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the element address stamp: data-element on every addressable root.
 *
 * The address is the element's identity in the design system, the
 * declaration it resolved through ({family}:{id}, see ElementAddress),
 * stamped UNCONDITIONALLY: in a visitor's render with no permission and
 * no demand, and in an editor's render beside the selectable key. Each
 * shape stamps at its own seam and nowhere else: a Layout Builder
 * placement at the block build (a plugin block by its base plugin, an
 * entity-backed block by its bundle), a section at its render array (root
 * and nested, by its layout), a field at its template, an entity root at
 * its view.
 *
 * @see \Drupal\daedalus_ui\Render\ElementAddress
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ElementAddressStampTest extends NestedStorageKernelTestBase {

  /**
   * The plugin block placement's component uuid.
   */
  private const PLUGIN_BLOCK = 'bbbbbbbb-1111-2222-3333-444444444444';

  /**
   * The field block placement's component uuid.
   */
  private const FIELD_BLOCK = 'aaaaaaaa-1111-2222-3333-444444444444';

  /**
   * The inline block placement's component uuid.
   */
  private const INLINE_BLOCK = 'cccccccc-1111-2222-3333-444444444444';

  /**
   * Builds an entity whose root section nests a section of every shape.
   *
   * The root section (onecol) holds a plugin block and a nested twocol
   * section; the nested section holds the name field block and an inline
   * basic block.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string}
   *   The entity, the root section uuid, the nested section uuid.
   */
  protected function entityWithEveryShape(): array {
    $nested = $this->createSection('layout_twocol_section', [
      new SectionComponent(self::FIELD_BLOCK, 'first', [
        'id' => 'field_block:entity_test:entity_test:name',
        'label' => 'Name',
        'label_display' => '0',
        'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      ]),
      $this->createBasicBlockComponent(self::INLINE_BLOCK, 'second'),
    ]);
    $root = $this->createSection('layout_onecol', [
      new SectionComponent(self::PLUGIN_BLOCK, 'content', [
        'id' => 'system_powered_by_block',
        'label' => 'Powered by',
        'label_display' => '0',
      ]),
      $this->createNestedSectionComponent($nested, 'content', 1),
    ]);
    $entity = $this->createTestEntity([$root]);
    $entity->set('name', 'Addressed name')->save();
    return [
      $entity,
      $root->getThirdPartySetting('daedalus_layout', 'uuid'),
      $nested->getThirdPartySetting('daedalus_layout', 'uuid'),
    ];
  }

  /**
   * The one element wearing an address, by the address.
   */
  private function addressed(\DOMXPath $xpath, string $address): \DOMElement {
    $nodes = $xpath->query(sprintf('//*[@%s="%s"]', ElementAddress::ATTRIBUTE, $address));
    $this->assertSame(1, $nodes->length, "Exactly one element wears $address in: " . $xpath->document->saveHTML());
    return $nodes->item(0);
  }

  /**
   * Asserts every shape's address on a render, whatever the mode.
   */
  private function assertAddresses(string $html): void {
    $xpath = $this->htmlXpath($html);

    // Sections: their layout, at root depth and nested alike.
    $root = $this->addressed($xpath, ElementAddress::layout('layout_onecol'));
    $nested = $this->addressed($xpath, ElementAddress::layout('layout_twocol_section'));
    $this->assertSame(1, $xpath->query(sprintf('.//*[@%s="%s"]', ElementAddress::ATTRIBUTE, ElementAddress::layout('layout_twocol_section')), $root)->length, 'The nested section sits inside the root section.');

    // Blocks: a plugin block by its base plugin, an entity-backed block by
    // its bundle, the field block placement by its base plugin too.
    $this->addressed($xpath, ElementAddress::blockPlugin('system_powered_by_block'));
    $inline = $this->addressed($xpath, ElementAddress::blockType('basic'));
    $field_block = $this->addressed($xpath, ElementAddress::blockPlugin('field_block:entity_test:entity_test:name'));
    $this->assertSame('block_plugin:field_block', $field_block->getAttribute(ElementAddress::ATTRIBUTE), 'The derivative suffix strips to the base plugin.');
    $this->assertSame(1, $xpath->query(sprintf('.//*[@%s="%s"]', ElementAddress::ATTRIBUTE, ElementAddress::blockType('basic')), $nested)->length, 'The inline block sits inside the nested section.');
    $this->assertStringNotContainsString('block:' . self::INLINE_BLOCK, $inline->getAttribute(ElementAddress::ATTRIBUTE), 'The address is a declaration, never an instance key.');

    // Fields: the name field's root inside the field block placement.
    $this->assertSame(1, $xpath->query(sprintf('.//*[@%s="%s"]', ElementAddress::ATTRIBUTE, ElementAddress::FIELD), $field_block)->length, 'The field root inside the placement wears the field kind.');

    // Nothing wears a defaults scope class: the address is the one stamp.
    $this->assertStringNotContainsString('daedalus-stylish-defaults--', $html);
  }

  /**
   * A visitor's render carries every address with no permission and no demand.
   */
  public function testVisitorRenderCarriesEveryAddress(): void {
    $this->setUpCurrentUser([], ['view test entity']);
    [$entity] = $this->entityWithEveryShape();

    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('data-selectable', $html, 'No selection identity rides a visitor\'s render without demand.');
    $this->assertAddresses($html);
    // The entity root's address rides the entity build's own attributes
    // (entity_test prints no template, so the bag is asserted at the seam).
    $this->assertSame(ElementAddress::ENTITY, $build['#attributes'][ElementAddress::ATTRIBUTE] ?? NULL, 'The entity root wears the entity kind in a visitor\'s render.');
  }

  /**
   * An editor's render carries every address beside the selectable keys.
   */
  public function testEditRenderCarriesEveryAddress(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    [$entity, $root_uuid, $nested_uuid] = $this->entityWithEveryShape();

    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertAddresses($html);
    $this->assertSame(ElementAddress::ENTITY, $build['#attributes'][ElementAddress::ATTRIBUTE] ?? NULL);

    // The same elements carry the selectable keys: one root per element,
    // both stamps on it.
    $xpath = $this->htmlXpath($html);
    $this->assertSame("container:$root_uuid", $this->addressed($xpath, ElementAddress::layout('layout_onecol'))->getAttribute('data-selectable'));
    $this->assertSame("container:$nested_uuid", $this->addressed($xpath, ElementAddress::layout('layout_twocol_section'))->getAttribute('data-selectable'));
    $this->assertSame('block:' . self::PLUGIN_BLOCK, $this->addressed($xpath, ElementAddress::blockPlugin('system_powered_by_block'))->getAttribute('data-selectable'));
    $this->assertSame('block:' . self::INLINE_BLOCK, $this->addressed($xpath, ElementAddress::blockType('basic'))->getAttribute('data-selectable'));
    $this->assertStringNotContainsString('data-block-type', $html, 'The retired edit-only type stamp is gone.');
  }

}
