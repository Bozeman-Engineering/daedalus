<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Rewrites an old heading component into a text block component.
 *
 * The old heading block type had no fields: the heading text was the
 * component label (the block forced the label on), and the tag (label_tag),
 * the size class (title_size), the alignment (horizontal_alignment) and the
 * link (link_enabled, url) lived in the component configuration. daedalus's
 * text block carries the content in fields, so each heading component
 * becomes an inline_block:text component over a new text block: the label
 * to field_text_text, the tag to field_text_tag, the url to field_text_url
 * when the link was enabled. The size class and the alignment have no field
 * to go to: they are dropped and counted per value, so the report says what
 * was lost and where re-authoring is due.
 *
 * A transform of daedalus_layout's migration spine, applied by the content
 * phase through ComponentTransformRegistry, whose section walk reaches
 * nested sections. The replacement block rides the block_serialized
 * contract of ComponentTransformInterface: written unsaved, it is saved and
 * its usage recorded by the inline-block presave machinery when the host is
 * saved, which is why the transform never saves it itself. The component
 * keeps its uuid, so styles addressing it hold; every other configuration
 * key the old suite did not own (a site's own block properties) is kept.
 */
final class HeadingTransform implements ComponentTransformInterface {

  /**
   * The old heading block's component plugin id.
   */
  public const HEADING = 'inline_block:heading';

  /**
   * The text block's component plugin id.
   */
  public const TEXT = 'inline_block:text';

  /**
   * The text block type.
   */
  public const TEXT_BUNDLE = 'text';

  /**
   * The tag a heading gets when neither stored value is an allowed tag.
   */
  public const DEFAULT_TAG = 'h2';

  /**
   * The old keys read for the tag, in order: the first allowed value wins.
   *
   * On the latest old release title_size held a size class (text-jumbo1,
   * text-large1) and label_tag the tag, always h2; a site whose title_size
   * holds a tag keeps it.
   */
  public const TAG_KEYS = ['title_size', 'label_tag'];

  /**
   * The old keys the text block has no field for, counted when dropped.
   */
  public const DROPPED = ['title_size', 'horizontal_alignment'];

  /**
   * The old keys a text component no longer carries.
   */
  private const OLD_KEYS = [
    'title_size',
    'label_tag',
    'horizontal_alignment',
    'link_enabled',
    'url',
    'block_id',
    'block_uuid',
  ];

  /**
   * The components rewritten so far.
   */
  private int $converted = 0;

  /**
   * The dropped values so far: key to value to count.
   *
   * @var array<string, array<string, int>>
   */
  private array $dropped = [];

  /**
   * Constructs the transform.
   *
   * @param list<string> $allowedTags
   *   The values field_text_tag allows on this site.
   */
  public function __construct(private readonly array $allowedTags) {}

  /**
   * {@inheritdoc}
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool {
    return $component->getPluginId() === self::HEADING;
  }

  /**
   * {@inheritdoc}
   */
  public function transform(SectionComponent $component, EntityInterface $host): void {
    $configuration = $component->get('configuration');
    $label = trim((string) ($configuration['label'] ?? ''));
    [$tag, $tag_key] = $this->tag($configuration);
    $values = [
      'type' => self::TEXT_BUNDLE,
      'info' => mb_substr($label, 0, 255),
      'reusable' => FALSE,
      'field_text_text' => $label,
      'field_text_tag' => $tag,
    ];
    $uri = $this->uri($configuration);
    if ($uri !== NULL) {
      $values['field_text_url'] = ['uri' => $uri];
    }
    $block = BlockContent::create($values);

    foreach (self::DROPPED as $key) {
      $value = $configuration[$key] ?? NULL;
      if ($key !== $tag_key && is_scalar($value) && (string) $value !== '') {
        $this->dropped[$key][(string) $value] = ($this->dropped[$key][(string) $value] ?? 0) + 1;
      }
    }

    $configuration = array_diff_key($configuration, array_flip(self::OLD_KEYS));
    $configuration['id'] = self::TEXT;
    $configuration['provider'] = 'layout_builder';
    $configuration['label'] = $label;
    $configuration['label_display'] = '0';
    $configuration['view_mode'] = $configuration['view_mode'] ?? 'full';
    $configuration['block_revision_id'] = NULL;
    $configuration['block_serialized'] = serialize($block);
    $component->setConfiguration($configuration);
    $this->converted++;
  }

  /**
   * The components rewritten so far.
   */
  public function converted(): int {
    return $this->converted;
  }

  /**
   * The dropped values so far.
   *
   * @return array<string, array<string, int>>
   *   Old key to value to the number of components that carried it.
   */
  public function dropped(): array {
    return $this->dropped;
  }

  /**
   * The tag for a heading and the old key it came from.
   *
   * @return array{0: string, 1: string|null}
   *   The tag, and the key whose value it is (NULL for the default).
   */
  private function tag(array $configuration): array {
    foreach (self::TAG_KEYS as $key) {
      $value = $configuration[$key] ?? NULL;
      if (is_string($value) && in_array($value, $this->allowedTags, TRUE)) {
        return [$value, $key];
      }
    }
    return [self::DEFAULT_TAG, NULL];
  }

  /**
   * The link field uri for a heading, or NULL when it was not linked.
   *
   * The old form took a URL or a site path; a link field's uri carries a
   * scheme, so a schemeless value becomes an internal: uri the way core's
   * link widget maps what a person types (`<front>` to the front page).
   */
  private function uri(array $configuration): ?string {
    if (empty($configuration['link_enabled'])) {
      return NULL;
    }
    $url = trim((string) ($configuration['url'] ?? ''));
    if ($url === '') {
      return NULL;
    }
    if (parse_url($url, PHP_URL_SCHEME) !== NULL) {
      return $url;
    }
    if (str_starts_with($url, '<front>')) {
      $url = '/' . substr($url, strlen('<front>'));
    }
    elseif (!in_array($url[0], ['/', '?', '#'], TRUE)) {
      $url = '/' . $url;
    }
    return 'internal:' . $url;
  }

}
