<?php

namespace Drupal\daedalus_edit_formatted_text_block\Plugin\Field\SampleValueGenerator;

use Drupal\Component\Utility\Random;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field_sample_value\Attribute\SampleValueGenerator;
use Drupal\field_sample_value\Plugin\Field\SampleValueGenerator\RandomTextValue;

/**
 * Random paragraphs that carry their own <p> markup.
 *
 * The formatted text block stamps its shipped daedalus_edit_formatted_text
 * format, which has no filter_autop (CKEditor emits explicit markup), so bare
 * text renders unwrapped and
 * unstyled. A sample value idiomatic to such a format must carry its own
 * paragraph structure.
 */
#[SampleValueGenerator(
  id: 'wrapped_random_text',
  label: new TranslatableMarkup('Random text (paragraph markup)'),
  field_types: ['text_long', 'text_with_summary'],
)]
class WrappedRandomText extends RandomTextValue {

  /**
   * {@inheritdoc}
   *
   * Generates paragraph by paragraph, each wrapped in <p>, instead of
   * post-processing Random::paragraphs() output (which joins on blank
   * lines and appends a trailing one).
   */
  protected function generateText(array $settings): string {
    $random = new Random();
    $paragraphs = [];
    for ($i = 0; $i < $this->getConfiguration()['count']; $i++) {
      $paragraphs[] = '<p>' . $random->sentences(mt_rand(4, 12)) . '</p>';
    }
    return implode("\n", $paragraphs);
  }

}
