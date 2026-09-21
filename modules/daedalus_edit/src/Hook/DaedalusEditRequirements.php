<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\filter\FilterFormatInterface;

/**
 * Status report entries for Daedalus Edit.
 *
 * The identity minter skips a vocabulary tag a format allows without an
 * unrestricted class attribute instead of refusing the save (see
 * IdentityMinter's rules): elements of that tag render, but stay
 * unaddressable by Daedalus Stylish and the markup view. The skip is silent
 * on the write path by design, so this is where the loss becomes visible:
 * one warning naming every enabled format and the tags it will not mint,
 * so a site widens the format when it wants those elements addressable.
 */
class DaedalusEditRequirements {

  use StringTranslationTrait;

  public function __construct(
    protected IdentityMinter $identityMinter,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $formats = [];
    foreach ($this->entityTypeManager->getStorage('filter_format')->loadMultiple() as $format) {
      assert($format instanceof FilterFormatInterface);
      if (!$format->status()) {
        continue;
      }
      $unmintable = $this->identityMinter->unmintable($format);
      if ($unmintable !== []) {
        $formats[] = sprintf('%s (%s): %s', $format->label(), $format->id(), implode(', ', $unmintable));
      }
    }
    if ($formats === []) {
      return [];
    }
    return [
      'daedalus_edit_identity_vocabulary' => [
        'title' => $this->t('Daedalus Edit identity vocabulary'),
        'value' => $this->formatPlural(
          count($formats),
          '1 text format allows vocabulary tags without the class attribute',
          '@count text formats allow vocabulary tags without the class attribute',
        ),
        'description' => $this->t('Daedalus Edit mints an identity class (for example @class) onto every vocabulary element it stores so the element can be styled and addressed; a tag a format allows without an unrestricted class attribute is skipped, and its elements stay unaddressable under that format: @formats. Allow the class attribute on those tags (for example @example) to make them addressable.', [
          '@class' => 'class="s-a3f"',
          '@formats' => implode('; ', $formats),
          '@example' => '<p class>',
        ]),
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
