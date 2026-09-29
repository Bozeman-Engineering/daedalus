<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

use Drupal\daedalus_ai\ImageGenerationGate;
use Drupal\daedalus_ai\MediaMinter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Mints pending media: identity now, pixels later.
 *
 * The one capability behind every image-generation surface (media-reference
 * fields, CSS background values, future UI buttons). A mint saves a real
 * media:image entity synchronously through the shared MediaMinter, with a
 * real uuid, the requested alt text, and a placeholder FILE behind its source
 * field, then registers a generation job keyed by that uuid. References to
 * the media (field target_id, CSS media token) are therefore final at compose
 * time; the deferred pipeline later swaps only the file, and cache tags
 * propagate the change.
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
   * The media bundle every pending mint lands in.
   *
   * The placeholder and every seed file are raster, and the shared minter
   * resolves a raster to this bundle; surfaces that gate access before a
   * mint check against it.
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
    protected MediaMinter $mediaMinter,
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
   *   When the current user may not create image media (the shared minter
   *   checks create access on the resolved bundle).
   */
  public function mint(string $prompt, string $alt_text, array $configuration = [], ?string $uuid = NULL, ?FileInterface $seed_file = NULL): MintResult {
    $file = $seed_file ?? $this->ensurePlaceholderFile();
    $media = $this->mediaMinter->mintFromFile($file, $alt_text, NULL, $uuid);

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
