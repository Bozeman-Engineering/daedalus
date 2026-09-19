<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;

/**
 * Resolves media tokens to file URLs as a pre-print map transform.
 *
 * The delivery half of the media value spine (MediaToken): storage keeps the
 * semantic url('media:<uuid>') token, and every server print site transforms
 * its map or variant styles through here immediately before printing —
 * CssStyleBackend for the entity's own map, VariantStylesheet for variant
 * styles (compiled file, workspace inline fallback, edit-mode inline
 * resolution). The printers never see a token, and never learn about media:
 * they stay the dumb, equivalence-locked pair.
 *
 * The image style is resolved at render, never stored (see the notes'
 * decisions): the style guide may constrain a media-capable property with an
 * image_style key ({constraints: {background-image: {image_style: hero}}}),
 * falling back to the daedalus_stylish.settings media_image_style site default,
 * falling back to the original file. Constraints resolve exactly the way
 * validation resolves them — the vocabulary roster of the key's captured
 * address for map entries, of the variant's own scope for variant styles —
 * against the site default theme, the same law validation enforced on
 * write.
 *
 * Degradation is the emit gate's: a token whose media (or file) no longer
 * resolves substitutes to "none" — a sound value in every <image> slot — so
 * a dangling reference degrades the declaration instead of printing a
 * literal media: URL. Unpublished media still resolves: public file URLs
 * are not access checked, so hiding the declaration would fake a privacy
 * the file system does not provide. Cache metadata bubbles through the
 * accumulator: the media entity's tags always (so a file replace or delete
 * invalidates the host render), the image style's config tag when one
 * applied.
 */
class MediaValueResolver {

  /**
   * The source field types whose media a style value may reference.
   *
   * The ONE media gate, checked on the source FIELD TYPE (not the source
   * plugin class, so custom sources with a qualifying source field count)
   * and mirrored at every seam so the offer, validation, and resolution can
   * never fork: the library opener's allowed types
   * (DaedalusStylishMediaLibraryOpener), token validation
   * (PropertyGroupBase::validateMediaTokens), and URL resolution (url()).
   *
   * svg_image_field is the vector lane: vector media resolve to a plain
   * file URL exactly like rasters, so SVGs only ever reach the browser in
   * inert positions (background-image / <img>), never inline in the DOM.
   * Image styles cannot process them; the supportsUri() guard below
   * degrades to the original file.
   */
  public const SOURCE_FIELD_TYPES = ['image', 'svg_image_field'];

  public function __construct(
    protected EntityRepositoryInterface $entityRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected StyleMapValidator $validator,
    protected ThemeHandlerInterface $themeHandler,
    protected VocabularyRosterBuilder $vocabulary,
  ) {}

  /**
   * Resolves every media token in a style map's property values.
   *
   * Only condition-keyed property values are transformed: the "variants"
   * reference list holds no CSS.
   *
   * @param array $map
   *   The decoded style map.
   * @param array $cache_tags
   *   Accumulator the resolved media's (and image styles') tags merge into.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The map's host entity, when available: enables per-block-type and
   *   per-layout constraint resolution for block:/container: keys, exactly as
   *   validation resolves them.
   *
   * @return array
   *   The map with every token replaced by url("<file url>") or "none".
   */
  public function resolveMap(array $map, array &$cache_tags, ?FieldableEntityInterface $entity = NULL): array {
    // A full closure, not an arrow fn: arrow fns capture by VALUE, and the
    // tag accumulator must flow back to the caller by reference.
    return $this->transformMapTokens($map, $entity, function (string $value, string $property, ?VocabularyRoster $roster) use (&$cache_tags): string {
      return $this->resolveValue($value, $property, $roster, $cache_tags);
    });
  }

