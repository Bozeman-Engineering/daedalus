<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\StyleValue\GenerateStyleValueHandler;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\daedalus_stylish\StyleValueResolver;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the `_generate` handled value on the style grammar's input tier.
 *
 * GenerateStyleValueHandler is the style grammar's face of the one
 * generation capability (PendingMediaMinter): a media-capable style
 * property value may arrive as {"_generate": "prompt"} and resolves at
 * write time to the canonical stored token url('media:<uuid>'). The
 * invariants under test:
 *
 * - CHECK is side-effect-free: shape errors (empty prompt, bad alt_text,
 *   unsupported params — prompt-only by design, sizing is CSS's job) and
 *   the media-create access gate all surface as validation errors, and
 *   nothing is minted, queued, or registered.
 * - The stand-in is plain CSS ("none"), never a media token, so
 *   StyleMapValidator's media-token loading can never trip over it.
 * - COMMIT, gate on: a media entity is minted with the placeholder file,
 *   the resolved fragment is the canonical token for its uuid, a
 *   generation job (prompt, empty provider configuration) rides the store
 *   under that uuid, and the pending target names the styles FIELD on the
 *   written entity (the subtree hosting the style backend's <style> tag,
 *   the completion's swap target) with the style key riding as
 *   pending_key for the shimmer; a lane with no entity degrades to the
 *   bare style key, and the variant root's empty key registers no target.
 * - COMMIT, gate off: the mint still happens (the placeholder file is the
 *   final image), no job is queued, nothing is registered, and the
 *   minter's disabled warning lands in the seam's warnings channel at the
 *   value's path.
 * - Through the REAL StyleValueResolver, a layers list joins literals and
 *   the resolved token into one comma-separated value, and commit-time
 *   warnings surface on the resolution.
 *
 * The handler is constructed from the same real collaborators the service
 * container wires (see DaedalusAiKernelTestBase: the daedalus_ai module itself
 * stays uninstalled to avoid its AI-stack dependency chain; daedalus_stylish is
 * enabled here for the seam interface and the resolver's collaborators).
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class GenerateStyleValueHandlerTest extends DaedalusAiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * The style-value handler under test.
   */
  protected GenerateStyleValueHandler $styleHandler;

  /**
   * A selectable style key to write under.
   */
  protected string $styleKey = 'container:2ccc0362-5579-85a0-f3a6-624b8a6de818';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->styleHandler = new GenerateStyleValueHandler(
      $this->minter,
      $this->collector,
      $this->container->get('daedalus_blueprint.access_checker'),
    );
  }

  /**
   * Grants the current user the right to create image media.
   *
   * The base user is view-only, which is exactly what the access-gate
   * assertions need; the commit-phase tests re-log through this.
   */
  protected function logInGeneratingEditor(): void {
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'generating editor'],
      ['view media', 'access content', 'create image media'],
    );
  }

  /**
   * Builds the seam's context array for a value in a style map.
   *
   * @param string $key
   *   The style-map key ('' for a variant's root element).
   * @param int|null $layer
   *   The layer index, NULL for a bare object value.
   *
   * @return array
   *   The context as StyleValueResolver builds it.
   */
  protected function styleContext(string $key, ?int $layer = NULL): array {
    $path = ($key === '' ? '' : "$key.") . 'default.background-image';
    return [
      'key' => $key,
      'condition' => 'default',
      'property' => 'background-image',
      'path' => $path,
      'layer' => $layer,
      'entity' => NULL,
    ];
  }

  /**
   * Counts the saved media entities.
   */
  protected function mediaCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * CHECK phase: shape and access errors, and no side effects ever.
   */
  public function testCheckPhaseIsSideEffectFreeAndValidates(): void {
    $context = $this->styleContext($this->styleKey);
    $this->assertSame('_generate', $this->styleHandler->getMarkerKey());

    // The base user cannot create media: a sound value still fails CHECK,
    // so COMMIT can never throw an access exception mid-write.
    $errors = $this->styleHandler->check(['_generate' => 'a dark forest'], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_ENTITY', $errors[0]->getCode());

    $this->logInGeneratingEditor();
    $media_before = $this->mediaCount();

    // A sound value checks clean and stands in as plain CSS (never a media
    // token — the validator loads those).
    $value = ['_generate' => 'a dark forest', 'alt_text' => 'Dark forest'];
    $this->assertSame([], $this->styleHandler->check($value, $context));
    $this->assertSame('none', $this->styleHandler->getStandIn($value, $context));

    // Shape errors: empty prompt, bad alt_text, and unsupported params
    // (prompt-only by design: backgrounds size via CSS, aspect rides the
    // provider default).
    $errors = $this->styleHandler->check(['_generate' => '  '], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('_generate', $errors[0]->getPath());
    $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode());

    $errors = $this->styleHandler->check(['_generate' => 'a forest', 'alt_text' => 42], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('alt_text', $errors[0]->getPath());

    $errors = $this->styleHandler->check(['_generate' => 'a forest', 'aspect_ratio' => '16:9'], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('', $errors[0]->getPath());
    $this->assertStringContainsString('aspect_ratio', $errors[0]->getMessage());

    // Side-effect-free throughout: nothing minted, queued, or registered.
    $this->assertSame($media_before, $this->mediaCount());
    $this->assertFalse($this->collector->hasPending());
  }

  /**
   * COMMIT, gate on: mint, canonical token, queued job, registered target.
   */
  public function testResolveMintsQueuesAndRegisters(): void {
    $this->logInGeneratingEditor();

    $context = $this->styleContext($this->styleKey);
    $warnings = [];
    $css = $this->styleHandler->resolve(
      ['_generate' => 'a dark forest at dusk', 'alt_text' => 'Dark forest'],
      $context,
      $warnings,
    );

    // The canonical stored token, final at compose time.
    $this->assertMatchesRegularExpression("/^url\\('media:[0-9a-f-]{36}'\\)$/", $css);
    $media_uuid = substr($css, 11, 36);

    // Identity-first: a saved media entity carries the placeholder file.
    $media = $this->container->get('entity.repository')->loadEntityByUuid('media', $media_uuid);
    $this->assertNotNull($media, 'The resolved token references a saved media entity.');
    $this->assertSame('Dark forest', $media->getName());
    $file = File::load((int) $media->get('field_media_image')->target_id);
    $this->assertSame('public://daedalus_ai/image-placeholder.png', $file->getFileUri());

    // The pixels are deferred: a job rides the store under the media uuid,
    // prompt-only (provider-default dimensions).
    $job = $this->jobStore->get($media_uuid);
    $this->assertNotNull($job, 'A generation job was queued for the minted media.');
    $this->assertSame('a dark forest at dusk', $job->prompt);
    $this->assertSame('Dark forest', $job->altText);
    $this->assertSame([], $job->configuration);

    // A keyed write whose lane carried no entity (this context) degrades to
    // the bare style key: shimmer targeting without a completion swap.
    $this->assertSame(
      [['media_uuid' => $media_uuid, 'target' => $this->styleKey]],
      $this->collector->getPending(),
    );
    $this->assertSame([], $warnings);

    // With the written entity in context (every real page lane), the target
    // is the styles FIELD on that entity — the subtree hosting the style
    // backend's <style> tag, whose completion swap repaints the background
    // live — with the style key riding as pending_key for the shimmer.
    $host = EntityTest::create(['name' => 'styled host']);
    $host->save();
    $entity_context = $this->styleContext($this->styleKey);
    $entity_context['entity'] = $host;
    $css = $this->styleHandler->resolve(['_generate' => 'a rocky coast'], $entity_context, $warnings);
    $hosted_uuid = substr($css, 11, 36);
    $pending = $this->collector->getPending();
    $this->assertSame([
      'media_uuid' => $hosted_uuid,
      'target' => [
        'uuid' => $host->uuid(),
        'field_name' => DaedalusStylishFieldManager::FIELD_NAME,
        'pending_key' => $this->styleKey,
      ],
    ], end($pending));

    // The variant-styles root entry has no key: the media still mints and
    // queues, with no page target registered behind it.
    $css = $this->styleHandler->resolve(['_generate' => 'a misty valley'], $this->styleContext(''), $warnings);
    $root_uuid = substr($css, 11, 36);
    $pending = $this->collector->getPending();
    $this->assertCount(3, $pending);
    $this->assertSame(['media_uuid' => $root_uuid, 'target' => NULL], $pending[2]);
    // alt_text omitted: the prompt names the media.
    $this->assertSame('a misty valley', $this->jobStore->get($root_uuid)->altText);

    // Through the REAL resolver, a layers list joins literals and the
    // resolved token into one comma-separated canonical value.
    $resolver = new StyleValueResolver(
      [$this->styleHandler],
      $this->container->get('plugin.manager.daedalus_stylish_property_group'),
    );
    $resolution = $resolver->check([
      $this->styleKey => [
        'default' => [
          'background-image' => [
            'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))',
            ['_generate' => 'dark forest'],
          ],
        ],
      ],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame(
      'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), none',
      $resolution->getMap()[$this->styleKey]['default']['background-image'],
      'The stand-in map is plain CSS for the validator.',
    );
    $committed = $resolution->commit()[$this->styleKey]['default']['background-image'];
    $this->assertMatchesRegularExpression(
      "/^linear-gradient\\(rgb\\(0 0 0 \\/ 0\\.5\\), rgb\\(0 0 0 \\/ 0\\.5\\)\\), url\\('media:[0-9a-f-]{36}'\\)$/",
      $committed,
    );
    $this->assertSame([], $resolution->getWarnings());
  }

  /**
   * COMMIT, gate off: placeholder is final, no job, warning at the path.
   */
  public function testResolveGateOff(): void {
    $this->logInGeneratingEditor();
    $this->setSiteGate(FALSE);

    $context = $this->styleContext($this->styleKey);
    $warnings = [];
    $css = $this->styleHandler->resolve(['_generate' => 'a dark forest'], $context, $warnings);

    // The reference is still final — the placeholder file IS the image.
    $this->assertMatchesRegularExpression("/^url\\('media:[0-9a-f-]{36}'\\)$/", $css);
    $media_uuid = substr($css, 11, 36);
    $media = $this->container->get('entity.repository')->loadEntityByUuid('media', $media_uuid);
    $file = File::load((int) $media->get('field_media_image')->target_id);
    $this->assertSame('public://daedalus_ai/image-placeholder.png', $file->getFileUri());

    // No job (the disabled provider is never called), no pending target.
    $this->assertNull($this->jobStore->get($media_uuid));
    $this->assertFalse($this->collector->hasPending());

    // The disabled warning rides the seam's warnings channel, located at
    // the value's map path.
    $this->assertCount(1, $warnings);
    $this->assertSame('image_generation_disabled', $warnings[0]->getCode());
    $this->assertSame($context['path'], $warnings[0]->getPath());

    // The REAL resolver surfaces it on the resolution after commit.
    $resolver = new StyleValueResolver(
      [$this->styleHandler],
      $this->container->get('plugin.manager.daedalus_stylish_property_group'),
    );
    $resolution = $resolver->check([
      $this->styleKey => ['default' => ['background-image' => ['_generate' => 'a dark forest']]],
    ]);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame([], $resolution->getWarnings(), 'No warnings before commit.');
    $resolution->commit();
    $resolution_warnings = $resolution->getWarnings();
    $this->assertCount(1, $resolution_warnings);
    $this->assertSame('image_generation_disabled', $resolution_warnings[0]->getCode());
  }

}
