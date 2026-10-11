<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;

/**
 * The one meaning of "page": an entity whose render is a page document.
 *
 * Every surface that sorts pages from other entities asks here: the AI
 * doors (the JSON tools refuse a page toward the page tool for the
 * gesture) and the bundle listing the create workflow forks on.
 */
class PageHosts {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HostResolver $hostResolver,
    protected DialectCatalogBuilder $catalogBuilder,
  ) {}

  /**
   * Whether a host is a page: an entity whose render is a page document.
   *
   * On a page-language site (the catalog declares a container layout, so
   * the page language speaks for pages): a host that owns a document
   * (hasDocument(): a content host carrying the override field, filled or
   * still empty, a template, or the site frame) or one whose render shows
   * its template's document (HostResolver::renderHost() answers the
   * display's host, not this one: the plain host of a bundle whose display
   * is Layout Builder enabled without overrides). Both edit on the canvas,
   * both read through page_read, so both speak the page language. A host
   * whose render is its
   * own fields with no document anywhere (no Layout Builder on its
   * display) is not a page, and on a site without a container layout
   * nothing is: every JSON door stays open there.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return bool
   *   TRUE when the host is a page.
   */
  public function isPage(HostInterface $host): bool {
    if ($this->catalogBuilder->build()->containerLayoutId() === '') {
      return FALSE;
    }
    return $host->hasDocument() || $this->hostResolver->renderHost($host) !== $host;
  }

  /**
   * The host an unsaved entity of a bundle would have.
   *
   * The askers with no host in hand (a birth, a judged birth, the bundle
   * listing) ask the bundle's questions of an unsaved entity through its
   * host: the override field is a bundle fact (daedalus_layout adds it to
   * every Layout Builder enabled bundle, locked or not) and the render
   * display resolves for an unsaved entity, so hasDocument(),
   * HostResolver::foreignOwner() and HostResolver::renderHost() answer for
   * the bundle what they answer for every saved entity of it. Nothing is
   * persisted.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The unsaved host, or NULL for a type whose entities are not born from
   *   a bundle's fields (unknown, or config: a template is born by enabling
   *   Layout Builder on a display, never from a blueprint).
   */
  public function bundleHost(string $entity_type, string $bundle): ?HostInterface {
    $definition = $this->entityTypeManager->getDefinition($entity_type, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      return NULL;
    }
    $bundle_key = $definition->getKey('bundle');
    $entity = $this->entityTypeManager->getStorage($entity_type)->create(
      $bundle_key ? [$bundle_key => $bundle] : [],
    );
    return $this->hostResolver->hosts($entity) ? $this->hostResolver->fromEntity($entity) : NULL;
  }

  /**
   * Whether a bundle's entities are pages, before one exists.
   *
   * The answer isPage() gives every saved entity of the bundle, asked of
   * the bundle's unsaved host (bundleHost()).
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE when an entity of the bundle would be a page; FALSE for a type
   *   nothing hosts.
   */
  public function isPageBundle(string $entity_type, string $bundle): bool {
    $host = $this->bundleHost($entity_type, $bundle);
    return $host !== NULL && $this->isPage($host);
  }

}
