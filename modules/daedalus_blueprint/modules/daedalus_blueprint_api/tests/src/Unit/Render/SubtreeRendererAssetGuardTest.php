<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Unit\Render;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint_api\Render\TargetKeyMapper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Pins the no-page-state guard on the renderer's asset commands.
 *
 * The attachments processor diffs attached libraries against the request's
 * `ajax_page_state`. When a request carries none, the diff cannot tell
 * "nothing loaded" from "state not sent" and resolves EVERY library as new:
 * the response re-ships the whole script stack, re-executing drupal.js
 * resets window.Drupal (destroying the client app mid-session), and the
 * settings command overwrites the client's ajaxPageState with a poisoned
 * subset. The guard makes a stateless request degrade to markup with no
 * asset commands instead. This surfaced live: ai_chatbot's agent
 * continuation POSTs carried no contexts, and one such turn's mutation
 * payload took down the whole edit-mode app.
 */
#[CoversClass(SubtreeRenderer::class)]
#[Group('daedalus_blueprint')]
class SubtreeRendererAssetGuardTest extends UnitTestCase {

  /**
   * Builds a renderer whose collaborators are inert except the given pair.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack holding the request under test.
   * @param \Drupal\Core\Render\AttachmentsResponseProcessorInterface $processor
   *   The attachments processor (asserting mock).
   *
   * @return \Drupal\daedalus_blueprint_api\Render\SubtreeRenderer
   *   The renderer under test.
   */
  protected function buildRenderer(RequestStack $request_stack, AttachmentsResponseProcessorInterface $processor): SubtreeRenderer {
    return new SubtreeRenderer(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(RendererInterface::class),
      $this->createMock(TargetKeyMapper::class),
      $processor,
      $request_stack,
    );
  }

  /**
   * Invokes the protected buildAssetCommands with bubbled attachments.
   *
   * @param \Drupal\daedalus_blueprint_api\Render\SubtreeRenderer $renderer
   *   The renderer.
   *
   * @return array
   *   The rendered asset commands.
   */
  protected function buildAssetCommands(SubtreeRenderer $renderer): array {
    $method = new \ReflectionMethod($renderer, 'buildAssetCommands');
    return $method->invoke($renderer, ['library' => ['core/drupal']]);
  }

  /**
   * A stateless request gets no asset commands and no diff attempt at all.
   */
  public function testStatelessRequestShipsNoAssetCommands(): void {
    $request_stack = new RequestStack();
    $request_stack->push(Request::create('/daedalus-blueprint/node/1', 'POST'));

    $processor = $this->createMock(AttachmentsResponseProcessorInterface::class);
    $processor->expects($this->never())->method('processAttachments');

    $this->assertSame([], $this->buildAssetCommands($this->buildRenderer($request_stack, $processor)));
  }

  /**
   * A request carrying ajax_page_state resolves assets through the processor.
   */
  public function testStatefulRequestResolvesAssetCommands(): void {
    $request = Request::create('/daedalus-blueprint/node/1', 'POST');
    $request->attributes->set('ajax_page_state', ['libraries' => 'core/drupal']);
    $request_stack = new RequestStack();
    $request_stack->push($request);

    $processor = $this->createMock(AttachmentsResponseProcessorInterface::class);
    $processor->expects($this->once())
      ->method('processAttachments')
      ->willReturnCallback(function (AjaxResponse $response): AjaxResponse {
        $response->addCommand(new SettingsCommand(['probe' => TRUE], TRUE));
        return $response;
      });

    $commands = $this->buildAssetCommands($this->buildRenderer($request_stack, $processor));
    $this->assertCount(1, $commands);
    $this->assertSame('settings', $commands[0]['command']);
  }

}
