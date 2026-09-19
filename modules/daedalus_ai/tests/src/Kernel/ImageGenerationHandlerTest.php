<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the AI-driven image-generation write path of the field handler.
 *
 * ImageGenerationHandler is mint+delegate sugar over the one generation
 * capability (PendingMediaMinter): when the LLM sends a media reference
 * value carrying a '_generate' key, the handler mints a media entity
 * synchronously (identity-first) and sets the reference IMMEDIATELY; only
 * the pixels are deferred, as a generation job keyed by the media uuid.
 * Several invariants are safety-critical and untested by daedalus_blueprint's
 * parent-handler tests (which only cover the plain target_id/_label path):
 *
 * - Gate ON, create: a NEW media entity carrying the placeholder file is
 *   minted and referenced, a generation job rides the store under its uuid
 *   (prompt/alt plus the adapter-built provider configuration), and the
 *   media uuid + write target are registered with the pending-target
 *   collector for the turn's trigger command.
 * - Gate ON, regenerate: a NEW media is minted seeded with the previous
 *   media's file (visual continuity, shared media never mutated) and the
 *   reference moves to it immediately.
 * - aspect_ratio validation: only 1:1 / 4:3 / 16:9 reach the job (as
 *   adapter-shaped configuration); an unsupported ratio is dropped so it
 *   cannot reach Imagen as a bad argument.
 * - Gate OFF: NO job is registered (so the disabled provider is never
 *   called), the minted placeholder is the final image, and a
 *   level-specific warning is returned so the AI can tell the user how to
 *   re-enable generation. The remediation text DIFFERS by which level is
 *   off (self-service vs admin); conflating them would mislead the user.
 * - applies(): the handler only intercepts entity_reference fields
 *   targeting media, never other reference fields.
 *
 * The handler is exercised through daedalus_blueprint's real deserialize()
 * entry point against a real media reference field, which is the production
 * path. Methods are kept fat (one create/regenerate sweep, one gate-off
 * sweep) to minimize per-method reinstalls without weakening any assertion.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class ImageGenerationHandlerTest extends DaedalusAiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Minting creates media as the current user; the base user has only view
    // permissions, so re-log as an account that may create image media.
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'generating editor'],
      ['view media', 'access content', 'create image media'],
    );
  }

  /**
   * Applies() targets only media entity_reference fields.
   *
   * The handler is tagged at priority 10 on all entity_reference fields, so its
   * applies() is the gate that keeps it from hijacking non-media references.
   */
  public function testAppliesOnlyToMediaReferences(): void {
    // A media reference: handled.
    $this->assertTrue($this->handler->applies($this->mediaFieldDefinition()));

    // A non-reference field: not handled.
    $string_definition = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create()
      ->getFieldDefinition('name');
    $this->assertFalse($this->handler->applies($string_definition));
  }

  /**
   * Gate ON: the whole generation-enabled path.
   *
   * Create mints and references immediately; regenerate seeds from the
   * previous file; aspect_ratio is validated; the collector is fed.
   *
   * This single method sweeps the entire generation-enabled path because each
   * assertion shares the same installed fixture; splitting them would
   * multiply the install cost for no added coverage.
   */
  public function testGenerationEnabledCreateRegenerateAndAspectRatio(): void {
    $definition = $this->mediaFieldDefinition();

    // CREATE with a valid aspect_ratio and an element uuid (the deserializer
    // threads it as the element_uuid option on every write path).
    $element_uuid = '2ccc0362-5579-85a0-f3a6-624b8a6de818';
    $create = $this->handler->deserialize(
      [
        '_generate' => 'a serene mountain lake at dawn',
        'alt_text' => 'A calm lake reflecting mountains',
        'aspect_ratio' => '16:9',
      ],
      $definition,
      ['element_uuid' => $element_uuid],
    );

    $this->assertFalse($create->hasErrors());
    // Identity-first: the reference is final at compose time and points at a
    // freshly minted media entity, not at any pre-existing placeholder.
    $value = $create->getValue();
    $this->assertIsArray($value);
    $minted = Media::load((int) $value['target_id']);
    $this->assertNotNull($minted, 'Create sets the reference to a saved media entity immediately.');
    $this->assertNotSame($this->existingMediaId, (int) $minted->id());
    $this->assertSame('A calm lake reflecting mountains', $minted->getName());
    // The minted media carries the ensured placeholder file.
    $file = File::load((int) $minted->get('field_media_image')->target_id);
    $this->assertSame('public://daedalus_ai/image-placeholder.png', $file->getFileUri());
    // Generation on: no warning is attached.
    $this->assertEmpty($create->getWarnings());

    // The job is registered under the minted media's uuid with the prompt,
    // alt text, and adapter-shaped configuration.
    $job = $this->jobStore->get($minted->uuid());
    $this->assertNotNull($job, 'A generation job rides the store under the media uuid.');
    $this->assertSame('a serene mountain lake at dawn', $job->prompt);
    $this->assertSame('A calm lake reflecting mountains', $job->altText);
    $this->assertSame(['aspect_ratio' => '16:9'], $job->configuration);

    // The collector received the media uuid plus the write target the
    // turn's trigger command will shimmer.
    $pending = $this->collector->getPending();
    $this->assertCount(1, $pending);
    $this->assertSame($minted->uuid(), $pending[0]['media_uuid']);
    $this->assertSame(
      ['uuid' => $element_uuid, 'field_name' => 'field_media_image'],
      $pending[0]['target'],
    );

    // REGENERATE: the field already references media backed by a real file.
    // A NEW media is minted seeded with that file (visual continuity), the
    // previous media is untouched, and the reference moves immediately.
    file_put_contents('public://previous.png', 'previous-pixels');
    $previous_file = File::create(['uri' => 'public://previous.png']);
    $previous_file->save();
    $previous_media = Media::create([
      'bundle' => 'image',
      'name' => 'Previous image',
      'field_media_image' => ['target_id' => $previous_file->id(), 'alt' => 'Previous'],
    ]);
    $previous_media->save();
    $host = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['field_media_image' => ['target_id' => $previous_media->id()]]);

    $update = $this->handler->deserialize(
      ['_generate' => 'a new banner image', 'alt_text' => 'Banner'],
      $definition,
      ['entity' => $host, 'element_uuid' => $element_uuid],
    );
    $this->assertFalse($update->hasErrors());
    $new_value = $update->getValue();
    $regenerated = Media::load((int) $new_value['target_id']);
    $this->assertNotSame((int) $previous_media->id(), (int) $regenerated->id(), 'Regenerate mints a NEW media; shared media is never mutated.');
    $this->assertSame(
      (int) $previous_file->id(),
      (int) $regenerated->get('field_media_image')->target_id,
      'The new mint is seeded with the previous media\'s file for visual continuity.',
    );
    $this->assertNotNull($this->jobStore->get($regenerated->uuid()));

    // alt_text defaults to the prompt when omitted.
    $no_alt = $this->handler->deserialize(['_generate' => 'sunset over the sea'], $definition);
    $no_alt_media = Media::load((int) $no_alt->getValue()['target_id']);
    $this->assertSame('sunset over the sea', $this->jobStore->get($no_alt_media->uuid())->altText);

    // INVALID aspect_ratio is dropped entirely so it cannot reach the
    // provider as a bad argument; the processor falls back to its default.
    $bad_ratio = $this->handler->deserialize(
      ['_generate' => 'a portrait', 'aspect_ratio' => '3:2'],
      $definition,
    );
    $bad_media = Media::load((int) $bad_ratio->getValue()['target_id']);
    $this->assertSame([], $this->jobStore->get($bad_media->uuid())->configuration, 'An unsupported aspect ratio is stripped rather than passed through.');

    // Each supported ratio passes through unchanged.
    foreach (['1:1', '4:3', '16:9'] as $ratio) {
      $valid = $this->handler->deserialize(
        ['_generate' => 'a tile', 'aspect_ratio' => $ratio],
        $definition,
      );
      $valid_media = Media::load((int) $valid->getValue()['target_id']);
      $this->assertSame(['aspect_ratio' => $ratio], $this->jobStore->get($valid_media->uuid())->configuration);
    }

    // The LLM-array-of-one normalization: a single-value field sent as [{...}]
    // is unwrapped and handled identically to {...}.
    $wrapped = $this->handler->deserialize(
      [['_generate' => 'a single wrapped value', 'aspect_ratio' => '4:3']],
      $definition,
    );
    $this->assertFalse($wrapped->hasErrors());
    $wrapped_media = Media::load((int) $wrapped->getValue()['target_id']);
    $this->assertNotNull($this->jobStore->get($wrapped_media->uuid()));
  }

  /**
   * Gate OFF (per-user toggle): mint without a job, self-service warning.
   *
   * The per-user level is the one a non-admin editor controls. With it off the
   * site switch stays on, so the warning must point the user at their own
   * "Generate images" toggle, not at an administrator.
   */
  public function testPerUserGateOffMintsFinalPlaceholderWithSelfServiceWarning(): void {
    $this->setUserGate('0');
    $this->assertFalse($this->gate->isEnabled());

    $result = $this->handler->deserialize(
      ['_generate' => 'a hero image', 'alt_text' => 'Hero'],
      $this->mediaFieldDefinition(),
    );

    // The media still mints and is referenced immediately, but its
    // placeholder file is the final image: no Imagen job is queued and
    // nothing registers with the collector.
    $media = Media::load((int) $result->getValue()['target_id']);
    $this->assertNotNull($media);
    $this->assertNull($this->jobStore->get($media->uuid()));
    $this->assertFalse($this->collector->hasPending());

    // A single level-specific warning rides back to the AI.
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('image_generation_disabled', $warnings[0]->getCode());
    $this->assertSame('field_media_image', $warnings[0]->getPath());
    // Self-service remediation: the per-user toggle, not an administrator.
    $this->assertStringContainsString('Generate images toggle', $warnings[0]->getMessage());
    $this->assertStringNotContainsString('administrator', $warnings[0]->getMessage());
  }

  /**
   * Gate OFF (site-wide switch): mint without a job, admin warning.
   *
   * With the master switch off the warning must tell the user an administrator
   * has to act, regardless of the per-user toggle. This is the branch that
   * protects an admin's deliberate site-wide disable from being worked around.
   */
  public function testSiteGateOffMintsFinalPlaceholderWithAdminWarning(): void {
    $this->setSiteGate(FALSE);
    $this->assertFalse($this->gate->isEnabled());

    $result = $this->handler->deserialize(
      ['_generate' => 'a hero image', 'alt_text' => 'Hero'],
      $this->mediaFieldDefinition(),
    );

    $media = Media::load((int) $result->getValue()['target_id']);
    $this->assertNotNull($media);
    $this->assertNull($this->jobStore->get($media->uuid()));

    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('image_generation_disabled', $warnings[0]->getCode());
    // Admin remediation: the user cannot fix this themselves.
    $this->assertStringContainsString('administrator must enable it', $warnings[0]->getMessage());
  }

  /**
   * A plain reference (no _generate) falls straight through to the parent.
   *
   * The handler must not interfere with ordinary media references: a value
   * without _generate is resolved exactly as EntityReferenceFieldHandler
   * would, with no mint, no job, and no warning.
   */
  public function testPlainReferenceFallsThroughToParent(): void {
    $result = $this->handler->deserialize(
      ['target_id' => $this->existingMediaId],
      $this->mediaFieldDefinition(),
    );

    $this->assertFalse($result->hasErrors());
    $this->assertSame(['target_id' => $this->existingMediaId], $result->getValue());
    $this->assertFalse($this->collector->hasPending());
    $this->assertEmpty($result->getWarnings());
  }

}
