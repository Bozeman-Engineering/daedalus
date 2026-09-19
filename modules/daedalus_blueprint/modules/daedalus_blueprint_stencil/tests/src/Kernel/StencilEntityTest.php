<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stencil content entity.
 *
 * The content twin of a shipped stencil file: same definition face (label,
 * description, element in the file grammar), persisted by users. String
 * machine names are the identity (auto-generated from the label on presave,
 * the DaedalusStylishVariant precedent), the element is one JSON blob in the file
 * grammar, and a single "save stencils" permission governs every mutation
 * while published stencils stay viewable by everyone (the palette lists
 * them).
 */
#[CoversClass(Stencil::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilEntityTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'field',
    'file',
    'image',
    'media',
    'daedalus_blueprint',
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('stencil');
  }

  /**
   * Creates and saves a stencil.
   */
  protected function createStencil(array $values = []): Stencil {
    $stencil = Stencil::create($values + [
      'label' => 'Call to Action',
      'description' => 'Two-column card: heading beside an image.',
    ]);
    if ($stencil->getElement() === []) {
      $stencil->setElement([
        'kind' => 'container',
        'layout_id' => 'layout_twocol_section',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [['kind' => 'block', 'block_type' => 'text']],
          ],
        ],
      ]);
    }
    $stencil->save();
    return $stencil;
  }

  /**
   * Machine names auto-generate from the label and never collide.
   */
  public function testMachineNameGeneration(): void {
    // Presave derives the machine name from the label when omitted.
    $stencil = $this->createStencil();
    $this->assertSame('call_to_action', $stencil->getMachineName());

    // A second stencil with the same label gets a numeric suffix instead of
    // colliding: machine names are the stencil ID vocabulary.
    $second = $this->createStencil();
    $this->assertSame('call_to_action_2', $second->getMachineName());

    // An explicit machine name is kept verbatim.
    $explicit = $this->createStencil(['machine_name' => 'hero_banner']);
    $this->assertSame('hero_banner', $explicit->getMachineName());

    // Re-saving never regenerates the name: identity is stable.
    $stencil->set('label', 'Renamed Call to Action')->save();
    $this->assertSame('call_to_action', $stencil->getMachineName());
  }

  /**
   * The element blob round-trips and is required to save.
   */
  public function testElementRoundTripAndRequirement(): void {
    $element = [
      'kind' => 'container',
      'layout_id' => 'layout_twocol_section',
      'label' => 'Call to Action',
      'styles' => ['' => ['default' => ['display' => 'flex']]],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'first',
          'children' => [['kind' => 'block', 'block_type' => 'text']],
        ],
      ],
    ];
    $stencil = $this->createStencil();
    $stencil->setElement($element);
    $stencil->save();

    // A fresh load decodes the one JSON column back to the exact subtree:
    // the same grammar as a stencil file's element key, nothing lost.
    $loaded = Stencil::load($stencil->id());
    $this->assertSame($element, $loaded->getElement());

    // A stencil IS its element; saving without one fails loudly.
    // Storage wraps the preSave exception.
    try {
      Stencil::create([
        'label' => 'Empty',
        'description' => 'No element.',
      ])->save();
      $this->fail('An element-less stencil saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }
  }

  /**
   * One "save stencils" permission; published stencils viewable by all.
   */
  public function testAccess(): void {
    $stencil = $this->createStencil();

    $saver = $this->createUser(['save stencils']);
    $viewer = $this->createUser([]);

    // Every mutation is governed by the single permission.
    $this->assertTrue($stencil->access('update', $saver));
    $this->assertTrue($stencil->access('delete', $saver));
    $this->assertFalse($stencil->access('update', $viewer));
    $this->assertFalse($stencil->access('delete', $viewer));

    // Published stencils are viewable by everyone: the palette lists them
    // for every editor, and the element subtree is not a secret.
    $this->assertTrue($stencil->access('view', $viewer));

    // Unpublished stencils are visible only to stencil savers.
    $stencil->setUnpublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('stencil')->resetCache();
    $this->assertFalse($stencil->access('view', $viewer));
    $this->assertTrue($stencil->access('view', $saver));
  }

  /**
   * Stencils are revisionable: a bad push is one revert away.
   */
  public function testRevisions(): void {
    $stencil = $this->createStencil();
    $first_element = $stencil->getElement();
    $first_revision_id = $stencil->getRevisionId();

    $stencil->setElement(['kind' => 'block', 'block_type' => 'text']);
    $stencil->setNewRevision();
    $stencil->save();
    $this->assertNotSame($first_revision_id, $stencil->getRevisionId());
    $this->assertSame(['kind' => 'block', 'block_type' => 'text'], Stencil::load($stencil->id())->getElement());

    // The old revision still carries the old element, and the machine
    // name (identity, non-revisionable) is the same on both.
    $storage = \Drupal::entityTypeManager()->getStorage('stencil');
    assert($storage instanceof RevisionableStorageInterface);
    $old = $storage->loadRevision($first_revision_id);
    assert($old instanceof Stencil);
    $this->assertSame($first_element, $old->getElement());
    $this->assertSame($stencil->getMachineName(), $old->getMachineName());
  }

}
