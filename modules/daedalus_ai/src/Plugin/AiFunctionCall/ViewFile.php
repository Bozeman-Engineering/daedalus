<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\FileKind;
use Drupal\daedalus_ai\Render\ToolImagesResult;
use Drupal\daedalus_blueprint_ai\ConversationContext;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-opens a file the conversation holds, or a media item's source file.
 *
 * Bytes reach the model once, in the leg they arrive (the ATTACHMENTS
 * block, a render_page shot), and the memory runner strips them after
 * every leg; what survives is the name. This tool is the road back from a
 * name to the bytes, by the one kind split every file surface shares
 * (FileKind): a raster image comes back through the ai_agents image
 * contract (`_images`, delivered on the next turn), a textual file (SVG,
 * CSS, CSV, HTML, JSON, text) comes back as its text, a PDF is refused
 * (tool results carry images and text only; the model saw it in the turn it
 * was attached), anything else is named and not delivered.
 *
 * A name resolves through the attachment store for the current
 * conversation and the current user only, so the model can never open a
 * file it was not handed. The `media:<uuid>` spelling, the one prints
 * already use, opens the source file of a media item the user may view,
 * which is how a generated image is inspected.
 */
#[FunctionCall(
  id: 'daedalus_ai:view_file',
  function_name: 'view_file',
  name: 'View File',
  description: 'Re-opens a file by name: one the user attached (named in the ATTACHMENTS block) or one render_page kept (named in its result). A raster image arrives as an image on your next turn; a textual file (SVG, CSS, CSV, HTML, JSON, text) is returned as text. PDFs cannot be re-opened; ask the user to attach one again. Pass media:<uuid> to open the source file of a media item (e.g. a generated image) instead.',
  group: 'information_tools',
  context_definitions: [
    'name' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Name'),
      required: TRUE,
      description: new TranslatableMarkup("The file's name exactly as the ATTACHMENTS block or render_page gave it (e.g. hero.svg), or media:<uuid> for a media item's source file."),
    ),
  ],
)]
final class ViewFile extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The spelling that addresses a media item's source file.
   */
  public const MEDIA_PREFIX = 'media:';

  /**
   * The attachment store the names resolve through.
   */
  protected Attachments $attachments;

  /**
   * The conversation whose files the names address.
   */
  protected ConversationContext $conversationContext;

  /**
   * The entity repository, for media by uuid.
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The image result, delivered via the ai_agents image contract.
   */
  protected ?ToolImagesResult $result = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->attachments = $container->get('daedalus_ai.attachments');
    $instance->conversationContext = $container->get('daedalus_blueprint_ai.conversation_context');
    $instance->entityRepository = $container->get('entity.repository');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $name = trim((string) $this->getContextValue('name'));
    if ($name === '') {
      $this->setOutput('Name the file to open: a name from the ATTACHMENTS block or a render_page result, or media:<uuid>.');
      return;
    }

    if (str_starts_with($name, self::MEDIA_PREFIX)) {
      $file = $this->mediaSourceFile(substr($name, strlen(self::MEDIA_PREFIX)));
      if (is_string($file)) {
        $this->setOutput($file);
        return;
      }
    }
    else {
      $thread = $this->conversationContext->get();
      if ($thread === '') {
        $this->setOutput('view_file only works from the page builder chat: this run has no conversation holding files.');
        return;
      }
      $file = $this->attachments->resolve($thread, $name);
      if ($file === NULL) {
        $this->setOutput("No file named \"$name\" in this conversation. Use the exact name from the ATTACHMENTS block or the render_page result; if the user meant a file they have not attached, ask them to attach it.");
        return;
      }
    }

    $this->deliver($file, $name);
  }

  /**
   * Hands the file to the model by its kind.
   *
   * @param \Drupal\file\FileInterface $file
   *   The resolved file.
   * @param string $name
   *   The name the model asked for, echoed so the answer pairs with the ask.
   */
  protected function deliver(FileInterface $file, string $name): void {
    $mime = (string) $file->getMimeType();
    $head = sprintf('%s (%s, %s)', $name, $mime, ByteSizeMarkup::create((int) $file->getSize()));
    switch (FileKind::fromMime($mime)) {
      case FileKind::Text:
        $this->setOutput($head . ":\n<file name=\"" . $file->getFilename() . "\">\n" . rtrim($this->attachments->bytes($file), "\n") . "\n</file>");
        return;

      case FileKind::Image:
        $this->result = new ToolImagesResult([
          new ImageFile($this->attachments->bytes($file), $mime, $file->getFilename()),
        ]);
        $this->setOutput($head . ' arrives as an image on your next turn.');
        return;

      case FileKind::Pdf:
        $this->setOutput($head . ': PDFs cannot be re-opened; ask the user to attach it again if you need it.');
        return;

      case FileKind::Other:
        $this->setOutput($head . ' is not a kind you can read.');
        return;
    }
  }

  /**
   * The source file of a media item the current user may view.
   *
   * @return \Drupal\file\FileInterface|string
   *   The file, or the sentence explaining why there is none.
   */
  protected function mediaSourceFile(string $uuid): FileInterface|string {
    $media = $uuid === '' ? NULL : $this->entityRepository->loadEntityByUuid('media', $uuid);
    if (!$media instanceof MediaInterface || !$media->access('view')) {
      return "No media item \"$uuid\" you can view.";
    }
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
    $file = $source_field === NULL ? NULL : $media->get($source_field->getName())->entity;
    if (!$file instanceof FileInterface) {
      return sprintf('Media item "%s" (%s) has no file of its own to open.', $media->label(), $media->bundle());
    }
    return $file;
  }

  /**
   * Half of ai_agents' duck-typed tool image contract.
   *
   * @see \Drupal\daedalus_ai\Render\ToolImagesResult
   */
  public function getPluginInstance(): static {
    return $this;
  }

  /**
   * The other half: the result whose _images reach the model.
   *
   * @see \Drupal\daedalus_ai\Render\ToolImagesResult
   */
  public function getResult(): ?ToolImagesResult {
    return $this->result;
  }

}
