<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Ajax;

use Drupal\daedalus_ai\Ajax\OpenLinkCommand;
use Drupal\daedalus_ai\Ajax\TriggerDeferredCommand;
use Drupal\daedalus_ai\Ajax\TriggerFollowupChatCommand;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the wire shape of daedalus_ai's AJAX command render() arrays.
 *
 * These arrays are the literal contract between the server-side UiCommandQueue
 * and the matching JS command handlers (daedalus-ai-open-link.js,
 * daedalus-ai-trigger-deferred.js, daedalus-ai-followup-chat.js). The client dispatches
 * on the exact 'command' string and, for the deferred/followup commands, builds
 * URLs from entityId expecting it to already be a STRING. Two regressions are
 * invisible to PHP but break the client:
 *
 * - Renaming a 'command' string would leave the JS handler unregistered, so the
 *   command would be silently dropped from the response.
 * - Dropping the (string) cast on entityId would send an int where the JS
 *   string-concatenates it into a path; most of the time harmless, but the cast
 *   is the documented contract and is asserted here with an INTEGER input to
 *   prove the cast actually happens.
 *
 * Pure unit test: each command is a plain CommandInterface with no Drupal
 * dependencies. One method per command keeps each contract independently
 * diagnosable; there is no install cost.
 */
#[Group('daedalus_ai')]
class AjaxCommandRenderTest extends UnitTestCase {

  /**
   * OpenLinkCommand renders the daedalusAiOpenLink shape with url and title.
   */
  public function testOpenLinkCommandRender(): void {
    $command = new OpenLinkCommand('/node/5', 'My new page');

    $this->assertSame(
      [
        'command' => 'daedalusAiOpenLink',
        'url' => '/node/5',
        'title' => 'My new page',
      ],
      $command->render(),
    );
  }

  /**
   * TriggerDeferredCommand renders daedalusAiTriggerDeferred with a string id.
   *
   * Passes an INTEGER entityId to prove the (string) cast in render() fires:
   * daedalus-ai-trigger-deferred.js POSTs entityId into a path and expects a
   * string. targetKeys (the spine keys the pending effect targets) and
   * messages must pass through untouched.
   */
  public function testTriggerDeferredCommandRender(): void {
    $target_keys = ['block:abc-123:field_media_image'];
    $messages = [
      'loading' => 'Generating image...',
      'success' => 'Done.',
    ];
    $command = new TriggerDeferredCommand(
      batchId: 'batch-9',
      entityType: 'node',
      entityId: 5,
      viewMode: 'full',
      targetKeys: $target_keys,
      messages: $messages,
    );

    $rendered = $command->render();

    $this->assertSame('daedalusAiTriggerDeferred', $rendered['command']);
    $this->assertSame('batch-9', $rendered['batchId']);
    $this->assertSame('node', $rendered['entityType']);
    $this->assertSame('5', $rendered['entityId']);
    $this->assertSame('full', $rendered['viewMode']);
    $this->assertSame($target_keys, $rendered['targetKeys']);
    $this->assertSame($messages, $rendered['messages']);
  }

  /**
   * TriggerFollowupChatCommand renders daedalusAiTriggerFollowup with a string id.
   *
   * Same integer-entityId cast contract as the deferred command. messageText
   * (the wire-format LLM message) must pass through unchanged. The command
   * carries no refresh targets: whatever the follow-up turn mutates rides
   * the chat response as mutation-response payloads.
   */
  public function testTriggerFollowupChatCommandRender(): void {
    $command = new TriggerFollowupChatCommand(
      batchId: 'batch-3',
      messageText: 'The image finished generating.',
      entityType: 'node',
      entityId: 12,
    );

    $rendered = $command->render();

    $this->assertSame('daedalusAiTriggerFollowup', $rendered['command']);
    $this->assertSame('batch-3', $rendered['batchId']);
    $this->assertSame('The image finished generating.', $rendered['messageText']);
    $this->assertSame('node', $rendered['entityType']);
    $this->assertSame('12', $rendered['entityId']);
    $this->assertArrayNotHasKey('targets', $rendered);
  }

}
