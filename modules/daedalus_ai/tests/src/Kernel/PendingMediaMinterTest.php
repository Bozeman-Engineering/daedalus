<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\PendingMedia\GenerationJobStore;
use Drupal\daedalus_ai\PendingMedia\PendingMediaMinter;
use Drupal\Core\Access\AccessException;
use Drupal\file\Entity\File;
use Drupal\media\MediaInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the pending-media minter: identity now, pixels later.
 *
 * Extends the gate/handler kernel base for its shared fixture (media:image
 * bundle, daedalus_ai.settings seed, gate helpers); the minter itself is
 * constructed directly from real container collaborators, mirroring its
 * service definition, because the daedalus_ai module's AI-stack dependencies are
 * unrelated to minting (see the base class docblock).
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class PendingMediaMinterTest extends DaedalusAiKernelTestBase {

  /**
   * The minter under test.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\PendingMediaMinter
   */
  protected PendingMediaMinter $minter;

  /**
   * The job store the minter registers into.
   *
   * @var \Drupal\daedalus_ai\PendingMedia\GenerationJobStore
   */
  protected GenerationJobStore $jobStore;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Minting creates media as the current user; the base user has only view
    // permissions, so mint as an account that may create image media.
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'minting editor'],
      ['view media', 'access content', 'create image media'],
    );

    $this->jobStore = new GenerationJobStore($this->container->get('tempstore.private'));
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
  }

  /**
   * Gate on: media mints with the placeholder file and the job is queued.
   */
  public function testMintQueuesJob(): void {
    $result = $this->minter->mint(
      'A dark forest at dusk',
      'Dark forest',
      ['aspect_ratio' => '16:9'],
    );

    $this->assertTrue($result->jobQueued);
    $this->assertNull($result->warning);

    $media = $result->media;
    $this->assertFalse($media->isNew(), 'The media entity is saved at mint time.');
    $this->assertSame('image', $media->bundle());
    $this->assertSame('Dark forest', $media->getName());

    $source_item = $this->sourceItem($media);
    $this->assertSame('Dark forest', $source_item->alt);
    $file = File::load((int) $source_item->target_id);
    $this->assertSame('public://daedalus_ai/image-placeholder.png', $file->getFileUri());
    $this->assertTrue(file_exists($file->getFileUri()), 'The ensured placeholder file exists on disk.');
    $this->assertGreaterThan(0, filesize($file->getFileUri()), 'The shipped asset was actually copied.');

    // The job is registered under the media uuid with the mint's payload.
    $job = $this->jobStore->get($media->uuid());
    $this->assertNotNull($job);
    $this->assertSame('A dark forest at dusk', $job->prompt);
    $this->assertSame('Dark forest', $job->altText);
    $this->assertSame(['aspect_ratio' => '16:9'], $job->configuration);

    // A second mint reuses the ensured managed file rather than copying again,
    // and an AI-minted uuid becomes the media's identity.
    $uuid = 'ab2c58f0-6b6f-4c7a-9d3e-2f4a5b6c7d8e';
    $second = $this->minter->mint('A sunny meadow', 'Meadow', [], $uuid);
    $this->assertSame($uuid, $second->media->uuid());
    $this->assertSame(
      (int) $source_item->target_id,
      (int) $this->sourceItem($second->media)->target_id,
      'Both mints share the one ensured placeholder file.',
    );
    $this->assertSame([], $this->jobStore->get($uuid)->configuration);
  }

  /**
   * Gate off: media still mints, no job, warning names the remediation level.
   */
  public function testMintGateOff(): void {
    // Site-wide switch off: remediation is an administrator's.
    $this->setSiteGate(FALSE);
    $result = $this->minter->mint('A dark forest', 'Dark forest');
    $this->assertFalse($result->jobQueued);
    $this->assertNull($this->jobStore->get($result->media->uuid()), 'No job is registered when the gate is off.');
    $this->assertSame('image_generation_disabled', $result->warning->getCode());
    $this->assertStringContainsString('an administrator must enable it', $result->warning->getMessage());
    // The placeholder file is the final image.
    $file = File::load((int) $this->sourceItem($result->media)->target_id);
    $this->assertSame('public://daedalus_ai/image-placeholder.png', $file->getFileUri());

    // Per-user toggle off: remediation is the user's own.
    $this->setSiteGate(TRUE);
    $this->setUserGate('0');
    $result = $this->minter->mint('A dark forest', 'Dark forest');
    $this->assertFalse($result->jobQueued);
    $this->assertStringContainsString('Generate images toggle', $result->warning->getMessage());

    // buildDisabledWarning re-scopes the warning to a caller-known path.
    $scoped = $this->minter->buildDisabledWarning('field_media_image');
    $this->assertSame('field_media_image', $scoped->getPath());
    $this->assertSame('image_generation_disabled', $scoped->getCode());
  }

  /**
   * A configured placeholder_image overrides the shipped asset.
   */
  public function testPlaceholderOverride(): void {
    // A site-supplied placeholder at an unmanaged public:// uri: the minter
    // ensures its own managed copy under public://daedalus_ai on first use.
    $uri = 'public://custom-placeholder.png';
    // Resolve the shipped asset through the extension list: the module lives
    // under modules/custom on d.o CI, not modules/contrib.
    $shipped = $this->container->getParameter('app.root') . '/' . $this->container->get('extension.list.module')->getPath('daedalus_ai') . '/' . PendingMediaMinter::DEFAULT_ASSET;
    file_put_contents($uri, file_get_contents($shipped));
    $this->config('daedalus_ai.settings')->set('placeholder_image', $uri)->save();

    $result = $this->minter->mint('A dark forest', 'Dark forest');
    $file = File::load((int) $this->sourceItem($result->media)->target_id);
    $this->assertSame('public://daedalus_ai/custom-placeholder.png', $file->getFileUri());
    $this->assertSame(
      filesize($uri),
      filesize($file->getFileUri()),
      'The ensured managed file is a byte-for-byte copy of the override.',
    );
  }

  /**
   * Overlong derived metadata is clamped at the mint choke point.
   *
   * CSS url('generate:') values carry no alt slot, so callers fall back to
   * the prompt as alt text and the media name inherits it; a long prompt
   * would overflow the storage columns (name varchar 255, alt varchar 512)
   * and the minter saves without entity validation. The clamp lives in the
   * shared MediaMinter; the GenerationJob keeps the full prompt.
   */
  public function testMintClampsDerivedMetadata(): void {
    $prompt = trim(str_repeat('sweeping golden dunes under a violet evening sky ', 13));
    $this->assertGreaterThan(512, mb_strlen($prompt));

    // No alt_text on the surface: the caller's fallback passes the prompt.
    $result = $this->minter->mint($prompt, $prompt);
    $media = $result->media;
    $this->assertFalse($media->isNew(), 'The media saves despite the overlong prompt.');

    // Name clamps to the 255 column, wordsafe with a trailing ellipsis: the
    // stem is a whole-word prefix of the prompt (the prompt continues with a
    // word boundary right after it).
    $name = $media->getName();
    $this->assertLessThanOrEqual(255, mb_strlen($name));
    $this->assertStringEndsWith('…', $name);
    $stem = mb_substr($name, 0, -1);
    $this->assertSame($stem, mb_substr($prompt, 0, mb_strlen($stem)));
    $this->assertSame(' ', mb_substr($prompt, mb_strlen($stem), 1), 'The wordsafe cut lands on a word boundary, not mid-word.');

    // Alt clamps to the 512 column the same way.
    $alt = $this->sourceItem($media)->alt;
    $this->assertLessThanOrEqual(512, mb_strlen($alt));
    $this->assertStringEndsWith('…', $alt);
    $alt_stem = mb_substr($alt, 0, -1);
    $this->assertSame($alt_stem, mb_substr($prompt, 0, mb_strlen($alt_stem)));

    // The generation job rides the full untruncated prompt.
    $job = $this->jobStore->get($media->uuid());
    $this->assertSame($prompt, $job->prompt);
  }

  /**
   * Minting requires media create access.
   */
  public function testMintRequiresCreateAccess(): void {
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'viewer'],
      ['view media', 'access content'],
    );
    $this->expectException(AccessException::class);
    $this->minter->mint('A dark forest', 'Dark forest');
  }

  /**
   * Returns the media source field's first item.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The minted media.
   *
   * @return \Drupal\Core\Field\FieldItemInterface
   *   The source field item (target_id + alt).
   */
  protected function sourceItem(MediaInterface $media) {
    $source_field = $media->bundle->entity->getSource()
      ->getSourceFieldDefinition($media->bundle->entity)
      ->getName();
    return $media->get($source_field)->first();
  }

}
