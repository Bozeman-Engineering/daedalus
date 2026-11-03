<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionInput;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Tool\ParameterProse;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Drupal\daedalus_blueprint_ai\PageToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a new page: a JSON envelope carrying one dialect document.
 *
 * The one-gesture create the dialect promised: entity identity and
 * entity-level fields ride the JSON envelope, and the page's first
 * document has one source, picked once here: a start (the spine's
 * PageStart: `template`, the bundle's default; `blank`, an empty page;
 * or a page blueprint's id, a whole saved page copied with fresh
 * identity) or the whole layout (with its styles) authored as ONE
 * page-language document. The bundle decides which parameters it takes
 * (judged at execute, since a static schema cannot spell a bundle fact):
 * a bundle whose pages own their layout (the override field on the
 * bundle) takes exactly one of `start` and `layout`, and a plain bundle
 * (its pages render the template's document) takes neither, since the
 * page lands with its fields alone and no document is seeded under it.
 * Either source is applied to the in-memory entity ahead of the one save
 * that lands entity and document together. Non-page entities (config,
 * variants, skins, media, a bundle no page document renders)
 * stay on eb_create_entity's JSON blueprint; this tool is for pages. A
 * saved blueprint is a page of its own (entity_type blueprint, whose
 * document is material for its subject's pages), so it is born here too:
 * its face in fields, its composition in layout, judged by the same
 * spine (the blueprint host expands field blocks against its subject and
 * BlueprintScopePolicy holds a block-scope blueprint to one root).
 */
#[FunctionCall(
  id: 'daedalus_blueprint:page_create',
  function_name: 'page_create',
  name: 'Page Create',
  description: 'Creates a new page in one gesture: entity fields (title etc.) in "fields", and the page\'s first document as "start" or "layout". "start" is one of three: "template" (the bundle\'s default layout, the one a page made in the UI gets), "blank" (an empty page), or the id of a page blueprint (a whole saved page, copied into the new page; the bundle\'s schema read lists the ones offered for it under "starts"). "layout" is the whole page authored as one page-language document (top-level <d-container> elements, styles inline or in a trailing <style> block). The bundle decides which it takes, and start and layout are never passed together: pages that own their layout take exactly one of them; pages that fill a locked template\'s slots take fields and optionally start (template, or a page blueprint of slot fills from the bundle\'s starts), never layout, the slots filled with page_edit afterwards; pages that render the template outright take neither (fields only). Saves and publishes the new page at once. Returns the page URL, id echoes, and the page print. A saved blueprint is authored the same way: entity_type blueprint, bundle blueprint, fields {label, description, scope (block: one root container placed into pages; page: a whole page, a start for new pages), subject_type and subject_bundle (the kind of page it is for, whose fields its field blocks name)}, layout its composition.',
  group: 'modification_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      description: new TranslatableMarkup(ParameterProse::ENTITY_TYPE),
    ),
    'bundle' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Bundle'),
      required: TRUE,
      description: new TranslatableMarkup('The bundle (e.g. page); the PAGES line names the page bundles.'),
    ),
    'fields' => new ContextDefinition(
      data_type: 'map',
      label: new TranslatableMarkup('Fields'),
      required: FALSE,
      description: new TranslatableMarkup('Entity field values (title, ...) as eb_read_schema prints them for the bundle; never layout: the layout parameter carries the page content.'),
    ),
    'start' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Start'),
      required: FALSE,
      description: new TranslatableMarkup('The page\'s first document, picked: "template" (the bundle\'s default layout), "blank" (an empty page) or a page blueprint id from the bundle\'s schema read (its "starts" list).'),
    ),
    'layout' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Layout'),
      required: FALSE,
      description: new TranslatableMarkup("The page's first document, authored: the whole page as one page-language document."),
    ),
  ],
)]
class PageCreate extends PageToolBase {

