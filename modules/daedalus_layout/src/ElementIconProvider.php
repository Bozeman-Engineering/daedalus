<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_layout\AddPanel\BlockTypeFace;
use Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface;

/**
 * Answers the icon of a container and of an inline block.
 *
 * The two kinds daedalus_ui cannot word itself are this module's: a
 * container node is a SectionBlock component (SectionBlock::PLUGIN_ID),
 * which wears the Container's own asset, and an inline block is an
 * instance of a block type, whose icon is the type's
 * `daedalus_layout.icon` setting resolved through the icon value grammar
 * (IconResolver, the one seam every icon value crosses). These are the
 * Add panel's two icon sources (AddPanelFaces), so a thing's face in the
 * panel and its row in an outline wear one icon by construction. A block
 * type with no icon set, or one deleted under a placed block, wears the
 * module's default block icon: an inline block always has a face here.
 * Anything else (a plugin block, a field block) is daedalus_ui's own and
 * gets NULL.
 *
 * Cacheability: the block type read rides the accumulator, and a media
 * icon's tags ride it through the resolver, so the render delivering the
 * annotation invalidates when the type's icon changes or the media's file
 * is replaced.
 */
final class ElementIconProvider implements ElementIconProviderInterface {

  public function __construct(
    private readonly IconResolver $iconResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function icon(array $component, string $kind, CacheableMetadata $cacheability): ?string {
    if ($kind === self::KIND_CONTAINER) {
      return $this->asset('container.svg');
    }
    $block_type_id = $component['block_type'] ?? NULL;
    if ($kind !== self::KIND_BLOCK || !is_string($block_type_id) || $block_type_id === '') {
      return NULL;
    }
    $block_type = $this->entityTypeManager->getStorage('block_content_type')->load($block_type_id);
    if ($block_type === NULL) {
      return $this->asset('default-block-icon.svg');
    }
    $cacheability->addCacheableDependency($block_type);
    $tags = [];
    $icon = $this->iconResolver->resolve($block_type->getThirdPartySetting(BlockTypeFace::NAMESPACE, BlockTypeFace::ICON_SETTING), $tags);
    $cacheability->addCacheTags($tags);
    return $icon ?? $this->asset('default-block-icon.svg');
  }

  /**
   * The root-relative URL of one of this module's assets.
   */
  private function asset(string $file): string {
    return '/' . $this->moduleList->getPath('daedalus_layout') . '/assets/' . $file;
  }

}
