<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\ComponentTransform\SectionTransformInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;

/**
 * Converts the four retired multi-region layouts to container sections.
 *
 * A daedalus_stylish_onecol/twocol/threecol/fourcol section becomes ONE
 * daedalus_stylish_container section: each populated (or region-styled) region
 * becomes a minted child container component in region order, so the
 * grid's track occupants stay grouped exactly as the old region wrappers
 * grouped them - at nested depth a child container collapses to one div,
 * byte-for-byte the role the region wrapper played. The single-column case
 * inlines its region's components directly (no wrapper level) unless the
 * region itself carried styles that need an element to land on.
 *
 * Host style-map coherence rides the same rewrite (the layout-block
 * migration's pattern): section:{uuid} keys rename to container:{uuid},
 * region:{uuid}:{name} entries move to the minted child's
 * container:{child} key, and references to the unmodified shipped
 * layout-exact variant families re-point to the generic kind:container
 * families. Conditions are NOT rewritten here - the host sweep
 * (ContainerGrammarSweep) rewrites conditions map-wide after the section
 * walk, one rule for every entry.
 *
 * Registered permanently (the LayoutBlockToSectionTransform posture): the
 * region orders are frozen constants, so stale tempstore sessions keep
 * converging even after the four layout plugins are deleted.
 */
class MultiRegionSectionTransform implements SectionTransformInterface {

