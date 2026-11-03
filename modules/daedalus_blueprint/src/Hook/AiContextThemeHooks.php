<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialKind;

/**
 * The AI context usage bar: one BudgetReading as a disk-usage gauge.
 *
 * The theme hook daedalus_blueprint_ai_context takes the reading and the
 * active design system's label and prints the whole budget as one bar:
 * a segment per material kind present (characters above zero), one for
 * the system (the fixed grammar no material toggle shrinks) and the free
 * remainder, with a legend stating each segment's characters and
 * estimated tokens. The preprocess derives the segments here so the
 * template prints and nothing else: widths are shares of the budget in
 * characters (the budget in tokens times the ratio), and past the budget
 * the used segments are scaled to fill the bar whole, the free part
 * gone, the text stating by how much the site is over. Tokens are an
 * estimate (the reading's ratio) and the template says so.
 */
final class AiContextThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'daedalus_blueprint_ai_context' => [
        'variables' => [
          'reading' => NULL,
          'design_system' => NULL,
        ],
        'initial preprocess' => static::class . ':preprocessAiContext',
      ],
    ];
  }

  /**
   * Prepares variables for the AI context usage template.
   *
   * Default template: daedalus-blueprint-ai-context.html.twig.
   *
   * @param array $variables
   *   An associative array containing:
   *   - reading: The BudgetReading.
   *   - design_system: The active design system's label, or NULL.
   */
  public function preprocessAiContext(array &$variables): void {
    $reading = $variables['reading'];
    assert($reading instanceof BudgetReading);
    $ratio = $reading->charactersPerToken;
    $budgetCharacters = $reading->budgetTokens * $ratio;
    $scale = $reading->total > $budgetCharacters ? $budgetCharacters / $reading->total : 1.0;

    $segments = [];
    foreach (MaterialKind::cases() as $kind) {
      $characters = $reading->of($kind);
      if ($characters === 0) {
        continue;
      }
      $segments[] = $this->segment($kind->value, ucfirst($kind->plural()), $characters, $ratio, $budgetCharacters, $scale);
    }
    if ($reading->system > 0) {
      $segments[] = $this->segment('system', 'System', $reading->system, $ratio, $budgetCharacters, $scale);
    }
    $freeTokens = max(0, $reading->budgetTokens - $reading->estimatedTokens);
    $overBy = max(0, $reading->estimatedTokens - $reading->budgetTokens);

    // The figures ride as integers and, beside each, as the grouped text
    // the template prints: Twig's trans tag passes a bare variable as a
    // placeholder and drops one carrying a filter, so number_format
    // cannot be applied inside the translatable sentences.
    $variables['segments'] = $segments;
    $variables['free'] = [
      'tokens' => $freeTokens,
      'tokens_text' => number_format($freeTokens),
      'width' => round($freeTokens / $reading->budgetTokens * 100, 2),
    ];
    $variables['estimated_tokens'] = $reading->estimatedTokens;
    $variables['estimated_tokens_text'] = number_format($reading->estimatedTokens);
    $variables['budget_tokens'] = $reading->budgetTokens;
    $variables['budget_tokens_text'] = number_format($reading->budgetTokens);
    $variables['percent'] = (int) round($reading->fraction * 100);
    $variables['over'] = $reading->over;
    $variables['over_by'] = $overBy;
    $variables['over_by_text'] = number_format($overBy);
    $variables['attributes']['class'][] = 'daedalus-ai-context';
    if ($reading->over) {
      $variables['attributes']['class'][] = 'daedalus-ai-context--over';
    }
  }

  /**
   * One segment of the bar: a kind's or the system's share of the budget.
   *
   * @return array{key: string, label: string, characters: int, characters_text: string, tokens: int, tokens_text: string, percent: float, width: float}
   *   The segment: its key (a MaterialKind value or `system`), label,
   *   characters, estimated tokens (each with its grouped text), share
   *   of the budget in percent and the width it draws (the share, scaled
   *   when the site is over).
   */
  private function segment(string $key, string $label, int $characters, int $ratio, int $budgetCharacters, float $scale): array {
    $share = $characters / $budgetCharacters * 100;
    $tokens = (int) ceil($characters / $ratio);
    return [
      'key' => $key,
      'label' => $label,
      'characters' => $characters,
      'characters_text' => number_format($characters),
      'tokens' => $tokens,
      'tokens_text' => number_format($tokens),
      'percent' => round($share, 1),
      'width' => round($share * $scale, 2),
    ];
  }

}
