<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

// cspell:ignore buton
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The site's materials: one typed roster every material surface projects.
 *
 * Built once per request by MaterialsRosterBuilder from the tagged
 * providers' records. Holds the records by kind and id, an index by
 * spelling per slot (what the value grammar resolves through; an axis
 * attribute slot carries its scope, MaterialRecord::attrSlot(), so the
 * index partitions attribute values per scope by construction and a
 * duplicate is a duplicate within one scope only), the ONE
 * applicability predicate ("can this element wear this variant"), and
 * the ONE did-you-mean formatter every corrective on both doors renders
 * candidates with. Immutable after construction; the cache metadata says
 * what a cached projection of it must depend on.
 *
 * Two views of one site: the full roster is legality (what resolves,
 * validates and applies), and offered() is the AI's roster, the records
 * their authors share with the AI (MaterialRecord::$offered), which the
 * cached brief and the schema project. A band verdict on the offered
 * view still resolves a stencil's wearings against the full roster: a
 * placement rule is a fact of legality, and opting the worn variant out
 * of the brief must not turn a band into a free-floating stencil.
 */
final class MaterialsRoster {

  /**
   * Records by kind value, then id, in provider order.
   *
   * @var array<string, array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>>
   */
  private array $byKind = [];

  /**
   * Records by slot, then spelling.
   *
   * @var array<string, array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>>
   */
  private array $bySpelling = [];

  /**
   * The offered view, built once on first ask (offered()).
   */
  private ?MaterialsRoster $offered = NULL;

  /**
   * Constructs the roster, indexing the records by kind, id and spelling.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The records, in roster order.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheMetadata
   *   The merged metadata of every provider (plus the builder's own).
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster|null $full
   *   For the offered view only: the full roster it is a view of, the one
   *   legality questions (isBand(), rootOnlyWears()) resolve against. NULL
   *   for a full roster.
   */
  public function __construct(array $records, private readonly CacheableMetadata $cacheMetadata, private readonly ?MaterialsRoster $full = NULL) {
    foreach (MaterialKind::cases() as $kind) {
      $this->byKind[$kind->value] = [];
    }
    foreach ($records as $record) {
      if (!$record instanceof MaterialRecord) {
        throw new \InvalidArgumentException('A materials roster holds MaterialRecord instances only.');
      }
      if (isset($this->byKind[$record->kind->value][$record->id])) {
        throw new \InvalidArgumentException(sprintf('Two %s records share the id "%s".', $record->kind->noun(), $record->id));
      }
      $this->byKind[$record->kind->value][$record->id] = $record;
      foreach ($record->spellings as $slot => $spelling) {
        if (isset($this->bySpelling[$slot][$spelling])) {
          $other = $this->bySpelling[$slot][$spelling];
          throw new \InvalidArgumentException(sprintf('%s "%s" and %s "%s" both spell "%s" in the %s slot.', ucfirst($other->kind->noun()), $other->id, $record->kind->noun(), $record->id, $spelling, $slot));
        }
        $this->bySpelling[$slot][$spelling] = $record;
      }
    }
  }

  /**
   * Every record, in roster order (kind order, then provider order).
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records.
   */
  public function all(): array {
    return array_merge(...array_map(array_values(...), array_values($this->byKind)));
  }

  /**
   * The AI's view: the records their authors share with the AI.
   *
   * The one view the cached brief (DialectCatalogPrompt) and the schema
   * (MaterialsSchema) project, so a material opted out leaves both at
   * once and nowhere else: legality keeps reading the full roster. The
   * same instance when nothing is opted out (the common site), so a
   * consumer memoizing on roster identity sees no second roster; a
   * filtered sibling otherwise, in roster order, carrying the same cache
   * metadata and resolving band verdicts against this full roster.
   */
  public function offered(): MaterialsRoster {
    if ($this->offered === NULL) {
      $all = $this->all();
      $records = array_values(array_filter($all, static fn (MaterialRecord $record): bool => $record->offered));
      $this->offered = count($records) === count($all) ? $this : new self($records, $this->cacheMetadata, $this->full ?? $this);
    }
    return $this->offered;
  }

  /**
   * The records of one kind, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function ofKind(MaterialKind $kind): array {
    return $this->byKind[$kind->value];
  }

  /**
   * The tokens, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function tokens(): array {
    return $this->ofKind(MaterialKind::Token);
  }

  /**
   * The swatches, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function swatches(): array {
    return $this->ofKind(MaterialKind::Swatch);
  }

  /**
   * The variants, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function variants(): array {
    return $this->ofKind(MaterialKind::Variant);
  }

  /**
   * The stencils, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function stencils(): array {
    return $this->ofKind(MaterialKind::Stencil);
  }

  /**
   * The animations, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records.
   */
  public function animations(): array {
    return $this->ofKind(MaterialKind::Animation);
  }

