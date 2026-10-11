<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_materials_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureResult;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * A hand-built provider: one record of every kind, every field exercised.
 *
 * Tests may replace the record set through the static override so one
 * container boot serves several roster shapes. The gestures are a
 * recording fake: every capture / update / remove lands on the static
 * $gestures log with the arguments the op handler dispatched, so the spine's
 * dispatch (kind routing, alias resolution, origin stamping) is observable
 * without a real material entity behind it.
 */
final class TestMaterialsProvider implements MaterialsProviderInterface {

  /**
   * An override for the record set, or NULL for the reference set.
   *
   * @var \Drupal\daedalus_blueprint\Materials\MaterialRecord[]|null
   */
  public static ?array $records = NULL;

  /**
   * An override for the gesture lines, or NULL for the reference pair.
   *
   * A harness enabling the real blueprint provider beside this fixture
   * (PageReadTest) drops the blueprint gesture here: two providers
   * teaching one kind is refused loudly by the roster builder.
   *
   * @var array<string, string>|null
   */
  public static ?array $gestureLines = NULL;

  /**
   * How many times records() ran, so memoization is observable.
   */
  public static int $builds = 0;

  /**
   * The gestures dispatched to this provider, in order.
   *
   * @var array<int, array{verb: string, kind: string, id: ?string, operation: array, entity: ?string, options: array}>
   */
  public static array $gestures = [];

  /**
   * {@inheritdoc}
   */
  public function records(): array {
    self::$builds++;
    return self::$records ?? self::referenceRecords();
  }

  /**
   * {@inheritdoc}
   */
  public function cacheMetadata(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheTags(['materials_test_list'])
      ->addCacheContexts(['user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function gestureLines(): array {
    return self::$gestureLines ?? [
      MaterialKind::Swatch->value => 'value',
      MaterialKind::Blueprint->value => 'element',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Mints nothing: records the dispatch and answers with an id derived
   * from the label. A missing label refuses, so the handler's semantic
   * failure path is observable too.
   */
  public function capture(MaterialKind $kind, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $this->record('capture', $kind, NULL, $operation, $host, $options);
    $label = trim((string) ($operation['label'] ?? ''));
    if ($label === '') {
      throw new MaterialGestureException([
        new BlueprintError('label', 'MISSING_PARAMETER', sprintf('A %s needs a label.', $kind->noun()), [], 'Name it.'),
      ]);
    }
    $id = preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? $label;
    return new MaterialGestureResult($kind, $id, $operation['warn'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $this->record('update', $kind, $id, $operation, $host, $options);
    return new MaterialGestureResult($kind, $id);
  }

  /**
   * {@inheritdoc}
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult {
    $this->record('remove', $kind, $id, [], NULL, $options);
    return new MaterialGestureResult($kind, $id);
  }

  /**
   * Logs a dispatched gesture.
   */
  protected function record(string $verb, MaterialKind $kind, ?string $id, array $operation, ?HostInterface $host, array $options): void {
    self::$gestures[] = [
      'verb' => $verb,
      'kind' => $kind->value,
      'id' => $id,
      'operation' => $operation,
      'entity' => $host?->uuid(),
      'options' => $options,
    ];
  }

  /**
   * The reference set: one record per kind, plus the applicability cases.
   *
   * The two width variants (container_full, block_full) spell the same
   * attribute value on two scopes: every pin over this set sees that an
   * axis attribute's spelling partitions per scope, never site-wide.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records.
   */
  public static function referenceRecords(): array {
    return [
      new MaterialRecord(
        kind: MaterialKind::Token,
        id: 'color/accent',
        label: 'accent',
        value: ['default' => '#1475ad', 'dark' => '#52b3eb'],
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--color-accent)'],
        origin: MaterialRecord::ORIGIN_SHIPPED,
      ),
      new MaterialRecord(
        kind: MaterialKind::Swatch,
        id: 'lime_green',
        label: 'Lime green',
        value: '#4dff8c',
        group: 'color',
        spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-lime_green)'],
      ),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'button',
        label: 'Button',
        description: 'Dress the text block\'s link as a solid button.',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'button'],
        scope: Address::fromString('block_type:text'),
        origin: MaterialRecord::ORIGIN_SHIPPED,
      ),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'container_full',
        label: 'Full width',
        description: 'Let the container span the full page width.',
        group: 'width',
        spellings: [MaterialRecord::attrSlot('kind:container', 'width') => 'full'],
        scope: Address::fromString('kind:container'),
        applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT],
        weight: -10,
        origin: MaterialRecord::ORIGIN_SHIPPED,
      ),
      // The partition: the block kind spells the same attribute value on
      // the same attribute name, in its own scope's slot.
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'block_full',
        label: 'Full width',
        description: 'Let the block span its section\'s full width.',
        group: 'width',
        spellings: [MaterialRecord::attrSlot('kind:block', 'width') => 'full'],
        scope: Address::fromString('kind:block'),
        origin: MaterialRecord::ORIGIN_SHIPPED,
      ),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'hero_glow',
        label: 'Hero glow',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'hero_glow'],
        scope: Address::fromString('kind:container'),
        ownedBy: 'hero',
        origin: MaterialRecord::ORIGIN_AI,
      ),
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'link_quiet',
        label: 'Quiet link',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'link_quiet'],
        scope: Address::fromString('base:a'),
      ),
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'hero',
        label: 'Hero',
        description: 'Single-column hero banner with a heading.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'hero'],
        icon: '/modules/x/assets/hero.svg',
        blueprintScope: 'block',
        origin: MaterialRecord::ORIGIN_SHIPPED,
        updatable: FALSE,
        favorite: TRUE,
      ),
      new MaterialRecord(
        kind: MaterialKind::Animation,
        id: 'fade_in',
        label: 'Fade in',
        description: 'Fade the element in from transparent.',
        value: ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']],
        spellings: [MaterialRecord::SLOT_ANIMATION => 'motion-fade_in'],
        origin: MaterialRecord::ORIGIN_SHIPPED,
      ),
    ];
  }

}
