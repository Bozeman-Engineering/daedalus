<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Capability\Showing;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;

/**
 * The model's read of a host, for a conversation: one composition.
 *
 * Every reader of a page (page_read, page_edit's echo, page_create's
 * echoes, the per-turn "Current page print") used to compose the same
 * four steps by hand: build the lean tree, stamp it for the acting user
 * (so an element the user cannot fully edit carries data-can), print it
 * against the live catalog, with the conversation's aliases echoed as
 * data-alias. This service owns that composition, so a read is one call
 * and every consumer spells a page alike; the result's reading notes
 * (DialectPrint::forReader()) are the consumer's to head the text with.
 *
 * The print also carries one note per slot door of the printed document
 * (DialectPrintNoteKind::Showing) saying what the door shows and where
 * that is edited, read from the spine's one answer for a join
 * (VerdictStamper::showing(), seen from the printed host as the canvas):
 * a door prints as a bare <pb-block plugin="daedalus_slot:{id}" />, and
 * the document it shows by reference (a default blueprint, the page the
 * frame's main slot hosts, the section filling it) is no node of this
 * host's tree, so the grammar cannot spell it and the AI would otherwise
 * read a door as an empty block. The grammar is unchanged: the notes are
 * the AI's adapter over the showing, as the chip's stamps and the tree's
 * shown-document row are the canvas's and the panel's.
 */
final class HostPrinter {

  public function __construct(
    protected NodeTreeBuilder $treeBuilder,
    protected VerdictStamper $verdictStamper,
    protected DialectPrinter $printer,
    protected DialectCatalogBuilder $catalogBuilder,
    protected DialectAliasMap $aliasMap,
    protected HostResolver $hostResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Prints a host's current document for the acting user.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to print: a page, a template or a saved blueprint, in the
   *   state the caller means (a page's staged copy prints as staged).
   * @param string $conversation
   *   The conversation whose minted aliases print back as data-alias; ''
   *   when none.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectPrint
   *   The print and its notes: the printer's, then one per door.
   */
  public function print(HostInterface $host, string $conversation): DialectPrint {
    $tree = $this->verdictStamper->stampTree($this->treeBuilder->build($host), $host);
    $print = $this->printer->print(
      $tree,
      $this->catalogBuilder->build(),
      $this->aliasMap->printAliases($host, $conversation),
    );
    return new DialectPrint($print->text, [...$print->notes, ...$this->doorNotes($tree, $host)]);
  }

  /**
   * One note per slot door of the tree, in print order.
   *
   * The holder the spine is asked for is the host whose document holds
   * the door: the delivered tree of a locked page is its template's
   * sections and then the page's own (the sections filling the template's
   * slots, the only top-level containers carrying `slot`), so a fill's
   * nodes are the printed host's and every other top-level section's are
   * the document it renders (HostResolver::renderHost(): the template on
   * a locked page, the host itself everywhere else), the rule the element
   * annotator keeps for the same tree.
   *
   * @param array $tree
   *   The ordered top-level nodes (NodeTreeBuilder's shape).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The printed host, the canvas the showing is seen from.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[]
   *   The door notes.
   */
  protected function doorNotes(array $tree, HostInterface $host): array {
    $render_host = $this->hostResolver->renderHost($host);
    $notes = [];
    foreach ($tree as $node) {
      if (is_array($node)) {
        $this->collectDoorNotes($node, $host, isset($node['slot']) ? $host : $render_host, $notes);
      }
    }
    return $notes;
  }

  /**
   * Collects the door notes of one node and its children.
   */
  protected function collectDoorNotes(array $node, HostInterface $canvas, HostInterface $holder, array &$notes): void {
    $uuid = $node['uuid'] ?? NULL;
    if (($node['kind'] ?? NULL) === 'block' && is_string($node['slot'] ?? NULL) && is_string($uuid) && $uuid !== '') {
      $showing = $this->verdictStamper->showing($canvas, $holder, $uuid);
      // A slot block of a tier the holder does not declare is no door
      // (OwnershipPolicy::declaredSlotOf()): nothing to say about it.
      if ($showing !== NULL) {
        $notes[] = new DialectPrintNote(DialectPrintNoteKind::Showing, $uuid, $this->doorDetail($node['slot'], $uuid, $showing));
      }
    }
    foreach ($node['children'] ?? [] as $child) {
      if (is_array($child)) {
        $this->collectDoorNotes($child, $canvas, $holder, $notes);
      }
    }
  }

  /**
   * The one line about a door: what it shows and where that is edited.
   *
   * "slot top (#uuid) shows blueprint 'Header' (edit: /blueprint/1)",
   * "slot main (#uuid) shows the page", "slot hero (#uuid) shows the
   * <pb-slot name="hero"> section of 'Locked'", "slot aside (#uuid) shows
   * nothing". The edit url rides only when the showing carries a portal
   * (the actor may update the shown blueprint): the spine names the host,
   * this surface derives the url, and a shown blueprint's editing surface
   * is its canonical route (its canvas).
   */
  protected function doorDetail(string $slot, string $uuid, Showing $showing): string {
    $subject = sprintf('slot %s (#%s)', $slot, $uuid);
    $label = $showing->document === NULL ? NULL : sprintf("'%s'", $showing->document['label']);
    switch ($showing->shows) {
      case Showing::DEFAULT:
        $detail = sprintf('%s shows blueprint %s', $subject, $label);
        $url = $showing->portal === NULL ? NULL : $this->portalUrl($showing->portal->entityTypeId, $showing->portal->id);
        return $url === NULL ? $detail : sprintf('%s (edit: %s)', $detail, $url);

      case Showing::ROUTE:
        return $label === NULL
          ? sprintf('%s shows the page', $subject)
          : sprintf('%s shows the page %s', $subject, $label);

      case Showing::FILL:
        return sprintf('%s shows the <pb-slot name="%s"> section of %s', $subject, $slot, $label);

      default:
        return sprintf('%s shows nothing', $subject);
    }
  }

  /**
   * The editing url of the host a portal names, or NULL when it is gone.
   */
  protected function portalUrl(string $entity_type_id, string $id): ?string {
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    return $entity?->toUrl()->toString();
  }

}