  /**
   * One record by kind and id, or NULL.
   */
  public function get(MaterialKind $kind, string $id): ?MaterialRecord {
    return $this->byKind[$kind->value][$id] ?? NULL;
  }

  /**
   * Whether a record of this kind and id exists.
   */
  public function has(MaterialKind $kind, string $id): bool {
    return isset($this->byKind[$kind->value][$id]);
  }

  /**
   * Whether the roster holds nothing at all.
   */
  public function isEmpty(): bool {
    return array_filter($this->byKind) === [];
  }

  /**
   * The record a spelling names in a slot, or NULL when nothing does.
   *
   * The index the value grammar resolves through: exact match on the
   * spelling the provider contributed (a `var(--x)` reference, a class
   * token, an axis attribute value in its scope's slot, a stencil name).
   */
  public function bySpelling(string $slot, string $spelling): ?MaterialRecord {
    return $this->bySpelling[$slot][$spelling] ?? NULL;
  }

  /**
   * Every spelling in a slot, keyed by spelling.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records, keyed by their spelling in the slot.
   */
  public function spellings(string $slot): array {
    return $this->bySpelling[$slot] ?? [];
  }

  /**
   * Whether a material may be worn by an element in a given situation.
   *
   * The one applicability predicate for AI and UI (its JS twin reads the same
   * records). Variants gate on three facts: their scope must pair with the
   * element's address (Address::pairsWith(), the family rule), a root-only
   * variant applies at top level only, and an owned variant applies only inside
   * its owning stencil's placements. A stencil gates on one fact, derived,
   * never authored: it is a page band when any variant its root captured
   * (MaterialRecord::APPLIES_WEARS) resolves to a root-only record in this
   * roster, and a band places at top level only; every other stencil places
   * anywhere the placement grammar allows. Tokens, swatches and animations are
   * values and apply everywhere.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord $record
   *   The material.
   * @param \Drupal\daedalus_blueprint\Materials\Address $element
   *   The element's own address ("block_type:text", "layout:layout_onecol",
   *   "kind:container"); for a stencil, the address its root lands as.
   * @param bool $top_level
   *   Whether the element sits at top level (a page band) rather than
   *   nested inside another section; for a stencil, where it would land.
   * @param string|null $within_stencil
   *   The id of the stencil whose placement the element sits inside, or
   *   NULL outside every placement.
   */
  public function applicable(MaterialRecord $record, Address $element, bool $top_level = TRUE, ?string $within_stencil = NULL): bool {
    if ($record->kind === MaterialKind::Stencil) {
      return $top_level || !$this->isBand($record);
    }
    if ($record->kind !== MaterialKind::Variant) {
      return TRUE;
    }
    if ($record->scope === NULL || !$record->scope->pairsWith($element)) {
      return FALSE;
    }
    if ($record->rootOnly() && !$top_level) {
      return FALSE;
    }
    if ($record->ownedBy !== NULL && $record->ownedBy !== $within_stencil) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Whether a stencil is a page band: its root wears a root-only variant.
   *
   * The one derivation behind every "band" word (the predicate above, the
   * schema's placement line, the dialect's refusal, the prompt's roster
   * line): the record's captured wearings resolved against the variant
   * records of THIS roster. A wearing that names no record here (a
   * variant unpublished since the capture, or one outside the active
   * design system) has no opinion. On the offered view the wearings
   * resolve against the full roster (the class doc: a band is legality).
   */
  public function isBand(MaterialRecord $stencil): bool {
    return $this->rootOnlyWears($stencil) !== [];
  }

  /**
   * The root-only variant records a stencil's root wears, keyed by id.
   *
   * What makes a stencil a band, named: a corrective spells the variant
   * and its axis from these. Resolved against the full roster, on every
   * view.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The root-only records among the stencil's wearings, in wearing
   *   order; [] for a stencil that wears none, and for every other kind.
   */
  public function rootOnlyWears(MaterialRecord $stencil): array {
    $records = [];
    foreach ($stencil->wears() as $id) {
      $variant = ($this->full ?? $this)->get(MaterialKind::Variant, $id);
      if ($variant !== NULL && $variant->rootOnly()) {
        $records[$id] = $variant;
      }
    }
    return $records;
  }

  /**
   * Whether a printed variant scope pairs with a printed element scope.
   *
   * The string door of the family rule, for the client twin's lock
   * (daedalus_ui roster.js pairs printed scopes with its own twin of
   * this door, and ValueGrammarTest::testClientTwinEquivalence asserts
   * both sides answer the same battery). The rule itself lives on the
   * address (Address::pairsWith()); a string that is not an address
   * pairs with nothing.
   */
  public static function scopesPair(string $variant_scope, string $element_scope): bool {
    $variant = Address::parse($variant_scope);
    $element = Address::parse($element_scope);
    return $variant !== NULL && $element !== NULL && $variant->pairsWith($element);
  }

  /**
   * The records of a kind most like a misspelled reference, best first.
   *
   * Ranked by similarity to the id, the label and each spelling
   * (whichever is closest), case-insensitive, so "limegreen" finds
   * lime_green "Lime green", "buton" finds button and the custom property
   * "--swatch-limegreen" a writer named finds var(--swatch-lime_green). A
   * record is a candidate when it contains the needle (or the needle
   * contains it) or is at least half similar; nothing below that is
   * offered, because a hint naming unrelated materials teaches the wrong
   * lesson.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The candidates, best first, at most $limit of them.
   */
  public function candidates(MaterialKind $kind, string $needle, int $limit = 5): array {
    return self::rank($this->byKind[$kind->value], $needle, $limit);
  }

  /**
   * The records spellable in a slot most like a misspelled reference.
   *
   * The slot-aware sibling of candidates(): a reference that failed to
   * resolve in a slot is corrected against every material that CAN be
   * written there (tokens and swatches for a var() in a style value,
   * style variants for a class, one axis's variants for its attribute,
   * stencils for a placement name), whatever their kinds, so a swatch
   * typo never gets a token-only hint.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The candidates, best first, at most $limit of them.
   */
  public function candidatesInSlot(string $slot, string $needle, int $limit = 5): array {
    return self::rank($this->bySpelling[$slot] ?? [], $needle, $limit);
  }

  /**
   * The did-you-mean hint for a misspelled reference, or '' when none.
   *
   * The one hint formatter for both doors: the candidates rendered by
   * describe(), so a rejection repeats the design language (id, label,
   * spelling, description) at exactly the moment the writer stepped
   * outside it.
   */
  public function didYouMean(MaterialKind $kind, string $needle, int $limit = 5): string {
    return self::hint($this->candidates($kind, $needle, $limit));
  }

  /**
   * The did-you-mean hint for a reference that failed in a slot, or ''.
   */
  public function didYouMeanInSlot(string $slot, string $needle, int $limit = 5): string {
    return self::hint($this->candidatesInSlot($slot, $needle, $limit));
  }

  /**
   * The structured candidate facts both doors put in an error's context.
   *
   * The context half of every material corrective (the hint is the prose
   * half, describe()): {kind, id, spelling} per record, the ValueReference
   * contract, so a client or a model can offer the candidates without
   * parsing prose. With a slot, the spelling is the record's in that slot
   * (the dialect door: what to write where the reference failed); without
   * one it is the id (the JSON door writes a material by its id).
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The candidates, best first.
   * @param string|null $slot
   *   The slot the reference failed in, or NULL on the JSON door.
   *
   * @return array<int, array{kind: string, id: string, spelling: ?string}>
   *   One fact triple per candidate, in the given order.
   */
  public static function candidateFacts(array $records, ?string $slot = NULL): array {
    return array_map(static fn (MaterialRecord $record): array => [
      'kind' => $record->kind->value,
      'id' => $record->id,
      'spelling' => $slot === NULL ? $record->id : $record->spelling($slot),
    ], array_values($records));
  }

  /**
   * Renders candidates as the did-you-mean sentence, naming their kind.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $candidates
   *   The candidates, best first.
   */
  private static function hint(array $candidates): string {
    if ($candidates === []) {
      return '';
    }
    $kinds = array_unique(array_map(static fn (MaterialRecord $record): string => $record->kind->value, $candidates));
    if (count($candidates) === 1) {
      $what = 'this ' . $candidates[0]->kind->noun();
    }
    else {
      $what = 'one of these ' . (count($kinds) === 1 ? $candidates[0]->kind->plural() : 'materials');
    }
    return sprintf('Did you mean %s: %s?', $what, self::describe($candidates));
  }

  /**
   * Scores records against a needle and keeps the plausible ones, best first.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The records to rank.
   * @param string $needle
   *   The text to score the records against.
   * @param int $limit
   *   The most records to keep.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   At most $limit records scoring 50 or more, best first; a record
   *   listed under several spellings ranks once.
   */
  private static function rank(array $records, string $needle, int $limit): array {
    $needle = strtolower(trim($needle));
    if ($needle === '') {
      return [];
    }
    $scored = [];
    $seen = [];
    foreach ($records as $record) {
      $key = $record->kind->value . '/' . $record->id;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $score = max(
        self::similarity($needle, $record->id),
        self::similarity($needle, $record->label),
        ...array_map(static fn (string $spelling): float => self::similarity($needle, $spelling), array_values($record->spellings)),
      );
      if ($score >= 50) {
        $scored[] = [$score, $record];
      }
    }
    usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
    return array_map(static fn (array $pair): MaterialRecord => $pair[1], array_slice($scored, 0, $limit));
  }

  /**
   * Renders records into prose: id "Label" (spelling): description.
   *
   * The one spelling for every corrective and roster line that names
   * materials the writer could have reached for. The spelling is shown
   * as it is written in its slot (var(--x), class="x", width="x",
   * <pb-stencil name="x">, animation-name: x); a material with no
   * spelling shows none.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The records.
   * @param bool $descriptions
   *   Whether to append each record's description (a per-turn surface
   *   naming what an element wears wants the identity only).
   */
  public static function describe(array $records, bool $descriptions = TRUE): string {
    return implode('; ', array_map(static function (MaterialRecord $record) use ($descriptions): string {
      $line = sprintf('%s "%s"', $record->id, $record->label);
      $spellings = [];
      foreach ($record->spellings as $slot => $spelling) {
        $spellings[] = self::spellingProse($slot, $spelling);
      }
      if ($spellings !== []) {
        $line .= ' (' . implode(', ', $spellings) . ')';
      }
      return $descriptions && $record->description !== '' ? $line . ': ' . $record->description : $line;
    }, $records));
  }

  /**
   * A spelling as it is written in its slot.
   */
  public static function spellingProse(string $slot, string $spelling): string {
    if (($attribute = MaterialRecord::slotAttribute($slot)) !== NULL) {
      return sprintf('%s="%s"', $attribute, $spelling);
    }
    return match ($slot) {
      MaterialRecord::SLOT_CLASS => sprintf('class="%s"', $spelling),
      MaterialRecord::SLOT_STENCIL => sprintf('<pb-stencil name="%s">', $spelling),
      MaterialRecord::SLOT_ANIMATION => sprintf('animation-name: %s', $spelling),
      default => $spelling,
    };
  }

  /**
   * Renders a stored named value (a string or condition-keyed map) as one line.
   *
   * The one spelling of what a token or swatch IS, for every surface that
   * shows values beside names (the cached prompt's NAMED VALUES, the
   * validator's correctives): the default value followed by its
   * conditioned values in parentheses ("#1a1a2e (dark: #e8e8f0)"),
   * matching the schema's teaching that condition keys are VALUES of one
   * name, never part of it.
   *
   * @param mixed $value
   *   The value as stored.
   * @param int $clip
   *   When positive, the longest each rendered part may be before it is
   *   cut with an ellipsis (a font stack on a budgeted prompt); 0 renders
   *   every part whole.
   */
  public static function valueProse(mixed $value, int $clip = 0): string {
    $render = static function (mixed $part) use ($clip): string {
      $text = is_scalar($part) || $part === NULL ? (string) $part : (string) json_encode($part);
      return $clip > 0 && mb_strlen($text) > $clip ? mb_substr($text, 0, $clip - 3) . '...' : $text;
    };
    if (!is_array($value)) {
      return $render($value);
    }
    $conditions = [];
    foreach ($value as $condition => $conditioned) {
      if ($condition !== 'default') {
        $conditions[] = $condition . ': ' . $render($conditioned);
      }
    }
    $default = $render($value['default'] ?? '');
    return $conditions === [] ? $default : trim($default . ' (' . implode(', ', $conditions) . ')');
  }

  /**
   * What a cached projection of this roster must depend on.
   */
  public function getCacheableMetadata(): CacheableMetadata {
    return $this->cacheMetadata;
  }

  /**
   * Similarity of a lowercase needle to a haystack string, 0..100.
   *
   * Containment either way counts as a full match (a prefix or a
   * label fragment is how people actually misremember names); otherwise
   * similar_text()'s percentage over the lowercased pair.
   */
  private static function similarity(string $needle, string $haystack): float {
    $haystack = strtolower($haystack);
    if ($haystack === '') {
      return 0.0;
    }
    if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
      return 100.0;
    }
    similar_text($needle, $haystack, $percent);
    return $percent;
  }

}
