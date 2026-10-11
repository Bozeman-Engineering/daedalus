<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\daedalus_blueprint_ai\PageToolBase;

/**
 * Reads a page as a dialect document.
 *
 * One read through one print grammar, for every host of a page document:
 * a page (entity_type and entity_id), a template (entity_view_display and
 * its display id) and a saved blueprint (blueprint and its id), which is
 * a page of its own. The read answers the host's own fields (the
 * serializer's fields map, stubs stripped, printed as fields: between the
 * host notice and the document) and then the document with its ids; the
 * CURRENT page needs it for its field values only (its per-turn print is
 * layout alone), and it is the read for OTHER pages, or to re-read after
 * out-of-band changes. A blueprint's fields are its face (label,
 * description, scope, subject) and its document is the composition every
 * placement lands; placing one needs no read (the schema prints each
 * blueprint's fragment without ids, and <pb-blueprint name="id"/> copies
 * it as is).
 */
#[FunctionCall(
  id: 'daedalus_blueprint:page_read',
  function_name: 'page_read',
  name: 'Page Read',
  description: 'Reads a page as a page-language document: its own fields (fields:, the map page_create takes) then its document as the "Current page print" tag does. The current page needs it for field values only; use it to read a DIFFERENT page before editing it. The same read answers a template (entity_type entity_view_display, entity_id its display id) and a saved blueprint (entity_type blueprint, entity_id from the BLUEPRINTS list or the schema), whose fields are its label, description, scope and subject and whose document is what every placement lands: read it before page_edit on the blueprint itself; placing one needs no read (<pb-blueprint name="id"/> for a copy as is, or the schema\'s fragment written with your own content and blueprint="id" on the root).',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The entity type ID (e.g. node) of the page to read; entity_view_display for a template (a bundle\'s default layout); blueprint for a saved blueprint.'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The entity ID of the page to read; for a template its display id (e.g. node.page.default); for a blueprint its entity_id from the BLUEPRINTS list or the schema.'),
    ),
  ],
)]
class PageRead extends PageToolBase {

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
    $entity_type = $this->getContextValue('entity_type');
    $entity_id = $this->getContextValue('entity_id');
    if (!is_string($entity_type) || $entity_type === '' || $entity_id === NULL || $entity_id === '') {
      $this->output = 'Error: page_read reads one host of a page document by entity_type and entity_id (a page, a template as entity_view_display, or a saved blueprint as blueprint); pass both.';
      return;
    }
    if (!$this->requireDocumentHostType($entity_type)) {
      return;
    }
    $host = $this->loadHost($entity_type, $entity_id, 'view');
    if ($host === NULL) {
      return;
    }
    // A content host stages in a workspace when the site requires one; a
    // template is config and never does.
    if ($host->persistence()->workspaceAware && !$this->requireWorkspace()) {
      return;
    }
    try {
      // The read is total: whatever the page language cannot spell prints
      // in the read-only register, and the print's own reading notes head
      // the document (once) to say what those annotations mean.
      $print = $this->hostPrinter->print($host, $this->conversationContext->get());
      $this->output = trim($print->text) === '' ? '(the page has no layout content yet)' : $print->forReader();
      // The page's own fields ride before the document: short before
      // long, and in the order page_create takes them, so what is read is
      // what is written.
      $fields = $this->fieldsBlock($host);
      if ($fields !== NULL) {
        $this->output = $fields . $this->output;
      }
      // A page the user cannot fully edit says so first (its layout is the
      // template's, a frozen translation, a required workspace): the
      // elements below carry data-can, and this line says where the
      // gesture belongs.
      $notice = $this->hostNotice($host);
      if ($notice !== NULL) {
        $this->output = $notice . "\n" . $this->output;
      }
    }
    catch (\Exception $e) {
      $this->output = 'Error: ' . $e->getMessage();
    }
  }

}
