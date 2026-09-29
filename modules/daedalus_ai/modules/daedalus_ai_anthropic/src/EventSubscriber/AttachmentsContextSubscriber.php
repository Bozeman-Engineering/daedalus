<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Drupal\daedalus_ai\FileKind;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hands the files attached to this turn to the model.
 *
 * The chat client uploads each attached file to the attachment route ahead
 * of the turn and sends the turn as JSON carrying `contexts.attachments`,
 * the names the store answered with. This subscriber resolves each name
 * through the store for the conversation (the agent's runner id, which is
 * the request's thread id) and the current user, so a name that is not the
 * user's own held file in this thread never reaches the model, and
 * prepends one <system-reminder> ATTACHMENTS block to the last user
 * message: per file its number, name, MIME type and size. The name is the
 * handle the model addresses the file by afterwards; it never sees a uuid.
 *
 * BYTES ARE DELIVERED ONCE, BY KIND: a textual file (text/*, JSON, SVG) is
 * inlined in the block up to INLINE_CEILING, beyond it the block says to
 * read it with view_file; a raster image rides the message as an image
 * (the provider sends it as an image block); a PDF rides as a document
 * (the provider sends it as a document block) and the block says it cannot
 * be re-opened, since tool results carry images and text only. Anything
 * else is named and not delivered. The memory runner strips files from
 * every message after every leg for cost; the block with its handles is
 * what survives, and view_file re-opens a handle on demand.
 *
 * NEVER STRIPPED: the print, selection and posture blocks are per-turn
 * facts and each subscriber sheds its earlier copies. An ATTACHMENTS block
 * is a fact about its own message (these files came with these words), so
 * it stays on the earlier turn; the guard only refuses to inject twice on
 * the same message, which also keeps a continuation leg from re-delivering
 * the bytes. Priority 12 sits between the selection's 10 and the
 * posture's 15, so the block lies between them: metadata, print,
 * selection, attachments, posture, the user's words.
 */
class AttachmentsContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  /**
   * The largest textual file inlined in the block, in bytes.
   */
  public const INLINE_CEILING = 64 * 1024;

  protected const ATTACHMENTS_MARKER = "<system-reminder>\nATTACHMENTS";

  public function __construct(
    protected Attachments $attachments,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', 12],
    ];
  }

  /**
   * Resolves the turn's attachments and injects them into the last message.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $names = $event->getContext()['attachments'] ?? NULL;
    if (!is_array($names) || $names === []) {
      return;
    }

    $agent = $event->getAgent();
    $message = $this->getLastUserMessage($agent);
    if (!$message || str_contains($message->getText(), self::ATTACHMENTS_MARKER)) {
      return;
    }

    $thread = $agent->getRunnerId();
    if (!is_string($thread) || $thread === '') {
      $this->logger->notice('Attachments @names arrived on a run without a thread id; ignored.', [
        '@names' => implode(', ', array_filter($names, 'is_string')),
      ]);
      return;
    }

    $files = [];
    foreach ($names as $name) {
      $file = is_string($name) ? $this->attachments->resolve($thread, $name) : NULL;
      if ($file === NULL) {
        $this->logger->notice('Attachment @name is not a file the current user holds in thread @thread; ignored.', [
          '@name' => is_string($name) ? $name : var_export($name, TRUE),
          '@thread' => $thread,
        ]);
        continue;
      }
      $files[] = $file;
    }
    if ($files === []) {
      return;
    }

    $this->injectIntoLastUserMessage($message, $files);
  }

  /**
   * Prepends the ATTACHMENTS block and delivers each file's bytes by kind.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $message
   *   The last user message.
   * @param list<\Drupal\file\FileInterface> $files
   *   The resolved files, in the order the request named them.
   */
  protected function injectIntoLastUserMessage(ChatMessage $message, array $files): void {
    $lines = [];
    foreach ($files as $index => $file) {
      $name = $file->getFilename();
      $mime = (string) $file->getMimeType();
      $size = (int) $file->getSize();
      $head = sprintf('- File %d: %s (%s, %s)', $index + 1, $name, $mime, ByteSizeMarkup::create($size));
      switch (FileKind::fromMime($mime)) {
        case FileKind::Text:
          if ($size > self::INLINE_CEILING) {
            $lines[] = $head . sprintf(': larger than the %s inline ceiling; read it with view_file("%s").', ByteSizeMarkup::create(self::INLINE_CEILING), $name);
            break;
          }
          $lines[] = $head . ', inlined:';
          $lines[] = '<file name="' . $name . '">';
          $lines[] = rtrim($this->attachments->bytes($file), "\n");
          $lines[] = '</file>';
          break;

        case FileKind::Image:
          $message->setImage(new ImageFile($this->attachments->bytes($file), $mime, $name));
          $lines[] = $head . ': the image is on this message.';
          break;

        case FileKind::Pdf:
          $message->setFile(new DocumentFile($this->attachments->bytes($file), $mime, $name));
          $lines[] = $head . ': the document is on this message; it cannot be re-opened later, so read what you need now.';
          break;

        case FileKind::Other:
          $lines[] = $head . ': not a kind you can read.';
          break;
      }
    }

    $tag_content = "ATTACHMENTS: the user attached the following file(s) to this message. "
      . "Address a file by its name; view_file(\"<name>\") re-opens it later.\n"
      . implode("\n", $lines);
    $reminder = "<system-reminder>\n" . $tag_content . "\n</system-reminder>\n\n";

    $message->setText($reminder . $message->getText());
  }

}
