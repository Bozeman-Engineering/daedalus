<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Recipe\InvalidConfigException;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotPlacements;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the slot roster guard: a placed slot id cannot leave the roster.
 *
 * A slot block derives per roster id, so a frame or a template placing
 * daedalus_slot:{id} needs the id rostered or the block is core's broken
 * block. The judgement (SlotPlacements::missing(), over a config storage)
 * is pinned first, then the doors that report it: typed config validation
 * of daedalus_blueprint.settings (the DaedalusSlotRosterPlaced constraint
 * on `slots`), the recipe door, a config action on the object, which core
 * validates only because the object's root is FullyValidatable, and the
 * import door, the roster subscriber's IMPORT_VALIDATE refusal over the
 * import's source storage.
 *
 * The fixture is the frame host test's: a stark frame, here placing the
 * main slot inside a container (the walk is nested-aware), and a locked
 * entity_test_rev template placing the hero slot; the roster names both.
 * The settings object comes from the module's install config, since the
 * recipe door validates the object's whole shape (its language, the
 * design system pointer and the roster).
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class SlotRosterGuardTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    'daedalus_stylish_unbound_test',
    'daedalus_blueprint_test',
  ];

  /**
   * The theme whose frame places the main slot.
   */
  protected const THEME = 'stark';

  /**
   * The config names of the two placers.
   */
  protected const FRAME = 'daedalus_blueprint.frame.' . self::THEME;
  protected const TEMPLATE = 'core.entity_view_display.entity_test_rev.entity_test_rev.default';

  /**
   * The identities of the fixture.
   */
  protected const FRAME_SECTION = '7a000000-0000-4000-8000-000000000001';
  protected const CHROME_BLOCK = '7a000000-0000-4000-8000-000000000002';
  protected const CONTAINER = '7a000000-0000-4000-8000-000000000003';
  protected const MAIN_SLOT = '7a000000-0000-4000-8000-000000000004';
  protected const TEMPLATE_SECTION = '7a000000-0000-4000-8000-000000000005';
  protected const TEMPLATE_BLOCK = '7a000000-0000-4000-8000-000000000006';
  protected const HERO_SLOT = '7a000000-0000-4000-8000-000000000007';

  /**
   * The roster entries the cases write, each slot tiered by its placer.
   *
   * The stark frame places main and the locked template places hero;
   * nothing places aside.
   */
  protected const MAIN_ENTRY = ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME];
  protected const HERO_ENTRY = ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE];
  protected const ASIDE_ENTRY = ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE];

  /**
   * The roster the fixture is built on: the frame's main, the template's hero.
   */
  protected const ROSTER = ['main' => self::MAIN_ENTRY, 'hero' => self::HERO_ENTRY];

  /**
   * The judgement.
   */
  protected SlotPlacements $placements;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // System's config for the site record core's importer insists on.
    $this->installConfig(['system', 'filter', 'block_content', 'daedalus_stylish', 'daedalus_blueprint']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('theme_installer')->install([self::THEME]);

    // The roster, on the installed object: the save rebuilds the slot
    // block definitions, so the placements below resolve to real plugins.
    $this->config(SlotBlock::CONFIG_NAME)->set(SlotBlock::ROSTER_KEY, self::ROSTER)->save();

    // The frame: a chrome block and, inside a container, the main slot.
    $frame = Frame::create(['id' => self::THEME]);
    $frame->appendSection($this->section(self::FRAME_SECTION, [
      $this->pluginBlock(self::CHROME_BLOCK, 'Chrome'),
      SectionBlock::createComponent($this->section(self::CONTAINER, [
        $this->slotBlock(self::MAIN_SLOT, 'main'),
      ]), 'content', 1),
    ]));
    $frame->save();

    // The locked template: a block of its own and the hero slot.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($this->section(self::TEMPLATE_SECTION, [
      $this->pluginBlock(self::TEMPLATE_BLOCK, 'Template block'),
      $this->slotBlock(self::HERO_SLOT, 'hero'),
    ]))->save();

    $this->placements = $this->container->get('daedalus_blueprint.slot_placements');
  }

  /**
   * A section of one column holding components, stamped with its identity.
   *
   * @param string $uuid
   *   The section's uuid (daedalus_layout's identity).
   * @param \Drupal\layout_builder\SectionComponent[] $components
   *   The components.
   */
  protected function section(string $uuid, array $components): Section {
    $section = new Section('layout_onecol', [], $components);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    return $section;
  }

  /**
   * A plugin block component.
   */
  protected function pluginBlock(string $uuid, string $label): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => 'system_powered_by_block',
      'label' => $label,
      'label_display' => 'visible',
    ]);
  }

  /**
   * A slot block component.
   */
  protected function slotBlock(string $uuid, string $slot): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':' . $slot,
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]);
  }

  /**
   * The judgement names each placed id a roster drops, with its placers.
   */
  public function testMissingNamesThePlacedIdsTheRosterDrops(): void {
    $this->assertSame([], $this->placements->missing(self::ROSTER), 'A roster naming every placed id drops nothing');
    $this->assertSame([], $this->placements->missing(self::ROSTER + ['aside' => self::ASIDE_ENTRY]), 'An unplaced id may come');
    $this->assertSame(['main' => [self::FRAME]], $this->placements->missing(['hero' => self::HERO_ENTRY]), 'The frame places main, inside its container');
    $this->assertSame(['hero' => [self::TEMPLATE]], $this->placements->missing(['main' => self::MAIN_ENTRY]), 'The template places hero');
    $this->assertSame(['main' => [self::FRAME], 'hero' => [self::TEMPLATE]], $this->placements->missing([]), 'Frames before templates');
    $this->assertSame(['main' => [self::FRAME], 'hero' => [self::TEMPLATE]], $this->placements->placed());

    // The judgement reads the placers of the storage it is handed, never
    // the roster: a copy of the active storage without the frame has no
    // placer of main, and a template with Layout Builder off places
    // nothing whatever sections it left behind.
    $copy = $this->copyOfActiveStorage();
    $copy->delete(self::FRAME);
    $this->assertSame([], $this->placements->missing(['hero' => self::HERO_ENTRY], $copy), 'No frame, no placer of main');
    $this->assertSame(['main' => [self::FRAME]], $this->placements->missing(['hero' => self::HERO_ENTRY]), 'The active storage still has the frame');
    $template = $copy->read(self::TEMPLATE);
    $template['third_party_settings']['layout_builder']['enabled'] = FALSE;
    $copy->write(self::TEMPLATE, $template);
    $this->assertSame([], $this->placements->missing([], $copy), 'A display without Layout Builder places nothing');
  }

  /**
   * Typed validation of the settings refuses a roster dropping a placed id.
   */
  public function testTypedValidationRefusesDroppingPlacedSlots(): void {
    $this->assertCount(0, $this->validate(self::ROSTER), 'The roster naming every placed id is valid');
    $this->assertCount(0, $this->validate(self::ROSTER + ['aside' => self::ASIDE_ENTRY]), 'An unplaced id is free to come');

    $violations = $this->validate(['hero' => self::HERO_ENTRY]);
    $this->assertCount(1, $violations, 'One violation for the one dropped id');
    $this->assertSame(SlotBlock::ROSTER_KEY, $violations->get(0)->getPropertyPath(), 'At the roster');
    $message = (string) $violations->get(0)->getMessage();
    $this->assertStringContainsString("'main'", $message, 'Naming the id');
    $this->assertStringContainsString(self::FRAME, $message, 'Naming its placer');

    $violations = $this->validate(['aside' => self::ASIDE_ENTRY]);
    $this->assertCount(2, $violations, 'One violation per dropped id');
    $messages = array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    $this->assertStringContainsString("'main'", $messages[0]);
    $this->assertStringContainsString(self::FRAME, $messages[0]);
    $this->assertStringContainsString("'hero'", $messages[1]);
    $this->assertStringContainsString(self::TEMPLATE, $messages[1]);
  }

  /**
   * The recipe door: a config action dropping a placed id is refused.
   *
   * Core validates a config action's result only for an object whose root
   * is FullyValidatable, so this is the pin on the marker as much as on
   * the constraint.
   */
  public function testConfigActionRefusesDroppingPlacedSlots(): void {
    $manager = $this->container->get('plugin.manager.config_action');

    // An id may come and, unplaced, go.
    $manager->applyAction('simpleConfigUpdate', SlotBlock::CONFIG_NAME, [SlotBlock::ROSTER_KEY => self::ROSTER + ['aside' => self::ASIDE_ENTRY]]);
    $this->assertSame(self::ROSTER + ['aside' => self::ASIDE_ENTRY], $this->roster());
    $manager->applyAction('simpleConfigUpdate', SlotBlock::CONFIG_NAME, [SlotBlock::ROSTER_KEY => self::ROSTER]);
    $this->assertSame(self::ROSTER, $this->roster());

    // A placed id may not.
    try {
      $manager->applyAction('simpleConfigUpdate', SlotBlock::CONFIG_NAME, [SlotBlock::ROSTER_KEY => ['hero' => self::HERO_ENTRY]]);
      $this->fail('A config action dropping the frame\'s main slot from the roster is refused');
    }
    catch (InvalidConfigException $e) {
      $this->assertCount(1, $e->violations);
      $this->assertSame(SlotBlock::ROSTER_KEY, $e->violations->get(0)->getPropertyPath());
      $this->assertStringContainsString("'main'", $e->getMessage());
      $this->assertStringContainsString(self::FRAME, $e->getMessage());
    }
    // Core's action manager applies, then validates, so the refused write
    // already stands in the active storage; the recipe stops here, and the
    // page survives on the safety net (DaedalusLayoutAdapter::declaredSlots()
    // counts no slot whose block is gone). The refusal is the recipe's,
    // not a rollback.
    $this->assertSame(['hero' => self::HERO_ENTRY], $this->roster(), 'The action manager validates after it applies');
  }

  /**
   * The import door: a config import dropping a placed id is refused.
   *
   * Core's importer validates extensions and dependencies and never a typed
   * constraint, so this door is the roster guard's own IMPORT_VALIDATE
   * subscriber: the judgement over the import's source storage, the staged
   * roster against the staged placers, each dropped id an import error in
   * the constraint's words, before anything is written.
   */
  public function testConfigImportRefusesDroppingPlacedSlots(): void {
    $this->assertSame([], $this->validateImport($this->copyOfActiveStorage()), 'The active state stages no change');

    // An id may come.
    $copy = $this->copyOfActiveStorage();
    $this->stageRoster($copy, self::ROSTER + ['aside' => self::ASIDE_ENTRY]);
    $this->assertSame([], $this->validateImport($copy), 'An unplaced id is free to come');

    // A placed id may not go.
    $copy = $this->copyOfActiveStorage();
    $this->stageRoster($copy, ['hero' => self::HERO_ENTRY]);
    $errors = $this->validateImport($copy);
    $this->assertCount(1, $errors, 'One error for the one dropped id');
    $this->assertStringContainsString("'main'", $errors[0], 'Naming the id');
    $this->assertStringContainsString(self::FRAME, $errors[0], 'Naming its placer');
    $this->assertSame((string) $this->validate(['hero' => self::HERO_ENTRY])->get(0)->getMessage(), $errors[0], 'In the constraint\'s words');
    $this->assertSame(self::ROSTER, $this->roster(), 'Refused before anything is written');

    // A staged placer is judged against the staged roster: the template
    // placing a slot the roster does not name is refused with the roster
    // untouched, and a frame leaving with the id it placed is not.
    $copy = $this->copyOfActiveStorage();
    $template = $copy->read(self::TEMPLATE);
    $template['third_party_settings']['layout_builder']['sections'][0]['components']['7a000000-0000-4000-8000-000000000008'] = $this->slotBlock('7a000000-0000-4000-8000-000000000008', 'aside')->toArray();
    $copy->write(self::TEMPLATE, $template);
    $errors = $this->validateImport($copy);
    $this->assertCount(1, $errors, 'The staged template places aside');
    $this->assertStringContainsString("'aside'", $errors[0]);
    $this->assertStringContainsString(self::TEMPLATE, $errors[0]);
    $copy = $this->copyOfActiveStorage();
    $copy->delete(self::FRAME);
    $this->stageRoster($copy, ['hero' => self::HERO_ENTRY]);
    $this->assertSame([], $this->validateImport($copy), 'No staged frame, no placer of main');

    // An import staging neither the roster nor a placer is not judged, even
    // over a roster a writer core never validates already got wrong.
    $this->config(SlotBlock::CONFIG_NAME)->set(SlotBlock::ROSTER_KEY, ['hero' => self::HERO_ENTRY])->save();
    $copy = $this->copyOfActiveStorage();
    $site = $copy->read('system.site');
    $site['name'] = 'Staged';
    $copy->write('system.site', $site);
    $this->assertSame([], $this->validateImport($copy), 'Nothing the judgement reads is staged');
  }

  /**
   * Validates the installed settings object with a roster.
   */
  protected function validate(array $roster): ConstraintViolationListInterface {
    $data = $this->config(SlotBlock::CONFIG_NAME)->getRawData();
    $data[SlotBlock::ROSTER_KEY] = $roster;
    return $this->container->get('config.typed')->createFromNameAndData(SlotBlock::CONFIG_NAME, $data)->validate();
  }

  /**
   * The active roster, read fresh.
   */
  protected function roster(): array {
    return $this->config(SlotBlock::CONFIG_NAME)->get(SlotBlock::ROSTER_KEY);
  }

  /**
   * A memory copy of the active config storage.
   */
  protected function copyOfActiveStorage(): MemoryStorage {
    $active = $this->container->get('config.storage');
    $copy = new MemoryStorage();
    foreach ($active->listAll() as $name) {
      $copy->write($name, $active->read($name));
    }
    return $copy;
  }

  /**
   * Stages a roster on a storage's settings record.
   */
  protected function stageRoster(MemoryStorage $storage, array $roster): void {
    $settings = $storage->read(SlotBlock::CONFIG_NAME);
    $settings[SlotBlock::ROSTER_KEY] = $roster;
    $storage->write(SlotBlock::CONFIG_NAME, $settings);
  }

  /**
   * Validates an import of a source storage into the active one.
   *
   * @return string[]
   *   The import errors; empty when the import validates.
   */
  protected function validateImport(MemoryStorage $source): array {
    $comparer = new StorageComparer($source, $this->container->get('config.storage'));
    $comparer->createChangelist();
    $importer = new ConfigImporter(
      $comparer,
      $this->container->get('event_dispatcher'),
      $this->container->get('config.manager'),
      $this->container->get('lock'),
      $this->container->get('config.typed'),
      $this->container->get('module_handler'),
      $this->container->get('module_installer'),
      $this->container->get('theme_handler'),
      $this->container->get('string_translation'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
    );
    try {
      $importer->validate();
    }
    catch (ConfigImporterException) {
      return array_map('strval', $importer->getErrors());
    }
    return [];
  }

}
