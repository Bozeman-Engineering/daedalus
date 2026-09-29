<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorManager;
use Drupal\daedalus_ai\FieldHandler\ImageGenerationHandler;
use Drupal\daedalus_ai\ImageDimension\AspectRatioDimensionAdapter;
use Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager;
use Drupal\daedalus_ai\ImageGenerationGate;
use Drupal\daedalus_ai\MediaMinter;
use Drupal\daedalus_ai\PendingMedia\GenerationJobStore;
use Drupal\daedalus_ai\PendingMedia\PendingMediaMinter;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\User;

/**
 * Base for the image-generation handler and gate kernel tests.
 *
 * These two scenarios share an identical, non-trivial fixture (a media:image
 * bundle, an existing media entity, an entity_test field referencing media,
 * the daedalus_ai default config, and a created user whose per-user preference can
 * be flipped). Reinstalling Drupal once per test METHOD is the dominant cost of
 * a kernel run, so the related gate/handler scenarios share this single setUp
 * rather than each paying for its own install.
 *
 * The daedalus_ai MODULE is deliberately NOT enabled: its info.yml hard-depends on
 * the full AI stack (ai_assistant_api, ai_agents, modeler_api,
 * daedalus_blueprint_ai), none of which the units under test (the field handler
 * and the gate) actually need. Enabling that chain would multiply the install
 * cost and add unrelated fragility. Instead the handler and gate are
 * constructed directly from their real collaborators pulled from the container
 * (entity_type.manager, the daedalus_blueprint access checker, daedalus_ui'
 * EditorSettings), which is exactly how the service container would wire them.
 * The daedalus_ai.settings config the gate/handler read is set on the config
 * factory directly for the same reason.
 *
 * The per-user gate level is flipped through the REAL EditorSettings service
 * (not a mock), because that is the integration the gate documents: the toggle
 * reads a value off the user entity's daedalus_ui_settings map field. The
 * site-wide level is flipped through real config. Helpers for both live here.
 */
abstract class DaedalusAiKernelTestBase extends KernelTestBase {

