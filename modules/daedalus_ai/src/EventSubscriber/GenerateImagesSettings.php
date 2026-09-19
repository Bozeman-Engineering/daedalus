<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\daedalus_ai\ImageGenerationGate;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\Event\SettingsSidebarEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes the per-user image-generation toggle to Edit Mode settings.
 *
 * Whether the AI generates real images or placeholders is a user preference
 * on the editing session, not a tool function — it lived on the DaedalusAi tool
 * only because the tool existed. The tool died with the selection-spine
 * convergence (the chat panel consumes the one selection now), so the
 * setting rides the non-tool settings seam, the same re-homing the layout
 * display toggles took when the Layout tool died.
 */
final class GenerateImagesSettings implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ImageGenerationGate $imageGenerationGate,
  ) {}

  /**
   * Adds the per-user "Generate images" toggle to the settings sidebar.
   *
   * @param \Drupal\daedalus_ui\Event\SettingsSidebarEvent $event
   *   The settings sidebar build event.
   */
  public function onBuildSettingsSidebar(SettingsSidebarEvent $event): void {
    // Expose the per-user toggle only when generation is enabled site-wide;
    // otherwise the master switch wins and the choice is moot.
    if (!$this->imageGenerationGate->isSiteEnabled()) {
      return;
    }

    $checked = $this->imageGenerationGate->isUserEnabled();
    $event->setSettings('daedalus_ai_generate_images', [
      'generate_images' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'daedalus-ai-toggle-generate-images',
          'title' => $this->t('Generate new images for image fields with AI. When off, a placeholder image is used instead.'),
        ],
        'label' => [
          '#id' => 'daedalus-ai-generate-images',
          '#type' => 'checkbox',
          '#title' => $this->t('Generate images'),
          '#attributes' => $checked ? ['checked' => 'checked'] : [],
        ],
        '#attached' => ['library' => ['daedalus_ai/generate_images_setting']],
      ],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SettingsSidebarEvent::class => ['onBuildSettingsSidebar'],
    ];
  }

}
