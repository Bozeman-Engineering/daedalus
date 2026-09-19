<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * The materials doctrine every model-facing surface states from ONE source.
 *
 * The preference order (variant > named value > inline value), the mint
 * gate (the user asks; the AI never mints of its own accord) and the
 * membership sentence (whose members the roster holds) are one sentence
 * each, stated here and nowhere else, so the design system brief in the
 * cached prompt, the MINT line and the gesture router lines, the schema
 * tool's description and the schema's guidance cannot drift apart (the
 * polaritySentence precedent). A surface that needs the sentence calls
 * these; a surface that restates it in its own words is the drift this
 * class exists to prevent (the mint gate once lived in four voices, the
 * kind list in three).
 */
final class MaterialsProse {

  /**
   * The preference order over materials and inline values, with the gate.
   *
   * Why the gate rides the same sentence: a mint joins the design system
   * for the life of the site (the AI would paint with that purple
   * forever), so "what to reach for" and "what never to create unasked"
   * are one rule read together, at the moment of choosing a value.
   */
  public static function preferenceSentence(): string {
    return 'Prefer a saved variant that expresses the intent, then a named value (a token or swatch by its var() spelling, an animation by the ident animation-name writes), then an inline value for what no material says; ' . self::mintGateSentence();
  }

  /**
   * The mint gate alone: the user asks, the mint is published at once.
   *
   * The clause the preference sentence closes with, for the surfaces
   * that teach a mint gesture (the brief's MINT line, the gesture op's
   * router line, the schema tool's description) and need the gate
   * without the preference order.
   */
  public static function mintGateSentence(): string {
    return 'never mint a material unless the user asks, and a mint is published site-wide at once.';
  }

  /**
   * The membership sentence: which system the materials belong to.
   *
   * The roster is the active design system's members plus the global
   * materials (members of none, offered under every system), and a mint
   * joins the active system unless it opts out. Named for the system
   * when one is active; a site with none has global materials only.
   *
   * @param string|null $label
   *   The active design system's label, NULL when none is active.
   */
  public static function membershipSentence(?string $label): string {
    if ($label === NULL) {
      return 'No design system is active: the materials below are the global ones (members of no system, offered under every system), and a save_material mint is global too.';
    }
    return sprintf('The materials below are %1$s\'s members plus the global ones (members of no system, offered under every system); a save_material mint joins %1$s unless it passes "global": true.', $label);
  }

}
