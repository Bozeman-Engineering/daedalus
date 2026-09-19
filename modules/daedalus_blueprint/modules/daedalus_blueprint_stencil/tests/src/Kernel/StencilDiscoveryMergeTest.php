<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\Exception\StencilDefinitionException;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two-source discovery merge: files plus stencil content entities.
 *
 * Discovery serves both sources uniformly (the same definition shape, with a
 * source marker and a provider that is NULL for content) in ONE flat ID
 * namespace where every collision is loud in both directions: content saves
 * validate their machine name against the combined vocabulary at presave,
 * and a module shipping the ID of an already-saved content stencil fails at
 * discovery exactly like a file-vs-file conflict. Content CRUD invalidates
 * the discovery cache through the vocabulary cache tag, so consumers never
 * serve a stale palette.
 */
#[CoversClass(StencilDiscovery::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilDiscoveryMergeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The file fixture module is deliberately absent here: tests that need
   * file stencils enable it mid-test, because the interesting collisions
   * depend on which source existed first.
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
   * The discovery service, resolved late (enableModules rebuilds containers).
   */
  protected function discovery(): StencilDiscovery {
    return \Drupal::service('daedalus_blueprint_stencil.discovery');
  }

  /**
   * Creates and saves a stencil content entity.
   */
  protected function createStencil(array $values = []): Stencil {
    $stencil = Stencil::create($values + [
      'label' => 'Testimonial',
      'description' => 'A quote beside a portrait.',
    ]);
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
    $stencil->save();
    return $stencil;
  }

  /**
   * Content stencils are served uniformly and track entity CRUD.
   */
  public function testContentStencilsServedUniformlyWithCrudInvalidation(): void {
    // Prime the discovery cache while no content exists: the CRUD
    // invalidation below has to beat a warm cache, not an empty one.
    $this->assertSame([], $this->discovery()->getStencils());

    // A saved stencil appears without any manual cache clearing (the insert
    // hook invalidates the vocabulary tag), in the exact shape a file
    // definition has, with the content source marker and a NULL provider.
    $stencil = $this->createStencil();
    $definition = $this->discovery()->getStencil('testimonial');
    $this->assertIsArray($definition);
    $this->assertSame('Testimonial', $definition['label']);
    $this->assertSame('A quote beside a portrait.', $definition['description']);
    $this->assertSame('layout_twocol_section', $definition['element']['layout_id']);
    $this->assertNull($definition['provider']);
    $this->assertSame(StencilDiscovery::SOURCE_CONTENT, $definition['source']);
    // No icon media set: the uniform "no icon" spelling is NULL.
    $this->assertNull($definition['icon']);
    $this->assertSame([
      'label',
      'description',
      'icon',
      'design_system',
      'offered',
      'element',
      'provider',
      'source',
      'origin',
    ], array_keys($definition));
    $this->assertSame('user', $definition['origin']);

    // Updates land immediately too: the palette face follows the entity.
    $stencil->set('label', 'Quote Card')->save();
    $this->assertSame('Quote Card', $this->discovery()->getStencil('testimonial')['label']);

    // Unpublishing pulls the stencil from the served set (the palette must
    // not offer a staged stencil), but the ID stays reserved: a new content
    // stencil cannot take it.
    $stencil->setUnpublished()->save();
    $this->assertNull($this->discovery()->getStencil('testimonial'));
    try {
      $this->createStencil(['machine_name' => 'testimonial']);
      $this->fail('A machine name reserved by an unpublished stencil was reused.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // Deleting frees the ID and drops the entry, again with no manual clear.
    $stencil->delete();
    $this->assertNull($this->discovery()->getStencil('testimonial'));
    $this->createStencil(['machine_name' => 'testimonial']);
    $this->assertSame(StencilDiscovery::SOURCE_CONTENT, $this->discovery()->getStencil('testimonial')['source']);
  }

  /**
   * A module shipping an existing content stencil's ID fails discovery.
   *
   * The direction presave cannot catch: the content stencil was saved first,
   * legitimately, and the colliding file arrives with a later module
   * install. Discovery errors exactly like a file-vs-file conflict.
   */
  public function testModuleShippingExistingContentIdFailsLoudly(): void {
    $this->createStencil(['label' => 'Call to Action', 'description' => 'A user-saved CTA.']);
    $this->assertSame(StencilDiscovery::SOURCE_CONTENT, $this->discovery()->getStencil('call_to_action')['source']);

    // The fixture ships call_to_action from a file.
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('A file stencil colliding with a content stencil must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      // The content stencil is the blamed definition (content merges after
      // files), so the exception's provider is NULL and the error names the
      // shipping module it collided with.
      $this->assertNull($e->getProvider());
      $this->assertSame('call_to_action', $e->getStencilId());
      $error = $e->getErrors()[0];
      $this->assertSame('STENCIL_CONFLICT', $error->getCode());
      $this->assertSame(['daedalus_blueprint_stencil_test', NULL], $error->getContext()['providers']);
    }
  }

  /**
   * Content presave validates machine names against the combined vocabulary.
   */
  public function testContentPresaveValidatesCombinedVocabulary(): void {
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    // An explicit machine name colliding with a file stencil fails loudly at
    // presave (storage wraps the exception).
    try {
      $this->createStencil(['machine_name' => 'hero']);
      $this->fail('A content stencil took a file stencil\'s ID.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('"hero"', $e->getPrevious()->getMessage());
    }

    // Auto-generation consults the combined vocabulary too: a label whose
    // natural machine name is taken by a FILE stencil suffixes past it
    // instead of colliding.
    $hero = $this->createStencil(['label' => 'Hero', 'description' => 'A user-saved hero.']);
    $this->assertSame('hero_2', $hero->getMachineName());

    // Content-vs-content is equally loud for explicit names.
    try {
      $this->createStencil(['machine_name' => 'hero_2']);
      $this->fail('Two content stencils took the same machine name.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
    }

    // Re-saving an existing stencil never trips over its own name: the
    // combined check excludes the entity itself on both sides.
    $hero->set('description', 'Still a user-saved hero.')->save();
    $this->assertSame('hero_2', $hero->getMachineName());

    // Both sources coexist in the one served set once the names differ.
    $stencils = $this->discovery()->getStencils();
    $this->assertSame(StencilDiscovery::SOURCE_MODULE, $stencils['hero']['source']);
    $this->assertSame(StencilDiscovery::SOURCE_CONTENT, $stencils['hero_2']['source']);
  }

}
