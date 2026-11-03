<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\Core\Extension\ModuleExtensionList;

/**
 * The wide selector tier: what the browser knows, read once per request.
 *
 * The ONE server reader of data/wide-selectors.json (generated from the
 * pinned mdn-data and html-tag-names by js/src/tools/generate-wide-
 * selectors.mjs at developer gesture, committed for a reviewable diff;
 * the client twin is js/src/wide-selectors.js, which bundles the same
 * file, and tests/js/wide-selectors.test.mjs holds both in lockstep with
 * the generator). The tier is the universe admission is judged against,
 * never a grant: the roster walk admits an undeclared tag, attribute,
 * markup class or unpublished pseudo-class by enforcement mode (open and
 * guarded admit, strict refuses with the corrective), and a word outside
 * the tier is a typo, refused in every mode with the nearest names
 * suggested (StyleMapValidator's STYLE_SELECTOR_UNKNOWN). The
 * pseudo-element list is the box slot's universe: bare names only (a
 * functional pseudo-element, `::part()`, is never addressable), read by
 * the roster walk for a terminal pseudo-element and by the code view's
 * box completion.
 *
 * A missing or malformed data file fails loudly here: the tier decides
 * which typed selectors are refused as typos, and a silently empty one
 * would refuse every tag and pseudo-class on every site.
 */
class WideSelectors {

  /**
   * The decoded file, once read.
   *
   * @var array{mdnData: string, htmlTagNames: string, pseudoClasses: string[], pseudoElements: string[], tags: string[]}|null
   */
  protected ?array $tier = NULL;

  public function __construct(protected ModuleExtensionList $moduleList) {}

  /**
   * Whether an HTML element name exists.
   *
   * @param string $name
   *   The lowercase tag name (the grammar lowercases tags).
   */
  public function knowsTag(string $name): bool {
    return in_array($name, $this->tags(), TRUE);
  }

  /**
   * Whether a pseudo-class name exists ("hover", never ":hover").
   *
   * Functional forms are known by their bare name ("nth-child", "is");
   * which of them parse is the grammar's business, decided before the
   * tier is asked.
   */
  public function knowsPseudoClass(string $name): bool {
    return in_array($name, $this->pseudoClasses(), TRUE);
  }

  /**
   * Whether a pseudo-element name exists ("marker", never "::marker").
   */
  public function knowsPseudoElement(string $name): bool {
    return in_array($name, $this->pseudoElements(), TRUE);
  }

  /**
   * Every HTML element name, sorted.
   *
   * @return string[]
   *   The tag names.
   */
  public function tags(): array {
    return $this->tier()['tags'];
  }

  /**
   * Every pseudo-class name, sorted, parens stripped.
   *
   * @return string[]
   *   The bare names.
   */
  public function pseudoClasses(): array {
    return $this->tier()['pseudoClasses'];
  }

  /**
   * Every pseudo-element name, sorted.
   *
   * @return string[]
   *   The bare names.
   */
  public function pseudoElements(): array {
    return $this->tier()['pseudoElements'];
  }

  /**
   * The committed file, decoded once.
   */
  protected function tier(): array {
    if ($this->tier === NULL) {
      $path = $this->moduleList->getPath('daedalus_stylish') . '/data/wide-selectors.json';
      $tier = json_decode(file_get_contents($path), TRUE, flags: JSON_THROW_ON_ERROR);
      foreach (['pseudoClasses', 'pseudoElements', 'tags'] as $list) {
        if (!is_array($tier[$list] ?? NULL) || $tier[$list] === []) {
          throw new \RuntimeException(sprintf('%s carries no %s list; regenerate it with `npm run generate:wide-selectors`.', $path, $list));
        }
      }
      $this->tier = $tier;
    }
    return $this->tier;
  }

}