  /**
   * The document refusal's closing line: nothing persisted, one whole re-emit.
   */
  private const NOTHING_CREATED = "\n\nNothing was created. Fix the document errors and call page_create again with the whole page.";

  /**
   * The start refusal's closing line: nothing persisted, pick a start.
   */
  private const NOTHING_STARTED = "\n\nNothing was created. Fix start and call page_create again.";

  /**
   * The start verb: a page's first document from a start, in memory.
   */
  protected PageStart $pageStart;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->pageStart = $container->get('daedalus_blueprint.page_start');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * The wire description closes with the birth rule the cached container
   * line states, from the same axes (DialectCatalogPrompt::
   * totalStatementProse()): a document is a total statement, and which
   * axes a section states is the site's data, never this attribute's.
   */
  public function normalize(): ToolsFunctionInput {
    $function = parent::normalize();
    $axes = $this->catalogBuilder->build()->axesFor(TRUE, NULL, NULL);
    $function->setDescription(rtrim($function->getDescription()) . DialectCatalogPrompt::totalStatementProse($axes));
    return $function;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // The tool-input fact rides an event before anything judges the input,
    // so a diagnostic capture sees exactly what arrived, guards included.
    $this->dispatchToolInput();
    if (!$this->requireValidParams()) {
      return;
    }

    $entity_type = $this->getContextValue('entity_type');
    $bundle = $this->getContextValue('bundle');
    if (!$this->requireDocumentHostType($entity_type) || !$this->requireFieldableType($entity_type)) {
      return;
    }
    if (!$this->requirePageBundleNamed($entity_type, $bundle)) {
      return;
    }

    // The bundle decides the parameters, before anything is built. Its
    // unsaved host answers the bundle facts: pages that carry a document
    // (PageStart::takesStart(): the override field) and own it whole
    // (no foreign owner) take their first document from exactly ONE
    // source, a start or an authored document; pages of a locked bundle
    // (rendered inside the template, owning only the slots it places)
    // take a start of slot fills or none, never an authored whole
    // document, since their root flow is the template's; pages with no
    // document at all land with their fields alone. A bundle no page
    // document renders is not a page and is the JSON create's; refusing
    // it here is what keeps the spine's late NO_LAYOUT (and its hint to
    // enable Layout Builder) out of this tool.
    $bundle_host = $this->pageHosts->bundleHost($entity_type, $bundle);
    $takes_start = $bundle_host !== NULL && $this->pageStart->takesStart($bundle_host);
    $locked = $takes_start && $this->hostResolver->foreignOwner($bundle_host) !== NULL;
    $owns_document = $takes_start && !$locked;
    if (!$takes_start && ($bundle_host === NULL || $this->hostResolver->renderHost($bundle_host) === $bundle_host)) {
      $this->output = sprintf(
        'Error: %s/%s is not a page bundle (no page document renders its entities), so page_create is not its door: create it with eb_create_entity. %s',
        $entity_type,
        $bundle,
        $this->pageBundlesSentence(),
      );
      return;
    }
    $layout = $this->getContextValue('layout');
    $layout = is_string($layout) ? $layout : '';
    $has_layout = trim($layout) !== '';
    $start = $this->getContextValue('start');
    $start = is_string($start) ? trim($start) : '';
    $has_start = $start !== '';
    if ($owns_document && $has_start && $has_layout) {
      $this->output = sprintf(
        'Error: pass exactly one of start or layout, not both: a page\'s first document has one source. Start it from %s (the bundle\'s template), %s (an empty page) or a page blueprint offered for this bundle (start: its id, listed under starts in the bundle\'s schema read), or author the whole page as ONE page-language document in layout.',
        PageStart::TEMPLATE,
        PageStart::BLANK,
      );
      return;
    }
    if ($owns_document && !$has_start && !$has_layout) {
      $this->output = sprintf(
        'Error: pass start or layout: this bundle\'s pages own their layout, so the new page needs its first document. Start it from %s (the bundle\'s template), %s (an empty page) or a page blueprint offered for this bundle (start: its id, listed under starts in the bundle\'s schema read), or author the whole page as ONE page-language document in layout.',
        PageStart::TEMPLATE,
        PageStart::BLANK,
      );
      return;
    }
    if ($locked && $has_layout) {
      $template = $this->hostResolver->renderHost($bundle_host);
      $this->output = sprintf(
        'Error: omit layout: this bundle\'s pages render the template and own only the slots it places. Pass fields, and start to fill the slots at birth (%s, the default, fills none; a page blueprint of slot fills is listed under starts in the bundle\'s schema read); fill a slot on the new page with page_edit by inserting inside its slot block. The layout is the template\'s: edit it with page_edit against %s %s.',
        PageStart::TEMPLATE,
        $template->entityTypeId(),
        $template->id(),
      );
      return;
    }
    if (!$takes_start && ($has_layout || $has_start)) {
      $template = $this->hostResolver->renderHost($bundle_host);
      $this->output = sprintf(
        'Error: omit %s: this bundle\'s pages render the template; pass fields only. The layout is the template\'s: edit it with page_edit against %s %s.',
        $has_layout && $has_start ? 'start and layout' : ($has_layout ? 'layout' : 'start'),
        $template->entityTypeId(),
        $template->id(),
      );
      return;
    }

    // The try covers the build and the persist: a failure before the page
    // lands is this tool's Error, and nothing was created. The echo follows
    // outside it, so a page that landed is never answered as an error (an
    // Error: invites the model to create it again); the print is total
    // over data, and an unknown failure in it fails as an exception.
    try {
      $blueprint = [
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'fields' => $this->getContextValue('fields') ?: [],
      ];
      $result = $this->deserializer->deserialize($blueprint, $this->blueprintOptions());
      if (!$result->isSuccess()) {
        $this->output = $this->formatResult($result);
        return;
      }
      $host = $result->getHost();
      if ($host === NULL) {
        $this->output = 'Error: the built entity has no host.';
        return;
      }
      $entity = $host->entity();

      if ($takes_start && $has_start) {
        // The start is the page's first document, its one source, applied
        // to the IN-MEMORY entity by the spine's one start verb (the same
        // verb the add form's chooser calls): template leaves the field to
        // seed on its first read, blank writes core's marker, a page
        // blueprint copies its whole document with fresh identity (on a
        // locked bundle: its slot fills, and blank fills none). A
        // refusal (an unknown id, a block blueprint, an empty one, one
        // that does not fit) comes back in the spine's shape and nothing
        // is half-made. No document was parsed, so no parse verdict
        // follows the input event.
        $result = $this->pageStart->apply($host, $start);
        if (!$result->isSuccess()) {
          $this->output = $this->formatResult($result) . self::NOTHING_STARTED;
          return;
        }
      }
      elseif ($owns_document) {
        // The document is the page's starting layout, its one source: the
        // display's template does not seed the page under it.
        $host->declineSeed();

        // Apply the whole document to the IN-MEMORY entity before anything
        // persists: the same spine batch the edit path rides parses the
        // document and validates the layout against the catalog and the
        // style guide, so a refusal costs one corrective and leaves nothing
        // half-made (no bare node to repair with page_edit, no open-link to
        // a page that never formed). One round for every fact: a document
        // that fails the grammar is still judged whole, so its grammar
        // errors and the semantic verdicts on the same bytes (a property
        // outside an element's roster, an unknown variant) come back
        // together, each answered in the spine's shape (context and hints).
        $result = $this->domOperationHandler->applyDocument($host, $layout, $this->dialectOptions());
        $this->recordParseVerdict($this->resultVerdict($result));
        if (!$result->isSuccess()) {
          $this->output = $this->formatResult($result) . self::NOTHING_CREATED;
          return;
        }
      }
      // A plain bundle's page lands with its fields alone: its render is
      // its template's document, which it does not own (a locked page is
      // never seeded; a bundle without the field has nothing to seed). No
      // document was parsed, so no parse verdict follows the input event.
      //
      // ONE save lands entity and layout together. Never save a bare
      // entity first: the layoutless original makes layout_builder treat
      // the layout save as a defaults-to-overrides transition and
      // DUPLICATE every inline block (an orphan block per component).
      // The unsaved-host usage gap is daedalus_layout's solved problem: its
      // presave flags each saved block by host uuid and records the
      // inline_block_usage rows post-insert, when the id exists.
      if (!$this->persistEntity($result, is_new: TRUE)) {
        return;
      }
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
      return;
    }

    // The echo is the answer page_read gives the page: for a plain or
    // locked page the host notice (where its layout is edited, which slots
    // it fills) heads the template's print with its elements marked and
    // its fills writable; an owning page prints its own document.
    $notice = $owns_document ? NULL : $this->hostNotice($host);
    $this->output = $this->formatArray($this->createdResult($result, $entity))
      . "\n\nCreated page:\n" . ($notice === NULL ? '' : $notice . "\n") . $this->hostPrinter->print($host, $this->conversationContext->get())->forReader();
  }

