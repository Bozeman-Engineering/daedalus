<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\EventSubscriber\TokenPropagationSubscriber;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\CurrentEntityContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the model-neutral propagation of page context into agent prompts.
 *
 * TokenPropagationSubscriber is the seam that carries "the page the user is on"
 * from the chat request into the AI agent, regardless of which provider module
 * formats it. It listens to two events in sequence during one request:
 *
 * - AiAssistantPassContextToAgentEvent (onPassContext): captures the client's
 *   view_mode and resolves the client's current_route to its canonical entity,
 *   recording entity_type/id/uuid/bundle/label AND handing the entity's
 *   type + bundle to daedalus_blueprint's CurrentEntityContext (the shared holder
 *   AI tools/skills read, since they cannot see the agent's prompt).
 * - BuildSystemPromptEvent (onBuildSystemPrompt): writes the captured view mode
 *   and entity context onto the event's tokens so the agent (and its
 *   sub-agents) inherit them.
 *
 * Two invariants are load-bearing:
 * - INHERITANCE: when this request captured nothing (e.g. a sub-agent's prompt
 *   build), the subscriber falls back to the tokens already on the event, so a
 *   sub-agent keeps the parent's page context rather than losing it.
 * - ACCESS: the current_route rides in the client's request, so route access is
 *   not view access. An entity the current user cannot view must never be
 *   surfaced as prompt context (leaking a private label). RouteEntityResolver
 *   enforces this; here we confirm the subscriber honors the NULL result and
 *   emits no entity token (view_mode, which is not entity-bound, survives).
 *
 * The subscriber is built directly from a fresh RouteEntityResolver and a fresh
 * CurrentEntityContext per scenario (daedalus_ai is not enabled; its info.yml drags
 * the full AI stack), mirroring the sibling ContextResolverTest which already
 * pins RouteEntityResolver's own contract. A fresh subscriber + context per
 * scenario keeps the subscriber's request-scoped captured state isolated.
 *
 * @group daedalus_ai
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class TokenPropagationSubscriberTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    // RouteEntityResolver delegates to daedalus_ui' derivation spine, so
    // daedalus_ui and its kernel-test dependency chain (see
    // DaedalusAiKernelTestBase for the chain's rationale) are required.
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['filter', 'text']);

    // Routes must be built so the path validator can resolve
    // /entity_test/{id} to the entity.entity_test.canonical route.
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Capture + propagate, sub-agent inheritance, and the access gate.
   *
   * One method walks the whole two-event contract on a shared entity_test
   * fixture. The capture/propagate and inheritance branches run as an admin (so
   * the routed entity is viewable); the access-gate branch switches to a
   * restricted user, exactly as ContextResolverTest flips accounts.
   */
  public function testTokenCapturePropagationInheritanceAndAccessGate(): void {
    // Admin so the routed entity is viewable and the happy path resolves it.
    $this->setUpCurrentUser([], [], TRUE);
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Routed Page']);
    $entity->save();
    $path = '/entity_test/' . $entity->id();

    // Scenario A: capture from the context event, propagate to the prompt.
    $context = new CurrentEntityContext();
    $subscriber = $this->subscriber($context);
    $subscriber->onPassContext($this->contextEvent([
      'view_mode' => 'teaser',
      'current_route' => $path,
    ]));

    // The current entity's type + bundle are handed to daedalus_blueprint so AI
    // tools and skills can resolve page context they cannot read off the agent.
    $this->assertTrue($context->hasEntity());
    $this->assertSame('entity_test', $context->getEntityTypeId());
    $this->assertSame('entity_test', $context->getBundle());

    // onBuildSystemPrompt writes the captured values onto a fresh event.
    $prompt = $this->promptEvent();
    $subscriber->onBuildSystemPrompt($prompt);
    $tokens = $prompt->getTokens();
    $this->assertSame('teaser', $tokens['current_view_mode']);
    $this->assertSame([
      'entity_type' => 'entity_test',
      'entity_id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'bundle' => 'entity_test',
      'label' => 'Routed Page',
    ], $tokens['current_entity_context']);

    // Scenario B: a fresh subscriber captured nothing (empty context is a safe
    // no-op), so onBuildSystemPrompt re-propagates the tokens already on the
    // event. This is the sub-agent inheritance path: the parent's page context
    // survives into the child prompt build.
    $inherited = [
      'current_view_mode' => 'full',
      'current_entity_context' => ['entity_type' => 'node', 'entity_id' => 7],
    ];
    $fresh_context = new CurrentEntityContext();
    $fresh_subscriber = $this->subscriber($fresh_context);
    $fresh_subscriber->onPassContext($this->contextEvent([]));
    $this->assertFalse($fresh_context->hasEntity(), 'An empty context captures nothing.');

    $inherited_prompt = $this->promptEvent($inherited);
    $fresh_subscriber->onBuildSystemPrompt($inherited_prompt);
    $inherited_tokens = $inherited_prompt->getTokens();
    $this->assertSame('full', $inherited_tokens['current_view_mode']);
    $this->assertSame(
      $inherited['current_entity_context'],
      $inherited_tokens['current_entity_context'],
      'Pre-existing tokens survive so a sub-agent inherits page context.',
    );

    // Scenario C: an entity the current user cannot view is never surfaced.
    $private = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Private Page']);
    $private->save();
    // A user without 'view test entity' cannot view entity_test entities.
    $this->setUpCurrentUser([], []);
    $denied_context = new CurrentEntityContext();
    $denied_subscriber = $this->subscriber($denied_context);
    $denied_subscriber->onPassContext($this->contextEvent([
      'view_mode' => 'teaser',
      'current_route' => '/entity_test/' . $private->id(),
    ]));

    // view_mode is not entity-bound, so it is still captured; the entity is
    // not, and daedalus_blueprint is never told about it.
    $this->assertFalse($denied_context->hasEntity(), 'An unviewable entity is not handed to daedalus_blueprint.');

    $denied_prompt = $this->promptEvent();
    $denied_subscriber->onBuildSystemPrompt($denied_prompt);
    $denied_tokens = $denied_prompt->getTokens();
    $this->assertSame('teaser', $denied_tokens['current_view_mode']);
    $this->assertArrayNotHasKey(
      'current_entity_context',
      $denied_tokens,
      'An unviewable entity never reaches the prompt as a token.',
    );
  }

  /**
   * Builds a subscriber with a fresh route resolver and the given context.
   *
   * @param \Drupal\daedalus_blueprint\CurrentEntityContext $context
   *   The current-entity holder the subscriber should populate.
   *
   * @return \Drupal\daedalus_ai\EventSubscriber\TokenPropagationSubscriber
   *   A subscriber wired to real collaborators, isolated per scenario.
   */
  private function subscriber(CurrentEntityContext $context): TokenPropagationSubscriber {
    return new TokenPropagationSubscriber(
      new RouteEntityResolver(
        $this->container->get('path.validator'),
        $this->container->get('daedalus_blueprint.access_checker'),
        $this->container->get('router.no_access_checks'),
        $this->container->get('daedalus_ui.ui'),
      ),
      $context,
    );
  }

  /**
   * Builds an AiAssistantPassContextToAgentEvent carrying the given context.
   *
   * The subscriber only reads getContext(); the agent is a mock because it is
   * never touched.
   *
   * @param array $context
   *   The context array (view_mode / current_route).
   *
   * @return \Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent
   *   The event to hand to onPassContext().
   */
  private function contextEvent(array $context): AiAssistantPassContextToAgentEvent {
    return new AiAssistantPassContextToAgentEvent(
      $this->createMock(AiAgentInterface::class),
      $context,
    );
  }

  /**
   * Builds a BuildSystemPromptEvent seeded with the given tokens.
   *
   * @param array $tokens
   *   Pre-existing tokens (empty for a fresh prompt build).
   *
   * @return \Drupal\ai_agents\Event\BuildSystemPromptEvent
   *   The event to hand to onBuildSystemPrompt().
   */
  private function promptEvent(array $tokens = []): BuildSystemPromptEvent {
    return new BuildSystemPromptEvent('system prompt', 'daedalus', $tokens);
  }

}
