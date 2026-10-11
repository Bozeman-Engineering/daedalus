<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

/**
 * Where one merged declaration value came from: the last layer to write it.
 *
 * PageBuilderDeclarations::getOrigins() serves a tree mirroring the layered
 * set with one of these at every leaf (a scalar, a list, or an empty map;
 * a populated map is a node whose children carry their own). A file origin
 * names the provider (module or theme) and the file's basename: every
 * declaration file sits at its extension's root, so provider plus basename
 * is the whole address, and a basename, unlike an absolute path, is stable
 * across environments and safe to cache or send to a client. The alter
 * origin (both NULL) marks a value hook_page_builder_declarations_alter()
 * wrote or changed; the module handler runs every implementation as one
 * layer, so no finer attribution exists there.
 *
 * Leaves are objects rather than {provider, file} maps so an origin tree
 * composes through PageBuilderDeclarations::merge() exactly as the
 * declarations do (an object is replaced whole, never merged key by key):
 * the effective origin tree of a block type is merge() of the universal
 * block kind's tree under the bundle's, the same call that builds the
 * effective declaration.
 */
final class DeclarationOrigin {

  public function __construct(
    public readonly ?string $provider,
    public readonly ?string $file,
  ) {
    if (($provider === NULL) !== ($file === NULL)) {
      throw new \InvalidArgumentException('A declaration origin names both its provider and its file, or neither (the alter hook).');
    }
  }

  /**
   * The origin of a value the alter hook wrote.
   */
  public static function alter(): self {
    return new self(NULL, NULL);
  }

  /**
   * Whether hook_page_builder_declarations_alter() wrote the value.
   */
  public function isAlter(): bool {
    return $this->provider === NULL;
  }

  /**
   * The origin as a plain map, for payloads and comparisons.
   *
   * @return array{provider: string|null, file: string|null}
   *   The provider and file, both NULL for the alter origin.
   */
  public function toArray(): array {
    return ['provider' => $this->provider, 'file' => $this->file];
  }

}
