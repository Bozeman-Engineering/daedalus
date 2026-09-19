<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

use Drupal\daedalus_ai\ImageGenerationGate;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;

/**
 * Mints pending media: identity now, pixels later.
 *
 * The one capability behind every image-generation surface (media-reference
 * fields, CSS background values, future UI buttons). A mint saves a real
 * media:image entity synchronously, with a real uuid, the requested alt text,
 * and a placeholder FILE behind its source field, then registers a generation
 * job keyed by that uuid. References to the media (field target_id, CSS media
 * token) are therefore final at compose time; the deferred pipeline later
 * swaps only the file, and cache tags propagate the change.
 *
 * Gate-aware: when image generation is off (site-wide or per-user, see
 * ImageGenerationGate), the media is still minted but the placeholder file is
 * the final image; no job is registered and the result carries a warning the
 * AI relays to the user.
 *
 * The placeholder file is a managed file ensured on first mint from
 * daedalus_ai.settings placeholder_image (a stream URI or an app-root-relative
 * path; empty means the neutral asset shipped with the module).
 */
class PendingMediaMinter {

  /**
   * The media bundle minted media belong to.
   */
  const MEDIA_BUNDLE = 'image';

  /**
   * The module-shipped placeholder asset, relative to the module directory.
   */
  const DEFAULT_ASSET = 'assets/image-placeholder.png';

  /**
   * Destination directory for the ensured managed placeholder file.
   */
  const PLACEHOLDER_DIRECTORY = 'public://daedalus_ai';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected ModuleExtensionList $moduleList,
    protected ImageGenerationGate $gate,
    protected GenerationJobStore $jobStore,
    protected BlueprintAccessChecker $accessChecker,
    protected string $appRoot,
  ) {}

  /**
   * Mints a pending media entity and queues its generation job.
   *
   * @param string $prompt
   *   The image generation prompt.
   * @param string $alt_text
   *   Alt text for the image (also the media name).
   * @param array $configuration
   *   Provider-shaped dimension configuration from the active provider's
   *   image-dimension adapter. Empty for the provider default.
   * @param string|null $uuid
   *   An AI-minted uuid to assign to the media entity, or NULL to let the
   *   entity system mint one.
   * @param \Drupal\file\FileInterface|null $seed_file
   *   A file to seed the mint with instead of the placeholder. A regenerate
   *   mints a NEW media seeded with the previous media's file (visual
   *   continuity while the pixels fill; shared media is never mutated).
   *
   * @return \Drupal\daedalus_ai\PendingMedia\MintResult
   *   The saved media, whether a job was queued, and the disabled-generation
   *   warning when the gate was off.
   *
   * @throws \Drupal\Core\Access\AccessException
   *   When the current user may not create image media.
   */
  public function mint(string $prompt, string $alt_text, array $configuration = [], ?string $uuid = NULL, ?FileInterface $seed_file = NULL): MintResult {
    if (!$this->accessChecker->createAccess('media', self::MEDIA_BUNDLE)) {
      throw new AccessException('Access denied: cannot create media.');
    }

    $file = $seed_file ?? $this->ensurePlaceholderFile();
    $media = $this->createMedia($alt_text, $file, $uuid);

    if (!$this->gate->isEnabled()) {
      return new MintResult($media, FALSE, $this->buildDisabledWarning());
    }

    $this->jobStore->set(new GenerationJob(
      mediaUuid: $media->uuid(),
      prompt: $prompt,
      altText: $alt_text,
      configuration: $configuration,
    ));
    return new MintResult($media, TRUE);
  }

  /**
   * Builds the warning returned to the AI when generation is disabled.
   *
   * Remediation differs by gate level: a site-wide switch needs an
   * administrator, a per-user toggle the user can flip themselves.
   *
   * @param string $path
   *   JSON-path-style location for the warning; consumers that know the field
   *   or property the mint served pass it here.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning
   *   The disabled-generation warning.
   */
  public function buildDisabledWarning(string $path = ''): BlueprintWarning {
    [$state, $fix] = $this->gate->isSiteEnabled()
      ? ['is off in your settings', 're-enable it via the Generate images toggle']
      : ['is disabled site-wide', 'an administrator must enable it'];
    return new BlueprintWarning(
      $path,
      'image_generation_disabled',
      sprintf('Image generation %s; the placeholder image was used as the final image. Tell the user: %s.', $state, $fix),
    );
  }

  /**
   * Creates and saves the media entity carrying the placeholder file.
   *
   * @param string $alt_text
   *   Alt text for the source field item (and the media name).
   * @param \Drupal\file\FileInterface $file
   *   The placeholder file.
   * @param string|null $uuid
   *   Optional uuid to assign.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media entity.
   */
  protected function createMedia(string $alt_text, FileInterface $file, ?string $uuid): MediaInterface {
    /** @var \Drupal\media\MediaTypeInterface $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load(self::MEDIA_BUNDLE);
    $source_field_name = $media_type->getSource()
      ->getSourceFieldDefinition($media_type)
      ->getName();

    // The alt text is often a generation prompt (CSS backgrounds have no alt
    // slot, so the prompt rides in as library metadata) and can exceed the
    // storage columns: media name is varchar 255, image alt varchar 512. The
    // minter saves without entity validation, so clamp here, at the one choke
    // point every mint surface funnels through.
    $values = [
      'bundle' => self::MEDIA_BUNDLE,
      'name' => Unicode::truncate($alt_text, 255, TRUE, TRUE),
      'status' => 1,
      $source_field_name => [
        'target_id' => $file->id(),
        'alt' => Unicode::truncate($alt_text, 512, TRUE, TRUE),
      ],
    ];
    if ($uuid !== NULL) {
      $values['uuid'] = $uuid;
    }

    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->entityTypeManager->getStorage('media')->create($values);
    $media->save();
    return $media;
  }

  /**
   * Ensures the managed placeholder file exists and returns it.
   *
   * Resolution order: a managed file already at the configured URI; the
   * managed copy ensured by a previous mint; a fresh managed copy of the
   * configured source (the shipped module asset when the setting is empty).
   *
   * @return \Drupal\file\FileInterface
   *   The managed placeholder file.
   */
  protected function ensurePlaceholderFile(): FileInterface {
    $source = trim((string) $this->configFactory->get('daedalus_ai.settings')->get('placeholder_image'));
    if ($source === '') {
      $source = $this->moduleList->getPath('daedalus_ai') . '/' . self::DEFAULT_ASSET;
    }

    $storage = $this->entityTypeManager->getStorage('file');

    // A configured stream URI that is already a managed file is used as-is.
    $existing = $storage->loadByProperties(['uri' => $source]);
    if ($existing) {
      return reset($existing);
    }

    $destination = self::PLACEHOLDER_DIRECTORY . '/' . basename($source);
    $existing = $storage->loadByProperties(['uri' => $destination]);
    if ($existing) {
      return reset($existing);
    }

    // Paths without a scheme are relative to the app root (module assets).
    $readable = $source;
    if (!str_contains($readable, '://') && !str_starts_with($readable, '/')) {
      $readable = $this->appRoot . '/' . $readable;
    }
    $data = @file_get_contents($readable);
    if ($data === FALSE) {
      throw new \RuntimeException(sprintf('Placeholder image source %s is not readable.', $source));
    }

    $directory = self::PLACEHOLDER_DIRECTORY;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $this->fileRepository->writeData($data, $destination, FileExists::Replace);
  }

}
