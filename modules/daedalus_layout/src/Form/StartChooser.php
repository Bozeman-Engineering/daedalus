<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_layout\Controller\StartPreview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The start chooser: the add form itself, before its fields.
 *
 * A page's first document has one source, picked once, when the page is
 * created: its bundle's template, a page blueprint, or blank. The editor's
 * door to that choice is the bundle's own add form, not a route of its
 * own: on any content entity add form (a ContentEntityForm holding a new
 * entity) whose bundle offers starts (BundleEditFormAlter's setting) and
 * is offered at least one page blueprint, a request with no `start` in
 * the URL shows the chooser in place of the fields: Blank first, then the
 * page blueprints the bundle is offered (PageStart::starts(), the one
 * "offered here" judgement asked for page scope) in the verb's own order,
 * the site's favorites first and then the rest, each run by label, the
 * one order the Add panel's rows and the schema's `starts` follow too
 * (the chooser never orders again). A Template card leads the cards only
 * when the bundle's template holds a document to follow
 * (PageStart::templateOffered(), the verb's own judgement): on a blank
 * template the two reserved starts open the same empty page, and Blank is
 * the honest card for it; `template` stays valid in the URL and the start
 * line either way, since it is the default a page gets when nothing is
 * chosen. A Blank card shows only where an empty page is the page's own
 * to open (PageStart::blankOffered()): on a locked bundle, whose pages
 * fill the template's slots and whose starts are fills of them, the two
 * reserved starts open the same template and Template is the honest
 * card. A card is one link to the same URL with `?start=<id>`; the
 * groups the offered blueprints carry (the site's group vocabulary, read
 * from the spine's dialect catalog, DialectCatalog::groups()) are filter
 * links with `?group=<id>`, shown only when there are any, and the
 * reserved cards stay under every filter. No radio, no Continue button,
 * no JavaScript.
 *
 * With a valid `start` the normal form shows under a "Starting from X.
 * Change" line, and the start is applied to the entity the form builds,
 * through the spine's one verb (PageStart::apply()), by an entity builder
 * on the submit pass: the form's own save then lands entity and document
 * together, the rule page_create follows. An unknown or unoffered `start`
 * shows the chooser again under a message. The setting off, or no page
 * blueprint offered for the bundle, is the plain form: the page starts
 * from its template as every page did before starts existed.
 *
 * A card's face is the page it starts: a lazily loaded frame on the
 * preview route (StartPreview), the start's dry run on a sample of the
 * bundle, scaled down by the stylesheet.
 */
final class StartChooser {

  use StringTranslationTrait;

  /**
   * The query parameter naming the chosen start.
   */
  public const QUERY_START = 'start';

  /**
   * The query parameter naming the group filter.
   */
  public const QUERY_GROUP = 'group';

  /**
   * The form state key carrying the valid start to the entity builder.
   */
  public const STATE_KEY = 'daedalus_layout_start';

  /**
   * The form keys the chooser leaves in place: the form's own identity.
   */
  private const FORM_IDENTITY = ['form_build_id', 'form_token', 'form_id'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PageHosts $pageHosts,
    protected PageStart $pageStart,
    protected HostResolver $hostResolver,
    protected DialectCatalogBuilder $catalogBuilder,
    protected RequestStack $requestStack,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_form_alter() for content entity add forms.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    // A new entity: the add form. An edit form, a translation's add form
    // (its entity exists) and a Layout Builder form never show a chooser.
    $entity = $form_object->getEntity();
    if (!$entity->isNew()) {
      return;
    }
    $bundle_entity_type = $entity->getEntityType()->getBundleEntityType();
    if ($bundle_entity_type === NULL) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface|null $bundle_entity */
    $bundle_entity = $this->entityTypeManager->getStorage($bundle_entity_type)->load($entity->bundle());
    if ($bundle_entity === NULL || !$bundle_entity->getThirdPartySetting('daedalus_layout', BundleEditFormAlter::SETTING, FALSE)) {
      return;
    }
    // The offer is asked of the bundle's own unsaved host and never of the
    // form's entity: a read through the form's entity could seed it, and
    // the entity builder's clone would carry the seed into every start.
    $host = $this->pageHosts->bundleHost($entity->getEntityTypeId(), $entity->bundle());
    if ($host === NULL || !$this->pageStart->takesStart($host)) {
      return;
    }
    $starts = $this->pageStart->starts($host);
    if ($starts === []) {
      return;
    }

    $form['#cache']['contexts'][] = 'url.query_args:' . self::QUERY_START;
    $form['#cache']['contexts'][] = 'url.query_args:' . self::QUERY_GROUP;
    $request = $this->requestStack->getCurrentRequest();
    $start = $request->query->get(self::QUERY_START);
    $start = is_string($start) && $start !== '' ? $start : NULL;
    if ($start !== NULL && (in_array($start, PageStart::RESERVED, TRUE) || isset($starts[$start]))) {
      $form_state->set(self::STATE_KEY, $start);
      $form['#entity_builders'][] = [static::class, 'applyStart'];
      $form[self::STATE_KEY] = $this->startLine($start, $starts, $bundle_entity->label(), $request);
      return;
    }
    $this->showChooser($form, $host, $starts, $entity, $bundle_entity->label(), $request, $start);
  }

  /**
   * Entity builder: applies the chosen start on the submit pass.
   *
   * EntityForm::buildEntity() calls the builders with the entity the form
   * built, whatever button submitted, and ContentEntityForm::validateForm()
   * builds the entity too, so this runs twice per submission: the
   * validation pass is left alone (its entity is validated and discarded)
   * and the start lands on the submit pass, whose entity the form saves.
   * Core marks the passes apart with the `entity_validated` temporary.
   */
  public static function applyStart(string $entity_type_id, EntityInterface $entity, array &$form, FormStateInterface $form_state): void {
    $start = $form_state->get(self::STATE_KEY);
    if (!is_string($start) || !$form_state->getTemporaryValue('entity_validated') || !$entity instanceof FieldableEntityInterface) {
      return;
    }
    \Drupal::service('daedalus_layout.start_chooser')->apply($entity, $start);
  }

  /**
   * Applies a start to the unsaved entity the form built.
   *
   * The chooser judged the start offered when it built the form, and the
   * verb judges it again here; a refusal between the two is a change of
   * the roster inside one request and is thrown, never swallowed into a
   * page that silently started from its template.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the form built, unsaved.
   * @param string $start
   *   The start: template, blank or a page blueprint's id.
   */
  public function apply(FieldableEntityInterface $entity, string $start): void {
    $result = $this->pageStart->apply($this->hostResolver->fromEntity($entity), $start);
    if (!$result->isSuccess()) {
      $errors = $result->getErrors();
      $error = reset($errors);
      throw new \RuntimeException(sprintf('The start "%s" the chooser offered was refused when the form was saved: %s (%s)', $start, $error->getMessage(), $error->getCode()));
    }
  }

  /**
   * Replaces the form's fields with the chooser.
   *
   * Every child of the form but its identity (the build id, the token, the
   * form id) is hidden, the actions among them: nothing submits from the
   * chooser, a card is a link. The form's own theme (a node form's two
   * column layout) is dropped so the chooser is the page's one body.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The bundle's unsaved host, whose template decides the Template card.
   * @param array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord> $starts
   *   The page blueprints offered, keyed by id.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The new entity the form holds: its type and bundle name the preview.
   * @param string|\Stringable $bundle_label
   *   The bundle's label.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string|null $refused
   *   The `start` the request named that is not offered, or NULL.
   */
  protected function showChooser(array &$form, HostInterface $host, array $starts, EntityInterface $entity, string|\Stringable $bundle_label, Request $request, ?string $refused): void {
    foreach (Element::children($form) as $key) {
      if (!in_array($key, self::FORM_IDENTITY, TRUE)) {
        $form[$key]['#access'] = FALSE;
      }
    }
    unset($form['#theme']);

    $declared = $this->catalogBuilder->build()->groups();
    $carried = [];
    foreach ($starts as $record) {
      foreach ($record->groups as $group) {
        $carried[$group] = TRUE;
      }
    }
    // The filters: the declared groups the offered starts carry, in
    // declaration order; an id the site does not declare is ignored.
    $filters = array_intersect_key($declared, $carried);
    $group = $request->query->get(self::QUERY_GROUP);
    $group = is_string($group) && isset($filters[$group]) ? $group : NULL;

    $chooser = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => ['daedalus-layout-start-chooser'],
        'data-daedalus-layout-start-chooser' => '',
      ],
      '#attached' => ['library' => ['daedalus_layout/start_chooser']],
    ];
    if ($refused !== NULL) {
      $chooser['refused'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['messages', 'messages--warning', 'daedalus-layout-start-chooser__refused'],
          'role' => 'alert',
          'data-daedalus-layout-start-refused' => $refused,
        ],
        'text' => [
          '#plain_text' => $this->t('"@start" is not a starting design for @bundle content. Choose one below.', [
            '@start' => $refused,
            '@bundle' => $bundle_label,
          ]),
        ],
      ];
    }
    $chooser['ask'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Choose how this page starts'),
      '#attributes' => ['class' => ['daedalus-layout-start-chooser__ask']],
    ];
    $chooser['lede'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Every start can be changed on the page afterwards. The choice only decides what is on it when it opens.'),
      '#attributes' => ['class' => ['daedalus-layout-start-chooser__lede']],
    ];
    if ($filters !== []) {
      $items = [
        $this->filterLink($this->t('All'), NULL, $group === NULL, $request),
      ];
      foreach ($filters as $id => $declaration) {
        $items[] = $this->filterLink($declaration['label'], $id, $group === $id, $request);
      }
      $chooser['filters'] = [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['daedalus-layout-start-filters']],
        '#wrapper_attributes' => ['class' => ['daedalus-layout-start-filters__wrapper']],
      ];
    }

    // The Template card only when there is a template to follow, the Blank
    // card only where an empty page is the page's own to open (a locked
    // bundle's pages fill the template's slots and have no empty page):
    // the verb's two judgements, read off the display and never off this
    // host's own document (a read would seed it).
    $cards = [];
    if ($this->pageStart->templateOffered($host)) {
      $locked = !$this->pageStart->blankOffered($host);
      $cards[] = $this->card(PageStart::TEMPLATE, $this->t('Template'), $locked
        ? $this->t('The @bundle template, its slots empty. Fill them on the page.', ['@bundle' => $bundle_label])
        : $this->t('The @bundle template. Follows the template until you edit the page.', ['@bundle' => $bundle_label]), $request, $entity);
    }
    if ($this->pageStart->blankOffered($host)) {
      $cards[] = $this->card(PageStart::BLANK, $this->t('Blank'), $this->t('An empty page. Nothing from the template.'), $request, $entity);
    }
    foreach ($starts as $id => $record) {
      if ($group !== NULL && !in_array($group, $record->groups, TRUE)) {
        continue;
      }
      $cards[] = $this->card($id, $record->label, $record->description, $request, $entity, $record);
    }
    $chooser['cards'] = [
      '#theme' => 'item_list',
      '#items' => $cards,
      '#attributes' => ['class' => ['daedalus-layout-start-cards']],
      '#wrapper_attributes' => ['class' => ['daedalus-layout-start-cards__wrapper']],
    ];
    $form['start_chooser'] = $chooser;
  }

  /**
   * One card: a link to the same address with the start chosen.
   *
   * The whole card is the link. Its face is the page it starts: a frame
   * loading the preview route (StartPreview, the start's dry run on a
   * sample of the bundle, chromeless) lazily, so a card below the fold
   * costs nothing until it scrolls near, scaled down to the face by the
   * stylesheet. The face's own paint (the shimmer of a blueprint's, the
   * tint of Template's, the outline of Blank's) lies beneath the frame
   * and shows until the document paints over it: the loading state. The
   * frame takes no pointer and no focus, so the card stays one link; the
   * name and description are there from the first paint.
   */
  protected function card(string $id, string|\Stringable $label, string|\Stringable $description, Request $request, EntityInterface $entity, ?MaterialRecord $record = NULL): array {
    $kind = $record === NULL ? $id : 'blueprint';
    return [
      '#type' => 'link',
      '#url' => $this->url($request, [self::QUERY_START => $id]),
      '#attributes' => [
        'class' => ['daedalus-layout-start-card', 'daedalus-layout-start-card--' . $kind],
        'data-daedalus-layout-start-card' => $id,
      ],
      '#title' => [
        'face' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['daedalus-layout-start-card__face'],
            'data-daedalus-layout-start-face' => $id,
          ],
          'frame' => [
            '#type' => 'html_tag',
            '#tag' => 'iframe',
            '#value' => '',
            '#attributes' => [
              'class' => ['daedalus-layout-start-card__frame'],
              'src' => StartPreview::url($entity->getEntityTypeId(), $entity->bundle(), $id)->toString(),
              'loading' => 'lazy',
              'title' => $this->t('Preview: @start', ['@start' => $label]),
              'tabindex' => '-1',
              'aria-hidden' => 'true',
              'scrolling' => 'no',
            ],
          ],
        ],
        'name' => [
          '#type' => 'html_tag',
          '#tag' => 'b',
          '#value' => Html::escape((string) $label),
          '#attributes' => ['class' => ['daedalus-layout-start-card__name']],
        ],
        'description' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => Html::escape((string) $description),
          '#attributes' => ['class' => ['daedalus-layout-start-card__description']],
        ],
      ],
    ];
  }

  /**
   * One group filter: a link to the same address with the group set.
   */
  protected function filterLink(string|\Stringable $label, ?string $group, bool $current, Request $request): array {
    $attributes = [
      'class' => ['daedalus-layout-start-filters__filter'],
      'data-daedalus-layout-start-group' => $group ?? '',
    ];
    if ($current) {
      $attributes['aria-current'] = 'true';
    }
    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $this->url($request, [self::QUERY_GROUP => $group]),
      '#attributes' => $attributes,
    ];
  }

  /**
   * The "Starting from X. Change" line above the form's fields.
   */
  protected function startLine(string $start, array $starts, string|\Stringable $bundle_label, Request $request): array {
    $label = match ($start) {
      PageStart::TEMPLATE => $this->t('the @bundle template', ['@bundle' => $bundle_label]),
      PageStart::BLANK => $this->t('a blank page'),
      default => $starts[$start]->label,
    };
    return [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => [
        'class' => ['daedalus-layout-start-line'],
        'data-daedalus-layout-start' => $start,
      ],
      '#attached' => ['library' => ['daedalus_layout/start_chooser']],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->t('Starting from <strong>@start</strong>.', ['@start' => $label]),
        '#attributes' => ['class' => ['daedalus-layout-start-line__text']],
      ],
      'change' => [
        '#type' => 'link',
        '#title' => $this->t('Change'),
        '#url' => $this->url($request, [self::QUERY_START => NULL]),
        '#attributes' => ['class' => ['daedalus-layout-start-line__change']],
      ],
    ];
  }

  /**
   * The current address with query parameters set or, with NULL, removed.
   *
   * The rest of the query (a destination, the other parameter) rides
   * along, so a pick keeps its filter and a filter keeps nothing it
   * should not.
   */
  protected function url(Request $request, array $changes): Url {
    $query = $request->query->all();
    foreach ($changes as $key => $value) {
      if ($value === NULL) {
        unset($query[$key]);
      }
      else {
        $query[$key] = $value;
      }
    }
    return Url::fromRouteMatch($this->routeMatch)->setOption('query', $query);
  }

}
