<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_blueprint\Materials\CanvasOffer;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\layout_builder\Entity\SampleEntityGeneratorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A start's live preview: the dry run of the start verb, rendered.
 *
 * A chooser card's face is the page the start would produce for this
 * bundle on this site, never a stored picture: one route renders a sample
 * entity of the bundle with the start applied, unsaved. The sample is
 * core's (the one Layout Builder renders a template's field blocks
 * against), duplicated before anything touches it because the generator's
 * entity is shared through its tempstore, with its document fields
 * emptied: core's sample value for the layout field is one empty onecol
 * section, which would read as an overridden document, and a dry run
 * starts from the entity an add form holds, sample field values and an
 * empty document. Then the spine's one verb (PageStart::apply()) does
 * what the add form's save would do: `template` leaves the field empty
 * and the entity renders its template (the plain render), `blank` writes
 * core's marker and the entity renders its own empty page, a page
 * blueprint's id copies its whole document with its unsaved inline blocks
 * serialized into the components. The entity view builder renders the
 * result in the full view mode, as the page's canonical route would.
 * Nothing is saved: no entity, no block, no media; a request leaves the
 * site's counts as they were (StartChooserTest pins it).
 *
 * The document is chromeless through daedalus_ui's preview-mode door
 * (Chromeless: ?navigationMode=preview, which ModeState reads before the
 * cookie), carried on the URL this class builds for the card's frame
 * (url()), and NOT through the pop-out route option: a pop-out is a tool
 * window outside the skin's reach (no token scope, no compiled skin, the
 * editor's chrome paint on the body), and a page's live preview is the
 * page as a visitor would see it, skin and all, with the editor gone.
 *
 * Access is the entity type's create access for the bundle, the gate
 * every creating door asks; what the chooser never offers (a non-page
 * bundle, a start that is neither reserved nor offered for the bundle) is
 * not found. The response carries what it depends on: the roster and the
 * offer (which starts exist and fit), the bundle, the display it rendered
 * through and whatever the render bubbled.
 */
final class StartPreview extends ControllerBase {

  use AutowireTrait;

  /**
   * The route name.
   */
  public const ROUTE = 'daedalus_layout.start_preview';

  /**
   * The query that makes the preview document chromeless.
   *
   * The preview mode of daedalus_ui (Chromeless::applies(), with
   * ModeState::getMode() reading the query parameter before the cookie):
   * the site page without the editor.
   */
  public const CHROMELESS_QUERY = ['navigationMode' => 'preview'];

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected PageHosts $pageHosts,
    protected PageStart $pageStart,
    protected HostResolver $hostResolver,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected CanvasOffer $offer,
    protected SampleEntityGeneratorInterface $sampleEntityGenerator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * The URL a card's frame loads: the route with the chromeless query.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $start
   *   The start: template, blank or a page blueprint's id.
   */
  public static function url(string $entity_type, string $bundle, string $start): Url {
    return Url::fromRoute(self::ROUTE, [
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'start' => $start,
    ], ['query' => self::CHROMELESS_QUERY]);
  }

  /**
   * Access: the entity type's create access for the bundle.
   *
   * A type or bundle the site lacks is forbidden here (the access system
   * answers before the controller); everything the chooser offers is
   * judged by the same handler the add form and page_create ask.
   */
  public function access(string $entity_type, string $bundle, AccountInterface $account): AccessResultInterface {
    if (!$this->entityTypeManager->hasDefinition($entity_type)
      || !isset($this->bundleInfo->getBundleInfo($entity_type)[$bundle])) {
      return AccessResult::forbidden(sprintf('No %s bundle %s on this site.', $entity_type, $bundle))
        ->addCacheTags(['entity_bundles']);
    }
    return $this->entityTypeManager->getAccessControlHandler($entity_type)->createAccess($bundle, $account, [], TRUE);
  }

  /**
   * The document's title: the sample's label, as the page's route shows it.
   */
  public function title(string $entity_type, string $bundle): string {
    return (string) $this->sampleEntityGenerator->get($entity_type, $bundle)->label();
  }

  /**
   * Renders the sample entity with the start applied.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $start
   *   The start: template, blank or a page blueprint's id.
   *
   * @return array
   *   The render array.
   */
  public function build(string $entity_type, string $bundle, string $start): array {
    $bundle_host = $this->pageHosts->bundleHost($entity_type, $bundle);
    if ($bundle_host === NULL || !$this->pageStart->takesStart($bundle_host) || !$this->pageHosts->isPage($bundle_host)) {
      throw new NotFoundHttpException(sprintf('%s %s is not a page bundle: nothing starts there.', $entity_type, $bundle));
    }
    $starts = $this->pageStart->starts($bundle_host);
    if (!in_array($start, PageStart::RESERVED, TRUE) && !isset($starts[$start])) {
      throw new NotFoundHttpException(sprintf('"%s" is not a start offered for %s %s.', $start, $entity_type, $bundle));
    }

    $entity = $this->sample($entity_type, $bundle);
    $host = $this->hostResolver->fromEntity($entity);
    $result = $this->pageStart->apply($host, $start);
    if (!$result->isSuccess()) {
      // The offer judged the start a moment ago; a refusal between the two
      // is a change inside one request and is loud, as the chooser's is.
      $errors = $result->getErrors();
      $error = reset($errors);
      throw new \RuntimeException(sprintf('The start "%s" offered for %s %s was refused by its preview: %s (%s)', $start, $entity_type, $bundle, $error->getMessage(), $error->getCode()));
    }

    $build = $this->entityTypeManager->getViewBuilder($entity_type)->view($entity, 'full');
    $metadata = CacheableMetadata::createFromRenderArray($build)
      ->merge($this->rosterBuilder->cacheMetadata())
      ->merge($this->offer->cacheMetadata())
      ->addCacheableDependency($host->display())
      ->addCacheContexts(['url.query_args:navigationMode']);
    $bundle_entity_type = $this->entityTypeManager->getDefinition($entity_type)->getBundleEntityType();
    if ($bundle_entity_type !== NULL) {
      $metadata->addCacheableDependency($this->entityTypeManager->getStorage($bundle_entity_type)->load($bundle));
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * A fresh copy of core's sample entity, its document fields empty.
   *
   * The generator's entity is shared (its tempstore), so the start lands
   * on a duplicate. Core's sample value for the layout field is one empty
   * onecol section, which the override storage would read as a document
   * of its own; the style map's sample is noise too. A dry run starts
   * where an add form's entity starts: sample field values, no document.
   *
   * The sample's published flag is a coin toss (the boolean sample value),
   * and the page's blocks follow their host's view access (every inline
   * block and field block takes the layout entity as its access
   * dependency; for an entity with no id core's node grants answer "is it
   * published" alone), so an unpublished sample would preview an empty
   * page. The dry run previews the page as published: the page a visitor
   * sees once it is.
   */
  protected function sample(string $entity_type, string $bundle): FieldableEntityInterface {
    $sample = $this->sampleEntityGenerator->get($entity_type, $bundle);
    assert($sample instanceof FieldableEntityInterface, 'A page bundle (bundleHost() answered one) is fieldable.');
    $entity = $sample->createDuplicate();
    if ($entity instanceof EntityPublishedInterface) {
      $entity->setPublished();
    }
    $entity->set(ContentHost::LAYOUT_FIELD, []);
    $host = $this->hostResolver->fromEntity($entity);
    $style_map = $host->styleMapDefinition();
    if ($style_map !== NULL) {
      $entity->set($style_map->getName(), []);
    }
    return $entity;
  }

}
