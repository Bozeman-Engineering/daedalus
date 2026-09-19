<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_ui\EditorSettings;

/**
 * Determines whether AI image generation is currently enabled.
 *
 * Generation is gated at two levels and runs only when both are on:
 *   - a site-wide master switch (daedalus_ai.settings.image_generation_enabled),
 *     and
 *   - a per-user preference, toggled live from the Edit Mode toolbar and stored
 *     per-account via daedalus_ui EditorSettings (key
 *     daedalus_ai.generate_images).
 *
 * When generation is off, media are still minted with the placeholder image
 * as their final image and no generation job is queued.
 */
class ImageGenerationGate {

  /**
   * EditorSettings namespace and key for the per-user preference.
   */
  const SETTING_NAMESPACE = 'daedalus_ai';
  const SETTING_KEY = 'generate_images';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EditorSettings $editorSettings,
  ) {}

  /**
   * Whether the site-wide master switch is on.
   *
   * @return bool
   *   TRUE unless an administrator has disabled image generation site-wide.
   */
  public function isSiteEnabled(): bool {
    return (bool) ($this->configFactory->get('daedalus_ai.settings')
      ->get('image_generation_enabled') ?? TRUE);
  }

  /**
   * Whether the current user's preference allows generation.
   *
   * @return bool
   *   FALSE only when the user has explicitly turned generation off; the
   *   preference defaults to on when unset (including for anonymous users).
   */
  public function isUserEnabled(): bool {
    return $this->editorSettings->get(self::SETTING_NAMESPACE, self::SETTING_KEY, '1') !== '0';
  }

  /**
   * Whether image generation is enabled right now (both levels on).
   *
   * @return bool
   *   TRUE when generation should run; FALSE to substitute the placeholder.
   */
  public function isEnabled(): bool {
    return $this->isSiteEnabled() && $this->isUserEnabled();
  }

}
