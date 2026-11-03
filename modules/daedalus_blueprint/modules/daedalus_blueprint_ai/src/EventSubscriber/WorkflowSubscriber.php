<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Context\ContextBlocksEvent;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the page builder's workflow and the skills catalog into conversations.
 *
 * Two parts:
 * 1. The Workflow section (BuildSystemPromptEvent): the loop every task
 *    runs, stated once, on the always-on layer. It is the one home of
 *    every rule that gates what the model does before it picks a tool:
 *    skills first, plan then wait for the go-ahead before a whole page's
 *    worth of building, pages through the page tools and everything else
 *    through JSON after a schema read, one atomic batch with structure and
 *    styling together, targeted edits, save discipline, errors explained.
 *    A tool's card says what the tool is for and takes; a skill carries
 *    craft; neither restates a rule from here. The module owns the
 *    section because the rules are facts about its tools: a site that
 *    writes its own agent prompt keeps them. It rides whether or not the
 *    site registers a skill (the loop governs the page tools regardless;
 *    without a catalog the skills rule has nothing to match and is inert).
 * 2. The skills catalog, stated as the skills block of the turn that opens
 *    a transcript segment (the memory runner's ContextBlocksEvent asks
 *    once per segment), so the list is near the request at the start of
 *    the conversation and again after every reset of the working
 *    transcript. The catalog prints id and description only; a skill's
 *    other facts (its reframe flag, its permission) never reach the model.
 *
 * The event is daedalus_ai's, a module this one does not depend on (it is
 * the other way round), so the subscription names the event by its name
 * rather than its class: the class resolves only when the event is
 * dispatched, which only daedalus_ai_memory does.
 */
final class WorkflowSubscriber implements EventSubscriberInterface {

  /**
   * The name of daedalus_ai's ContextBlocksEvent.
   *
   * @see \Drupal\daedalus_ai\Context\ContextBlocksEvent::EVENT_NAME
   */
  public const CONTEXT_BLOCKS_EVENT = 'daedalus_ai.context_blocks';

  /**
   * The Workflow section of the cached system prompt.
   *
   * The approval rule attaches to a whole page's worth of building,
   * whichever tool does it (a new page through page_create, or the empty
   * page the user is standing on filled through page_edit), never to a
   * creation tool: the record showed a model standing on an empty page
   * building it in place with page_edit and reading "before creating an
   * entity" as not applying. A request that already says to go ahead is
   * the go-ahead.
   */
  public const WORKFLOW = <<<'INSTRUCTION'
## Workflow

The loop every task runs, whichever tool it ends in:

1. A matching skill comes before anything else. When a skill in the catalog matches the request, this is a BLOCKING REQUIREMENT: invoke it with eb_skill BEFORE any other response about the task, then hold to what it teaches for the rest of the task. The catalog rides the <system-reminder> messages in the conversation; invoke only a skill listed there, and never skip one to save time: a skill changes your approach.
2. Plan, then wait for the go-ahead. A whole page's worth of building (a new page, or the empty page the user is standing on filled) starts only after the user has seen the plan: present the plan and wait for the go-ahead, unless the request already told you to go ahead, in which case the plan opens the same turn the build runs in. Smaller work (a section added, an element changed) needs no approval turn.
3. Pages speak the page language: page_read, page_edit and page_create, and nothing else edits a page. Everything else (config entities, skins, other non-page entities) speaks JSON through eb_create_entity and eb_edit, after eb_read_schema has taught its shape. Materials change only through the save_material / update_material / remove_material page ops.
4. One motion. Every change to an existing page is ONE atomic page_edit batch, structure and styling together (fragments carry style="" inline, conditions ride the style op). A new page is ONE page_create document, the full layout with its styles. Never bare structure to style later. Edits to what exists are targeted ops, never rebuilds.
5. Save discipline. page_create saves and publishes the new page itself (a page must exist before edits can stage). Every edit after that stays staged and invisible to visitors until saved; publishing is the user's call. Never pass save=true unless the user explicitly asks to save or publish.
6. Errors. Never echo raw tool output. On an error, explain the problem and the fix its hint names, then resend.

INSTRUCTION;

  public function __construct(
    private readonly SkillsResolverInterface $skillsResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', -20],
      self::CONTEXT_BLOCKS_EVENT => 'onContextBlocks',
    ];
  }

  /**
   * Appends the Workflow section to the system prompt.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $event->setSystemPrompt($event->getSystemPrompt() . "\n" . self::WORKFLOW);
  }

  /**
   * States the skills catalog on the turn that opens a segment.
   */
  public function onContextBlocks(ContextBlocksEvent $event): void {
    if (!$event->opensSegment()) {
      return;
    }
    $skills = $this->skillsResolver->getSkills();
    if (empty($skills)) {
      return;
    }
    $lines = [];
    foreach ($skills as $id => $definition) {
      $lines[] = sprintf('- %s: %s', $id, $definition['description'] ?? '');
    }
    $event->addBlock(new Block(BlockKind::Skills, implode("\n", $lines)));
  }

}
