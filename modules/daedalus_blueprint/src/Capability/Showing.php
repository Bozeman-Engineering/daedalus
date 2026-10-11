<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * What a join of the render stack shows, seen from one canvas by one actor.
 *
 * A render is a stack of documents (the site frame, a template, the page,
 * the blueprints shown by reference), and a join is a node whose content
 * is another document's: a slot door (daedalus_slot:{id}, placed by the
 * frame or a template) or a linked placement (daedalus_blueprint_linked:
 * {machine_name}). The spine answers once per join, per canvas, per actor
 * (VerdictStamper::showing()), and every surface that says what is behind
 * a door (the canvas's portal chip and claim stamp, the element
 * annotation, the Page elements tree's shown-document row, the verbs, the
 * AI's page print) is an adapter over this one answer, never a second
 * derivation of it.
 *
 * - `shows`: one of ROUTE (the slot hosts the route's output: the frame's
 *   main slot on a page, the door whose default is the blueprint being
 *   edited on that blueprint's canvas), FILL (a document beneath the
 *   canvas fills the slot with a section of its own), DEFAULT (the door's
 *   default blueprint, or a linked placement's blueprint, shown by
 *   reference) or NOTHING (an empty door, a default that names no
 *   published blueprint, the frame's main slot while a door hosts the
 *   route).
 * - `document`: the identity and label of the document shown
 *   (`{entity_type, id, label}`: the route host, the filler, the
 *   blueprint), or NULL when nothing is shown or the route has no host.
 * - `blueprint`: the machine name of the blueprint shown by reference,
 *   when `shows` is DEFAULT; NULL otherwise.
 * - `portal`: where the shown blueprint is edited, only when the actor
 *   may update it (the spine names the host, a surface derives the url);
 *   NULL for every other kind, since the route host and a filler are the
 *   canvas's own stack and the door hosting the route wears no portal.
 * - `claimable`: whether this canvas may detach the shown default into
 *   the slot (detach_blueprint with `slot`): `shows` is DEFAULT and the
 *   door's insert verdict allows, seen from this canvas. Never for a
 *   linked placement, whose Detach is its own verb.
 */
final class Showing {

  /**
   * The slot hosts the route's output.
   */
  public const ROUTE = 'route';

  /**
   * A document beneath the canvas fills the slot.
   */
  public const FILL = 'fill';

  /**
   * The door's default blueprint, or the linked blueprint, by reference.
   */
  public const DEFAULT = 'default';

  /**
   * Nothing: an empty door, an unresolved default, main while a door hosts.
   */
  public const NOTHING = 'nothing';

  /**
   * Constructs a showing.
   *
   * @param string $shows
   *   One of ROUTE, FILL, DEFAULT, NOTHING.
   * @param array{entity_type: string, id: string, label: string}|null $document
   *   The shown document's identity, or NULL.
   * @param string|null $blueprint
   *   The shown blueprint's machine name, when `shows` is DEFAULT.
   * @param \Drupal\daedalus_blueprint\Capability\Portal|null $portal
   *   The portal to the shown blueprint, when the actor may open it.
   * @param bool $claimable
   *   Whether this canvas may claim the shown default into the slot.
   */
  public function __construct(
    public readonly string $shows,
    public readonly ?array $document,
    public readonly ?string $blueprint,
    public readonly ?Portal $portal,
    public readonly bool $claimable,
  ) {}

  /**
   * The showing of nothing.
   */
  public static function nothing(): self {
    return new self(self::NOTHING, NULL, NULL, NULL, FALSE);
  }

  /**
   * A document's identity as a showing carries it.
   *
   * @return array{entity_type: string, id: string, label: string}
   *   The host's identity and label.
   */
  public static function documentOf(HostInterface $host): array {
    return [
      'entity_type' => $host->entityTypeId(),
      'id' => (string) $host->id(),
      'label' => $host->label(),
    ];
  }

  /**
   * The showing as delivery data (the annotation's `showing` key).
   *
   * @return array{shows: string, document: array|null, blueprint: string|null, portal: array|null, claimable: bool}
   *   The showing, the portal in Portal::toArray()'s shape.
   */
  public function toArray(): array {
    return [
      'shows' => $this->shows,
      'document' => $this->document,
      'blueprint' => $this->blueprint,
      'portal' => $this->portal?->toArray(),
      'claimable' => $this->claimable,
    ];
  }

}
