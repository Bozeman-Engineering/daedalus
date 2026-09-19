<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_dev_tools\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\daedalus_blueprint_ai\Event\PageToolInputEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Writes armed page-tool input captures to the private filesystem.
 *
 * The ground-truth diagnostic: the EXACT bytes a page
 * tool received at execute time, so a "the tool parsed something other
 * than what the log shows" mystery can be settled against ground truth
 * instead of ai_logging's reconstruction. One JSON file per tool
 * execution at private://ai-tool-capture/<conversation>/<time>-<tool>.json;
 * the same file is rewritten when the tool re-dispatches its event with
 * the parse verdict set (the received timestamp keys the file, so both
 * dispatches land in one record).
 *
 * Capture only runs while ARMED: a state flag the
 * daedalus-blueprint:tool-capture Drush command sets, never on by default.
 * When armed, a failed write throws: a capture you armed on purpose that
 * silently drops files would be worse than no capture at all.
 */
final class ToolInputCaptureSubscriber implements EventSubscriberInterface {

  /**
   * The state flag that arms the capture. Never on by default.
   */
  public const STATE_KEY = 'daedalus_blueprint_ai_dev_tools.tool_input_capture';

  /**
   * The capture root on the private filesystem.
   */
  public const DIRECTORY = 'private://ai-tool-capture';

  public function __construct(
    private readonly StateInterface $state,
    private readonly FileSystemInterface $fileSystem,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PageToolInputEvent::class => 'onPageToolInput'];
  }

  /**
   * Writes (or completes) the capture record for one tool execution.
   */
  public function onPageToolInput(PageToolInputEvent $event): void {
    if (!$this->state->get(self::STATE_KEY, FALSE)) {
      return;
    }
    // Without the private wrapper, PHP file functions warn and then treat
    // "private://..." as a literal relative path, silently landing the
    // capture on a "private:" directory under the docroot. Refuse loudly.
    if (!$this->streamWrapperManager->isValidScheme('private')) {
      throw new \RuntimeException('Tool-input capture is armed but the private filesystem is not configured: set $settings[\'file_private_path\'] (or disarm with drush daedalus-blueprint:tool-capture disarm).');
    }
    $uri = $this->captureUri($event);
    $directory = dirname($uri);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException(sprintf('Tool-input capture is armed but the capture directory %s could not be prepared.', $directory));
    }
    $record = [
      'tool' => $event->getToolId(),
      'conversation' => $event->getConversation(),
      'received' => $this->formatTime($event->getReceivedTime(), 'Y-m-d\TH:i:s.up'),
      'input' => $event->getContextValues(),
      'verdict' => $event->getVerdict(),
    ];
    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($uri, $json) === FALSE) {
      throw new \RuntimeException(sprintf('Tool-input capture is armed but %s could not be written.', $uri));
    }
  }

  /**
   * The capture file for one tool execution.
   *
   * Keyed by the event's received timestamp (millisecond precision, so two
   * calls in one turn never collide within a second) and the tool id; both
   * dispatches of one execution share the timestamp, so the verdict
   * dispatch rewrites the record the input dispatch opened.
   */
  private function captureUri(PageToolInputEvent $event): string {
    $conversation = $event->getConversation();
    $conversation = $conversation === '' ? 'no-conversation' : preg_replace('/[^A-Za-z0-9._-]/', '_', $conversation);
    return sprintf(
      '%s/%s/%s-%s.json',
      self::DIRECTORY,
      $conversation,
      $this->formatTime($event->getReceivedTime(), 'Ymd-His.v'),
      $event->getToolId(),
    );
  }

  /**
   * Formats a microsecond Unix timestamp.
   */
  private function formatTime(float $timestamp, string $format): string {
    return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
      ->format($format);
  }

}
