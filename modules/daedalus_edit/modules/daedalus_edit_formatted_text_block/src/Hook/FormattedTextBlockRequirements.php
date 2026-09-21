<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit_formatted_text_block\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\filter\FilterFormatInterface;

/**
 * Status report entry for the text format the shipped field stamps.
 *
 * The module ships the format its field stamps (sample values and editor
 * settings name it), but the field config cannot express that as a config
 * dependency: a field config depending on filter.format.* is deleted, with
 * its content, when the format is deleted, because only the field type (not
 * a third-party setting) gets to repair a dependency removal. So the status
 * report says so instead when the format has gone missing or been disabled.
 */
class FormattedTextBlockRequirements {

  use StringTranslationTrait;

  /**
   * The text format the shipped field's sample values and editor stamp.
   */
  public const FORMAT = 'daedalus_edit_formatted_text';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $format = $this->entityTypeManager->getStorage('filter_format')->load(self::FORMAT);
    if ($format instanceof FilterFormatInterface && $format->status()) {
      return [];
    }
    return [
      'daedalus_edit_formatted_text_block_text_format' => [
        'title' => $this->t('Daedalus Edit Formatted Text Block text format'),
        'value' => self::FORMAT,
        'description' => $this->t('Daedalus Edit Formatted Text Block ships fields whose sample values and editor settings use the @format text format, which is missing or disabled. Content stamped with it renders empty until the format exists and is enabled.', [
          '@format' => self::FORMAT,
        ]),
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

}
