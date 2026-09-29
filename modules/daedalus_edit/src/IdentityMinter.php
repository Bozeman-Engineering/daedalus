<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterFormatInterface;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Mints identity classes onto semantic text elements in stored field HTML.
 *
 * Eager identity's canonical guarantee: every semantic text element of the
 * declared instance vocabulary carries a short random base36 id class
 * (e.g. class="s-a3f") in the stored HTML, so a cold-loaded page's elements
 * are addressable by Daedalus Stylish and the markup view with no editor attached.
 * The CKEditor postfixer mirrors this live; this service is the server-side
 * source of truth on every write path (stage-time seam, presave backstop,
 * backfill).
 *
 * The tag vocabulary is READ, not owned: the spine's `instances:`
 * declaration (the '.s-*' namespace daedalus_edit declares in
 * daedalus_edit.page_builder.yml, PageBuilderDeclarations::getInstances()) is
 * the one vocabulary truth — the minter, the CKEditor client contract, the
 * annotator's row labels, and the styling resolvers all consume it there,
 * so no consumer can drift.
 *
 * Rules:
 * - Ids are random (not sequential) so deleted elements' ids are never
 *   reused and orphaned style-map entries cannot resurrect onto unrelated
 *   elements. Uniqueness is per field value (block-scoped), enforced here.
 * - Minting is universal: every declared-vocabulary element mints at any
 *   depth, list items and nested lists included, so every element the
 *   markup actually has is addressable. A collapsed <li>text</li> holds
 *   no paragraph element, so nothing forces one; the minter only ever
 *   stamps elements that exist.
 * - Only vocabulary tags the format allows WITH an unrestricted class
 *   attribute mint. A tag the format strips entirely cannot render, and a
 *   tag allowed without class would lose its minted id at render; both are
 *   skipped, never stamped and never fatal. The minter runs on every save
 *   of every formatted text field on the site (EntityIdentityMinting has no
 *   bundle gate), so refusing such a format would make every field stored
 *   under it unsavable the moment daedalus_edit installs; unminted elements
 *   are the lesser harm. The loss is reported instead: unmintable() names
 *   the skipped tags and Hook\DaedalusEditRequirements lists them per
 *   format on the status report, so a site widens its format when it wants
 *   those elements addressable.
 * - When nothing needs minting the input string is returned byte-identical:
 *   parsing and reserializing untouched HTML could introduce phantom diffs
 *   on write paths that compare values.
 */
final class IdentityMinter {

  /**
   * The instances-lane pattern key of the namespace this service mints.
   *
   * The declaration-side spelling of CLASS_PATTERN's namespace: the minted
   * class shape is minter mechanics, while the namespace's tag vocabulary
   * and labels live on the spine declaration this key addresses.
   */
  public const PATTERN_KEY = '.s-*';

  /**
   * The class token pattern that carries an identity, e.g. "s-a3f".
   */
  public const CLASS_PATTERN = '/^s-([a-z0-9]+)$/';

  /**
   * Minted id length in base36 characters.
   */
  public const ID_LENGTH = 3;

  /**
   * The base36 alphabet minted ids draw from.
   */
  public const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

  /**
   * The declared vocabulary, memoized per request.
   *
   * @var string[]|null
   */
  private ?array $declaredVocabulary = NULL;

  public function __construct(
    private readonly PageBuilderDeclarations $declarations,
  ) {}

  /**
   * The declared identity vocabulary: the namespace's tags, in order.
   *
   * Read from the spine's `instances:` declaration for this service's
   * namespace (PATTERN_KEY), resolved against the site default theme —
   * minting runs on write paths (API requests often render the admin
   * theme) and stored identity must match what the site's front pages
   * validate and style against, the same reasoning as
   * PageBuilderDeclarations::siteTheme().
   *
   * @return string[]
   *   The declared tag names; [] when no namespace is declared, which
   *   makes every mint a no-op (an undeclared vocabulary mints nothing).
   */
  public function declaredVocabulary(): array {
    if ($this->declaredVocabulary === NULL) {
      $declaration = $this->declarations->getInstances($this->declarations->siteTheme())[self::PATTERN_KEY] ?? [];
      $tags = $declaration['tags'] ?? [];
      $this->declaredVocabulary = is_array($tags) ? array_map('strval', array_keys($tags)) : [];
    }
    return $this->declaredVocabulary;
  }

  /**
   * The contract as the client editor consumes it (drupalSettings).
   *
   * The CKEditor Identity plugin (js/daedalus_edit/ckeditor/identity.js) is
   * the live mirror of this service; it reads THIS delivered contract
   * (drupalSettings.daedalusEdit.identity, attached by the form panel) instead
   * of carrying copies of the vocabulary, class shape, and id alphabet,
   * one server truth, zero client twins to drift — and the vocabulary
   * itself is read from the spine's instances declaration, so the client
   * mirror speaks the declared tags without a second source. classPattern
   * is the regex SOURCE (no delimiters) so JS constructs the same RegExp.
   *
   * @return array
   *   {vocabulary, classPattern, idLength, alphabet}.
   */
  public function clientContract(): array {
    return [
      'vocabulary' => $this->declaredVocabulary(),
      'classPattern' => trim(self::CLASS_PATTERN, '/'),
      'idLength' => self::ID_LENGTH,
      'alphabet' => self::ALPHABET,
    ];
  }

