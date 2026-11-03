<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\daedalus_blueprint\Materials\ConditionClause;

/**
 * The wide condition tier: the feature queries the browser knows.
 *
 * The ONE server reader of data/wide-conditions.json (generated from the
 * pinned webref CSS extract by js/src/tools/generate-wide-selectors.mjs
 * at developer gesture, committed for a reviewable diff;
 * tests/js/wide-conditions.test.mjs holds it in lockstep with the
 * generator). It is what "a literal the browser knows" means on the WHEN
 * side: every `@media` and `@container` feature the specs define, by
 * name, with its type (`range`, so `min-`/`max-` spellings exist too, or
 * `discrete`) and its value space (the ident alternatives of a discrete
 * feature; a range feature's values are numbers, dimensions and ratios,
 * which no list enumerates). The tier is the universe admission is
 * judged against, never a grant: StyleMapValidator::validateCondition
 * admits a literal clause by enforcement mode (open and guarded admit,
 * strict admits references only) and refuses a feature or value outside
 * the tier in every mode with the nearest names suggested.
 *
 * A missing or malformed data file fails loudly here: the tier decides
 * which typed at-rule preludes are refused as typos, and a silently empty
 * one would refuse every literal on every site.
 */
class WideConditions {

  /**
   * The decoded file, once read.
   *
   * @var array{webrefCss: string, media: array<string, array{type: string, syntax: string, values: ?string[]}>, container: array<string, array{type: string, syntax: string, values: ?string[]}>}|null
   */
  protected ?array $tier = NULL;

  public function __construct(protected ModuleExtensionList $moduleList) {}

  /**
   * The feature a `(name: value)` test names, under an at-rule form.
   *
   * A range feature answers for its bare name and its `min-`/`max-`
   * prefixed spellings alike (`min-width` is the `width` feature); a
   * discrete feature for its bare name only.
   *
   * @param string $form
   *   ConditionClause::MEDIA or ConditionClause::CONTAINER.
   * @param string $name
   *   The lowercase feature name as spelled in the test.
   *
   * @return array{type: string, syntax: string, values: ?string[]}|null
   *   The feature record, or NULL when the tier knows no such feature
   *   under that form.
   */
  public function feature(string $form, string $name): ?array {
    $features = $this->features($form);
    if (isset($features[$name])) {
      return $features[$name];
    }
    if (preg_match('/^(?:min|max)-(.+)$/', $name, $matches) && ($features[$matches[1]]['type'] ?? NULL) === 'range') {
      return $features[$matches[1]];
    }
    return NULL;
  }

  /**
   * Every feature name a test may spell under an at-rule form.
   *
   * The bare names plus the `min-`/`max-` spellings of the range
   * features: the candidate list a typo is corrected against.
   *
   * @param string $form
   *   ConditionClause::MEDIA or ConditionClause::CONTAINER.
   *
   * @return string[]
   *   The names, bare ones first in sorted order, then the prefixed ones.
   */
  public function featureNames(string $form): array {
    $features = $this->features($form);
    $names = array_keys($features);
    foreach ($features as $name => $feature) {
      if ($feature['type'] === 'range') {
        $names[] = "min-$name";
        $names[] = "max-$name";
      }
    }
    return $names;
  }

  /**
   * The features of an at-rule form, by name.
   *
   * @param string $form
   *   ConditionClause::MEDIA or ConditionClause::CONTAINER.
   *
   * @return array<string, array{type: string, syntax: string, values: ?string[]}>
   *   The feature records; [] for any other form.
   */
  public function features(string $form): array {
    return match ($form) {
      ConditionClause::MEDIA => $this->tier()['media'],
      ConditionClause::CONTAINER => $this->tier()['container'],
      default => [],
    };
  }

  /**
   * The committed file, decoded once.
   */
  protected function tier(): array {
    if ($this->tier === NULL) {
      $path = $this->moduleList->getPath('daedalus_stylish') . '/data/wide-conditions.json';
      $tier = json_decode(file_get_contents($path), TRUE, flags: JSON_THROW_ON_ERROR);
      foreach (['media', 'container'] as $list) {
        if (!is_array($tier[$list] ?? NULL) || $tier[$list] === []) {
          throw new \RuntimeException(sprintf('%s carries no %s features; regenerate it with `npm run generate:wide-selectors`.', $path, $list));
        }
      }
      $this->tier = $tier;
    }
    return $this->tier;
  }

}
