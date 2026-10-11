<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_ui\Render\BlockStandIn;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stand-in a block that built nothing wears on the editing canvas.
 *
 * The editing posture (mode edit, core's preview flag on the section
 * render) gives every placed block a body: core bypasses block access and
 * prints its placeholder sentence for an empty build. daedalus_ui's
 * LayoutBuilderBlockAttributes replaces that sentence with BlockStandIn
 * (the label and a reason) and stamps the reason on the block root beside
 * the selectable key, keeping core's label attribute. A block with
 * content keeps its content; the visitor posture (any other mode) is
 * untouched, because core drops an empty or forbidden block there before
 * the subscriber sees a build.
 *
 * @see \Drupal\daedalus_ui\EventSubscriber\LayoutBuilderBlockAttributes
 * @see \Drupal\daedalus_ui\Render\BlockStandIn
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class BlockStandInTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_ui_stand_in_test'];

  /**
   * The placed components, keyed by leg.
   *
   * @var array<string, \Drupal\layout_builder\SectionComponent>
   */
  protected array $components;

  /**
   * Builds an entity whose root section holds the three legs.
   *
   * An empty block anyone may see, an empty block forbidden to everyone,
   * and core's Powered by block, which builds markup for anyone.
   */
  protected function entityWithThreeBlocks(): EntityTest {
    $this->components = [
      'empty' => new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'daedalus_ui_stand_in_test_empty',
        'label' => 'Footer menu',
        'label_display' => '0',
      ]),
      'forbidden' => new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'daedalus_ui_stand_in_test_forbidden',
        'label' => 'User login',
        'label_display' => '0',
      ]),
      'filled' => new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'system_powered_by_block',
        'label' => 'Powered by',
        'label_display' => '0',
      ]),
    ];
    $section = $this->createSection('layout_onecol', array_values($this->components));
    return $this->createTestEntity([$section]);
  }

  /**
   * The block root element a leg's placement rendered, by its selectable key.
   */
  protected function blockRoot(\DOMXPath $xpath, string $leg): \DOMElement {
    $key = SelectableKey::block($this->components[$leg]->getUuid());
    $nodes = $xpath->query(sprintf('//*[@data-selectable="%s"]', $key));
    $this->assertSame(1, $nodes->length, sprintf('The %s block renders one keyed root.', $leg));
    return $nodes->item(0);
  }

  /**
   * The editing posture prints the stand-in for an empty build, reason stamped.
   */
  public function testEditingPostureWearsTheStandIn(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    $entity = $this->entityWithThreeBlocks();

    // The editing posture is the canvas's section render with core's
    // preview flag (LayoutBuilderPlus::buildAdministrativeSection); a
    // kernel render of the entity has no route to derive the host from,
    // so the posture is taken at the section seam, as the nested stamping
    // test's preview leg does.
    $storage = $this->getNestedStorage($entity);
    $contexts = [
      'layout_builder.entity' => EntityContext::fromEntity($entity),
      NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID => EntityContext::fromEntity($entity),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ];
    $build = $storage->getSection(0)->toRenderArray($contexts, TRUE);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    // Core's raw sentence never reaches the canvas.
    $this->assertStringNotContainsString('Placeholder for the', $html);

    // The empty block: the stand-in with the placement's label and the
    // EMPTY reason, the reason stamped on the root beside the selectable
    // key, core's label attribute kept.
    $empty = $this->blockRoot($xpath, 'empty');
    $this->assertSame(BlockStandIn::EMPTY, $empty->getAttribute(BlockStandIn::ATTRIBUTE));
    $this->assertTrue($empty->hasAttribute('data-layout-content-preview-placeholder-label'));
    $stand_in = $xpath->query(sprintf('.//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', BlockStandIn::CLASS_NAME), $empty);
    $this->assertSame(1, $stand_in->length, 'The empty block carries one stand-in.');
    $this->assertStringContainsString('Footer menu', $stand_in->item(0)->textContent);
    $this->assertStringContainsString('Nothing to show here yet', $stand_in->item(0)->textContent);
    $this->assertStringNotContainsString('Shown to visitors only', $stand_in->item(0)->textContent);

    // The forbidden block: the same stand-in with the VISITORS_ONLY reason,
    // from the block's own access answer for the editor.
    $forbidden = $this->blockRoot($xpath, 'forbidden');
    $this->assertSame(BlockStandIn::VISITORS_ONLY, $forbidden->getAttribute(BlockStandIn::ATTRIBUTE));
    $this->assertTrue($forbidden->hasAttribute('data-layout-content-preview-placeholder-label'));
    $stand_in = $xpath->query(sprintf('.//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', BlockStandIn::CLASS_NAME), $forbidden);
    $this->assertSame(1, $stand_in->length, 'The forbidden block carries one stand-in.');
    $this->assertStringContainsString('User login', $stand_in->item(0)->textContent);
    $this->assertStringContainsString('Shown to visitors only', $stand_in->item(0)->textContent);

    // A block with content keeps its content: no stand-in, no reason.
    $filled = $this->blockRoot($xpath, 'filled');
    $this->assertFalse($filled->hasAttribute(BlockStandIn::ATTRIBUTE));
    $this->assertTrue($filled->hasAttribute('data-layout-content-preview-placeholder-label'));
    $this->assertSame(0, $xpath->query(sprintf('.//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', BlockStandIn::CLASS_NAME), $filled)->length);
    $this->assertStringContainsString('Powered by', $filled->textContent);
  }

  /**
   * The visitor posture is unchanged: core drops the blocks, nothing stamps.
   *
   * The entity's own view render, the preview flag off: the display's
   * buildSections, what every mode but edit renders.
   */
  public function testVisitorPostureIsUnchanged(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    $entity = $this->entityWithThreeBlocks();

    $html = $this->renderEntity($entity);

    $this->assertStringNotContainsString(BlockStandIn::ATTRIBUTE, $html);
    $this->assertStringNotContainsString(BlockStandIn::CLASS_NAME, $html);
    $this->assertStringNotContainsString('data-layout-content-preview-placeholder-label', $html);
    $this->assertStringNotContainsString('Placeholder for the', $html);
    $this->assertStringNotContainsString(SelectableKey::block($this->components['empty']->getUuid()), $html);
    $this->assertStringNotContainsString(SelectableKey::block($this->components['forbidden']->getUuid()), $html);
    $this->assertStringContainsString(SelectableKey::block($this->components['filled']->getUuid()), $html);
    $this->assertStringContainsString('Powered by', $html);
  }

}
