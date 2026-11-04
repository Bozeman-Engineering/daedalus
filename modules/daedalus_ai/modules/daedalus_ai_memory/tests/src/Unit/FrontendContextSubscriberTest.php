<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai_memory\EventSubscriber\FrontendContextSubscriber;

/**
 * The frontend's session context is emptied for a managed agent only.
 *
 * @group daedalus_ai_memory
 */
class FrontendContextSubscriberTest extends UnitTestCase {

  /**
   * A managed agent's context is emptied; another agent's is untouched.
   */
  public function testEmptiesTheContextOfManagedAgentsOnly(): void {
    $subscriber = new FrontendContextSubscriber($this->getConfigFactoryStub([
      'daedalus_ai_memory.settings' => ['enabled_agents' => ['daedalus' => 'daedalus']],
    ]));
    $context = ['current_route' => '/node/2', 'view_mode' => 'default'];

    $event = new AiAssistantPassContextToAgentEvent($this->agent('daedalus'), $context);
    $subscriber->onPassContext($event);
    $this->assertSame([], $event->getContext(), 'The managed agent gets its context as blocks, so the frontend lines are not left for ai_assistant_api to render into the system prompt.');

    $event = new AiAssistantPassContextToAgentEvent($this->agent('other_agent'), $context);
    $subscriber->onPassContext($event);
    $this->assertSame($context, $event->getContext(), 'An agent the spine does not manage keeps the contrib behavior.');
  }

  /**
   * The subscriber sits between the daedalus readers and the contrib capture.
   */
  public function testRunsAfterTheReadersAndBeforeTheCapture(): void {
    $events = FrontendContextSubscriber::getSubscribedEvents();
    [, $priority] = $events[AiAssistantPassContextToAgentEvent::EVENT_NAME];
    $this->assertLessThan(-10, $priority, 'After TokenPropagationSubscriber (-10).');
    $this->assertGreaterThan(-1000, $priority, 'Before ai_assistant_api\'s PassContextToAgentSubscriber (-1000).');
  }

  /**
   * An agent double answering its id.
   */
  private function agent(string $id): AiAgentInterface {
    $agent = $this->createMock(AiAgentInterface::class);
    $agent->method('getId')->willReturn($id);
    return $agent;
  }

}
