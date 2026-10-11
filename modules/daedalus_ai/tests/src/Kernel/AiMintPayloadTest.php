<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\EventSubscriber\AiToolActionSubscriber;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * An AI mint of a non-page entity reaches the client as a materials delta.
 *
 * The turn pipeline is real from the tool's event onward: the persisted
 * event goes through the real dispatcher into the real
 * AiToolActionSubscriber, and the real MutatedTurn builds what
 * DeepChatResponseListener injects as `blueprint_responses`. Pinned:
 * - a NEW swatch (no page, no link template) queues no open-link and
 *   collects no page mutation, yet the turn answers a materials-only
 *   payload carrying the swatch's record (the entity hook recorded it);
 * - a page mutation's payload carries the `history` timeline the turn's
 *   writes extended AND the delta of a mint made in the same turn, with
 *   no trailing materials-only payload (the delta rides once);
 * - a turn that changed nothing answers nothing.
 */
#[CoversClass(MutatedTurn::class)]
#[CoversClass(AiToolActionSubscriber::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class AiMintPayloadTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The AI stack beyond `ai` (agents, assistants) is not needed to build
   * the turn: the subscriber's dependencies are daedalus_ai's and
   * daedalus_ui's own; `key` and `ai` ride for the services daedalus_ai's
   * container autowires; daedalus_stylish supplies the material whose entity hook
   * feeds the change set.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'key',
    'ai',
    'entity_test',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    // A swatch save compiles the palette stylesheet, which builds the
    // roster; the daedalus_stylish provider reads all four storages.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['filter', 'text', 'daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * A chat-shaped request (its contexts feed the payload render).
   */
  protected function chatRequest(): Request {
    return Request::create('/api/deepchat', 'POST', [], [], [], [], json_encode([
      'messages' => [['role' => 'user', 'text' => 'save this color']],
      'contexts' => ['view_mode' => 'full'],
    ]));
  }

  /**
   * Fires the persisted event the tools dispatch, through the real bus.
   */
  protected function persisted(object $entity, bool $is_new, array $targets = []): void {
    // A page result carries its host; a config result (the swatch) its
    // entity, as the tool base dispatches them.
    $resolver = $this->container->get('daedalus_blueprint.host_resolver');
    $subject = $resolver->hosts($entity) ? ['host' => $resolver->fromEntity($entity)] : ['entity' => $entity];
    $this->container->get('event_dispatcher')->dispatch(new AiToolActionEvent(
      'entity_persisted',
      'tool:eb_create_entity',
      $subject + ['is_new' => $is_new, 'targets' => $targets],
    ));
  }

  /**
   * A minted swatch with no page behind it answers a materials-only payload.
   */
  public function testNonPageMintAnswersMaterialsOnlyPayload(): void {
    $turn = $this->container->get('daedalus_ai.mutated_turn');
    $this->assertSame([], $turn->buildPayloads($this->chatRequest()), 'A turn that changed nothing answers nothing.');

    $swatch = DaedalusStylishSwatch::create(['label' => 'Brand red', 'kind' => 'color']);
    $swatch->setSwatchValue('#b91c1c');
    $swatch->save();
    $this->persisted($swatch, TRUE);

    // No page to open: the subscriber queued nothing (the old path threw
    // on the missing link template and dropped the turn's effects).
    $this->assertSame([], $this->container->get('daedalus_ai.ui_command_queue')->flush());
    $this->assertFalse($turn->hasMutations());

    $payloads = $turn->buildPayloads($this->chatRequest());
    $this->assertCount(1, $payloads);
    $payload = $payloads[0];
    $this->assertTrue($payload['success']);
    $this->assertArrayNotHasKey('blueprint', $payload);
    $this->assertArrayNotHasKey('entity_uuid', $payload);
    $upsert = $payload['materials']['upsert'];
    $this->assertCount(1, $upsert);
    $this->assertSame('swatch', $upsert[0]['kind']);
    $this->assertSame('brand_red', $upsert[0]['id']);
    $this->assertSame('Brand red', $upsert[0]['label']);
    $this->assertSame('#b91c1c', $upsert[0]['value']);
    $this->assertSame('var(--swatch-brand_red)', $upsert[0]['spellings']['style']);
    $this->assertSame([], $payload['materials']['remove']);

    // Drained: the next leg of the turn carries nothing.
    $this->assertSame([], $turn->buildPayloads($this->chatRequest()));
  }

  /**
   * A page payload carries the history timeline and the turn's delta once.
   */
  public function testPagePayloadCarriesHistoryAndDelta(): void {
    $turn = $this->container->get('daedalus_ai.mutated_turn');
    $page = EntityTest::create(['name' => 'Landing']);
    $page->save();

    $swatch = DaedalusStylishSwatch::create(['label' => 'Sea', 'kind' => 'color']);
    $swatch->setSwatchValue('#0ea5e9');
    $swatch->save();
    $this->persisted($swatch, TRUE);
    $this->persisted($page, FALSE, [['uuid' => $page->uuid(), 'field_name' => 'name']]);
    $this->assertTrue($turn->hasMutations());

    $payloads = $turn->buildPayloads($this->chatRequest());
    $this->assertCount(1, $payloads, 'The page payload carried the delta; no materials-only payload trails it.');
    $payload = $payloads[0];
    $this->assertSame($page->uuid(), $payload['entity_uuid']);
    $this->assertArrayHasKey('blueprint', $payload);

    // The timeline rides the payload, in the shape the history sidebar and
    // undo chrome read (no writes were staged here, so it is the empty
    // timeline; the key's presence is the contract).
    $this->assertSame(['pointer', 'can_undo', 'can_redo', 'entries'], array_keys($payload['history']));
    $this->assertFalse($payload['history']['can_undo']);

    $this->assertSame(['sea'], array_column($payload['materials']['upsert'], 'id'));
    $this->assertSame([], $payload['materials']['remove']);
  }

}
