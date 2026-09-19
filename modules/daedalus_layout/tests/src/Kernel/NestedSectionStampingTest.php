<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests Tier-A stamping at the container render seam.
 *
 * A nested section is an ordinary section whose identity stamps at its OWN
 * root at every depth (collapse translation is dead): the section selectable
 * key, its regions' keys, and the local delta the editing JS reads —
 * rendered by NestedSectionRenderArray with no block sandwich and no
 * block-key aliasing. Emptiness is NOT an daedalus_layout stamp: it is a root-form
 * fact the element resolver answers ({empty: true}) and the composed
 * container render prints (daedalus_ui ComposedLayoutRenderTest locks
 * that side).
 *
 * @see \Drupal\daedalus_layout\EventSubscriber\NestedSectionRenderArray
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class NestedSectionStampingTest extends NestedStorageKernelTestBase {

  /**
   * Builds an entity with two nested sections inside its root section.
   *
   * The first nested section (twocol) holds a name field block in "first"
   * with "second" left empty; the second nested section (onecol) has no
   * components at all, locking that an empty section still renders its own
   * attributed root with no daedalus_layout emptiness stamp.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string, 3: string}
   *   The entity, the root section uuid, the populated nested section uuid,
   *   the empty nested section uuid.
   */
  protected function entityWithNestedSection(): array {
    $nested = $this->createSection('layout_twocol_section', [
      new SectionComponent($this->uuid->generate(), 'first', [
        'id' => 'field_block:entity_test:entity_test:name',
        'label' => 'Name',
        'label_display' => '0',
        'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      ]),
    ]);
    $empty = $this->createSection('layout_onecol');
    $root = $this->createSection('layout_onecol', [
      SectionBlock::createComponent($nested, 'content'),
      SectionBlock::createComponent($empty, 'content', 1),
    ]);
    $entity = $this->createTestEntity([$root]);
    // The nested field block needs real content: a field block with no value
    // renders nothing to assert on.
    $entity->set('name', 'Nested sentinel')->save();
    return [
      $entity,
      $root->getThirdPartySetting('daedalus_layout', 'uuid'),
      $nested->getThirdPartySetting('daedalus_layout', 'uuid'),
      $empty->getThirdPartySetting('daedalus_layout', 'uuid'),
    ];
  }

  /**
   * Edit mode stamps the nested section's full identity at its own root.
   */
  public function testEditModeStampsNestedSectionIdentity(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    [$entity, $root_uuid, $nested_uuid, $empty_uuid] = $this->entityWithNestedSection();

    $html = $this->renderEntity($entity);
    $xpath = $this->htmlXpath($html);

    // The nested section element carries its own selectable key, the local
    // delta (the container's one-section list — core-form URLs built from
    // it are recomputed by uuid in the NestedRouteEnhancer), and no storage
    // type/id attributes (those describe the root storage; the JS reads
    // them from drupalSettings).
    $section = $xpath->query(sprintf('//*[@data-selectable="%s"]', SelectableKey::container($nested_uuid)));
    $this->assertSame(1, $section->length, 'The nested section stamps its own selectable key.');
    $this->assertSame('0', $section->item(0)->getAttribute('data-layout-builder-section-delta'));
    $this->assertFalse($section->item(0)->hasAttribute('data-layout-builder-section-storage-type'));

    // Regions carry NO selectable keys (they are not addressable elements
    // under the container model), but the region NAME still stamps for the
    // core-form URLs. Empty regions are no longer seeded: emptiness is a
    // section-root fact, and not an daedalus_layout one at all.
    $this->assertStringNotContainsString('data-selectable="region:', $html);
    $first = $xpath->query('//*[@region="first"]');
    $this->assertSame(1, $first->length, 'The populated nested region stamps its name.');
    $this->assertStringNotContainsString('data-region-empty', $html);

    // An empty section still renders its own attributed root, and daedalus_layout
    // stamps NO emptiness fact anywhere: emptiness is the element
    // resolver's {empty: true} root-form answer, printed by the composed
    // container render via the declaration's own attributes — never a
    // second stamp at this seam.
    $empty_section = $xpath->query(sprintf('//*[@data-selectable="%s"]', SelectableKey::container($empty_uuid)));
    $this->assertSame(1, $empty_section->length, 'The empty nested section renders its own root.');
    $this->assertStringNotContainsString('data-container-empty', $html);

    // One identity: the container never renders as a block element. No
    // block selectable key, no block uuid attribute, no block sandwich.
    $this->assertStringNotContainsString(SelectableKey::block($nested_uuid), $html);
    $this->assertStringNotContainsString(sprintf('data-layout-builder-block-uuid="%s"', $nested_uuid), $html);

    // The root section still stamps its own identity around it.
    $this->assertStringContainsString(SelectableKey::container($root_uuid), $html);
  }

  /**
   * Live renders without demand stay byte-clean: no keys, no emptiness stamp.
   */
  public function testLiveRenderStampsNoKeysWithoutDemand(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity']);
    [$entity, , $nested_uuid] = $this->entityWithNestedSection();

    $html = $this->renderEntity($entity);
    $this->assertStringNotContainsString('data-selectable', $html);
    $this->assertStringNotContainsString('data-region-empty', $html);
    $this->assertStringNotContainsString('data-container-empty', $html);
    // The admin delta attribute is static config and stamps in every mode,
    // exactly as root sections always have.
    $this->assertStringContainsString('data-layout-builder-section-delta', $html);
    // The name field block rendered at depth (context forwarding intact).
    $this->assertStringContainsString((string) $entity->label(), $html);
    $this->assertStringNotContainsString($nested_uuid, $html);
  }

  /**
   * The event's preview flag propagates into the embedded section's builds.
   *
   * The canvas renders root sections with $in_preview = TRUE; the container
   * seam must hand that flag to the embedded section's own component builds
   * (core's builder then stamps its preview placeholder label on them), or
   * nested blocks render live-shaped inside an edit preview.
   */
  public function testPreviewShapePropagatesIntoEmbeddedSections(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    [$entity] = $this->entityWithNestedSection();

    $storage = $this->getNestedStorage($entity);
    $contexts = [
      'layout_builder.entity' => EntityContext::fromEntity($entity),
      NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID => EntityContext::fromEntity($entity),
      'view_mode' => new Context(new ContextDefinition('string'), 'default'),
    ];
    $build = $storage->getSection(0)->toRenderArray($contexts, TRUE);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    // The nested field block carries core's preview attribute, which only
    // the preview branch of the component builder stamps.
    $this->assertStringContainsString('data-layout-content-preview-placeholder-label', $html);
  }

}