  public function __construct(
    protected ContainerMigration $migration,
    protected UuidInterface $uuid,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(Section $section, EntityInterface $host): bool {
    return isset(ContainerMigration::LAYOUT_REGIONS[$section->getLayoutId()]);
  }

  /**
   * {@inheritdoc}
   */
  public function transform(Section $section, EntityInterface $host): Section {
    $layout_id = $section->getLayoutId();
    $region_order = ContainerMigration::LAYOUT_REGIONS[$layout_id];
    $lines = [];

    $third_party = [];
    foreach ($section->getThirdPartyProviders() as $provider) {
      $third_party[$provider] = $section->getThirdPartySettings($provider);
    }
    $section_uuid = $third_party['daedalus_layout']['uuid'] ?? NULL;
    if (!is_string($section_uuid) || $section_uuid === '') {
      // Identity is minted lazily elsewhere (NestedAwareSectionStorage);
      // the migration needs it now to key the container and its children.
      $section_uuid = $this->uuid->generate();
      $third_party['daedalus_layout']['uuid'] = $section_uuid;
    }
    // Read the stored layout settings RAW: core's getLayoutSettings() (and
    // toArray()) round-trips through getLayout(), which resolves the plugin,
    // and the four retired plugins no longer exist on real sites. The
    // migration is frozen-shape by design (LAYOUT_REGIONS is a constant for
    // the same reason) and must never need the retired plugin to run.
    $settings = \Closure::bind(static fn (Section $s) => $s->layoutSettings, NULL, Section::class)($section);
    $replacement = new Section(ContainerMigration::TARGET_LAYOUT, is_array($settings) ? $settings : [], [], $third_party);

    $map = $this->hostStyleMap($host);
    $map_changed = FALSE;

    $by_region = [];
    foreach ($section->getComponents() as $component) {
      $by_region[$component->getRegion()][] = $component;
    }
    foreach ($by_region as &$list) {
      usort($list, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    }
    unset($list);

    $context = sprintf('%s section %s on %s', $layout_id, $section_uuid, $host->getEntityTypeId() . ':' . $host->id());
    $weight = 0;
    $minted = 0;
    foreach ($region_order as $region) {
      $components = $by_region[$region] ?? [];
      unset($by_region[$region]);
      $region_style_keys = $map === NULL ? [] : $this->regionStyleKeys($map, $section_uuid, $region);
      if ($components === [] && $region_style_keys === []) {
        continue;
      }
      if ($layout_id === 'daedalus_stylish_onecol' && $region_style_keys === []) {
        // Single column, unstyled region: the components ARE the flow.
        foreach ($components as $component) {
          $component->set('region', 'content');
          $replacement->appendComponent($component);
          $component->setWeight($weight++);
        }
        continue;
      }
      $child = new Section(ContainerMigration::TARGET_LAYOUT);
      $minted++;
      $child_uuid = $this->uuid->generate();
      $child->setThirdPartySetting('daedalus_layout', 'uuid', $child_uuid);
      $child_weight = 0;
      foreach ($components as $component) {
        $component->set('region', 'content');
        $child->appendComponent($component);
        $component->setWeight($child_weight++);
      }
      $container_component = SectionBlock::createComponent($child, 'content');
      $replacement->appendComponent($container_component);
      $container_component->setWeight($weight++);
      // The region wrapper's styles land on the child container - the same
      // rendered box under its new addressable key.
      foreach ($region_style_keys as $key) {
        $target = 'container:' . $child_uuid . substr($key, strlen("region:$section_uuid:$region"));
        $map[$target] = array_key_exists($target, $map)
          ? $this->migration->mergeEntries($map[$target], $map[$key])
          : $map[$key];
        unset($map[$key]);
        $lines[] = sprintf('%s: moved the "%s" styles to the minted child container ("%s").', $context, $key, $target);
        $map_changed = TRUE;
      }
    }
    // Components in regions the layout never declared (corrupt data):
    // append to the flow so nothing is silently dropped.
    foreach ($by_region as $region => $components) {
      foreach ($components as $component) {
        $component->set('region', 'content');
        $replacement->appendComponent($component);
        $component->setWeight($weight++);
      }
      $lines[] = sprintf('%s: the "%s" region does not exist on %s; its components were appended to the container flow.', $context, $region, $layout_id);
    }

    // The section's own entries: rename to the container key and re-point
    // layout-exact shipped variant references to the generic families.
    if ($map !== NULL) {
      $prefix = 'section:' . $section_uuid;
      foreach (array_keys($map) as $key) {
        if ($key !== $prefix && !str_starts_with($key, $prefix . '::')) {
          continue;
        }
        $entry = $map[$key];
        unset($map[$key]);
        $map_changed = TRUE;
        if (is_array($entry)) {
          $entry = $this->migration->repointEntryRefs($entry, "$context $key", $lines, $map_changed);
        }
        $target = 'container:' . $section_uuid . substr($key, strlen($prefix));
        $map[$target] = array_key_exists($target, $map) && is_array($map[$target]) && is_array($entry)
          ? $this->migration->mergeEntries($map[$target], $entry)
          : $entry;
        $lines[] = sprintf('%s: renamed the "%s" style key to "%s".', $context, $key, $target);
      }
      if ($map_changed) {
        $this->writeHostStyleMap($host, $map);
      }
    }

    $lines[] = sprintf('%s: converted to %s (%d child container(s) minted).', $context, ContainerMigration::TARGET_LAYOUT, $minted);
    $logger = $this->loggerFactory->get('daedalus_stylish_layouts');
    foreach ($lines as $line) {
      $logger->notice($line);
    }
    return $replacement;
  }

  /**
   * The host's style map, or NULL when the host cannot carry one.
   *
   * Read through the host (a content host's field, a display host's
   * template setting), so a template's multi-region sections migrate
   * their styles exactly as a page's do.
   */
  protected function hostStyleMap(EntityInterface $host): ?array {
    if (!$this->hostResolver->hosts($host)) {
      return NULL;
    }
    $store = $this->hostResolver->fromEntity($host);
    return $store->hasStyleStore() ? $store->styleMap() : NULL;
  }

  /**
   * Writes the host's style map back.
   */
  protected function writeHostStyleMap(EntityInterface $host, array $map): void {
    $this->hostResolver->fromEntity($host)->setStyleMap($map);
  }

  /**
   * The map keys addressing one region of one section (part keys included).
   *
   * @return string[]
   *   Matching keys.
   */
  protected function regionStyleKeys(array $map, string $section_uuid, string $region): array {
    $exact = "region:$section_uuid:$region";
    $keys = [];
    foreach (array_keys($map) as $key) {
      if ($key === $exact || str_starts_with($key, $exact . '::')) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

}
