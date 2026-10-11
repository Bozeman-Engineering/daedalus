<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The chat-turn body the sidebar renders is the body ai_chatbot accepts.
 *
 * This is the seam between daedalus_ai and ai_chatbot, crossed live: the
 * test never spells a request body of its own. It reads the connect object
 * ChatSidebar::build() rendered onto the page's <deep-chat> element, exactly
 * as deep-chat does in the browser, and POSTs it to the url it names, as
 * deep-chat does on a turn. The Kernel ChatSidebarTest pins the body's shape
 * against ai_chatbot's processor plugin; this class proves the shape is one
 * DeepChatApi::api() lets through, which is the check a shape assertion
 * written in daedalus's own words cannot make (drupal/ai 1.5 moved the
 * contract onto chat processor plugins and every Kernel and FJS test stayed
 * green while every real turn answered 400).
 *
 * No key is installed and no LLM is called: the site is the AI umbrella
 * recipe's product with its empty Anthropic key. The assistant is
 * agent-backed, so daedalus_ai_memory's AgentMemoryRunner runs the turn,
 * catches the provider's setup failure and answers it as the assistant
 * entity's own error_message in a normal 200 reply. That reply is the
 * evidence: a body refused at the gate answers 400 and never reaches the
 * runner, while a body that reaches the runner comes back as the
 * assistant's words. Functional rather than FJS because the body is
 * server-rendered and the turn is plain HTTP; the browser adds nothing.
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class ChatTurnContractTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * The rendered connect body plus one user message crosses ai_chatbot's gate.
   */
  public function testRenderedBodyReachesTheRunner(): void {
    $this->loginAsEditor();

    // A page the sidebar renders on: a real page of the recipe's bundle, as
    // the editor sees it.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Chat-turn contract page',
    ]);
    $node->save();
    $this->drupalGet($node->toUrl());

    // The body as deep-chat reads it: the element's connect attribute,
    // entity-decoded by the page parser the way the browser decodes it.
    $element = $this->getSession()->getPage()->find('css', 'deep-chat.deepchat-element');
    $this->assertNotNull($element, 'The page carries the chat sidebar.');
    $connect = Json::decode((string) $element->getAttribute('connect'));
    $this->assertIsArray($connect);
    $this->assertArrayHasKey('url', $connect);
    $this->assertArrayHasKey('additionalBodyProps', $connect);

    // The thread id rides drupalSettings (ai_chatbot rotates it there on a
    // history clear); the follow-up lane adds it the same way.
    $thread_id = $this->getDrupalSettings()['ai_deepchat']['thread_id'] ?? NULL;
    $this->assertIsString($thread_id);
    $this->assertNotSame('', $thread_id);

    // A turn as deep-chat sends one: the connect's body props plus the one
    // user message, to the connect's url (the CSRF token rides its query).
    $body = $connect['additionalBodyProps'] + [
      'thread_id' => $thread_id,
      'messages' => [['role' => 'user', 'text' => 'ping']],
    ];
    $response = $this->getHttpClient()->request('POST', $this->getAbsoluteUrl($connect['url']), [
      'json' => $body,
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();

    // The gate: the pre-1.5 body answers 400 "Invalid or missing chat
    // processor plugin." here and never reaches the runner.
    $this->assertSame(200, $status, "The rendered body crossed ai_chatbot's gate: $raw");
    $answer = Json::decode($raw);
    $this->assertIsArray($answer, $raw);
    $this->assertArrayHasKey('html', $answer, $raw);
    $this->assertFalse($answer['should_continue'] ?? FALSE, $raw);

    // The reply is the assistant's own failure wording (no key installed),
    // read from the installed entity rather than spelled here: the turn
    // reached the runner, which rendered the entity's error_message with its
    // [error_message] token replaced by the provider's reason.
    $assistant_id = $this->config('daedalus_ai.settings')->get('assistant_id');
    $assistant = $this->container->get('entity_type.manager')->getStorage('ai_assistant')->load($assistant_id);
    $this->assertNotNull($assistant, "The $assistant_id assistant is installed.");
    $template = (string) $assistant->get('error_message');
    $this->assertStringContainsString('[error_message]', $template, 'The fixture assistant carries the reason token.');
    [$prefix] = explode('[error_message]', $template, 2);
    $this->assertNotSame('', trim($prefix), 'The fixture assistant words its failures.');
    $this->assertStringContainsString(trim($prefix), $answer['html'], $raw);
    $this->assertStringNotContainsString('[error_message]', $answer['html'], 'The reason token was rendered, not echoed.');
  }

}
