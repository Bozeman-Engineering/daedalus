<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\field_sample_value\SampleValueEntityGeneratorInterface;

/**
 * The one ladder that makes a field value up, with two entrances.
 *
 * Every sample the spine mints comes from here, in one rung order: the
 * field's own default value (the site's answer), then the field's
 * configured field_sample_value generator (the site's sample policy), then
 * core's generateSampleItems() (the field type's invention). The first two
 * rungs are the site answering; the third is the spine inventing. The two
 * entrances differ in whether inventing is allowed:
 *
 * - Placement (fillOnPlacement(); add_element's `populate: 'sample'` on a
 *   fresh inline block) fills only what the site answers: default value,
 *   else the configured generator's answer, else nothing. A dropped block
 *   is an editor's starting point, and a made-up link, tag or media
 *   reference is noise the editor must undo; field_sample_value's own
 *   contract leaves unconfigured fields alone.
 * - The `_sample` request (fillOnRequest() through extract(); SampleRequest
 *   on a write op, resolved into an ExtractedValue) means "make one up", so
 *   on a field with NO policy it continues to core's type-level sample.
 *
 * On both entrances a configured generator's answer is final even when
 * empty: the entity_reference generator on a site with no published target
 * yields nothing, and that empty answer is the site's policy, not an
 * invitation to fabricate an unsaved target. The generator service says
 * whether the site answered (populateWithSampleValue() returns TRUE when a
 * configured generator ran, whatever it yielded); the ladder reads that
 * flag, never isEmpty(), to tell "no policy" from "the policy yielded
 * nothing".
 *
 * daedalus_blueprint depends on field_sample_value, so the generator is
 * always wired on a site; it is optional only for kernel isolation, where
 * a test enables daedalus_blueprint without its dependencies and the
 * ladder skips the policy rung.
 *
 * A generator configured with prevent_save marks the entity so the
 * SampleValue constraint refuses the value; the spine validates every
 * write as saveable, so such a request fails at write time with that
 * constraint's message rather than staging a value that could never be
 * saved.
 */
final class SampleFiller {

  public function __construct(
    private readonly ?SampleValueEntityGeneratorInterface $generator = NULL,
  ) {}

  /**
   * The placement entrance: fills an item list with the site's answer only.
   *
   * Whatever the list held is replaced: a sample is a write, not a merge.
   * The ladder stops after the policy rung; core's type-level sample is not
   * a placement rung.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The item list to fill.
   *
   * @return bool
   *   TRUE when the site answered with a value; FALSE when the field has no
   *   default value and either no configured generator or one that yielded
   *   nothing, and the list is left empty.
   */
  public function fillOnPlacement(FieldItemListInterface $items): bool {
    $this->askTheSite($items);
    return !$items->isEmpty();
  }

  /**
   * The request entrance: the site's answer, else core's invention.
   *
   * Whatever the list held is replaced: a sample is a write, not a merge.
   * Core's generateSampleItems() runs only when the site did not answer
   * (no default value and no configured generator); a configured
   * generator's empty answer is final.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The item list to fill.
   *
   * @return bool
   *   TRUE when a rung yielded a value; FALSE when the field has no default
   *   value and either a configured generator that yielded nothing or no
   *   generator and no type-level sample, and the list is left empty.
   */
  public function fillOnRequest(FieldItemListInterface $items): bool {
    if (!$this->askTheSite($items)) {
      $items->generateSampleItems();
      $items->filterEmptyItems();
    }
    return !$items->isEmpty();
  }

  /**
   * Resolves a `_sample` request on an entity's field into an ExtractedValue.
   *
   * The request entrance runs on a detached copy of the field's item list
   * (still parented to the entity, so default value callbacks and
   * generators see their host), and the entity itself is untouched until
   * the spine applies the value like any other ExtractedValue: field
   * access, constraint validation and the FieldAppliedEvent included.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose field is requested.
   * @param string $field_name
   *   The field name; the caller has established it exists.
   *
   * @return \Drupal\daedalus_blueprint\ExtractedValue|null
   *   The sample as an ExtractedValue, or NULL when no rung yielded one
   *   (the caller reports NO_SAMPLE_VALUE).
   */
  public function extract(FieldableEntityInterface $entity, string $field_name): ?ExtractedValue {
    $items = clone $entity->get($field_name);
    return $this->fillOnRequest($items) ? new ExtractedValue($items) : NULL;
  }

  /**
   * Runs the site's rungs: the default value, then the configured generator.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The item list to fill; whatever it held is replaced.
   *
   * @return bool
   *   TRUE when the site answered: the default value yielded items, or a
   *   configured generator ran (whatever it yielded). FALSE when the field
   *   has no default value and no configured generator, so a caller allowed
   *   to invent may continue to core's type-level sample.
   */
  private function askTheSite(FieldItemListInterface $items): bool {
    $items->setValue([], FALSE);
    $items->applyDefaultValue(FALSE);
    if (!$items->isEmpty()) {
      return TRUE;
    }
    return $this->generator !== NULL && $this->generator->populateWithSampleValue($items);
  }

}
