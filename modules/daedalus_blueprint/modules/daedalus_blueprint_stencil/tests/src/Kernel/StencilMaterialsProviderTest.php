<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilElementTree;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stencil materials provider: discovery's set as records.
 *
 * Pins the projection of a stencil definition into a roster record for
 * both sources: file stencils (shipped origin, not updatable, under the
 * developer gate too, root-relative icon path) and published content
 * stencils (user origin, updatable, media
 * icon RESOLVED to the source file's URL with the media's tags on the
 * roster; NULL for none or a dangling reference), the root kind (section
 * for a container-rooted subtree, block for a bare block), the stencil
 * slot spelling, the root's captured wearings as applies.wears (a capture
 * fact: the variant ids in the root node's own styles entry, whatever
 * they are; the roster, not this provider, decides whether they make a
 * band), the element tree in the printer's shape (StencilElementTree::
 * dialectNodes(): the definition with every variant reference expanded,
 * what the schema prints as the stencil's fragment), the published-only
 * rule, the discovery cache tag as the provider's cache domain, and the
 * memo reset on stencil CRUD.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilMaterialsProviderTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'daedalus_blueprint',
    'daedalus_blueprint_stencil',
    'daedalus_blueprint_stencil_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('stencil');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
  }

  /**
   * File and content stencils project into one record shape.
   */
  public function testRecords(): void {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $before = $builder->build();
    $this->assertSame(['call_to_action', 'hero'], array_keys($before->stencils()));
    $this->assertContains(StencilDiscovery::CACHE_TAG, $before->getCacheableMetadata()->getCacheTags());

    $hero = $before->get(MaterialKind::Stencil, 'hero');
    $this->assertInstanceOf(MaterialRecord::class, $hero);
    $this->assertSame('Hero', $hero->label);
    $this->assertSame('Single-column hero banner with a heading.', $hero->description);
    $this->assertSame('section', $hero->rootKind);
    $this->assertStringEndsWith('/daedalus_blueprint_stencil_test/assets/hero.svg', (string) $hero->icon);
    $this->assertSame(['stencil' => 'hero'], $hero->spellings);
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $hero->origin);
    $this->assertFalse($hero->updatable);
    // The developer gate lifts the distro lock for shipped ENTITIES; a file
    // stencil has none, its update lane is drush stencil-ship from a saved
    // placement, so the record stays not updatable under the open gate too
    // (the UI verbs and the op degrade the same on both audiences).
    $this->setSetting(DistroLock::SETTING, TRUE);
    $builder->reset();
    $this->assertFalse($builder->build()->get(MaterialKind::Stencil, 'hero')->updatable, 'A file stencil is not updatable under the developer gate.');
    $this->setSetting(DistroLock::SETTING, FALSE);
    $builder->reset();
    $before = $builder->build();
    $hero = $before->get(MaterialKind::Stencil, 'hero');
    $this->assertNull($hero->value);
    $this->assertNull($hero->group);
    $this->assertSame([], $hero->applies, 'A root that wears nothing carries no wearings.');
    $this->assertSame([], $hero->wears());
    $this->assertSame([], $before->get(MaterialKind::Stencil, 'call_to_action')->applies, 'Root overrides without variants are not wearings.');
    $this->assertNull($before->get(MaterialKind::Stencil, 'call_to_action')->icon, 'No icon key means the default icon.');
    $this->assertSame('hero', $before->bySpelling('stencil', 'hero')?->id);
    // The tree is the definition's, in the printer's shape (region nodes
    // and all: the printer, not the provider, judges what prints).
    $discovery = $this->container->get('daedalus_blueprint_stencil.discovery');
    $this->assertSame(StencilElementTree::dialectNodes($discovery->getStencil('hero')['element']), $hero->tree);
    $this->assertSame('layout_onecol', $hero->tree[0]['layout_id']);
    $this->assertSame('region', $hero->tree[0]['children'][0]['kind']);
    $this->assertSame(StencilElementTree::dialectNodes($discovery->getStencil('call_to_action')['element']), $before->get(MaterialKind::Stencil, 'call_to_action')->tree);

    // Content stencils: a published block-rooted one joins, updatable and
    // user-minted; an unpublished one reserves its id but is not served.
    // The stencil insert hook resets the memo, so no explicit reset.
    $quote = Stencil::create([
      'label' => 'Pull quote',
      'machine_name' => 'pull_quote',
      'description' => 'A single quote block.',
      'status' => 1,
    ]);
    $quote->setElement(['kind' => 'block', 'block_type' => 'text']);
    $quote->save();
    $draft = Stencil::create([
      'label' => 'Draft band',
      'machine_name' => 'draft_band',
      'description' => 'Still staging.',
      'status' => 0,
    ]);
    $draft->setElement(['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]);
    $draft->save();

    $after = $builder->build();
    $this->assertNotSame($before, $after, 'A stencil save resets the memo.');
    $this->assertSame(['call_to_action', 'hero', 'pull_quote'], array_keys($after->stencils()));
    $pull_quote = $after->get(MaterialKind::Stencil, 'pull_quote');
    $this->assertSame('block', $pull_quote->rootKind);
    $this->assertTrue($pull_quote->updatable);
    $this->assertSame(MaterialRecord::ORIGIN_USER, $pull_quote->origin);
    $this->assertNull($pull_quote->icon);
    $this->assertSame(['stencil' => 'pull_quote'], $pull_quote->spellings);
    $this->assertSame([
      'kind' => 'stencil',
      'id' => 'pull_quote',
      'label' => 'Pull quote',
      'description' => 'A single quote block.',
      'value' => NULL,
      'group' => NULL,
      'spellings' => ['stencil' => 'pull_quote'],
      'scope' => NULL,
      'applies' => [],
      'owned_by' => NULL,
      'published' => TRUE,
      'weight' => 0,
      'icon' => NULL,
      'root_kind' => 'block',
      'origin' => 'user',
      'updatable' => TRUE,
      'design_system' => NULL,
      'offered' => TRUE,
    ], $pull_quote->toArray());
    $this->assertSame([['kind' => 'block', 'block_type' => 'text']], $pull_quote->tree, 'The tree rides the record, never the delivery payload.');

    // Publishing the draft surfaces it through the same hook (discovery
    // order: files, then content by entity id).
    $draft->setPublished()->save();
    $this->assertSame(['call_to_action', 'hero', 'pull_quote', 'draft_band'], array_keys($builder->build()->stencils()));
    $this->assertSame('section', $builder->build()->get(MaterialKind::Stencil, 'draft_band')->rootKind);

    // The root's variants ride the record as applies.wears, in definition
    // order and only the root's (a child's wearings are not the
    // placement's); overrides beside them change nothing.
    $band = Stencil::create([
      'label' => 'Wide band',
      'machine_name' => 'wide_band',
      'description' => 'A full-width band.',
      'status' => 1,
    ]);
    $band->setElement([
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'styles' => [
        '' => ['variants' => ['container_site', 'glow'], 'default' => ['padding-top' => '2rem']],
        '::.inner' => ['variants' => ['card']],
      ],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'content',
          'children' => [
          ['kind' => 'block', 'block_type' => 'text', 'styles' => ['' => ['variants' => ['button']]]],
          ],
        ],
      ],
    ]);
    $band->save();
    $record = $builder->build()->get(MaterialKind::Stencil, 'wide_band');
    $this->assertSame([MaterialRecord::APPLIES_WEARS => ['container_site', 'glow']], $record->applies);
    $this->assertSame(['container_site', 'glow'], $record->wears());
    $this->assertSame(['wears' => ['container_site', 'glow']], $record->toArray()['applies']);
    // The tree expands every variant reference to the printer's map, the
    // part entry and the nested block included, and touches nothing else.
    $this->assertSame([[
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'styles' => [
        '' => [
          'variants' => [['variant' => 'container_site'], ['variant' => 'glow']],
          'default' => ['padding-top' => '2rem'],
        ],
        '::.inner' => ['variants' => [['variant' => 'card']]],
      ],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'content',
          'children' => [
          ['kind' => 'block', 'block_type' => 'text', 'styles' => ['' => ['variants' => [['variant' => 'button']]]]],
          ],
        ],
      ],
    ],
    ], $record->tree);
  }

  /**
   * A member stencil is offered (and cataloged) only under its system.
   *
   * The provider projects discovery's resolved membership onto the record
   * and filters nothing; the roster partitions (global plus the active
   * system's), and the AI's materials catalog reads the roster, so a
   * shipped stencil of an inactive system is absent from both while the
   * unpartitioned members() read still sees it.
   */
  public function testDesignSystemPartition(): void {
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $schema = $this->container->get('daedalus_blueprint.materials_schema');
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);

    // No active system: the aurora member is off the roster and the
    // catalog; the global ones (opted out, or from a packageless module)
    // are on.
    $roster = $builder->build();
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_band'], array_keys($roster->stencils()));
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_band'], array_keys($schema->fragment()['stencils']));
    $this->assertNull($roster->get(MaterialKind::Stencil, 'plain_band')->designSystem);
    $this->assertSame(['aurora_band', 'aurora_named_band'], array_map(static fn (MaterialRecord $record): string => $record->id, $builder->members('aurora')));

    $active->activate('aurora');
    $roster = $builder->build();
    $this->assertEqualsCanonicalizing(['aurora_band', 'aurora_named_band', 'call_to_action', 'hero', 'plain_band'], array_keys($roster->stencils()));
    $this->assertSame('aurora', $roster->get(MaterialKind::Stencil, 'aurora_band')->designSystem);
    $this->assertSame('aurora', $roster->get(MaterialKind::Stencil, 'aurora_band')->toArray()['design_system']);
    $this->assertArrayHasKey('aurora_band', $schema->fragment()['stencils']);

    // A content member follows the same partition.
    $quote = Stencil::create([
      'label' => 'Aurora quote',
      'machine_name' => 'aurora_quote',
      'description' => 'A quote.',
      'design_system' => 'aurora',
      'status' => 1,
    ]);
    $quote->setElement(['kind' => 'block', 'block_type' => 'text']);
    $quote->save();
    $this->assertSame('aurora', $builder->build()->get(MaterialKind::Stencil, 'aurora_quote')->designSystem);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active->activate('borealis');
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_band'], array_keys($builder->build()->stencils()));
  }

  /**
   * A content stencil's media icon rides the record resolved to a URL.
   *
   * The client palette paints the record and cannot resolve the catalog's
   * `media:<uuid>` spelling, so the provider resolves it through the icon
   * value grammar's one seam; the media's tags join the roster's metadata
   * (an icon edit invalidates every projection), and a reference whose
   * media is gone degrades to NULL, the default icon, with nothing to tag.
   */
  public function testMediaIconResolves(): void {
    $type = $this->createMediaType('image');
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    file_put_contents('public://stencil-icon.png', 'png');
    $file = File::create(['uri' => 'public://stencil-icon.png', 'filename' => 'stencil-icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Stencil icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    $stencil = Stencil::create([
      'label' => 'Promo',
      'machine_name' => 'promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
      'status' => 1,
    ]);
    $stencil->setElement(['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]);
    $stencil->save();

    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $roster = $builder->build();
    $this->assertSame(
      $this->container->get('file_url_generator')->generateString('public://stencil-icon.png'),
      $roster->get(MaterialKind::Stencil, 'promo')->icon,
    );
    $this->assertContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());

    $media->delete();
    $builder->reset();
    $roster = $builder->build();
    $this->assertNull($roster->get(MaterialKind::Stencil, 'promo')->icon);
    $this->assertNotContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());
  }

}
