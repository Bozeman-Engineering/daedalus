<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * Saves bytes a batch declares and holds them for its conversation.
 *
 * The page language's authoring form for model-written bytes: a
 * <pb-file name type>...</pb-file> declaration at a document's top level
 * and the `file` op ({name, type, content}) in a page_edit batch. Both
 * reach this seam from DomOperationHandler, which owns the grammar and the
 * error shape but not the file store: the implementer (daedalus_ai's
 * handler over daedalus_ui's file lane and the attachment store) saves the
 * bytes through the one file lane (validators, the FileValidationEvent
 * chain and the SVG sanitizer run) as a temporary file and records the
 * declared name as the file's alias for the conversation ON THE OVERLAY
 * ONLY: the alias layer settles with the outermost batch
 * (BlueprintOperations::batchOperations()), so a rolled-back batch leaves
 * no name behind while later ops in the same batch (file="name",
 * url('file:name')) already resolve it. The implementer never calls
 * DialectAliasMap::persist() itself.
 *
 * Collected onto the DOM operation handler through the
 * daedalus_blueprint.file_declaration_handler service tag. A site with no
 * implementer answers every declaration with a wiring error.
 */
interface FileDeclarationHandlerInterface {

  /**
   * Saves declared bytes and records their name for the conversation.
   *
   * @param string $conversation
   *   The conversation (thread id) that holds the file afterwards.
   * @param string $name
   *   The declared name: the handle the model addresses the file by
   *   (file="name", url('file:name'), view_file("name")). Recorded as the
   *   alias exactly as declared, whatever filename the lane saves under.
   * @param string $type
   *   The declared MIME type; the lane refuses one that disagrees with the
   *   name's extension.
   * @param string $content
   *   The bytes.
   *
   * @throws \Drupal\daedalus_blueprint\Dialect\FileDeclarationException
   *   When the bytes are refused (an extension the site does not accept, a
   *   type that disagrees with the name, a body the sanitizer rejects, the
   *   size ceiling), with one message per reason.
   */
  public function declare(string $conversation, string $name, string $type, string $content): void;

}