  /**
   * Guards that the bundle is known and visible, else names the page bundles.
   *
   * The visibility guard refuses what the catalog lists and hides, in the
   * wording every tool shares; an unknown bundle of a known type passes
   * it (nothing hides what does not exist) and would be judged by the
   * host facts of a bundle that is not there. A create names its bundle
   * cold, so page_create judges both here and every refusal ends with the
   * page bundles this site has (pageBundlesSentence()): the corrective
   * names the door instead of stopping at "unknown".
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE if the tool may proceed. FALSE if the bundle is unknown or
   *   hidden; the output property is set with the refusal.
   */
  protected function requirePageBundleNamed(string $entity_type, string $bundle): bool {
    if (!$this->requireVisibleEntityType($entity_type, $bundle)) {
      $this->output .= ' ' . $this->pageBundlesSentence();
      return FALSE;
    }
    if (!isset($this->bundleListBuilder->getVisibleBundles()[$entity_type]['bundles'][$bundle])) {
      $this->output = sprintf('Unknown or hidden bundle: %s/%s. %s', $entity_type, $bundle, $this->pageBundlesSentence());
      return FALSE;
    }
    return TRUE;
  }

  /**
   * The page bundles this site has, as a corrective's closing sentence.
   *
   * The same projection the cached PAGES line prints (BundleListBuilder::
   * getPageBundles()), in the same spelling, so a model that mis-named a
   * bundle reads the right one here and finds it on the line.
   */
  protected function pageBundlesSentence(): string {
    $pages = $this->bundleListBuilder->getPageBundles();
    if ($pages === []) {
      return 'This site has no page bundle: nothing is page_create\'s to make.';
    }
    return 'The page bundles are ' . implode(', ', array_map([BundleListBuilder::class, 'pageBundleName'], $pages)) . '.';
  }

  /**
   * The create's result array: the spine's result plus the landed facts.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The result that was persisted.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   *
   * @return array
   *   The result array with entity_id, persisted, the publish state (said
   *   outright: without it the model narrates a "draft" that does not
   *   exist) and the view URL.
   */
  protected function createdResult(BlueprintResult $result, EntityInterface $entity): array {
    $result_array = $result->toArray();
    $result_array['entity_id'] = $entity->id();
    $result_array['persisted'] = 'database';
    if ($entity instanceof EntityPublishedInterface) {
      $result_array['status'] = $entity->isPublished() ? 'published' : 'unpublished';
    }
    try {
      $result_array['view_url'] = $entity->toUrl()->toString();
    }
    catch (\Exception) {
    }
    return $result_array;
  }

}
