<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Section;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the blueprint content entity's identity and revisions.
 *
 * The content lane of the blueprint library: string machine names are the
 * identity (auto-generated from the label on presave, the variant
 * precedent), the type is the bundle, and every save of an existing
 * blueprint is a new revision. Access (per type) is BlueprintAccessTest's;
 * the document and the host are BlueprintHostTest's.
 */
#[CoversClass(Blueprint::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintEntityTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'entity_test',
    'field',
    'file',
    'image',
    'media',
    'options',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_blueprint_library',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);
    EntityTestBundle::create(['id' => 'page', 'label' => 'Page'])->save();
  }

  /**
   * Creates and saves a blueprint for the test bundle.
   */
  protected function createBlueprint(array $values = []): Blueprint {
    $blueprint = Blueprint::create($values + [
      'type' => 'section',
      'label' => 'Call to Action',
      'description' => 'Two-column card: heading beside an image.',
      'scope' => 'block',
      'subject_type' => 'entity_test_with_bundle',
      'subject_bundle' => 'page',
    ]);
    $blueprint->save();
    return $blueprint;
  }

  /**
   * Machine names auto-generate from the label and never collide.
   */
  public function testMachineNameGeneration(): void {
    // Presave derives the machine name from the label when omitted.
    $blueprint = $this->createBlueprint();
    $this->assertSame('call_to_action', $blueprint->getMachineName());

    // A second blueprint with the same label gets a numeric suffix instead
    // of colliding: machine names are the blueprint ID vocabulary.
    $second = $this->createBlueprint();
    $this->assertSame('call_to_action_2', $second->getMachineName());

    // An explicit machine name is kept verbatim.
    $explicit = $this->createBlueprint(['machine_name' => 'hero_banner']);
    $this->assertSame('hero_banner', $explicit->getMachineName());

    // Re-saving never regenerates the name: identity is stable.
    $blueprint->set('label', 'Renamed Call to Action')->save();
    $this->assertSame('call_to_action', $blueprint->getMachineName());
  }

  /**
   * The two starts that are not blueprints are reserved ids.
   *
   * `blank` and `template` share the slot a page blueprint's id is named
   * in (the chooser, page_create's `start`), so no content blueprint saves
   * under either: an explicit reserved name is refused loudly, and a label
   * that would generate one steps past it to a suffixed name instead of
   * failing the save of a blueprint called "Blank".
   */
  public function testReservedIdsAreRefused(): void {
    $this->assertSame(['blank', 'template'], Blueprint::RESERVED_IDS);
    foreach (Blueprint::RESERVED_IDS as $reserved) {
      try {
        $this->createBlueprint(['machine_name' => $reserved]);
        $this->fail(sprintf('The reserved id "%s" must be refused.', $reserved));
      }
      catch (EntityStorageException $e) {
        // Storage wraps what presave throws.
        $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
        $this->assertSame(sprintf('The blueprint machine name "%s" is reserved: "blank" and "template" name the starts that are not blueprints (an empty page, the bundle\'s template) beside a page blueprint\'s id.', $reserved), $e->getPrevious()->getMessage());
      }
      $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadByProperties(['machine_name' => $reserved]), 'Nothing was saved.');
    }

    // The generator treats a reserved id as taken.
    $this->assertSame('blank_2', $this->createBlueprint(['label' => 'Blank'])->getMachineName());
    $this->assertSame('template_2', $this->createBlueprint(['label' => 'Template'])->getMachineName());
    $this->assertSame('blank_3', $this->createBlueprint(['label' => 'Blank'])->getMachineName());
  }

  /**
   * A block-scope blueprint saves with at most one root.
   *
   * The storage door of the one-root rule (discovery holds the file door,
   * BlueprintScopePolicy the op door): core's default-content importer
   * saves an entity with no op and no validation, so presave is where a
   * block blueprint of several sections is refused. A page blueprint is
   * a whole document and takes any number; one root, or none while the
   * blueprint is composing, passes.
   */
  public function testBlockScopeHoldsOneRoot(): void {
    $two = [new Section('layout_onecol'), new Section('layout_onecol')];
    try {
      $this->createBlueprint(['machine_name' => 'footer', 'layout_builder__layout' => $two]);
      $this->fail('A block blueprint with two roots must be refused.');
    }
    catch (EntityStorageException $e) {
      // Storage wraps what presave throws.
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertSame('The block blueprint "footer" holds 2 root containers; a block-scope blueprint is one container placed into a page and its document holds exactly one root. A document of several roots is a page-scope blueprint.', $e->getPrevious()->getMessage());
    }
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadByProperties(['machine_name' => 'footer']), 'Nothing was saved.');

    $page = $this->createBlueprint(['machine_name' => 'landing', 'scope' => 'page', 'layout_builder__layout' => $two]);
    $this->assertCount(2, Blueprint::load($page->id())->get('layout_builder__layout')->getSections(), 'A page blueprint takes several roots.');

    $one = $this->createBlueprint(['machine_name' => 'band', 'layout_builder__layout' => [new Section('layout_onecol')]]);
    $this->assertCount(1, Blueprint::load($one->id())->get('layout_builder__layout')->getSections(), 'A block blueprint takes one root.');
    $this->assertSame('block', $one->getScope());
  }

  /**
   * Groups: zero or more ids on the entity, revisioned with the details.
   *
   * A group is a filter category and nothing else; the entity stores the
   * ids (shape-checked where they are written: discovery for a file, the
   * gesture lane for a saved blueprint) in order, and a revision keeps
   * the groups it was saved with, like the allow-list.
   */
  public function testGroups(): void {
    $blueprint = $this->createBlueprint();
    $this->assertSame([], $blueprint->getGroups(), 'A blueprint is in no group by default.');

    $blueprint->setGroups(['heroes', 'bands'])->save();
    $first_revision_id = $blueprint->getRevisionId();
    $this->assertSame(['heroes', 'bands'], Blueprint::load($blueprint->id())->getGroups());

    $created = $this->createBlueprint(['machine_name' => 'band', 'groups' => ['bands']]);
    $this->assertSame(['bands'], Blueprint::load($created->id())->getGroups(), 'The field takes a list on create.');

    $blueprint->setGroups([])->save();
    $this->assertSame([], Blueprint::load($blueprint->id())->getGroups(), 'An empty list files it under no group.');
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    assert($storage instanceof RevisionableStorageInterface);
    $old = $storage->loadRevision($first_revision_id);
    assert($old instanceof Blueprint);
    $this->assertSame(['heroes', 'bands'], $old->getGroups(), 'Groups are revisioned with the details.');
  }

  /**
   * The site favorite: off by default, a boolean, revisioned.
   *
   * A listing fact and nothing else: the entity stores the flag (the
   * default an offering surface lists under Favorites for everyone), and
   * a revision keeps the flag it was saved with, like the groups.
   */
  public function testFavorite(): void {
    $blueprint = $this->createBlueprint();
    $this->assertFalse($blueprint->isFavorite(), 'A blueprint is not a favorite by default.');

    $blueprint->setFavorite(TRUE)->save();
    $first_revision_id = $blueprint->getRevisionId();
    $this->assertTrue(Blueprint::load($blueprint->id())->isFavorite());

    $created = $this->createBlueprint(['machine_name' => 'band', 'favorite' => TRUE]);
    $this->assertTrue(Blueprint::load($created->id())->isFavorite(), 'The field takes the flag on create.');

    $blueprint->setFavorite(FALSE)->save();
    $this->assertFalse(Blueprint::load($blueprint->id())->isFavorite());
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    assert($storage instanceof RevisionableStorageInterface);
    $old = $storage->loadRevision($first_revision_id);
    assert($old instanceof Blueprint);
    $this->assertTrue($old->isFavorite(), 'The favorite is revisioned with the details.');
  }

  /**
   * The type is the bundle: born as one, never changed, a type the site has.
   */
  public function testType(): void {
    $blueprint = $this->createBlueprint();
    $this->assertSame('section', $blueprint->getType());
    $this->assertSame('section', $blueprint->bundle());
    $this->assertSame('Section', $blueprint->get('type')->entity->label(), 'The bundle is the shipped blueprint_type config entity.');
    $this->assertSame('site_chrome', $this->createBlueprint(['type' => 'site_chrome', 'machine_name' => 'header'])->getType());

    // A blueprint is created AS a type: without one, core refuses the
    // entity before any blueprint rule runs.
    try {
      Blueprint::create(['label' => 'Typeless']);
      $this->fail('A blueprint without a type must be refused.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('Missing bundle', $e->getMessage());
    }
  }

  /**
   * Blueprints are revisionable: every save of an existing one is a revision.
   */
  public function testRevisions(): void {
    $blueprint = $this->createBlueprint();
    $first_revision_id = $blueprint->getRevisionId();

    // A plain save is a new revision, stamped to the saver with the log
    // cleared (the type answers TRUE to the storage handler; preSave gives
    // a plain save the same).
    $blueprint->setDescription('A card, revised.');
    $blueprint->save();
    $this->assertNotSame($first_revision_id, $blueprint->getRevisionId());
    $this->assertSame('', (string) $blueprint->getRevisionLogMessage());

    // A save that marked its own revision keeps its log message.
    $blueprint->setNewRevision(TRUE);
    $blueprint->setRevisionLogMessage('Pushed from a page.');
    $blueprint->save();
    $this->assertSame('Pushed from a page.', (string) Blueprint::load($blueprint->id())->getRevisionLogMessage());

    // The old revision still carries the old description, and the machine
    // name (identity, non-revisionable) is the same on both.
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    assert($storage instanceof RevisionableStorageInterface);
    $old = $storage->loadRevision($first_revision_id);
    assert($old instanceof Blueprint);
    $this->assertSame('Two-column card: heading beside an image.', $old->getDescription());
    $this->assertSame($blueprint->getMachineName(), $old->getMachineName());
    $this->assertSame(3, (int) $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('id', $blueprint->id())->count()->execute());
  }

}
