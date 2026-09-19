<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\StyleValue;

use Drupal\daedalus_ai\PendingMedia\PendingMediaMinter;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\StyleValueHandlerInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;

/**
 * The `_generate` handled value on the style grammar's input tier.
 *
 * Registers daedalus_ai's one generation capability (PendingMediaMinter) on
 * daedalus_stylish's style-value seam, so a media-capable style property (e.g.
 * background-image) accepts {"_generate": "prompt"} exactly the way
 * media-reference fields do — one word, one meaning, in both host grammars.
 *
 * Identity-first, content-deferred: COMMIT mints the media synchronously
 * (real uuid, placeholder file) and resolves to the canonical stored token
 * url('media:<uuid>'), so the stored CSS is final at compose time; the
 * generation job fills the file behind the uuid later. The registered
 * pending target names the styles FIELD on the entity whose map was
 * written (the same target update_style answers with), because the
 * resolved CSS lives in the style backend's <style> tag inside that
 * entity's wrapper, not in the styled element's own subtree; swapping the
 * field subtree on completion is what repaints the background live. The
 * style key itself rides along as pending_key so the shimmer lands on the
 * styled ELEMENT (style keys are selectable keys).
 *
 * Params are prompt-only (plus optional alt_text for the media entity):
 * backgrounds are sized with CSS, and the image's aspect rides the
 * provider default. When image generation is gated off, the mint still
 * happens with the placeholder file as the final image, no job is queued,
 * and the minter's disabled warning surfaces on the write's result.
 *
 * Soft wiring: the service is tagged daedalus_stylish.style_value_handler; without
 * daedalus_stylish the tag is never collected and this class never loads, so daedalus_ai
 * takes no hard dependency on daedalus_stylish.
 */
class GenerateStyleValueHandler implements StyleValueHandlerInterface {

  public function __construct(
    protected PendingMediaMinter $minter,
    protected PendingTargetCollector $pendingTargets,
    protected BlueprintAccessChecker $accessChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getMarkerKey(): string {
    return '_generate';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return '{"_generate": "detailed description of the desired image", "alt_text": "accessible image description"} (alt_text optional) generates a new image for a media-capable property: it stores a final url(\'media:<uuid>\') token immediately, and the image itself generates asynchronously behind that uuid, so never wait for it or rewrite the value. Size the background with CSS (background-size and friends).';
  }

  /**
   * {@inheritdoc}
   *
   * Side-effect-free: shape-checks the object and the current user's right
   * to create media (so COMMIT never throws an access exception mid-write);
   * nothing is minted here.
   */
  public function check(array $value, array $context): array {
    $errors = [];

    $prompt = $value['_generate'];
    if (!is_string($prompt) || trim($prompt) === '') {
      $errors[] = new BlueprintError('_generate', 'INVALID_STYLE_VALUE', 'The _generate prompt must be a non-empty string.', [], 'Provide {"_generate": "detailed description of the desired image"}.');
    }

    $alt = $value['alt_text'] ?? NULL;
    if ($alt !== NULL && (!is_string($alt) || trim($alt) === '')) {
      $errors[] = new BlueprintError('alt_text', 'INVALID_STYLE_VALUE', 'alt_text must be a non-empty string when provided.', [], 'Omit alt_text to reuse the prompt as the image description.');
    }

    if ($extra = array_diff(array_keys($value), ['_generate', 'alt_text'])) {
      $errors[] = new BlueprintError('', 'INVALID_STYLE_VALUE', sprintf('Unsupported key(s) on the _generate value: %s.', implode(', ', $extra)), [], 'A generated style value takes only _generate (the prompt) and optional alt_text. Size the background with CSS (background-size and friends); the generated image itself uses the provider default dimensions.');
    }

    if (!$this->accessChecker->createAccess('media', PendingMediaMinter::MEDIA_BUNDLE)) {
      $errors[] = new BlueprintError('', 'ACCESS_DENIED_ENTITY', 'The current user cannot create media, so no image can be generated for this value.', [], 'Provide the value as plain CSS (e.g. url(\'media:<uuid>\') referencing existing media) instead.');
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   *
   * "none" is sound in any <image> slot and, unlike a media token, loads
   * nothing at validation time.
   */
  public function getStandIn(array $value, array $context): string {
    return 'none';
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(array $value, array $context, array &$warnings): string {
    $prompt = trim($value['_generate']);
    $alt_text = trim((string) ($value['alt_text'] ?? '')) ?: $prompt;

    $mint = $this->minter->mint($prompt, $alt_text);

    if ($mint->jobQueued) {
      // The completion swap targets the styles FIELD on the written entity
      // (where the style backend's <style> tag renders), mirroring
      // update_style's own targets; the style key rides as pending_key so
      // the shimmer lands on the styled element itself. The variant-styles
      // lane's root entry has no key ('') and its entity is the variant,
      // not a page — the media still processes, just with no page target.
      // A keyed write whose lane carried no entity (the seam allows NULL)
      // degrades to the bare style key: shimmer without a completion swap.
      $key = $context['key'];
      $entity = $context['entity'];
      $target = NULL;
      if ($key !== '') {
        $target = $entity === NULL ? $key : [
          'uuid' => $entity->uuid(),
          'field_name' => DaedalusStylishFieldManager::FIELD_NAME,
          'pending_key' => $key,
        ];
      }
      $this->pendingTargets->register($mint->media->uuid(), $target);
    }
    else {
      // Gate off: the placeholder file is the final image; tell the AI why
      // and how the user restores generation, located at this value.
      $warnings[] = $this->minter->buildDisabledWarning($context['path']);
    }

    return MediaToken::build($mint->media->uuid());
  }

}
