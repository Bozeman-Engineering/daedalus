<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability\Policy;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * On a non-default translation the document is frozen; content is open.
 *
 * The document (the section tree plus its style map) is ONE structure
 * shared by every translation: core creates the layout field
 * untranslatable and denies Layout Builder on translations, and the style
 * map keys into that one tree. A write through a translation would mutate
 * it for every language, so configure, style, arrange and insert are
 * refused there; translatable field values stay the translation's own.
 */
final class TranslationPolicy implements CapabilityPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    if (!$capability->isDocumentWrite()) {
      return NULL;
    }
    $fields = $host->fields();
    if (!$fields instanceof TranslatableInterface || $fields->isDefaultTranslation()) {
      return NULL;
    }
    return new Refusal(
      code: 'LAYOUT_NOT_TRANSLATABLE',
      message: 'The layout is shared across all translations and cannot be written through a non-default translation.',
      hint: 'Write translatable field values with langcode; write the layout and styles without a langcode (they apply to every language).',
    );
  }

}
