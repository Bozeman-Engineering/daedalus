<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Slot;

use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Plugin\Derivative\SlotDeriver;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The slot roster: every slot id, its label and the tier declaring it.
 *
 * The roster is a mapping of `{label, tier}` per id in
 * daedalus_blueprint.settings, read through one service (SlotRoster) by
 * the deriver, the offer and the verdict. These pins cover the reader
 * (entries, the tier of an id, the ids of a tier), the shipped roster
 * (`main`, the frame's), the deriver reading it, and the schema refusing
 * an entry with no tier or a tier that is neither frame nor template.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(SlotRoster::class)]
#[CoversClass(SlotDeriver::class)]
#[Group('daedalus_blueprint')]
class SlotRosterTest extends BlueprintKernelTestBase {

  /**
   * A roster of two frame slots and two template slots.
   */
  protected const ROSTER = [
    FrameHost::MAIN_SLOT => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
    'header' => ['label' => 'Header', 'tier' => SlotRoster::TIER_FRAME],
    'content' => ['label' => 'Content', 'tier' => SlotRoster::TIER_TEMPLATE],
    'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
  ];

  /**
   * The roster service.
   */
  protected function roster(): SlotRoster {
    return $this->container->get('daedalus_blueprint.slot_roster');
  }

  /**
   * Writes the roster, as the recipe or a site builder does.
   */
  protected function writeRoster(array $slots): void {
    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, $slots)
      ->save();
  }

  /**
   * The reader: entries, the tier of an id, the ids of a tier.
   */
  public function testReadsTheRoster(): void {
    $this->writeRoster(self::ROSTER);
    $roster = $this->roster();

    $this->assertSame(self::ROSTER, $roster->entries());
    $this->assertSame(SlotRoster::TIER_FRAME, $roster->tier(FrameHost::MAIN_SLOT));
    $this->assertSame(SlotRoster::TIER_FRAME, $roster->tier('header'));
    $this->assertSame(SlotRoster::TIER_TEMPLATE, $roster->tier('content'));
    $this->assertNull($roster->tier('aside'), 'An id the roster does not name has no tier.');
    $this->assertSame([FrameHost::MAIN_SLOT, 'header'], $roster->ids(SlotRoster::TIER_FRAME));
    $this->assertSame(['content', 'hero'], $roster->ids(SlotRoster::TIER_TEMPLATE));

    // A roster save is read at once.
    $this->writeRoster(['hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_FRAME]]);
    $this->assertSame(['hero'], $roster->ids(SlotRoster::TIER_FRAME));
    $this->assertSame([], $roster->ids(SlotRoster::TIER_TEMPLATE));
    $this->assertNull($roster->tier('content'));
  }

  /**
   * The module ships `main` alone, the frame's slot for the route's output.
   */
  public function testShippedRoster(): void {
    $this->installConfig(['daedalus_blueprint']);
    $roster = $this->roster();
    $this->assertSame([FrameHost::MAIN_SLOT => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME]], $roster->entries());
    $this->assertSame([FrameHost::MAIN_SLOT], $roster->ids(SlotRoster::TIER_FRAME));
    $this->assertSame([], $roster->ids(SlotRoster::TIER_TEMPLATE));
  }

  /**
   * The deriver reads the roster: one slot block per id, named "<label> slot".
   */
  public function testDeriverReadsTheRoster(): void {
    $this->enableModules(['block']);
    $this->writeRoster(self::ROSTER);
    $definitions = $this->container->get('plugin.manager.block')->getDefinitions();
    foreach (self::ROSTER as $id => $entry) {
      $plugin_id = SlotBlock::PLUGIN_ID . ':' . $id;
      $this->assertArrayHasKey($plugin_id, $definitions, "A slot block derives for $id whatever its tier.");
      $this->assertSame($entry['label'] . ' slot', (string) $definitions[$plugin_id]['admin_label'], 'The block list names the slot by its roster label.');
    }
    $this->assertArrayNotHasKey(SlotBlock::PLUGIN_ID . ':aside', $definitions, 'No block for an id the roster does not name.');
  }

  /**
   * The schema: a tier is required and is frame or template.
   */
  public function testRosterValidation(): void {
    $this->assertSame([], $this->violations(self::ROSTER), 'A roster of labelled, tiered entries is valid.');
    $this->assertSame(
      ['slots.hero' => "'tier' is a required key."],
      $this->violations(['hero' => ['label' => 'Hero']]),
      'An entry with no tier is refused.',
    );
    $this->assertSame(
      ['slots.hero.tier' => 'The value you selected is not a valid choice.'],
      $this->violations(['hero' => ['label' => 'Hero', 'tier' => 'page']]),
      'A page never declares a slot: the tier is frame or template.',
    );
  }

  /**
   * The constraint violations of the settings config holding a roster.
   *
   * @return array<string, string>
   *   Property path to message.
   */
  protected function violations(array $slots): array {
    $violations = [];
    // The roster's labels are translatable, so the config object names its
    // language, as the shipped config does.
    $data = ['langcode' => 'en', 'active_design_system' => NULL, SlotBlock::ROSTER_KEY => $slots];
    foreach ($this->container->get('config.typed')->createFromNameAndData(SlotBlock::CONFIG_NAME, $data)->validate() as $violation) {
      $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
    }
    return $violations;
  }

}