  use UserCreationTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The daedalus_ai module is intentionally not enabled (its hard AI-stack
   * dependencies are unrelated to the gate/handler under test), so its config
   * schema for daedalus_ai.settings is not loaded. These tests only READ that
   * config, they do not audit its schema, so the checker is disabled rather
   * than dragging in the whole module just to register one schema file.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'entity_test',
    'daedalus_blueprint',
    // daedalus_ui provides EditorSettings (the per-user gate level) and the
    // daedalus_ui_settings base field it reads. Its own dependency chain
    // (navigation, twig_events, daedalus_tempstore) must be listed explicitly: the
    // activation voter service fails to compile if daedalus_tempstore
    // is not enabled first. The core navigation module's renderer in turn pulls
    // layout_builder, so layout_builder/layout_discovery are listed too.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_ui',
    // daedalus_blueprint_api provides the TargetKeyMapper the pending-target
    // collector maps field targets through.
    'daedalus_blueprint_api',
  ];

  /**
   * The image generation field handler under test.
   *
   * @var \Drupal\daedalus_ai\FieldHandler\ImageGenerationHandler
   */
  protected ImageGenerationHandler $handler;

  /**
   * The image generation gate under test.
   *
   * @var \Drupal\daedalus_ai\ImageGenerationGate
   */
  protected ImageGenerationGate $gate;

  /**
   * An existing media entity for plain (non-generate) reference values.
   *
   * @var int
   */
  protected int $existingMediaId;

  /**
   * The job store the minter registers into.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\GenerationJobStore
   */
  protected GenerationJobStore $jobStore;

  /**
   * The shared media minter the pending minter mints through.
   *
   * @var \Drupal\daedalus_ai\MediaMinter
   */
  protected MediaMinter $mediaMinter;

  /**
   * The pending-media minter behind the handler.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\PendingMediaMinter
   */
  protected PendingMediaMinter $minter;

  /**
   * The pending-target collector the handler registers into.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\PendingTargetCollector
   */
  protected PendingTargetCollector $collector;

  /**
   * The dimension manager the handler reads sizing through (no provider).
   *
   * @var \Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterManager
   */
  protected ImageDimensionAdapterManager $dimensionManager;

  /**
   * The current (authenticated) user whose preference the gate reads.
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $currentUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media', 'filter', 'text']);

    // An authenticated user is required: the gate's per-user level reads
    // daedalus_ui EditorSettings, which returns the default for anonymous.
    // Grant the entity_test view permission so the existing media reference
    // is not dropped by the access-aware reference resolution in the parent
    // handler. The user is created BEFORE the gate is built so the gate's
    // EditorSettings reads this account.
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'editor'],
      ['view media', 'access content'],
    );

    // A media:image bundle plus an existing media entity for the plain
    // (target_id) reference path. createMediaType() also creates the
    // bundle's source field (as the media type form would), so entities are
    // genuinely savable.
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);
    $existing = Media::create([
      'bundle' => 'image',
      'name' => 'Existing media',
    ]);
    $existing->save();
    $this->existingMediaId = (int) $existing->id();

    // A single-value entity_reference field on entity_test targeting media:
    // the surface the image-generation handler applies to.
    FieldStorageConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'cardinality' => 1,
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_media_image',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Image',
      'settings' => ['handler' => 'default'],
    ])->save();

    // Seed the config the gate/minter read (the module's default config),
    // since the daedalus_ai module itself is not enabled here.
    $this->config('daedalus_ai.settings')
      ->set('image_generation_enabled', TRUE)
      ->save();

    $this->gate = new ImageGenerationGate(
      $this->container->get('config.factory'),
      $this->container->get('daedalus_ui.editor_settings'),
    );
    // The dimension manager's provider lookup needs the ai module's provider
    // plugin manager, which the AI stack (deliberately not enabled here, see
    // the class docblock) would provide. That class is final and cannot be
    // doubled, so override the lookup itself to report "no provider
    // configured"; the aspect-ratio adapter is the catch-all default, matching
    // production wiring when no text_to_image provider is set.
    $dimension_manager = new class() extends ImageDimensionAdapterManager {

      public function __construct() {}

      /**
       * {@inheritdoc}
       */
      protected function getDefaults(): array {
        return [];
      }

    };
    $dimension_manager->addAdapter(new AspectRatioDimensionAdapter());
    $this->dimensionManager = $dimension_manager;

    // The handler is mint+delegate sugar over the pending-media capability:
    // real minter, job store, and collector, wired from the same container
    // collaborators their service definitions name.
    $this->jobStore = new GenerationJobStore($this->container->get('tempstore.private'));
    $this->mediaMinter = new MediaMinter(
      $this->container->get('entity_type.manager'),
      $this->container->get('daedalus_ui.file_lane'),
      $this->container->get('file.mime_type.guesser'),
      $this->container->get('daedalus_blueprint.access_checker'),
    );
    $this->minter = new PendingMediaMinter(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('file_system'),
      $this->container->get('file.repository'),
      $this->container->get('extension.list.module'),
      $this->gate,
      $this->jobStore,
      $this->mediaMinter,
      $this->container->getParameter('app.root'),
    );
    $this->collector = new PendingTargetCollector(
      $this->container->get('tempstore.private'),
      fn () => $this->container->get('daedalus_blueprint_api.target_key_mapper'),
      new DeferredProcessorManager(),
    );
    $this->handler = new ImageGenerationHandler(
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.channel.default'),
      $this->minter,
      $this->collector,
      $dimension_manager,
      $this->container->get('daedalus_blueprint.access_checker'),
    );
  }

  /**
   * Returns the field_media_image definition for handler deserialization.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The entity_test field_media_image definition.
   */
  protected function mediaFieldDefinition() {
    return $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create()
      ->getFieldDefinition('field_media_image');
  }

  /**
   * Flips the site-wide image-generation master switch.
   *
   * @param bool $enabled
   *   TRUE to enable, FALSE to disable site-wide.
   */
  protected function setSiteGate(bool $enabled): void {
    $this->config('daedalus_ai.settings')
      ->set('image_generation_enabled', $enabled)
      ->save();
  }

  /**
   * Flips the current user's per-user generate-images preference.
   *
   * Written through the real EditorSettings service so the test exercises the
   * same read/write path the live toolbar toggle uses. Passing NULL clears the
   * preference, restoring the unset (defaults-on) state.
   *
   * @param string|null $value
   *   '1' to enable, '0' to disable, NULL to unset the preference.
   */
  protected function setUserGate(?string $value): void {
    $editor_settings = $this->container->get('daedalus_ui.editor_settings');
    if ($value === NULL) {
      // Clear the namespace key directly on the user entity to model "unset".
      $user = User::load($this->currentUser->id());
      $user->set('daedalus_ui_settings', []);
      $user->save();
      return;
    }
    $editor_settings->set(
      ImageGenerationGate::SETTING_NAMESPACE,
      ImageGenerationGate::SETTING_KEY,
      $value,
    );
  }

}
