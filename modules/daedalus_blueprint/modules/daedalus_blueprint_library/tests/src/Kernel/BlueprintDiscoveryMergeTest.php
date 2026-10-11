<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\Exception\BlueprintDefinitionException;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two-source discovery merge: files plus blueprint entities.
 *
 * Discovery serves both sources uniformly (the same definition shape, with
 * a source marker and a provider that is NULL for content) in ONE flat ID
 * namespace where every collision is loud in both directions: content
 * saves validate their machine name against the combined vocabulary at
 * presave, and a module shipping the ID of an already-saved content
 * blueprint fails at discovery exactly like a file-vs-file conflict. A
 * content blueprint's document is read through its host (the saved
 * entity's tree and node-less styles), and content CRUD invalidates the
 * discovery cache through the vocabulary cache tag, so consumers never
 * serve a stale palette.
 */
#[CoversClass(BlueprintDiscovery::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintDiscoveryMergeTest extends BlueprintKernelTestBase {

  /**
   * The discovery service, resolved late (enableModules rebuilds containers).
   */
  protected function discovery(): BlueprintDiscovery {
    return \Drupal::service('daedalus_blueprint_library.discovery');
  }

  /**
   * A published blueprint's entry is its built tree; a placement reads it.
   *
   * The merge leg: a blueprint entity with a one-container document (a
   * plugin block inside) appears as an entry whose `layout` is exactly
   * NodeTreeBuilder::build() over the saved entity's host and whose
   * `styles` is the node-less remainder (none here), and the placement
   * operation instantiates that layout onto a page with provenance.
   */
  public function testPublishedDocumentIsTheBuiltTree(): void {
    $section_uuid = \Drupal::service('uuid')->generate();
    $block_uuid = \Drupal::service('uuid')->generate();
    $blueprint = $this->createBlueprint([], [
      $this->section('layout_onecol', [$this->pluginComponent('system_powered_by_block', 'content', $block_uuid)], $section_uuid),
    ]);

    $definition = $this->discovery()->getBlueprint('testimonial');
    $this->assertSame($this->builtLayout($blueprint), $definition['layout'], 'The entry\'s layout is the tree built over the saved entity.');
    $this->assertCount(1, $definition['layout']);
    $root = $definition['layout'][0];
    $this->assertSame('container', $root['kind']);
    $this->assertSame('layout_onecol', $root['layout_id']);
    $this->assertSame($section_uuid, $root['uuid']);
    $this->assertSame('region', $root['children'][0]['kind']);
    $this->assertSame('system_powered_by_block', $root['children'][0]['children'][0]['plugin_id']);
    $this->assertSame($block_uuid, $root['children'][0]['children'][0]['uuid']);
    $this->assertSame([], $definition['styles'], 'No style map: no node-less keys.');
    $this->assertSame('block', $definition['scope']);
    $this->assertSame('entity_test:entity_test', $definition['subject']);

    // The placement operation places the entry's layout, fresh identity
    // and the provenance stamp on the root.
    ['entity' => $entity] = $this->createHost();
    $host = $this->host($entity);
    $result = $this->operations()->batchOperations($host, [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'testimonial',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $sections = $host->sections();
    $this->assertCount(2, $sections);
    $placed = $sections[1];
    $this->assertSame('testimonial', $placed->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
    $this->assertNotSame($section_uuid, $placed->getThirdPartySetting('daedalus_layout', 'uuid'), 'The placement mints its own identity.');
    $components = array_values($placed->getComponents());
    $this->assertCount(1, $components);
    $this->assertSame('system_powered_by_block', $components[0]->getPluginId());
    $this->assertNotSame($block_uuid, $components[0]->getUuid());
  }

  /**
   * Content blueprints are served uniformly and track entity CRUD.
   *
   * The served entry carries the offered flag, the type and the type's
   * frame-only rule (resolved from the type entities once per rebuild)
   * in the file lane's shape, plus the content lane's origin and entity
   * id. The served content entry's key list asserted here and the file
   * definition's in BlueprintDiscoveryTest::testDesignSystemMembership()
   * are the discovery entry's two exhaustive pins. Any key the payload
   * gains is owed to both, and a task that adds one names both classes
   * in its verify.
   */
  public function testContentBlueprintsServedUniformlyWithCrudInvalidation(): void {
    // Prime the discovery cache while no content exists: the CRUD
    // invalidation below has to beat a warm cache, not an empty one.
    $this->assertSame([], $this->discovery()->getBlueprints());

    // A saved blueprint appears without any manual cache clearing (the
    // insert hook invalidates the vocabulary tag), in the exact shape a
    // file definition has, with the content source marker, a NULL
    // provider, its origin and its entity id (a file has no page).
    $blueprint = $this->createBlueprint();
    $definition = $this->discovery()->getBlueprint('testimonial');
    $this->assertIsArray($definition);
    $this->assertSame('Testimonial', $definition['label']);
    $this->assertSame('A quote beside a portrait.', $definition['description']);
    $this->assertSame('layout_onecol', $definition['layout'][0]['layout_id']);
    $this->assertNull($definition['provider']);
    $this->assertSame(BlueprintDiscovery::SOURCE_CONTENT, $definition['source']);
    // No icon media set: the uniform "no icon" spelling is NULL.
    $this->assertNull($definition['icon']);
    $this->assertSame([
      'label',
      'description',
      'icon',
      'design_system',
      'offered',
      'scope',
      'subject',
      'bundles',
      'groups',
      'favorite',
      'type',
      'frame_only',
      'layout',
      'styles',
      'provider',
      'source',
      'origin',
      'entity_id',
    ], array_keys($definition));
    $this->assertFalse($definition['favorite'], 'Not a favorite by default: the one entry shape across both lanes.');
    $this->assertSame([], $definition['bundles'], 'No allow-list: the blueprint fits any host that has what its layout needs.');
    $this->assertSame('user', $definition['origin']);
    $this->assertSame((string) $blueprint->id(), $definition['entity_id'], 'A saved blueprint is a page of its own: the entry names the id the page tools address it by.');

    // Updates land immediately too: the palette face follows the entity.
    $blueprint->set('label', 'Quote Card')->save();
    $this->assertSame('Quote Card', $this->discovery()->getBlueprint('testimonial')['label']);

    // Unpublishing pulls the blueprint from the served set (the palette
    // must not offer a staged blueprint), but the ID stays reserved: a new
    // content blueprint cannot take it.
    $blueprint->setUnpublished()->save();
    $this->assertNull($this->discovery()->getBlueprint('testimonial'));
    try {
      $this->createBlueprint(['machine_name' => 'testimonial']);
      $this->fail('A machine name reserved by an unpublished blueprint was reused.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // Deleting frees the ID and drops the entry, again with no manual clear.
    $blueprint->delete();
    $this->assertNull($this->discovery()->getBlueprint('testimonial'));
    $this->createBlueprint(['machine_name' => 'testimonial']);
    $this->assertSame(BlueprintDiscovery::SOURCE_CONTENT, $this->discovery()->getBlueprint('testimonial')['source']);
  }

  /**
   * A module shipping an existing content blueprint's ID fails discovery.
   *
   * The direction presave cannot catch: the content blueprint was saved
   * first, legitimately, and the colliding file arrives with a later
   * module install. Discovery errors exactly like a file-vs-file conflict.
   */
  public function testModuleShippingExistingContentIdFailsLoudly(): void {
    $this->createBlueprint(['label' => 'Call to Action', 'description' => 'A user-saved CTA.']);
    $this->assertSame(BlueprintDiscovery::SOURCE_CONTENT, $this->discovery()->getBlueprint('call_to_action')['source']);

    // The fixture ships call_to_action from a file.
    $this->enableModules(['daedalus_blueprint_library_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A file blueprint colliding with a content blueprint must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      // The content blueprint is the blamed definition (content merges
      // after files), so the exception's provider is NULL and the error
      // names the shipping module it collided with.
      $this->assertNull($e->getProvider());
      $this->assertSame('call_to_action', $e->getBlueprintId());
      $error = $e->getErrors()[0];
      $this->assertSame('BLUEPRINT_CONFLICT', $error->getCode());
      $this->assertSame(['daedalus_blueprint_library_test', NULL], $error->getContext()['providers']);
    }
  }

  /**
   * Content presave validates machine names against the combined vocabulary.
   */
  public function testContentPresaveValidatesCombinedVocabulary(): void {
    $this->enableModules(['daedalus_blueprint_library_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    // An explicit machine name colliding with a file blueprint fails loudly
    // at presave (storage wraps the exception).
    try {
      $this->createBlueprint(['machine_name' => 'hero']);
      $this->fail('A content blueprint took a file blueprint\'s ID.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('"hero"', $e->getPrevious()->getMessage());
    }

    // Auto-generation consults the combined vocabulary too: a label whose
    // natural machine name is taken by a FILE blueprint suffixes past it
    // instead of colliding.
    $hero = $this->createBlueprint(['label' => 'Hero', 'description' => 'A user-saved hero.']);
    $this->assertSame('hero_2', $hero->getMachineName());

    // Content-vs-content is equally loud for explicit names.
    try {
      $this->createBlueprint(['machine_name' => 'hero_2']);
      $this->fail('Two content blueprints took the same machine name.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // Re-saving an existing blueprint never trips over its own name: the
    // combined check excludes the entity itself on both sides.
    $hero->set('description', 'Still a user-saved hero.')->save();
    $this->assertSame('hero_2', $hero->getMachineName());

    // Both sources coexist in the one served set once the names differ.
    $blueprints = $this->discovery()->getBlueprints();
    $this->assertSame(BlueprintDiscovery::SOURCE_MODULE, $blueprints['hero']['source']);
    $this->assertSame(BlueprintDiscovery::SOURCE_CONTENT, $blueprints['hero_2']['source']);
  }

}
