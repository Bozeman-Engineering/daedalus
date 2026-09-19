<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Install;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\filter\Entity\FilterFormat;

/**
 * Runtime status check for a text format a submodule's shipped config stamps.
 *
 * A submodule ships the format its fields stamp (sample values and editor
 * settings name it), but the field config cannot express that as a config
 * dependency: a field config depending on filter.format.* is deleted, with
 * its content, when the format is deleted, because only the field type (not
 * a third-party setting) gets to repair a dependency removal. So the status
 * report says so instead when the format has gone missing or been disabled.
 */
final class TextFormatRequirement {

  /**
   * Builds the hook_requirements() runtime entry for a required text format.
   *
   * @param string $module
   *   The module machine name, used as the requirement key.
   * @param string $module_label
   *   The module's human name.
   * @param string $format
   *   The text format machine name.
   *
   * @return array
   *   A requirements array; empty when the format exists and is enabled.
   */
  public static function check(string $module, string $module_label, string $format): array {
    $format_entity = FilterFormat::load($format);
    if ($format_entity && $format_entity->status()) {
      return [];
    }
    return [
      $module . '_text_format' => [
        'title' => t('@module text format', ['@module' => $module_label]),
        'value' => $format,
        'description' => t('@module ships fields whose sample values and editor settings use the @format text format, which is missing or disabled. Content stamped with it renders empty until the format exists and is enabled.', [
          '@module' => $module_label,
          '@format' => $format,
        ]),
        'severity' => RequirementSeverity::Error,
      ],
    ];
  }

}
