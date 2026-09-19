<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\EventSubscriber;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Core\Ajax\AjaxResponse;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\daedalus_layout\EventSubscriber\DialogCloseSubscriber;

/**
 * Unit tests for DialogCloseSubscriber.
 */
#[CoversClass(DialogCloseSubscriber::class)]
#[Group('daedalus_layout')]
class DialogCloseSubscriberTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Mock request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $requestStack;

  /**
   * The subscriber under test.
   *
   * @var \Drupal\daedalus_layout\EventSubscriber\DialogCloseSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requestStack = $this->prophesize(RequestStack::class);
    $this->subscriber = new DialogCloseSubscriber(
      $this->requestStack->reveal()
    );
  }

  /**
   * Tests get subscribed events.
   */
  public function testGetSubscribedEvents(): void {
    $events = DialogCloseSubscriber::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals(['onResponse', -50], $events[KernelEvents::RESPONSE]);
  }

  /**
   * Tests on response ignores non ajax response.
   */
  public function testOnResponseIgnoresNonAjaxResponse(): void {
    $response = new Response('Hello');
    $event = $this->createResponseEvent($response);

    $this->subscriber->onResponse($event);

    // Response should be unchanged.
    $this->assertSame($response, $event->getResponse());
    $this->assertEquals('Hello', $event->getResponse()->getContent());
  }

  /**
   * Tests on response ignores when no section storage.
   */
  public function testOnResponseIgnoresWhenNoSectionStorage(): void {
    $response = new AjaxResponse();
    $response->addCommand(new TestAjaxCommand([
      'command' => 'closeDialog',
      'selector' => '#drupal-off-canvas',
      'persist' => FALSE,
    ]));
    $event = $this->createResponseEvent($response);

    $request = $this->createRequest();
    $this->requestStack->getCurrentRequest()->willReturn($request);

    $this->subscriber->onResponse($event);

    // Commands should be unchanged.
    $commands = $response->getCommands();
    $this->assertEquals('#drupal-off-canvas', $commands[0]['selector']);
  }

  /**
   * Tests on response transforms off canvas close command.
   */
  public function testOnResponseTransformsOffCanvasCloseCommand(): void {
    $response = new AjaxResponse();
    $response->addCommand(new TestAjaxCommand([
      'command' => 'closeDialog',
      'selector' => '#drupal-off-canvas',
      'persist' => FALSE,
    ]));
    $event = $this->createResponseEvent($response);

    $storage = $this->prophesize(SectionStorageInterface::class)->reveal();
    $request = $this->createRequest([
      'section_storage' => $storage,
    ]);
    $this->requestStack->getCurrentRequest()->willReturn($request);

    $this->subscriber->onResponse($event);

    $commands = $response->getCommands();
    $this->assertEquals('closeDialog', $commands[0]['command']);
    $this->assertEquals('.ui-dialog-content', $commands[0]['selector']);
  }

  /**
   * Tests on response ignores other close dialog commands.
   */
  public function testOnResponseIgnoresOtherCloseDialogCommands(): void {
    $response = new AjaxResponse();
    $response->addCommand(new TestAjaxCommand([
      'command' => 'closeDialog',
      'selector' => '#drupal-modal',
      'persist' => FALSE,
    ]));
    $event = $this->createResponseEvent($response);

    $storage = $this->prophesize(SectionStorageInterface::class)->reveal();
    $request = $this->createRequest([
      'section_storage' => $storage,
    ]);
    $this->requestStack->getCurrentRequest()->willReturn($request);

    $this->subscriber->onResponse($event);

    $commands = $response->getCommands();
    $this->assertEquals('closeDialog', $commands[0]['command']);
    $this->assertEquals('#drupal-modal', $commands[0]['selector']);
  }

  /**
   * Tests on response leaves replace commands alone.
   */
  public function testOnResponseLeavesReplaceCommandsAlone(): void {
    $response = new AjaxResponse();
    $response->addCommand(new TestAjaxCommand([
      'command' => 'insert',
      'method' => 'replaceWith',
      'selector' => '#layout-builder',
      'data' => '<div>Layout</div>',
    ]));
    $event = $this->createResponseEvent($response);

    $storage = $this->prophesize(SectionStorageInterface::class)->reveal();
    $request = $this->createRequest([
      'section_storage' => $storage,
    ]);
    $this->requestStack->getCurrentRequest()->willReturn($request);

    $this->subscriber->onResponse($event);

    // The layout element always renders root, so #layout-builder replaces
    // are correct as-is at any nesting depth.
    $commands = $response->getCommands();
    $this->assertEquals('#layout-builder', $commands[0]['selector']);
    $this->assertEquals('<div>Layout</div>', $commands[0]['data']);
  }

  /**
   * Tests on response ignores non section storage objects.
   */
  public function testOnResponseIgnoresNonSectionStorageObjects(): void {
    $response = new AjaxResponse();
    $response->addCommand(new TestAjaxCommand([
      'command' => 'closeDialog',
      'selector' => '#drupal-off-canvas',
      'persist' => FALSE,
    ]));
    $event = $this->createResponseEvent($response);

    $request = $this->createRequest([
      'section_storage' => 'not-a-storage-object',
    ]);
    $this->requestStack->getCurrentRequest()->willReturn($request);

    $this->subscriber->onResponse($event);

    $commands = $response->getCommands();
    $this->assertEquals('#drupal-off-canvas', $commands[0]['selector']);
  }

  /**
   * Creates a ResponseEvent for testing.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The response.
   *
   * @return \Symfony\Component\HttpKernel\Event\ResponseEvent
   *   The event.
   */
  protected function createResponseEvent(Response $response): ResponseEvent {
    $kernel = $this->prophesize(HttpKernelInterface::class);
    $request = new Request();
    return new ResponseEvent(
      $kernel->reveal(),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response
    );
  }

  /**
   * Creates a Request with attributes set.
   *
   * @param array $attributes
   *   The request attributes.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  protected function createRequest(array $attributes = []): Request {
    $request = new Request();
    foreach ($attributes as $key => $value) {
      $request->attributes->set($key, $value);
    }
    return $request;
  }

}

/**
 * Test AJAX command that returns raw array.
 */
class TestAjaxCommand implements CommandInterface {

  /**
   * The command array.
   *
   * @var array
   */
  protected array $command;

  /**
   * Constructs a TestAjaxCommand.
   *
   * @param array $command
   *   The command array.
   */
  public function __construct(array $command) {
    $this->command = $command;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return $this->command;
  }

}
