<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\daedalus\Traits\AiContextRefusalTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the blueprint materials provider: discovery's set as records.
 *
 * Pins the projection of a blueprint definition into a roster record for
 * both sources: file blueprints (shipped origin, not updatable, under the
 * developer gate too, root-relative icon path) and published blueprint
 * entities (user origin, updatable, media icon RESOLVED to the source
 * file's URL with the media's tags on the roster; NULL for none or a
 * dangling reference), the blueprint's scope, the blueprint slot spelling,
 * a block-scope root's captured wearings as applies.wears (a capture fact:
 * the variant ids in the root node's own styles entry, whatever they are;
 * the roster, not this provider, decides whether they make a section), the
 * definition's layout as the record's tree (discovery's served grammar,
 * what the schema prints as the fragment), the published-only rule, the
 * discovery cache tag as the provider's cache domain, and the memo reset
 * on blueprint CRUD. The AI context budget's guard on the gestures:
 * while the site is full, an offered capture (through the save_material
 * op, the AI's door) and an update turning `offered` on are refused with
 * the one wording, and a capture withheld from the AI, a face edit,
 * turning `favorite` on (the Add panel's listing, no share) and a flag
 * re-stated go through.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintMaterialsProviderTest extends BlueprintKernelTestBase {

  use AiContextRefusalTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_blueprint_library_test'];

  /**
   * File and content blueprints project into one record shape.
   */
  public function testRecords(): void {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $before = $builder->build();
    $this->assertSame(['call_to_action', 'hero'], array_keys($before->blueprints()));
    $this->assertContains(BlueprintDiscovery::CACHE_TAG, $before->getCacheableMetadata()->getCacheTags());

    $hero = $before->get(MaterialKind::Blueprint, 'hero');
    $this->assertInstanceOf(MaterialRecord::class, $hero);
    $this->assertSame('Hero', $hero->label);
    $this->assertSame('Single-column hero banner with a heading.', $hero->description);
    $this->assertSame('block', $hero->blueprintScope);
    $this->assertStringEndsWith('/daedalus_blueprint_library_test/assets/hero.svg', (string) $hero->icon);
    $this->assertSame(['blueprint' => 'hero'], $hero->spellings);
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $hero->origin);
    $this->assertFalse($hero->updatable);
    $this->assertNull($hero->url, 'A file blueprint has no canvas to open.');
    // The developer gate lifts the distro lock for shipped ENTITIES; a file
    // blueprint has none, its update lane is the ship command from its
    // own canvas, so the record stays not updatable under the open gate
    // too (the UI verbs and the op degrade the same on both audiences).
    $this->setSetting(DistroLock::SETTING, TRUE);
    $builder->reset();
    $this->assertFalse($builder->build()->get(MaterialKind::Blueprint, 'hero')->updatable, 'A file blueprint is not updatable under the developer gate.');
    $this->setSetting(DistroLock::SETTING, FALSE);
    $builder->reset();
    $before = $builder->build();
    $hero = $before->get(MaterialKind::Blueprint, 'hero');
    $this->assertNull($hero->value);
    // The roster groups blueprints by type: a file naming none is a section.
    $this->assertSame('section', $hero->group);
    // The file's groups and favorite ride the record and the delivery:
    // the Add panel files and lists its rows by them.
    $this->assertSame(['heroes', 'openers'], $hero->groups);
    $this->assertTrue($hero->favorite);
    $this->assertSame(['heroes', 'openers'], $hero->toArray()['groups']);
    $this->assertTrue($hero->toArray()['favorite']);
    $this->assertSame([], $hero->applies, 'A root that wears nothing carries no wearings.');
    $this->assertSame([], $hero->wears());
    $this->assertSame([], $before->get(MaterialKind::Blueprint, 'call_to_action')->applies, 'Root overrides without variants are not wearings.');
    $this->assertNull($before->get(MaterialKind::Blueprint, 'call_to_action')->icon, 'No icon key means the default icon.');
    $this->assertSame('hero', $before->bySpelling('blueprint', 'hero')?->id);
    // The tree is the definition's layout as served (region nodes and all:
    // the printer, not the provider, judges what prints).
    $discovery = $this->container->get('daedalus_blueprint_library.discovery');
    $this->assertSame($discovery->getBlueprint('hero')['layout'], $hero->tree);
    $this->assertSame('layout_onecol', $hero->tree[0]['layout_id']);
    $this->assertSame('region', $hero->tree[0]['children'][0]['kind']);
    $this->assertSame($discovery->getBlueprint('call_to_action')['layout'], $before->get(MaterialKind::Blueprint, 'call_to_action')->tree);
    // The definition's node-less styles ride the record beside the tree (a
    // page's start copies both halves); a file without the key has none,
    // and no client surface reads them.
    $this->assertSame($discovery->getBlueprint('hero')['styles'], $hero->styles);
    $this->assertSame([], $hero->styles);
    $this->assertArrayNotHasKey('styles', $hero->toArray(), 'The page-level styles stay server-side, like the tree.');

    // Content blueprints: a published one joins, updatable and user-minted;
    // an unpublished one reserves its id but is not served. The blueprint
    // insert hook resets the memo, so no explicit reset.
    $quote = $this->createBlueprint([
      'label' => 'Pull quote',
      'machine_name' => 'pull_quote',
      'description' => 'A single quote block.',
    ]);
    $draft = $this->createBlueprint([
      'label' => 'Draft section',
      'machine_name' => 'draft_section',
      'description' => 'Still staging.',
      'status' => 0,
    ]);

    $after = $builder->build();
    $this->assertNotSame($before, $after, 'A blueprint save resets the memo.');
    $this->assertSame(['call_to_action', 'hero', 'pull_quote'], array_keys($after->blueprints()));
    $pull_quote = $after->get(MaterialKind::Blueprint, 'pull_quote');
    $this->assertSame('block', $pull_quote->blueprintScope);
    $this->assertTrue($pull_quote->updatable);
    $this->assertSame($quote->toUrl()->toString(), $pull_quote->url, 'A content blueprint\'s record names its canvas (the canonical route), the Open blueprint verb\'s destination from a detached placement.');
    $this->assertSame(MaterialRecord::ORIGIN_USER, $pull_quote->origin);
    $this->assertNull($pull_quote->icon);
    $this->assertSame([], $pull_quote->groups, 'A saved blueprint in no group.');
    $this->assertFalse($pull_quote->favorite, 'A saved blueprint is not a favorite by default.');
    $this->assertSame(['blueprint' => 'pull_quote'], $pull_quote->spellings);
    $this->assertSame([
      'kind' => 'blueprint',
      'id' => 'pull_quote',
      'label' => 'Pull quote',
      'description' => 'A single quote block.',
      'value' => NULL,
      // The type, delivered as the record's group.
      'group' => 'section',
      'spellings' => ['blueprint' => 'pull_quote'],
      'scope' => NULL,
      'applies' => [],
      'owned_by' => NULL,
      'published' => TRUE,
      'weight' => 0,
      'icon' => NULL,
      'blueprint_scope' => 'block',
      'origin' => 'user',
      'updatable' => TRUE,
      'design_system' => NULL,
      'offered' => TRUE,
      'groups' => [],
      'favorite' => FALSE,
      'url' => $quote->toUrl()->toString(),
    ], $pull_quote->toArray());
    $built = $this->builtLayout($quote);
    $this->assertNotEmpty($built[0]['uuid'], 'The discovery entry keeps the document\'s identity for placement.');
    unset($built[0]['uuid']);
    $this->assertSame($built, $pull_quote->tree, 'The tree is the saved document\'s without its identity (a fragment), and rides the record, never the delivery payload.');
    $this->assertSame('layout_onecol', $pull_quote->tree[0]['layout_id']);
    $this->assertSame([], $pull_quote->styles, 'A saved blueprint with no style store carries no page-level styles.');

    // Publishing the draft surfaces it through the same hook (discovery
    // order: files, then content by entity id).
    $draft->setPublished()->save();
    $this->assertSame(['call_to_action', 'hero', 'pull_quote', 'draft_section'], array_keys($builder->build()->blueprints()));
    $this->assertSame('block', $builder->build()->get(MaterialKind::Blueprint, 'draft_section')->blueprintScope);

    // A saved blueprint's groups and favorite reach its record, and the
    // delivery, through the entity.
    $quote->setGroups(['quotes'])->setFavorite(TRUE)->save();
    $pull_quote = $builder->build()->get(MaterialKind::Blueprint, 'pull_quote');
    $this->assertSame(['quotes'], $pull_quote->groups);
    $this->assertTrue($pull_quote->favorite);
    $this->assertSame(['quotes'], $pull_quote->toArray()['groups']);
    $this->assertTrue($pull_quote->toArray()['favorite']);

    // The root's variants ride the record as applies.wears, in definition
    // order and only the root's (a nested node's wearings are not the
    // placement's); overrides beside them change nothing. The tree carries
    // discovery's normalized references.
    $this->enableModules(['daedalus_blueprint_library_instantiate_test']);
    $this->container->get('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $record = $builder->build()->get(MaterialKind::Blueprint, 'cta');
    $this->assertSame([MaterialRecord::APPLIES_WEARS => ['card']], $record->applies);
    $this->assertSame(['card'], $record->wears());
    $this->assertSame(['wears' => ['card']], $record->toArray()['applies']);
    $this->assertSame([['variant' => 'card']], $record->tree[0]['styles']['']['variants']);
    $this->assertSame('aliceblue', $record->tree[0]['styles']['']['default']['background-color']);
    $this->assertSame([['variant' => 'panel']], $record->tree[0]['children'][1]['children'][1]['styles']['']['variants'], 'A nested container\'s wearing stays on its node.');
    $this->assertSame([], $builder->build()->get(MaterialKind::Blueprint, 'solo')->wears(), 'A root without variants wears nothing.');
  }

  /**
   * A member blueprint is offered (and cataloged) only under its system.
   *
   * The provider projects discovery's resolved membership onto the record
   * and filters nothing; the roster partitions (global plus the active
   * system's), and the AI's materials catalog reads the roster, so a
   * shipped blueprint of an inactive system is absent from both while the
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
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_section'], array_keys($roster->blueprints()));
    // The schema's default listing is every shared blueprint; the whole
    // partition also reads through the reserved bucket (no layer declares
    // a group here).
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_section'], array_keys($schema->narrow($schema->fragment(), NULL, DialectCatalog::UNGROUPED)['blueprints']));
    $this->assertNull($roster->get(MaterialKind::Blueprint, 'plain_section')->designSystem);
    $this->assertSame(['aurora_named_section', 'aurora_section'], array_map(static fn (MaterialRecord $record): string => $record->id, $builder->members('aurora')));

    $active->activate('aurora');
    $roster = $builder->build();
    $this->assertEqualsCanonicalizing(['aurora_section', 'aurora_named_section', 'call_to_action', 'hero', 'plain_section'], array_keys($roster->blueprints()));
    $this->assertSame('aurora', $roster->get(MaterialKind::Blueprint, 'aurora_section')->designSystem);
    $this->assertSame('aurora', $roster->get(MaterialKind::Blueprint, 'aurora_section')->toArray()['design_system']);
    $this->assertArrayHasKey('aurora_section', $schema->narrow($schema->fragment(), NULL, DialectCatalog::UNGROUPED)['blueprints']);

    // A content member follows the same partition.
    $this->createBlueprint([
      'label' => 'Aurora quote',
      'machine_name' => 'aurora_quote',
      'description' => 'A quote.',
      'design_system' => 'aurora',
    ]);
    $this->assertSame('aurora', $builder->build()->get(MaterialKind::Blueprint, 'aurora_quote')->designSystem);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active->activate('borealis');
    $this->assertEqualsCanonicalizing(['call_to_action', 'hero', 'plain_section'], array_keys($builder->build()->blueprints()));
  }

  /**
   * A content blueprint's media icon rides the record resolved to a URL.
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
    file_put_contents('public://blueprint-icon.png', 'png');
    $file = File::create(['uri' => 'public://blueprint-icon.png', 'filename' => 'blueprint-icon.png']);
    $file->save();
    $media = Media::create([
      'bundle' => $type->id(),
      'name' => 'Blueprint icon',
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    $this->createBlueprint([
      'label' => 'Promo',
      'machine_name' => 'promo',
      'description' => 'A promo section.',
      'icon' => $media->id(),
    ]);

    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $roster = $builder->build();
    $this->assertSame(
      $this->container->get('file_url_generator')->generateString('public://blueprint-icon.png'),
      $roster->get(MaterialKind::Blueprint, 'promo')->icon,
    );
    $this->assertContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());

    $media->delete();
    $builder->reset();
    $roster = $builder->build();
    $this->assertNull($roster->get(MaterialKind::Blueprint, 'promo')->icon);
    $this->assertNotContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());
  }

  /**
   * The budget guards sharing: an offered save, or `offered` turned on.
   *
   * With the budget set tiny, save_material through the batch door (the
   * AI's op; the dialogs submit the same op) is refused with the one
   * AI_CONTEXT_FULL wording at path `offered`, the reading as context and
   * the dialog's path in the hint, and nothing is saved; the same save
   * with `offered: false` goes through. On the provider (the details
   * route's door) an update turning `offered` on is refused at `offered`,
   * while a face edit keeping it off passes and so does turning
   * `favorite` on: the favorite is the Add panel's listing, no AI surface
   * reads it, so it shares nothing. With room again the same update
   * shares; full again, re-stating the flag already on (the details
   * dialog re-sends it) is no share and passes.
   */
  public function testBudgetGuardsSharing(): void {
    $this->installConfig(['daedalus_blueprint']);
    ['entity' => $entity, 'root_section' => $root] = $this->createHost();
    $budget = $this->container->get('daedalus_blueprint.materials_budget');
    $provider = $this->container->get('daedalus_blueprint_library.materials_provider');
    $storage = $this->container->get('entity_type.manager')->getStorage('blueprint');
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => '<d-container id="hero"></d-container>',
        'at' => ['ref' => $root, 'position' => 'afterend'],
      ],
    ], $this->dialectOptions());
    $this->assertBatchSuccess($result);
    $hero = $result->getAliases()['hero'];
    $save = [
      'op' => 'save_material',
      'kind' => 'blueprint',
      'element' => $hero,
      'label' => 'Banner',
      'description' => 'The banner section',
    ];

    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $reading = $budget->reading();
    $this->assertTrue($reading->over);

    // The AI's op: an offered save is refused through the batch door with
    // the provider's refusal as the batch's one error, and nothing saved.
    $refused = $this->operations()->batchOperations($this->host($entity), [$save], $this->dialectOptions());
    $this->assertFalse($refused->isSuccess(), 'A full site refuses an offered save.');
    $errors = $refused->getErrors();
    $this->assertCount(1, $errors);
    $this->assertErrorIsFull($errors[0], $reading, 'offered');
    $this->assertSame([], $storage->loadByProperties(['machine_name' => 'banner']), 'A refused save mints nothing.');

    // A save withheld from the AI shares nothing and goes through.
    $this->assertBatchSuccess($this->operations()->batchOperations($this->host($entity), [$save + ['offered' => FALSE]], $this->dialectOptions()));
    $blueprint = $this->loadBlueprint('banner');
    $this->assertFalse($blueprint->isOffered());
    $this->assertFalse($blueprint->isFavorite());
    $id = $blueprint->uuid();

    // The details route's door: turning offered on is refused at that
    // path; a face edit keeping it off passes, and so does marking the
    // blueprint a favorite, which shares nothing.
    $share = ['offered' => TRUE, 'label' => 'Shared banner'];
    $this->assertRefusedAsFull(fn () => $provider->update(MaterialKind::Blueprint, $id, $share, NULL, []), $reading, 'offered');
    $this->assertSame('Banner', $this->loadBlueprint('banner')->label(), 'A refused update writes nothing.');
    $provider->update(MaterialKind::Blueprint, $id, ['label' => 'Banner section', 'offered' => FALSE, 'favorite' => TRUE], NULL, []);
    $blueprint = $this->loadBlueprint('banner');
    $this->assertSame('Banner section', $blueprint->label());
    $this->assertTrue($blueprint->isFavorite(), 'A favorite is a listing fact: it passes on a full site.');
    $this->assertFalse($blueprint->isOffered());

    // Room again: the same update shares.
    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, MaterialsBudget::INSTALL_DEFAULTS[MaterialsBudget::KEY_BUDGET])->save();
    $this->assertFalse($budget->reading()->over);
    $provider->update(MaterialKind::Blueprint, $id, ['offered' => TRUE, 'favorite' => TRUE], NULL, []);
    $blueprint = $this->loadBlueprint('banner');
    $this->assertTrue($blueprint->isOffered());
    $this->assertTrue($blueprint->isFavorite());

    // Full again: the flag re-stated as it stands is no share.
    $this->config('daedalus_blueprint.settings')->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $this->assertTrue($budget->reading()->over);
    $provider->update(MaterialKind::Blueprint, $id, ['label' => 'Banner', 'offered' => TRUE, 'favorite' => TRUE], NULL, []);
    $this->assertSame('Banner', $this->loadBlueprint('banner')->label());
  }

}
