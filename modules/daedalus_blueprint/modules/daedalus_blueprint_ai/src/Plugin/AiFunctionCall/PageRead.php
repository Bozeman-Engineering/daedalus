<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_ai\PageToolBase;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint_stencil\StencilElementTree;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads a page, or a stencil's definition, as a dialect document.
 *
 * Two reads through one print grammar. A page read (entity_type and
 * entity_id) is rarely needed for the CURRENT page (its print rides every
 * turn); it is the read for OTHER pages, or to re-read after out-of-band
 * changes. A stencil read (stencil: an id from the roster) prints the
 * definition's tree as a document without ids: a definition has no
 * identity, so the print is the composition itself, the same fragment
 * the schema's stencil entries carry. Placing (a <pb-stencil> copy as
 * is, or the fragment written with the author's content under
 * stencil="id") needs no read: the schema already shows the fragment.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:page_read',
  function_name: 'page_read',
  name: 'Page Read',
  description: 'Reads a page or a stencil as a page-language document. A page (entity_type and entity_id) prints as the "Current page print" tag does; never needed for the current page, use it to read a DIFFERENT page before editing it. A stencil (stencil: an id from the roster) prints its definition without ids, the same fragment the schema shows; read it to learn how a composition is built (an exemplar), never to place it: placing needs no read (<pb-stencil name="id"/> for a copy as is, or the fragment written with your own content and stencil="id" on the root).',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: FALSE,
      description: new TranslatableMarkup('The entity type ID (e.g. node) of the page to read. With entity_id; omit both to read a stencil.'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: FALSE,
      description: new TranslatableMarkup('The entity ID of the page to read.'),
    ),
    'stencil' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Stencil'),
      required: FALSE,
      description: new TranslatableMarkup('The id of a stencil to read instead of a page (one of the stencils on the roster).'),
    ),
  ],
)]
class PageRead extends PageToolBase {

  /**
   * Stencil discovery, when the stencil module is installed.
   *
   * The definition (the body) lives on the stencil module's discovery; the
   * roster carries only the record. Without the module no stencil is on
   * the roster and the stencil read refuses with an empty roster.
   */
  protected ?StencilDiscoveryInterface $stencilDiscovery = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    if ($container->has('daedalus_blueprint_stencil.discovery')) {
      $instance->stencilDiscovery = $container->get('daedalus_blueprint_stencil.discovery');
    }
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // The tool-input fact rides an event before anything judges the input,
    // so dev tools capture exactly what arrived. A read parses
    // nothing, so no verdict ever follows.
    $this->dispatchToolInput();
    if (!$this->requireValidParams()) {
      return;
    }
    $stencil = $this->getContextValue('stencil');
    if (is_string($stencil) && $stencil !== '') {
      $this->readStencil($stencil);
      return;
    }
    $entity_type = $this->getContextValue('entity_type');
    $entity_id = $this->getContextValue('entity_id');
    if (!is_string($entity_type) || $entity_type === '' || $entity_id === NULL || $entity_id === '') {
      $this->output = 'Error: page_read reads a page (entity_type and entity_id) or a stencil (stencil: an id from the roster); pass one or the other.';
      return;
    }
    if (!$this->requireContentEntityType($entity_type)) {
      return;
    }
    if (!$this->requireWorkspace()) {
      return;
    }
    $entity = $this->loadEntity($entity_type, $entity_id, 'view');
    if (!$entity) {
      return;
    }
    try {
      $print = $this->printPage($entity);
      $this->output = trim($print) === '' ? '(the page has no layout content yet)' : $print;
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

  /**
   * Prints a stencil's definition as a document without ids.
   *
   * The roster is the model's world (the published set, partitioned by
   * the active design system), so an id the roster does not carry is
   * refused with the ids it does, even when discovery knows it.
   */
  protected function readStencil(string $id): void {
    $catalog = $this->catalogBuilder->build();
    $roster = $catalog->roster();
    $record = $roster->get(MaterialKind::Stencil, $id);
    $definition = $record === NULL ? NULL : $this->stencilDiscovery?->getStencil($id);
    if ($record === NULL || $definition === NULL) {
      $available = array_keys($roster->stencils());
      $this->output = sprintf(
        'Error: "%s" is not an available stencil. %s',
        $id,
        $available === [] ? 'This site has no stencils.' : 'Available: ' . implode(', ', $available) . '.',
      );
      return;
    }
    try {
      $print = $this->dialectPrinter->print(StencilElementTree::dialectNodes($definition['element']), $catalog);
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
      return;
    }
    $this->output = sprintf(
      "Stencil \"%s\" (%s), root %s: the definition below is a template without ids, not a page. Place it as <pb-stencil name=\"%s\"/>, or write this fragment with your own content and stencil=\"%s\" on the root; edit the placement, never this.\n%s",
      $id,
      $record->label,
      (string) $record->rootKind,
      $id,
      $id,
      $print,
    );
  }

}