  /**
   * Builds the client mirror's uuid -> URL table from a style map's tokens.
   *
   * The preview payload (drupalSettings.daedalusStylish.mediaUrls): the client
   * pre-print transform substitutes tokens from this table exactly where the
   * server would have resolved them, so the live-preview tag shows the image
   * the settled render will show. Each URL resolves through the same
   * image-style logic as server printing (the one url() seam underneath).
   *
   * The table is deliberately FLAT: one URL per uuid, first resolution
   * wins. When guide constraints give the same media different derivative
   * styles on different keys, the preview for the later key shows the first
   * key's derivative until its op settles; the authoritative tag that
   * replaces it is always server-printed, so nothing wrong ever persists.
   * Unresolvable uuids are omitted: the client substitutes "none" for any
   * uuid the table cannot answer, mirroring the server's emit-gate
   * degradation.
   *
   * @param array $map
   *   The decoded style map (the tempstore-staged host's, when one exists:
   *   the map the blueprint mirror will show).
   * @param array $cache_tags
   *   Accumulator the resolved media's (and image styles') tags merge into;
   *   attach these wherever the table is attached.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The map's host entity, for constraint resolution (see resolveMap()).
   *
   * @return array
   *   Lowercased media uuid => root-relative URL, resolvable tokens only.
   */
  public function clientUrlTable(array $map, array &$cache_tags, ?FieldableEntityInterface $entity = NULL): array {
    $table = [];
    $this->transformMapTokens($map, $entity, function (string $value, string $property, ?VocabularyRoster $roster) use (&$table, &$cache_tags): string {
      $image_style = $this->imageStyleFor($property, $roster);
      foreach (MediaToken::extract($value) as $payload) {
        $uuid = strtolower($payload);
        if (!array_key_exists($uuid, $table)) {
          $url = $this->url($uuid, $image_style, $cache_tags);
          if ($url !== NULL) {
            $table[$uuid] = $url;
          }
        }
      }
      return $value;
    });
    return $table;
  }

  /**
   * Walks a map's token-bearing property values through a transform.
   *
   * The one walk both resolveMap() and clientUrlTable() share, so what
   * counts as a resolvable position can never fork: condition-keyed property
   * values only; the "variants" reference list holds no CSS.
   *
   * @param array $map
   *   The decoded style map.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The map's host entity, for constraint resolution.
   * @param callable $transform
   *   fn (string $value, string $property, ?VocabularyRoster $roster):
   *   string. Receives each token-bearing value with the roster of its
   *   key's address (NULL when the key does not parse); the return value
   *   replaces the original.
   *
   * @return array
   *   The map with every token-bearing value transformed.
   */
  protected function transformMapTokens(array $map, ?FieldableEntityInterface $entity, callable $transform): array {
    foreach ($map as $key => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      // The roster is only needed for the image_style constraint, so it
      // resolves lazily: token-free maps never touch the style guide.
      $resolved = FALSE;
      $roster = NULL;
      foreach ($entry as $condition => $properties) {
        if ((string) $condition === 'variants' || !is_array($properties)) {
          continue;
        }
        foreach ($properties as $property => $value) {
          if (!is_scalar($value) || !MediaToken::mentions((string) $value)) {
            continue;
          }
          if (!$resolved) {
            $roster = $this->keyRoster((string) $key, $entity);
            $resolved = TRUE;
          }
          $map[$key][$condition][$property] = $transform((string) $value, (string) $property, $roster);
        }
      }
    }
    return $map;
  }

  /**
   * Resolves every media token in a variant's element-relative styles.
   *
   * Variants resolve their image style constraint via their scope's
   * roster (the decision's declaration context): the scope's roster
   * covers every entry, including slotted and part keys — per-part
   * constraint refinement is not consulted here, the site default still
   * applies underneath.
   *
   * @param array $styles
   *   The variant's element-relative styles.
   * @param string $scope
   *   The variant's scope, a root address printed (Address).
   * @param array $cache_tags
   *   Accumulator the resolved media's (and image styles') tags merge into.
   *
   * @return array
   *   The styles with every token resolved.
   */
  public function resolveVariantStyles(array $styles, string $scope, array &$cache_tags): array {
    $roster = NULL;
    foreach ($styles as $relative => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      foreach ($entry as $condition => $properties) {
        if (!is_array($properties)) {
          continue;
        }
        foreach ($properties as $property => $value) {
          if (!is_scalar($value) || !MediaToken::mentions((string) $value)) {
            continue;
          }
          $roster ??= $this->vocabulary->build(Address::fromString($scope), $this->defaultTheme());
          $styles[$relative][$condition][$property] = $this->resolveValue((string) $value, (string) $property, $roster, $cache_tags);
        }
      }
    }
    return $styles;
  }

