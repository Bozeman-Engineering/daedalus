<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

use Drupal\ai\OperationType\GenericType\DocumentFile;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\FileKind;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * States the attachments block and delivers the turn's files.
 *
 * The chat client uploads each attached file to the attachment route ahead
 * of the turn and sends the turn as JSON carrying `contexts.attachments`,
 * the names the store answered with. This producer resolves each name
 * through the store for the conversation (the event's conversation id,
 * which is the request's thread id) and the current user, so a name that
 * is not the user's own held file in this thread never reaches the model,
 * and states one block under the renderer's "ATTACHMENTS" header: per
 * file its number, name, MIME type and size. The name is the handle the
 * model addresses the file by afterwards; it never sees a uuid.
 *
 * BYTES ARE DELIVERED ONCE, BY KIND: a textual file (text/*, JSON, SVG) is
 * inlined in the block up to INLINE_CEILING, beyond it the block says to
 * read it with view_file; a raster image is delivered with the turn as an
 * image (the provider sends it as an image block); a PDF as a document
 * (the provider sends it as a document block) and the block says it cannot
 * be re-opened, since tool results carry images and text only. Anything
 * else is named and not delivered. The renderer places the delivered
 * files on the turn's first leg only and the transcript never stores
 * them; the block with its handles is what survives, and view_file
 * re-opens a handle on demand.
 */
final class AttachmentsBlockProducer implements EventSubscriberInterface {

  /**
   * The largest textual file inlined in the block, in bytes.
   */
  public const INLINE_CEILING = 64 * 1024;

  public function __construct(
    private readonly Attachments $attachments,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ContextBlocksEvent::EVENT_NAME => 'onContextBlocks',
    ];
  }

  /**
   * Resolves the turn's attachments, states them and delivers their bytes.
   */
  public function onContextBlocks(ContextBlocksEvent $event): void {
    $names = $event->context()['attachments'] ?? NULL;
    if (!is_array($names) || $names === []) {
      return;
    }

    $thread = $event->conversationId();
    if ($thread === '') {
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
          $event->addFile(new ImageFile($this->attachments->bytes($file), $mime, $name));
          $lines[] = $head . ': the image is on this message.';
          break;

        case FileKind::Pdf:
          $event->addFile(new DocumentFile($this->attachments->bytes($file), $mime, $name));
          $lines[] = $head . ': the document is on this message; it cannot be re-opened later, so read what you need now.';
          break;

        case FileKind::Other:
          $lines[] = $head . ': not a kind you can read.';
          break;
      }
    }

    $event->addBlock(new Block(BlockKind::Attachments, implode("\n", $lines)));
  }

}
