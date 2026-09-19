<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the user's declared design posture each turn.
 *
 * The design skill defines two postures, on brand (the default) and
 * workshopping, and makes the posture the user's call, declared by intent
 * in the conversation. The chat panel's "On brand styling" toggle is the
 * UI's way to make that declaration: its state rides every request as the
 * `design_posture` context (`on_brand` or `workshopping`) and lands here
 * as a <system-reminder> DESIGN POSTURE block prepended to the last user
 * message. The block speaks the skill's own vocabulary so the reminder and
 * the doctrine name the same two postures; the skill text is untouched.
 *
 * A BLOCK EVERY TURN, IN BOTH STATES: a flip back to on brand
 * mid-conversation must land as a signal. Silence on the on-brand turn,
 * with the model's own off-brand history still in the transcript, would
 * keep it workshopping; so the on-brand posture is stated as explicitly
 * as the workshopping one, and absent or unknown context reads as on
 * brand (the skill's default) rather than as no block.
 *
 * ON THE USER MESSAGE, NEVER THE SYSTEM PROMPT: the prompt is the stable
 * cached prefix; the posture can change between turns.
 *
 * ONE LIVE BLOCK: before injecting, every earlier user message in the
 * agent's persisted history sheds its posture block, so the history holds
 * exactly one declaration, the current turn's. Priority 15 sits above the
 * selection's 10 and the print's 5, so this block is prepended last and
 * lies innermost: metadata, print, selection, posture, the user's words.
 */
class DesignPostureContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  protected const DESIGN_POSTURE_MARKER = "<system-reminder>\nDESIGN POSTURE";

  protected const ON_BRAND_TEXT = 'DESIGN POSTURE: on brand. The user\'s On brand styling toggle is on: '
    . 'the design system is the boundary, and a page is on brand when it is a legal arrangement '
    . 'of the system\'s materials. Full creative liberty inside that boundary; the wow comes from '
    . 'what you arrange, not from restyling the brushes.';

  protected const WORKSHOPPING_TEXT = 'DESIGN POSTURE: workshopping. The user turned On brand styling off: '
    . 'the materials are reference, not boundary, and new styling is on the table. Commit to one '
    . 'direction and take one real aesthetic risk you can justify; workshopped styling stays on '
    . 'this page unless the user graduates it.';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', 15],
    ];
  }

  /**
   * Strips earlier posture blocks and injects this turn's declaration.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $this->stripEarlierReminderBlocks($event->getAgent(), self::DESIGN_POSTURE_MARKER);

    $message = $this->getLastUserMessage($event->getAgent());
    if (!$message || str_contains($message->getText(), self::DESIGN_POSTURE_MARKER)) {
      return;
    }

    $posture = $event->getContext()['design_posture'] ?? NULL;
    $text = $posture === 'workshopping' ? self::WORKSHOPPING_TEXT : self::ON_BRAND_TEXT;
    $reminder = "<system-reminder>\n" . $text . "\n</system-reminder>\n\n";

    $message->setText($reminder . $message->getText());
  }

}