  /**
   * Ensures every mintable element in $html carries a unique identity class.
   *
   * @param string $html
   *   A text field value's HTML.
   * @param \Drupal\filter\FilterFormatInterface $format
   *   The text format the value is stored under; its restrictions decide
   *   which vocabulary tags can carry identity.
   *
   * @return string
   *   The HTML with identity classes minted where missing or colliding, or
   *   the input byte-identical when nothing needed minting.
   */
  public function mint(string $html, FilterFormatInterface $format): string {
    $vocabulary = $this->vocabulary($format);
    if ($vocabulary === [] || trim($html) === '') {
      return $html;
    }

    $document = Html::load($html);
    $xpath = new \DOMXPath($document);

    // First pass: every identity class anywhere in the document, in document
    // order, including elements outside the mintable walk (non-vocabulary
    // carriers a format lets through). Their ids are taken, and the first
    // carrier of an id is its owner; later carriers are collisions.
    $taken = [];
    $owner = [];
    foreach ($xpath->query('//*[@class]') as $element) {
      $id = $this->identityOf($element);
      if ($id !== NULL) {
        $taken[$id] = TRUE;
        $owner[$id] ??= $element;
      }
    }

    // Second pass: the mintable elements, every vocabulary element at any
    // depth. Mint where identity is missing; re-mint where the element's id
    // is owned by an earlier element (paste/duplicate collisions).
    $query = implode(' | ', array_map(
      static fn (string $tag): string => "//{$tag}",
      $vocabulary,
    ));
    $changed = FALSE;
    foreach ($xpath->query($query) as $element) {
      $id = $this->identityOf($element);
      if ($id !== NULL && $owner[$id] === $element) {
        continue;
      }
      $this->stamp($element, $id, $this->mintId($taken));
      $changed = TRUE;
    }

    return $changed ? Html::serialize($document) : $html;
  }

  /**
   * The vocabulary tags the format allows but cannot carry identity on.
   *
   * The status-report side of the skip rule: every tag listed here renders
   * under the format yet is never minted, so its elements are not
   * addressable until the format allows class on it.
   *
   * @return string[]
   *   The declared vocabulary tags the format allows without an
   *   unrestricted class attribute, in vocabulary order; [] when every
   *   allowed vocabulary tag mints.
   */
  public function unmintable(FilterFormatInterface $format): array {
    return $this->partition($format)['unmintable'];
  }

  /**
   * The vocabulary tags the format can carry identity on.
   *
   * @return string[]
   *   The subset of the declared vocabulary the format allows with an
   *   unrestricted class attribute.
   */
  private function vocabulary(FilterFormatInterface $format): array {
    return $this->partition($format)['mintable'];
  }

  /**
   * Splits the declared vocabulary by what the format lets identity through.
   *
   * @return array{mintable: string[], unmintable: string[]}
   *   'mintable': allowed tags with an unrestricted class attribute;
   *   'unmintable': allowed tags whose class attribute is stripped or
   *   value-restricted. Tags the format strips entirely appear in neither:
   *   no element of them can render, so identity on them is moot.
   */
  private function partition(FilterFormatInterface $format): array {
    $declared = $this->declaredVocabulary();
    $restrictions = $format->getHtmlRestrictions();
    if ($restrictions === FALSE) {
      return ['mintable' => $declared, 'unmintable' => []];
    }
    $allowed = $restrictions['allowed'];
    $partition = ['mintable' => [], 'unmintable' => []];
    foreach ($declared as $tag) {
      if (!isset($allowed[$tag])) {
        continue;
      }
      // The class attribute must be allowed with unrestricted values (TRUE);
      // a value-restricted class would strip random s-* ids just the same.
      $class = $allowed[$tag]['class'] ?? $allowed['*']['class'] ?? FALSE;
      $partition[$class === TRUE ? 'mintable' : 'unmintable'][] = $tag;
    }
    return $partition;
  }

  /**
   * The element's identity, or NULL when it carries none.
   */
  private function identityOf(\DOMElement $element): ?string {
    foreach ($this->classes($element) as $class) {
      if (preg_match(self::CLASS_PATTERN, $class, $matches)) {
        return $matches[1];
      }
    }
    return NULL;
  }

  /**
   * Writes a fresh identity onto the element, replacing $stale if present.
   */
  private function stamp(\DOMElement $element, ?string $stale, string $fresh): void {
    $classes = $this->classes($element);
    if ($stale !== NULL) {
      $index = array_search('s-' . $stale, $classes, TRUE);
      $classes[$index] = 's-' . $fresh;
    }
    else {
      $classes[] = 's-' . $fresh;
    }
    $element->setAttribute('class', implode(' ', $classes));
  }

  /**
   * The element's class attribute as a token list.
   *
   * @return string[]
   *   The class tokens, empty when the attribute is absent.
   */
  private function classes(\DOMElement $element): array {
    return preg_split('/\s+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY);
  }

  /**
   * Mints a random id unique against $taken, claiming it there.
   *
   * @param array<string, true> $taken
   *   Ids already present in the document, keyed by id.
   */
  private function mintId(array &$taken): string {
    do {
      $id = '';
      for ($i = 0; $i < self::ID_LENGTH; $i++) {
        $id .= self::ALPHABET[random_int(0, 35)];
      }
    } while (isset($taken[$id]));
    $taken[$id] = TRUE;
    return $id;
  }

}
