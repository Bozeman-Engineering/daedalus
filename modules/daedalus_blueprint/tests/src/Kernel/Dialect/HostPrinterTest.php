<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\DialectPrint;
use Drupal\daedalus_blueprint\Dialect\DialectPrintNoteKind;
use Drupal\daedalus_blueprint\Dialect\HostPrinter;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The AI's page print names each slot door and what it shows.
 *
 * A door prints as a bare <pb-block plugin="daedalus_slot:{id}" /> and
 * the document behind it is no node of the printed host, so HostPrinter
 * reads the spine's showing (VerdictStamper::showing(), the printed host
 * as the canvas) and adds one note per door: what it shows and, for a
 * blueprint the actor may update, where it is edited. The reading notes
 * carry those lines after the grammar's teaching sentences; the grammar
 * itself is unchanged.
 */
#[CoversClass(HostPrinter::class)]
#[CoversClass(DialectPrint::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class HostPrinterTest extends BlueprintKernelTestBase {

  /**
   * The template's hero door, defaulting to the header blueprint.
   */
  protected string $heroDoor;

  /**
   * The template's aside door, empty.
   */
  protected string $asideDoor;

  /**
   * The frame's top door, defaulting to the header blueprint.
   */
  protected string $topDoor;

  /**
   * The frame's main slot.
   */
  protected string $mainSlot;

  /**
   * The frame's linked placement of the header blueprint.
   */
  protected string $linked;

  /**
   * The header blueprint.
   */
  protected Blueprint $header;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      'daedalus_layout',
      'options',
      'image',
      'media',
      'daedalus_blueprint_library',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['block_content', 'image', 'file', 'media', 'daedalus_blueprint_library']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->createBlockContentType('basic', 'Basic');
    $this->setUpCurrentUser([], [], TRUE);
    $this->enableLayoutBuilder();

    $this->container->get('config.factory')->getEditable(SlotBlock::CONFIG_NAME)
      ->set(SlotBlock::ROSTER_KEY, [
        'hero' => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
        'aside' => ['label' => 'Aside', 'tier' => SlotRoster::TIER_TEMPLATE],
        'main' => ['label' => 'Main', 'tier' => SlotRoster::TIER_FRAME],
        'top' => ['label' => 'Top', 'tier' => SlotRoster::TIER_FRAME],
      ])
      ->save();
    $this->header = $this->blueprint('header_bp', 'Header');
    $uuids = new PhpUuid();

    // The locked bundle's template: a hero door showing the header, an
    // empty aside door.
    $this->heroDoor = $uuids->generate();
    $this->asideDoor = $uuids->generate();
    $template = $this->createSection('layout_onecol', [
      [
        'uuid' => $uuids->generate(),
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Template block'],
      ],
      [
        'uuid' => $this->heroDoor,
        'configuration' => [SlotBlock::DEFAULT_KEY => 'header_bp'] + $this->slotConfiguration('hero'),
      ],
      [
        'uuid' => $this->asideDoor,
        'configuration' => $this->slotConfiguration('aside'),
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $uuids->generate());
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->removeAllSections()->appendSection($template)->save();

    // The frame: a top door showing the header, main, a linked header.
    $this->container->get('theme_installer')->install(['stark']);
    $this->container->get('theme.manager')->setActiveTheme($this->container->get('theme.initialization')->getActiveThemeByName('stark'));
    $this->topDoor = $uuids->generate();
    $this->mainSlot = $uuids->generate();
    $this->linked = $uuids->generate();
    $frame_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $this->topDoor,
        'configuration' => [SlotBlock::DEFAULT_KEY => 'header_bp'] + $this->slotConfiguration('top'),
      ],
      ['uuid' => $this->mainSlot, 'configuration' => $this->slotConfiguration(FrameHost::MAIN_SLOT)],
      [
        'uuid' => $this->linked,
        'configuration' => [
          'id' => LinkedBlueprintBlock::pluginId('header_bp'),
          'label' => 'Header',
          'label_display' => '0',
          'context_mapping' => LinkedBlueprintBlock::CONTEXT_MAPPING,
        ],
      ],
    ]);
    $frame_section->setThirdPartySetting('daedalus_layout', 'uuid', $uuids->generate());
    $frame = Frame::create(['id' => 'stark']);
    $frame->appendSection($frame_section);
    $frame->save();
  }

  /**
   * A slot block's configuration, as the site builder places one.
   */
  protected function slotConfiguration(string $slot): array {
    return [
      'id' => SlotBlock::PLUGIN_ID . ':' . $slot,
      'label' => ucfirst($slot),
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ];
  }

  /**
   * A published blueprint a door can default to.
   */
  protected function blueprint(string $machine_name, string $label): Blueprint {
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => (new PhpUuid())->generate(),
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Blueprint block'],
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', (new PhpUuid())->generate());
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => 'A default for a slot.',
      'scope' => 'block',
      'subject_type' => 'entity_test_rev',
      'subject_bundle' => 'entity_test_rev',
      'status' => 1,
      ContentHost::LAYOUT_FIELD => [$section],
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * The template's host.
   */
  protected function template(): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(
      $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test_rev.entity_test_rev.default'),
    );
  }

  /**
   * The frame's host.
   */
  protected function frame(): HostInterface {
    return $this->container->get('daedalus_blueprint.host_resolver')->fromEntity(
      $this->entityTypeManager->getStorage(Frame::ENTITY_TYPE_ID)->loadUnchanged('stark'),
    );
  }

  /**
   * Prints a host through the service under test.
   */
  protected function print(HostInterface $host): DialectPrint {
    return $this->container->get('daedalus_blueprint.host_printer')->print($host, '');
  }

  /**
   * The showing notes of a print, element id to detail, in print order.
   *
   * @return array<string, string>
   *   The door uuid to the note's detail.
   */
  protected function doorNotes(DialectPrint $print): array {
    $notes = [];
    foreach ($print->notes as $note) {
      if ($note->kind === DialectPrintNoteKind::Showing) {
        $notes[$note->elementId] = $note->detail;
      }
    }
    return $notes;
  }

  /**
   * The frame's print names its doors: the header by reference, the page.
   *
   * The top door shows the header blueprint with its canvas as the edit
   * url (the admin may update it); main hosts the route, and on the
   * frame's own canvas no page is the route, so it "shows the page". The
   * linked placement is a join too but no door: it prints as itself and
   * earns no line. The lines close the reading notes, which head the
   * text once; the text itself spells the doors as before.
   */
  public function testFrameDoorsAreNamed(): void {
    $print = $this->print($this->frame());
    $edit = $this->header->toUrl()->toString();
    $this->assertMatchesRegularExpression('#^/blueprint/\d+$#', $edit, "The blueprint's canvas is its canonical route.");

    $this->assertSame([
      $this->topDoor => sprintf("slot top (#%s) shows blueprint 'Header' (edit: %s)", $this->topDoor, $edit),
      $this->mainSlot => sprintf('slot main (#%s) shows the page', $this->mainSlot),
    ], $this->doorNotes($print), 'One note per door, in print order; the linked placement earns none.');
    $this->assertFalse($print->isAuthored(), 'A print naming its doors is not a write template.');

    $notes = $print->readingNotes();
    $this->assertStringStartsWith("Reading notes:\n", $notes);
    $this->assertStringEndsWith(
      sprintf("\n- slot top (#%s) shows blueprint 'Header' (edit: %s)\n- slot main (#%s) shows the page", $this->topDoor, $edit, $this->mainSlot),
      $notes,
      'The door lines close the reading notes, after the grammar sentences.',
    );
    $reader = $print->forReader();
    $this->assertStringStartsWith($notes . "\n\n", $reader, 'The notes head the text once, a blank line between.');
    $this->assertSame(1, substr_count($reader, 'Reading notes:'));
    $this->assertMatchesRegularExpression(
      '/\n  <pb-block id="' . preg_quote($this->topDoor, '/') . '"( data-can="[^"]*")? plugin="daedalus_slot:top" \/>\n/',
      $print->text,
      'The door prints as the bare slot block it was: the grammar is unchanged.',
    );
    $this->assertStringNotContainsString('Header', $print->text, 'What the door shows is told, never printed.');
  }

  /**
   * A template's and a locked page's doors: default, nothing, fill, access.
   *
   * On the template's canvas the hero door shows the header (editable by
   * the admin) and the aside door nothing. On a locked page the template's
   * doors print inside the template's sections with the template as their
   * holder: a hero section of the page's own fills the hero door, so it
   * shows that <pb-slot> section, and aside still shows nothing. An
   * actor who may not update the header is told what the door shows but
   * not where it is edited.
   */
  public function testTemplateAndLockedPageDoors(): void {
    $edit = $this->header->toUrl()->toString();
    $this->assertSame([
      $this->heroDoor => sprintf("slot hero (#%s) shows blueprint 'Header' (edit: %s)", $this->heroDoor, $edit),
      $this->asideDoor => sprintf('slot aside (#%s) shows nothing', $this->asideDoor),
    ], $this->doorNotes($this->print($this->template())));

    $fill = $this->createSection('layout_onecol', [
      [
        'uuid' => (new PhpUuid())->generate(),
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Own block'],
      ],
    ]);
    $fill->setThirdPartySetting('daedalus_layout', 'uuid', (new PhpUuid())->generate());
    $fill->setThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING, 'hero');
    $page = $this->host($this->createRevEntity(['name' => 'Locked', ContentHost::LAYOUT_FIELD => [$fill]]));
    $print = $this->print($page);
    $this->assertSame([
      $this->heroDoor => sprintf("slot hero (#%s) shows the <pb-slot name=\"hero\"> section of 'Locked'", $this->heroDoor),
      $this->asideDoor => sprintf('slot aside (#%s) shows nothing', $this->asideDoor),
    ], $this->doorNotes($print), "The template's doors are read with the template as holder; the page's fill is what the hero door shows.");
    $this->assertMatchesRegularExpression('/\n<pb-slot id="[^"]*"[^>]* name="hero">\n/', $print->text, 'The fill prints as the slot section the note names.');

    $unfilled = $this->host($this->createRevEntity(['name' => 'Plain']));
    $this->setUpCurrentUser([], ['administer entity_test content', 'configure any layout']);
    $this->assertSame([
      $this->heroDoor => sprintf("slot hero (#%s) shows blueprint 'Header'", $this->heroDoor),
      $this->asideDoor => sprintf('slot aside (#%s) shows nothing', $this->asideDoor),
    ], $this->doorNotes($this->print($unfilled)), 'No portal for an actor who may not update the blueprint: no edit url.');
  }

}
