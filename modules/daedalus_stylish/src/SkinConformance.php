<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Judges a skin against the style guide's token contract.
 *
 * The contract (site.token_contract, StyleGuide::getTokenContract()) is
 * the token names the design system promises: what the guide curates
 * as stops and what a theme's own CSS reads. The guide build proves the
 * guide's half (every curated stop is a contract name); this judge
 * answers the skin's half, whether one skin MINTS every contract name,
 * and names what it lacks per scale. The read is the skin's merged
 * tokens (package layer and site layer), so a site-minted token honors
 * the contract as a shipped one does.
 *
 * Skin data never bricks the guide: a skin that lacks a name is not an
 * error here but a fact the callers act on (a skin is choosable for a
 * system only while it conforms; a row curating a stop the worn skin
 * lacks simply cannot offer it). The verdict is a cheap array diff over
 * two reads that are each cached on their own tags, so it is derived on
 * every ask and memoized nowhere.
 */
final class SkinConformance {

  public function __construct(
    protected StyleGuide $styleGuide,
  ) {}

  /**
   * The contract names the skin does not mint, per scale.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin to judge, read through its merged tokens.
   * @param string|null $theme
   *   The theme whose guide declares the contract, or NULL for the
   *   default.
   *
   * @return array<string, list<string>>
   *   Missing token names keyed by scale, in contract order; scales the
   *   skin fully mints are absent, so [] means the skin conforms.
   */
  public function lacks(DaedalusStylishSkinInterface $skin, ?string $theme = NULL): array {
    $tokens = $skin->tokens();
    $lacks = [];
    foreach ($this->styleGuide->getTokenContract($theme) as $scale => $names) {
      $minted = array_map('strval', array_keys($tokens[$scale] ?? []));
      $missing = array_values(array_diff($names, $minted));
      if ($missing !== []) {
        $lacks[$scale] = $missing;
      }
    }
    return $lacks;
  }

  /**
   * Whether the skin mints every name the contract promises.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin to judge.
   * @param string|null $theme
   *   The theme whose guide declares the contract, or NULL for the
   *   default.
   */
  public function conforms(DaedalusStylishSkinInterface $skin, ?string $theme = NULL): bool {
    return $this->lacks($skin, $theme) === [];
  }

}
