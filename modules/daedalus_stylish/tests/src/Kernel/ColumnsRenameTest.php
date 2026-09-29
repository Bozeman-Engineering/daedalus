<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_layout\ComponentTransform\HostEntityWalker;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\ShippedDefinitionsImporter;
use Drupal\daedalus_stylish_layouts\ContainerMigration;
use Drupal\entity_test\Entity\EntityTestMulRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the one-shot rename of the shipped Columns variant (row → columns).
 *
 * The real post-update runs against the real shipped set (the module
 * installed, so `columns` synced on install) over the three stores a
 * machine name lives in: host style maps across revisions (a direct
 * wearing and one a composition stamped "via", both keeping their place
 * and their provenance), stored stencils (the bare-name list, at depth)
 * and variant styles across revisions (a composition nesting the name; a
 * shipped-unmodified one is left to the sync lane). Then the legacy
 * shipped `row` entity retires, `columns` stands shipped and unmodified,
 * the same sync lands the real Row under the freed `row` name, and a
 * second walk rewrites nothing (the retire step is never rerun: the
 * post-update is a one-shot precisely because `row` is minted again).
 * Translations ride the same walk by construction (the map field is
 * untranslatable and HostEntityWalker's own suite covers translated
 * layout fields), so the fixture stays monolingual. A modified shipped
 * `row` is kept, the retirement's report says so.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ColumnsRenameTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
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
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    // The unbound site: the shipped Columns gap is a token reference the
    // suite's bindings would refuse on a site that mints nothing.
    'daedalus_stylish_unbound_test',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'file',
    'image',
    'media',
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_mulrev');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('stencil');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_animation_usage']);
    // The importer switches to an administrative account (shipped variants
    // are code, not user input); give the switcher one to find.
    $this->setUpCurrentUser([], [], TRUE);
    // The install syncs the shipped set: `columns` lands here.
    \Drupal::service('module_installer')->install(['daedalus_stylish_layouts']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_mulrev', 'entity_test_mulrev');
    // A revisionable host: saving an overridable display auto-creates the
    // layout_builder__layout field on entity_test_mulrev.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_mulrev',
      'bundle' => 'entity_test_mulrev',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Loads a variant by machine name, bypassing access.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariantInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariantInterface);
    return $variant;
  }

  /**
   * Devolves the installed site to the pre-rename state: `row`, no `columns`.
   *
   * The install synced `columns` and the real Row (`row`); a site that
   * installed before the rename carries the Columns disk source under
   * `row` instead and no Row at all (the roster refuses both at once: two
   * variants on one axis cannot spell "columns"), so both landed entities
   * go and the legacy one is minted with the Columns styles. It reads
   * shipped and, unless the test tweaks it, unmodified.
   */
  protected function devolveToLegacyRow(?array $modified_styles = NULL): DaedalusStylishVariantInterface {
    $columns = $this->loadVariant(ContainerMigration::COLUMNS_VARIANT);
    $styles = $columns->getStyles();
    $columns->delete();
    $this->loadVariant('row')->delete();
    $row = DaedalusStylishVariant::create([
      'label' => 'Columns',
      'machine_name' => 'row',
      'scope' => 'kind:container',
      'axis' => ContainerMigration::CONTAINER_LAYOUT_AXIS,
      'status' => TRUE,
    ]);
    $row->setStyles($modified_styles ?? $styles);
    $row->setShippedSource('daedalus_stylish_layouts', $styles);
    $row->save();
    return $row;
  }

  /**
   * A container section carrying a daedalus_layout uuid.
   */
  protected function containerSection(string $uuid): Section {
    $section = new Section(ContainerMigration::TARGET_LAYOUT);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    return $section;
  }

  /**
   * Stages a host revision with a map exactly as given (a syncing save).
   */
  protected function stageMap(EntityTestMulRev $host, array $map): void {
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map, JSON_UNESCAPED_SLASHES));
    $host->setSyncing(TRUE);
    $host->save();
  }

  /**
   * Reads a host revision's style map.
   */
  protected function revisionMap(int|string $revision_id): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrev');
    $storage->resetCache();
    /** @var \Drupal\entity_test\Entity\EntityTestMulRev $revision */
    $revision = $storage->loadRevision($revision_id);
    $item = $revision->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Runs the rename post-update to completion, batch by batch.
   */
  protected function runPostUpdate(): array {
    $path = $this->container->get('extension.list.module')->getPath('daedalus_stylish_layouts');
    require_once $path . '/daedalus_stylish_layouts.post_update.php';
    $sandbox = [];
    do {
      daedalus_stylish_layouts_post_update_rename_row_to_columns($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->container->get('entity_type.manager')->getStorage('entity_test_mulrev')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('stencil')->resetCache();
    return $sandbox;
  }

  /**
   * The post-update repoints every store and retires the legacy entity.
   */
  public function testPostUpdateRenamesEveryStore(): void {
    $this->devolveToLegacyRow();
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');

    // A user composition nesting the old name on its slot, two revisions.
    $card = DaedalusStylishVariant::create(['label' => 'Card Left', 'machine_name' => 'card_left', 'scope' => 'kind:container']);
    $card->setStyles([
      '' => ['default' => ['padding' => '2rem']],
      'container(1)' => ['variants' => [['variant' => 'row', 'fragment' => '']], 'default' => ['margin' => '1px']],
    ])->save();
    $card_revision_1 = $card->getRevisionId();
    $card->setStyles([
      '' => ['default' => ['padding' => '3rem']],
      'container(1)' => ['variants' => [['variant' => 'row', 'fragment' => '']], 'default' => ['margin' => '2px']],
    ]);
    $card->setNewRevision(TRUE);
    $card->save();
    $card_revision_2 = $card->getRevisionId();

    // A shipped-unmodified composition from another provider nesting the
    // old name: the sync lane's, never rewritten in place.
    $shipped_styles = ['container(1)' => ['variants' => [['variant' => 'row', 'fragment' => '']]]];
    $shipped = DaedalusStylishVariant::create(['label' => 'Card Shipped', 'machine_name' => 'card_shipped', 'scope' => 'kind:container']);
    $shipped->setStyles($shipped_styles);
    $shipped->setShippedSource('cs_stylish', $shipped_styles);
    $shipped->save();

    // A stored stencil naming it at depth, in the current children grammar.
    $stencil = Stencil::create([
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'A card.',
      'status' => TRUE,
    ]);
    $stencil->setElement([
      'kind' => 'container',
      'layout_id' => ContainerMigration::TARGET_LAYOUT,
      'label' => 'Card',
      'styles' => ['' => ['variants' => ['card_left']]],
      'children' => [
        [
          'kind' => 'container',
          'layout_id' => ContainerMigration::TARGET_LAYOUT,
          'label' => 'Card Wrapper',
          'styles' => ['' => ['variants' => ['container_full', 'row']]],
          'children' => [
            ['kind' => 'block', 'block_type' => 'text', 'block_fields' => ['info' => 'Text']],
          ],
        ],
      ],
    ]);
    $stencil->save();

    // Host A, two revisions: the band wears the old name directly beside
    // its width, the nested container wears it via the composition.
    $uuid = $this->container->get('uuid');
    $band = $uuid->generate();
    $nested = $uuid->generate();
    $host = EntityTestMulRev::create([
      'name' => 'Host A',
      OverridesSectionStorage::FIELD_NAME => [$this->containerSection($band), $this->containerSection($nested)],
    ]);
    $this->stageMap($host, [
      "container:$band" => ['variants' => [['variant' => 'container_site', 'fragment' => ''], ['variant' => 'row', 'fragment' => '']]],
      "container:$nested" => ['variants' => [['variant' => 'row', 'fragment' => '', 'via' => 'card_left'], ['variant' => 'card_left', 'fragment' => 'container(1)']]],
    ]);
    $revision_1 = $host->getRevisionId();
    $host_storage = $this->container->get('entity_type.manager')->getStorage('entity_test_mulrev');
    /** @var \Drupal\entity_test\Entity\EntityTestMulRev $revision_2 */
    $revision_2 = $host_storage->createRevision($host);
    $this->stageMap($revision_2, [
      "container:$band" => ['variants' => [['variant' => 'row', 'fragment' => '']], 'default' => ['padding-top' => '2rem']],
      "container:$nested" => ['default' => ['gap' => '1rem']],
    ]);
    $revision_2_id = $revision_2->getRevisionId();
    $this->assertNotSame($revision_1, $revision_2_id);

    // Host B wears nothing under the old name: untouched by the walk.
    $other = $uuid->generate();
    $host_b = EntityTestMulRev::create([
      'name' => 'Host B',
      OverridesSectionStorage::FIELD_NAME => [$this->containerSection($other)],
    ]);
    $this->stageMap($host_b, ["container:$other" => ['variants' => [['variant' => 'container_site', 'fragment' => '']]]]);

    $sandbox = $this->runPostUpdate();
    $this->assertSame(2, $sandbox[HostEntityWalker::SANDBOX_KEY]['saved'], 'Exactly the two revisions wearing the old name were re-saved.');

    // Host maps: every revision repointed, place and provenance kept.
    $this->assertSame([
      "container:$band" => ['variants' => [['variant' => 'container_site', 'fragment' => ''], ['variant' => 'columns', 'fragment' => '']]],
      "container:$nested" => ['variants' => [['variant' => 'columns', 'fragment' => '', 'via' => 'card_left'], ['variant' => 'card_left', 'fragment' => 'container(1)']]],
    ], $this->revisionMap($revision_1), 'The old revision repointed, the via origin riding along.');
    $this->assertSame([
      "container:$band" => ['variants' => [['variant' => 'columns', 'fragment' => '']], 'default' => ['padding-top' => '2rem']],
      "container:$nested" => ['default' => ['gap' => '1rem']],
    ], $this->revisionMap($revision_2_id), 'The default revision repointed.');
    $host_storage->resetCache();
    $host_b = EntityTestMulRev::load($host_b->id());
    $this->assertSame(["container:$other" => ['variants' => [['variant' => 'container_site', 'fragment' => '']]]], $host_b->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap());

    // Variant styles: both revisions of the user composition nest the new
    // name; the shipped-unmodified one still says the old (the sync lane's).
    $storage->resetCache();
    $this->assertSame([['variant' => 'columns', 'fragment' => '']], $storage->loadRevision($card_revision_1)->getStyles()['container(1)']['variants']);
    $this->assertSame([['variant' => 'columns', 'fragment' => '']], $storage->loadRevision($card_revision_2)->getStyles()['container(1)']['variants']);
    $this->assertSame(['margin' => '2px'], $storage->loadRevision($card_revision_2)->getStyles()['container(1)']['default'], 'The rest of the entry rides untouched.');
    $kept = $this->loadVariant('card_shipped');
    $this->assertSame([['variant' => 'row', 'fragment' => '']], $kept->getStyles()['container(1)']['variants']);
    $this->assertFalse($kept->isModified(), 'The shipped-unmodified composition was not rewritten in place.');

    // Stored stencils: the bare-name list at depth.
    $element = $this->container->get('entity_type.manager')->getStorage('stencil')->loadUnchanged($stencil->id())->getElement();
    $this->assertSame(['card_left'], $element['styles']['']['variants']);
    $this->assertSame(['container_full', 'columns'], $element['children'][0]['styles']['']['variants']);

    // The retire-and-sync: the legacy entity is gone, columns stands
    // shipped and unmodified under its label, and the freed `row` name
    // now carries the real Row (a fresh entity, its styles the Row's).
    $columns = $this->loadVariant('columns');
    $this->assertInstanceOf(DaedalusStylishVariantInterface::class, $columns);
    $this->assertTrue($columns->isShipped());
    $this->assertFalse($columns->isModified());
    $this->assertSame('daedalus_stylish_layouts', $columns->getProvider());
    $this->assertSame('Columns', $columns->label());
    $this->assertSame(ContainerMigration::CONTAINER_LAYOUT_AXIS, $columns->getAxis());
    $rows = $storage->loadByProperties(['machine_name' => 'row']);
    $this->assertCount(1, $rows, 'Exactly one entity under the freed name: the Row the sync landed.');
    $row = reset($rows);
    $this->assertSame('Row', $row->label());
    $this->assertTrue($row->isShipped());
    $this->assertFalse($row->isModified());
    $this->assertSame('daedalus_stylish_layouts', $row->getProvider());
    $this->assertSame(ContainerMigration::CONTAINER_LAYOUT_AXIS, $row->getAxis());
    $this->assertSame('wrap', $row->getStyles()['']['default']['flex-wrap']);
    $this->assertArrayNotHasKey('::> *', $row->getStyles());

    // Idempotence: a second walk finds nothing under the old name. The
    // retire step is deliberately NOT rerun: `row` is now the shipped
    // Row, and retiring it again would be the permanent-sweep mistake
    // the one-shot exists to avoid.
    $before = [
      $this->revisionMap($revision_1),
      $this->revisionMap($revision_2_id),
      $element,
      $storage->loadRevision($card_revision_2)->getStyles(),
    ];
    $rename = $this->container->get('daedalus_stylish_layouts.variant_rename');
    $this->assertSame([], $rename->renameInStencils('row', 'columns'));
    // The shipped-unmodified composition is still the sync lane's: the
    // skip line persists until its provider delivers the rename, nothing
    // else is reported.
    $again = $rename->renameInVariants('row', 'columns');
    $this->assertCount(1, $again);
    $this->assertStringContainsString('Variant "card_shipped"', $again[0]);
    $this->assertStringContainsString('left its nested "row" references to the sync lane', $again[0]);
    $sandbox = [];
    $this->assertSame(0, $rename->walkHosts($sandbox, 'row', 'columns'));
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame($before, [
      $this->revisionMap($revision_1),
      $this->revisionMap($revision_2_id),
      $this->container->get('entity_type.manager')->getStorage('stencil')->loadUnchanged($stencil->id())->getElement(),
      $storage->loadRevision($card_revision_2)->getStyles(),
    ]);
  }

  /**
   * The sync before the update refuses columns beside row, loudly, harmlessly.
   *
   * Every cache rebuild syncs shipped definitions and the update run
   * rebuilds before its post-updates, so the first rebuild after the code
   * lands meets the legacy `row` still published: `columns` is that
   * definition's failure on the report (the roster's spelling rule), the
   * rest of the module's file lands, nothing throws, and the site keeps
   * its `row` entity. That entity is unmodified shipped content under a
   * name disk now ships as the Row, so the same sync UPDATES it to the
   * Row's face (the deploy window: a container still wearing `row`
   * renders as a Row until the post-update runs). The post-update then
   * retires it, the sync lands `columns` and a fresh Row, and the host
   * wearings are repointed.
   */
  public function testSyncRefusesColumnsBesideLegacyRow(): void {
    $this->devolveToLegacyRow();

    $report = $this->container->get('daedalus_stylish.shipped_sync')->sync(['daedalus_stylish_layouts']);
    $this->assertTrue($report->hasFailures());
    $variants = $report->report('variant');
    $this->assertSame(ShippedDefinitionsImporter::FAILED, $variants->outcome('daedalus_stylish_layouts', 'columns'));
    $this->assertSame(ShippedDefinitionsImporter::UNCHANGED, $variants->outcome('daedalus_stylish_layouts', 'container_site'), 'The rest of the file lands.');
    $this->assertSame(ShippedDefinitionsImporter::UPDATED, $variants->outcome('daedalus_stylish_layouts', 'row'), 'The legacy entity takes the Row face.');
    $errors = $variants->failures()['daedalus_stylish_layouts']['columns'];
    $this->assertCount(1, $errors);
    $this->assertSame('SHIPPED_VARIANT_SPELLING_CONFLICT', $errors[0]->getCode());
    $this->assertStringContainsString('the published "row" variant already owns', $errors[0]->getMessage());
    $this->assertNull($this->loadVariant('columns'));
    $legacy = $this->loadVariant('row');
    $this->assertNotNull($legacy);
    $this->assertSame('Row', $legacy->label());
    $this->assertSame('wrap', $legacy->getStyles()['']['default']['flex-wrap']);
    $this->assertFalse($legacy->isModified());

    $this->runPostUpdate();
    $row = $this->loadVariant('row');
    $this->assertNotNull($row);
    $this->assertNotSame($legacy->uuid(), $row->uuid(), 'The legacy entity retired; the Row is a fresh entity.');
    $this->assertSame('Row', $row->label());
    $this->assertNotNull($this->loadVariant('columns'));
    $this->assertFalse($this->container->get('daedalus_stylish.shipped_sync')->sync(['daedalus_stylish_layouts'])->hasFailures(), 'Converged: the next rebuild refuses nothing.');
  }

  /**
   * A modified shipped row is user work: kept and reported, never deleted.
   */
  public function testModifiedShippedRowIsKept(): void {
    $styles = $this->loadVariant(ContainerMigration::COLUMNS_VARIANT)->getStyles();
    $styles['']['default']['gap'] = '2rem';
    $row = $this->devolveToLegacyRow($styles);
    $this->assertTrue($row->isShipped());
    $this->assertTrue($row->isModified());

    $lines = $this->container->get('daedalus_stylish_layouts.variant_rename')->retireShipped('row', 'daedalus_stylish_layouts');
    $this->assertCount(1, $lines);
    $this->assertStringContainsString('Kept the modified "row" variant', $lines[0]);
    $this->assertNotNull($this->loadVariant('row'));

    // A user variant reusing the name is never the provider's to retire.
    $this->loadVariant('row')->delete();
    $user = DaedalusStylishVariant::create([
      'label' => 'My row',
      'machine_name' => 'row',
      'scope' => 'kind:container',
      'axis' => ContainerMigration::CONTAINER_LAYOUT_AXIS,
      'status' => TRUE,
    ]);
    $user->setStyles(['' => ['default' => ['gap' => '2rem']]])->save();
    $this->assertSame([], $this->container->get('daedalus_stylish_layouts.variant_rename')->retireShipped('row', 'daedalus_stylish_layouts'));
    $this->assertNotNull($this->loadVariant('row'));
  }

}
