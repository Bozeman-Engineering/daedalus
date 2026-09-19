<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the empty-container drop affordance on the one-box container.
 *
 * Emptiness is a root form: the declaration's {empty: true} forms carry
 * the `data-container-empty` marker as a declared attribute, so the ONE
 * emptiness fact prints on the container root in every mode (harmless
 * live — the affordance CSS keys on the hint, not the marker). The
 * translated hint (`data-container-empty-hint`, "Empty container") is
 * stamped by the composed render in edit mode only, on the same root —
 * the container is one box, so <section> page band and nested <div> carry
 * it alike. The affordance CSS rides the edit-mode asset bundle
 * (daedalus_stylish_layouts_library_info_alter), so live renders never load it.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class EmptyContainerAffordanceTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
  ];

  /**
   * The edit-only hint attribute the composed render stamps on the root.
   */
  protected const HINT_ATTRIBUTE = 'data-container-empty-hint';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Builds an entity with an empty container at each depth.
   *
   * Two root sections: an EMPTY container, and a populated container whose
   * only component embeds an EMPTY nested container (an empty root cannot
   * host a nested section, so each depth needs its own empty specimen; the
   * populated root doubles as the no-affordance control).
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string, 3: string}
   *   The entity, the empty root section uuid, the populated root section
   *   uuid, the empty nested section uuid.
   */
  protected function entityWithEmptyContainers(): array {
    $empty_root = $this->createSection('daedalus_stylish_container');
    $empty_nested = $this->createSection('daedalus_stylish_container');
    $populated_root = $this->createSection('daedalus_stylish_container', [
      SectionBlock::createComponent($empty_nested, 'content'),
    ]);
    $entity = $this->createTestEntity([$empty_root, $populated_root]);
    return [
      $entity,
      $empty_root->getThirdPartySetting('daedalus_layout', 'uuid'),
      $populated_root->getThirdPartySetting('daedalus_layout', 'uuid'),
      $empty_nested->getThirdPartySetting('daedalus_layout', 'uuid'),
    ];
  }

  /**
   * Edit mode stamps the hint on the one root at each depth, only there.
   */
  public function testEditModeStampsHintPerDepth(): void {
    $this->setUpCurrentUser([], ['configure any layout', 'view test entity', 'use daedalus ui edit mode']);
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    [$entity, $empty_root_uuid, $populated_root_uuid, $empty_nested_uuid] = $this->entityWithEmptyContainers();

    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $xpath = $this->htmlXpath($html);

    // ROOT depth: the empty <section> root wears both the declared marker
    // and the hint — one box, so the fact and its dressing share the
    // element the editor selects.
    $root = $xpath->query(sprintf('//*[@data-selectable="%s"]', SelectableKey::container($empty_root_uuid)));
    $this->assertSame(1, $root->length, 'The empty root container renders.');
    $this->assertSame('section', $root->item(0)->nodeName);
    $this->assertTrue($root->item(0)->hasAttribute('data-container-empty'), 'The empty form\'s declared marker prints.');
    $this->assertSame('Empty container', $root->item(0)->getAttribute(self::HINT_ATTRIBUTE), 'At root depth the hint lands on the section root.');

    // NESTED depth: the empty <div> form, marker and hint on the same
    // one element.
    $nested = $xpath->query(sprintf('//*[@data-selectable="%s"]', SelectableKey::container($empty_nested_uuid)));
    $this->assertSame(1, $nested->length, 'The empty nested container renders.');
    $this->assertSame('div', $nested->item(0)->nodeName);
    $this->assertTrue($nested->item(0)->hasAttribute('data-container-empty'));
    $this->assertSame('Empty container', $nested->item(0)->getAttribute(self::HINT_ATTRIBUTE), 'At nested depth the hint lands on the div root.');

    // The populated container matched a populated form: no marker, no
    // hint, and exactly the two empty roots carry the hint page-wide.
    $populated = $xpath->query(sprintf('//*[@data-selectable="%s"]', SelectableKey::container($populated_root_uuid)));
    $this->assertSame(1, $populated->length);
    $this->assertFalse($populated->item(0)->hasAttribute('data-container-empty'));
    $this->assertFalse($populated->item(0)->hasAttribute(self::HINT_ATTRIBUTE));
    $this->assertSame(2, substr_count($html, self::HINT_ATTRIBUTE), 'Exactly the two empty roots carry the hint.');
  }

  /**
   * Live renders keep the declared marker but no hint and no library.
   */
  public function testLiveRenderCarriesNoAffordance(): void {
    $this->setUpCurrentUser([], ['view test entity']);
    [$entity] = $this->entityWithEmptyContainers();

    $build = $this->entityTypeManager->getViewBuilder('entity_test')->view($entity, 'default');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    // The marker is a declared attribute — the one resolver-answered fact,
    // printed in every mode — but the edit-only hint (what the affordance
    // CSS keys on) never stamps, so the affordance cannot paint.
    $this->assertStringContainsString('data-container-empty', $html);
    $this->assertStringNotContainsString(self::HINT_ATTRIBUTE, $html);
    $this->assertNotContains('daedalus_stylish_layouts/edit_affordances', $build['#attached']['library'] ?? []);
  }

  /**
   * The affordance CSS rides the edit-mode asset bundle.
   *
   * The library attach re-homed when the container preprocess died with
   * the template: daedalus_ui's composed render stamps the hint but
   * cannot attach a daedalus_stylish library (dependency direction), so
   * daedalus_stylish_layouts joins its CSS to daedalus_ui/edit_mode — loaded
   * exactly when hints can exist, and already on the page when a
   * container is emptied mid-session.
   */
  public function testAffordanceLibraryRidesEditMode(): void {
    $library = $this->container->get('library.discovery')->getLibraryByName('daedalus_ui', 'edit_mode');
    $dependencies = $library['dependencies'] ?? [];
    $this->assertContains('daedalus_stylish_layouts/edit_affordances', $dependencies);
  }

  /**
   * Parses rendered HTML for querying.
   */
  protected function htmlXpath(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new \DOMXPath($dom);
  }

}
