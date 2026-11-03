<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

/**
 * The kinds of per-turn block a user turn of the conversation carries.
 *
 * A block is a fact the spine states beside the user's words for one turn:
 * the block producers answer the runner's ContextBlocksEvent with blocks of
 * a kind, the transcript keeps them typed on the user entry, and the
 * renderer decides their text and placement per provider capability. The
 * kind is the contract between producer and renderer; neither reads the
 * other's text. The vocabulary lives here, in daedalus_ai, because the
 * producers do and the transcript that holds the blocks is a sub-module's.
 */
enum BlockKind: string {

  // The "Current page:" line naming the host the conversation is on.
  case CurrentPage = 'current_page';

  // The print of the page in hand.
  case Print = 'print';

  // The elements the user has selected on the page.
  case Selection = 'selection';

  // The files attached to the turn, named by handle.
  case Attachments = 'attachments';

  // The skills list, stated once at segment open.
  case Skills = 'skills';

  // What the previous segment carried over, stated once at segment open.
  case CarryOver = 'carry_over';

}
