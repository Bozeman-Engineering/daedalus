<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Unit\EventSubscriber;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\EventSubscriber\BlueprintMirrorRefresh;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\daedalus_ui\ModeState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Unit tests for BlueprintMirrorRefresh.
 */
#[CoversClass(BlueprintMirrorRefresh::class)]
#[Group('daedalus_layout')]
class BlueprintMirrorRefreshTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The request stack shared by the subscriber and its real ModeState.
   */
  protected RequestStack $requestStack;

  /**
   * Mock storage handler.
   *
   * @var \Drupal\daedalus_blueprint\EntityStorageHandler|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $storageHandler;

  /**
   * Mock blueprint mirror.
   *
   * @var \Drupal\daedalus_ui\BlueprintMirror|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $blueprintMirror;

  /**
   * The subscriber under test.
   */
  protected BlueprintMirrorRefresh $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requestStack = new RequestStack();
    $this->storageHandler = $this->prophesize(EntityStorageHandler::class);
    $this->blueprintMirror = $this->prophesize(BlueprintMirror::class);
    // ModeState is final; the real one reads the navigationMode query param
    // off the shared request stack, exactly like production.
    $this->subscriber = new BlueprintMirrorRefresh(
      $this->requestStack,
      new ModeState($this->requestStack),
      $this->storageHandler->reveal(),
      $this->blueprintMirror->reveal(),
    );
  }

  /**
   * Tests get subscribed events.
   */
  public function testGetSubscribedEvents(): void {
    $events = BlueprintMirrorRefresh::getSubscribedEvents();

    $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    $this->assertEquals(['onResponse', -50], $events[KernelEvents::RESPONSE]);
  }

  /**
   * Tests ignores non ajax response.
   */
  public function testIgnoresNonAjaxResponse(): void {
    $this->pushRequest(['section_storage' => $this->editableStorage()]);
    $response = new Response('Hello');

    $this->subscriber->onResponse($this->createResponseEvent($response));

    $this->assertEquals('Hello', $response->getContent());
  }

  /**
   * Tests ignores outside edit mode.
   */
  public function testIgnoresOutsideEditMode(): void {
    $this->pushRequest(['section_storage' => $this->editableStorage()], mode: NULL);
    $response = new AjaxResponse();

    $this->subscriber->onResponse($this->createResponseEvent($response));

    $this->assertEquals([], $response->getCommands());
  }

  /**
   * Tests ignores without section storage.
   */
  public function testIgnoresWithoutSectionStorage(): void {
    $this->pushRequest([]);
    $response = new AjaxResponse();

    $this->subscriber->onResponse($this->createResponseEvent($response));

    $this->assertEquals([], $response->getCommands());
  }

  /**
   * Tests skips when response already carries envelope.
   */
  public function testSkipsWhenResponseAlreadyCarriesEnvelope(): void {
    $this->pushRequest(['section_storage' => $this->editableStorage()]);
    $response = new AjaxResponse();
    $response->setAttachments(['drupalSettings' => ['daedalusUi' => ['blueprint' => '{"already":"delivered"}']]]);

    $this->subscriber->onResponse($this->createResponseEvent($response));

    $this->assertEquals([], $response->getCommands());
  }

  /**
   * Tests prepends envelope settings command.
   */
  public function testPrependsEnvelopeSettingsCommand(): void {
    $this->pushRequest(['section_storage' => $this->editableStorage(view_mode: 'teaser')]);
    $staged = $this->entity()->reveal();
    $this->storageHandler->load('node', 6)->willReturn($staged);
    $this->blueprintMirror->envelope($staged, 'teaser')->willReturn('{"fresh":"envelope"}');

    $response = new AjaxResponse();
    $response->addCommand(new RawArrayCommand(['command' => 'insert', 'selector' => '#layout-builder']));
    $this->subscriber->onResponse($this->createResponseEvent($response));

    $commands = $response->getCommands();
    $this->assertEquals('settings', $commands[0]['command']);
    $this->assertTrue($commands[0]['merge']);
    $this->assertEquals('{"fresh":"envelope"}', $commands[0]['settings']['daedalusUi']['blueprint']);
    $this->assertEquals('insert', $commands[1]['command']);
  }

  /**
   * Tests nested storage resolves root and view mode falls back.
   */
  public function testNestedStorageResolvesRootAndViewModeFallsBack(): void {
    $root = $this->editableStorage(view_mode: NULL);
    $nested = $this->prophesize(NestedSectionStorageInterface::class);
    $nested->getRootStorage()->willReturn($root);
    $this->pushRequest(['section_storage' => $nested->reveal()]);
    $staged = $this->entity()->reveal();
    $this->storageHandler->load('node', 6)->willReturn($staged);
    $this->blueprintMirror->envelope($staged, 'full')->willReturn('{"fresh":"envelope"}');

    $response = new AjaxResponse();
    $this->subscriber->onResponse($this->createResponseEvent($response));

    $commands = $response->getCommands();
    $this->assertEquals('{"fresh":"envelope"}', $commands[0]['settings']['daedalusUi']['blueprint']);
  }

  /**
   * Pushes the current request with attributes and an edit-mode query param.
   */
  protected function pushRequest(array $attributes, ?string $mode = 'edit'): void {
    $request = new Request($mode === NULL ? [] : ['navigationMode' => $mode]);
    foreach ($attributes as $key => $value) {
      $request->attributes->set($key, $value);
    }
    $this->requestStack->push($request);
  }

  /**
   * A section storage whose contexts carry the test entity and view mode.
   */
  protected function editableStorage(?string $view_mode = 'full'): SectionStorageInterface {
    $entity_context = $this->prophesize(ContextInterface::class);
    $entity_context->getContextValue()->willReturn($this->entity()->reveal());
    $contexts = ['entity' => $entity_context->reveal()];
    if ($view_mode !== NULL) {
      $view_mode_context = $this->prophesize(ContextInterface::class);
      $view_mode_context->getContextValue()->willReturn($view_mode);
      $contexts['view_mode'] = $view_mode_context->reveal();
    }
    $storage = $this->prophesize(SectionStorageInterface::class);
    $storage->getContexts()->willReturn($contexts);
    return $storage->reveal();
  }

  /**
   * The test root entity.
   *
   * @return \Prophecy\Prophecy\ObjectProphecy
   *   A node 6 entity prophecy.
   */
  protected function entity() {
    $entity = $this->prophesize(FieldableEntityInterface::class);
    $entity->getEntityTypeId()->willReturn('node');
    $entity->id()->willReturn(6);
    return $entity;
  }

  /**
   * Creates a ResponseEvent for testing.
   */
  protected function createResponseEvent(Response $response): ResponseEvent {
    $kernel = $this->prophesize(HttpKernelInterface::class);
    return new ResponseEvent(
      $kernel->reveal(),
      $this->requestStack->getCurrentRequest() ?? new Request(),
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
  }

}

/**
 * Test AJAX command that returns a raw array.
 */
class RawArrayCommand implements CommandInterface {

  public function __construct(protected array $command) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return $this->command;
  }

}