  /**
   * Resolves a media uuid to the URL delivery should print.
   *
   * The one uuid -> URL seam, public so the client mirror's uuid->url table
   * (preview resolution) is served by the same logic as server printing.
   *
   * @param string $uuid
   *   The media entity uuid (a token's payload).
   * @param string|null $image_style
   *   An image style machine name, or NULL for the original file. A style
   *   that does not exist or cannot process the file degrades to the
   *   original file, never to nothing.
   * @param array $cache_tags
   *   Accumulator; the media's tags merge in whenever the media loads (so
   *   even a non-image media's edits invalidate), the style's config tag
   *   when a derivative URL is served.
   *
   * @return string|null
   *   A root-relative URL, or NULL when the uuid resolves to no image or
   *   vector file (media module absent, no such media, non-qualifying
   *   source, missing file).
   */
  public function url(string $uuid, ?string $image_style, array &$cache_tags): ?string {
    if (!$this->entityTypeManager->hasDefinition('media')) {
      return NULL;
    }
    $media = $this->entityRepository->loadEntityByUuid('media', $uuid);
    if (!$media instanceof MediaInterface) {
      return NULL;
    }
    $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());
    // The gate is the source FIELD TYPE (SOURCE_FIELD_TYPES), mirroring
    // validation (PropertyGroupBase::validateMediaTokens): custom sources
    // with a qualifying source field count.
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
    if (!in_array($source_field?->getType(), self::SOURCE_FIELD_TYPES, TRUE)) {
      return NULL;
    }
    $file = $media->get($source_field->getName())->entity;
    if (!$file instanceof FileInterface) {
      return NULL;
    }
    $uri = (string) $file->getFileUri();
    if ($image_style !== NULL && $this->entityTypeManager->hasDefinition('image_style')) {
      $style = $this->entityTypeManager->getStorage('image_style')->load($image_style);
      if ($style instanceof ImageStyleInterface && $style->supportsUri($uri)) {
        $cache_tags = Cache::mergeTags($cache_tags, $style->getCacheTags());
        return $this->fileUrlGenerator->transformRelative($style->buildUrl($uri));
      }
    }
    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Resolves a media uuid for a keyless preview surface (a fresh pick).
   *
   * The media library selection response carries the resolved URL so a pick
   * previews instantly (the client extends its uuid -> URL table before
   * staging). No map key exists at selection time, so no guide constraint
   * can apply — the site default image style (or the original file)
   * resolves. Consistent with the flat client table's contract: a preview
   * derivative may differ from a key's constrained one until the op
   * settles; the authoritative tag is always server-printed.
   *
   * @param string $uuid
   *   The media entity uuid.
   *
   * @return string|null
   *   A root-relative URL, or NULL when the uuid resolves to no image or
   *   vector file.
   */
  public function previewUrl(string $uuid): ?string {
    $cache_tags = [];
    // The empty property and the absent roster ride the normal fallback
    // chain past the (inapplicable) guide constraint to the site default.
    return $this->url($uuid, $this->imageStyleFor('', NULL), $cache_tags);
  }

  /**
   * Substitutes every token in one property value.
   */
  protected function resolveValue(string $value, string $property, ?VocabularyRoster $roster, array &$cache_tags): string {
    $image_style = $this->imageStyleFor($property, $roster);
    return MediaToken::substitute($value, function (string $uuid) use ($image_style, &$cache_tags): string {
      $url = $this->url($uuid, $image_style, $cache_tags);
      return $url === NULL ? 'none' : sprintf('url("%s")', $url);
    });
  }

  /**
   * The image style a property's tokens resolve through, or NULL for original.
   *
   * Guide constraint (the property's image_style key in the roster's value
   * space, the effective constraint validation binds) -> daedalus_stylish.settings
   * media_image_style site default -> NULL (the original file).
   */
  protected function imageStyleFor(string $property, ?VocabularyRoster $roster): ?string {
    $style = $roster?->valueSpace($property)['image_style'] ?? NULL;
    $style ??= $this->configFactory->get('daedalus_stylish.settings')->get('media_image_style');
    return is_string($style) && $style !== '' ? $style : NULL;
  }

  /**
   * The vocabulary roster a map key's constraints live on.
   *
   * Exactly validation's resolution: the key's address (captured per
   * bundle / per layout with the host entity in context, universal kind
   * degradation without) with its part, tag-tier or children suffix,
   * built by the one roster derivation. NULL when the key does not parse.
   */
  protected function keyRoster(string $key, ?FieldableEntityInterface $entity): ?VocabularyRoster {
    $parsed = $this->validator->parseKey($key);
    $root = $parsed === NULL ? NULL : $this->validator->captureScope($key, $entity === NULL ? [] : ['entity' => $entity]);
    if ($root === NULL) {
      return NULL;
    }
    return $this->vocabulary->build(Address::of($root->family, $root->id, $parsed['sub'], $parsed['children']), $this->defaultTheme());
  }

  /**
   * The guide theme: the site default, exactly as validation resolves it.
   *
   * Styled pages render in the default theme; the active theme during an
   * API or admin request is often the admin theme and would resolve the
   * wrong guide — the same reasoning as StyleMapValidator::validate().
   */
  protected function defaultTheme(): ?string {
    return $this->themeHandler->getDefault() ?: NULL;
  }

}
