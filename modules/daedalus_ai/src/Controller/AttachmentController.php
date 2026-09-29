<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The chat's attachment routes: the lane's HTTP adapter for a conversation.
 *
 * The attach route takes one multipart file and the thread it belongs to,
 * saves it temporary through the one file lane (validators, the
 * FileValidationEvent chain with the SVG sanitizer) and holds it in the
 * attachment store; the answer is the name the model addresses it by. The
 * release route is the eager counterpart the chat's Clear History runs
 * before ai_chatbot's reset, which hands the same thread id back to the next
 * conversation.
 */
final class AttachmentController extends ControllerBase {

  public function __construct(
    private readonly FileLane $fileLane,
    private readonly Attachments $attachments,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_ui.file_lane'),
      $container->get('daedalus_ai.attachments'),
    );
  }

  /**
   * Saves a multipart `file` for the `thread_id` and holds it.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {name, mime, size}: the saved filename (renamed on collision, so it is
   *   the name to use), the stored MIME type and the size in bytes. A file
   *   the lane refuses is a 400 naming the reasons.
   */
  public function attach(Request $request): JsonResponse {
    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      throw new BadRequestHttpException('The request carries no file.');
    }
    $thread = $this->threadId($request);
    try {
      $file = $this->fileLane->fromRequest($upload, Attachments::DIRECTORY);
    }
    catch (FileLaneException $e) {
      throw new BadRequestHttpException($e->getMessage(), $e);
    }
    $name = $this->attachments->attach($file, $thread);
    return new JsonResponse([
      'name' => $name,
      'mime' => $file->getMimeType(),
      'size' => (int) $file->getSize(),
    ]);
  }

  /**
   * Releases the current user's files held by the `thread_id`.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {released}: how many files were released.
   */
  public function release(Request $request): JsonResponse {
    $released = $this->attachments->release($this->threadId($request), $this->currentUser());
    return new JsonResponse(['released' => $released]);
  }

  /**
   * The request's thread id, from the form body or a JSON body.
   */
  private function threadId(Request $request): string {
    $thread = $request->request->get('thread_id');
    if ($thread === NULL && $request->getContentTypeFormat() === 'json') {
      $thread = $request->toArray()['thread_id'] ?? NULL;
    }
    if (!is_string($thread) || $thread === '') {
      throw new BadRequestHttpException('The request names no thread_id.');
    }
    return $thread;
  }

}
