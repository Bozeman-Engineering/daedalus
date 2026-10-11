<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * The subject vocabulary: the kinds of host a blueprint can be material for.
 *
 * A subject is spelled `entity_type:bundle`, the one spelling the file
 * envelope, the discovery entry, the gesture payload and the `bundles`
 * allow-list share. The OFFERED subjects are the page-builder bundles: a
 * fieldable bundle one of whose displays is Layout Builder enabled, since
 * a blueprint's canvas renders against a sample of its subject and the
 * Add panel's roster reads the subject display's restrictions (a bundle
 * no display lays out has nothing a blueprint could be material for).
 * The blueprint type itself is never a subject: its own display is
 * Layout Builder enabled, but a blueprint is material FOR hosts, not
 * for blueprints.
 *
 * Parsing is the gesture lane's validation (the details route and
 * update_material): a malformed or unknown subject refuses loudly here,
 * before the entity's presave would, so the client gets the gesture
 * error shape with the vocabulary as the corrective. The allow-list's
 * entries are shape-checked only, as the entity stores them: the fit
 * (daedalus_blueprint's BlueprintRequirements) reads them against a
 * host's subject at delivery and placement, and a package may ship
 * material for a bundle whose config lands later.
 */
final class BlueprintSubjects {

  /**
   * The spelling of a subject and of an allow-list entry.
   */
  public const PATTERN = '/^[a-z0-9_]+:[a-z0-9_]+$/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * A subject's spelling.
   */
  public static function spell(string $entity_type_id, string $bundle): string {
    return $entity_type_id . ':' . $bundle;
  }

  /**
   * The offered subjects: `type:bundle` => "Entity type: Bundle", sorted.
   *
   * Every fieldable bundle a Layout Builder display lays out, the
   * blueprint type excluded.
   *
   * @return array<string, string>
   *   The options, label-sorted.
   */
  public function options(): array {
    $options = [];
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    foreach ($storage->loadMultiple() as $display) {
      if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
        continue;
      }
      $entity_type_id = $display->getTargetEntityTypeId();
      $bundle = $display->getTargetBundle();
      if ($entity_type_id === BlueprintHost::ENTITY_TYPE_ID) {
        continue;
      }
      $key = self::spell($entity_type_id, $bundle);
      if (!isset($options[$key]) && ($label = $this->label($entity_type_id, $bundle)) !== NULL) {
        $options[$key] = $label;
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * A subject's display label, or NULL when the bundle is not fieldable.
   */
  public function label(string $entity_type_id, string $bundle): ?string {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      return NULL;
    }
    $bundles = $this->bundleInfo->getBundleInfo($entity_type_id);
    if (!isset($bundles[$bundle])) {
      return NULL;
    }
    $bundle_label = (string) $bundles[$bundle]['label'];
    $type_label = (string) $definition->getLabel();
    return $bundle_label === $type_label ? $type_label : sprintf('%s: %s', $type_label, $bundle_label);
  }

  /**
   * Parses a gesture's subject, loudly.
   *
   * @param mixed $subject
   *   The gesture value.
   * @param string $path
   *   The error path.
   *
   * @return array{0: string, 1: string}
   *   The entity type id and the bundle.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function parse(mixed $subject, string $path = 'subject'): array {
    if (!is_string($subject) || !preg_match(self::PATTERN, $subject)) {
      throw new BlueprintGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', sprintf('%s must be spelled "entity_type:bundle".', $path), [], 'Name the kind of host the blueprint is material for, e.g. "node:page".'),
      ]);
    }
    [$entity_type_id, $bundle] = explode(':', $subject, 2);
    if ($this->label($entity_type_id, $bundle) === NULL) {
      throw new BlueprintGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', sprintf('"%s" is not a fieldable bundle on this site.', $subject), ['available' => array_keys($this->options())], sprintf('Name one of the site\'s page-builder bundles: %s.', implode(', ', array_keys($this->options())) ?: '(none has a Layout Builder display)')),
      ]);
    }
    return [$entity_type_id, $bundle];
  }

  /**
   * Parses a gesture's allow-list, loudly, shape only.
   *
   * @param mixed $bundles
   *   The gesture value: a list of `entity_type:bundle` strings; [] (or
   *   NULL) clears the list, which means any host the blueprint fits.
   *
   * @return string[]
   *   The entries, de-duplicated, in order.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function parseBundles(mixed $bundles, string $path = 'bundles'): array {
    if ($bundles === NULL) {
      return [];
    }
    if (!is_array($bundles) || array_filter($bundles, static fn ($entry) => !is_string($entry) || !preg_match(self::PATTERN, $entry)) !== []) {
      throw new BlueprintGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', sprintf('%s must be a list of "entity_type:bundle" strings.', $path), [], 'List the hosts the blueprint may be placed on, e.g. ["node:page"], or pass an empty list for any host it fits.'),
      ]);
    }
    return array_values(array_unique($bundles));
  }

}
