<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;

/**
 * The attachment store: the files a conversation holds, by name.
 *
 * A file that reached the lane on a conversation's behalf (a chat
 * attachment, a render shot, bytes the model declared) is held here under
 * the conversation's thread id. Holding means two things: a file.usage row
 * (module daedalus_ai, type thread, id the thread), which core answers by
 * making the file permanent (a used file is permanent) so temporary-file
 * garbage collection cannot take it while the thread lives, and a name
 * (the lane's clean filename, renamed on collision, or the name a
 * declaration gave) recorded as the file's alias in the dialect's alias
 * layer for that conversation, which
 * is how the model addresses it afterwards (`view_file("hero.svg")`,
 * `file="hero.svg"`, `url('file:hero.svg')`). The model never sees a file
 * uuid; a name resolves only for the file's owner, never for an arbitrary
 * uuid. attach() settles the name at once (nothing else will); hold()
 * leaves it to the batch that declared the file.
 *
 * The store also answers WHERE a conversation's files are saved:
 * directory() is one directory per conversation and user, named by a long
 * random token minted here and kept under the thread, never derived from
 * the thread id or anything else a reader of a page could know. Every
 * writer (the attach route, a declaration, a render shot) asks for it and
 * hands it to the file lane; the files stay public, and a file's address is
 * known only to whoever was handed it. The token is forgotten with the
 * thread's last file, so the conversation that reuses a thread id after
 * Clear History writes somewhere new.
 *
 * Release mirrors the hold. The chat's Clear History releases eagerly (the
 * ai_chatbot reset hands the same thread id straight back to the next
 * conversation, so anything left behind would bleed into it), and the cron
 * sweep releases the threads nobody cleared: a thread whose session is gone
 * from ai_assistant_api's private tempstore (reset, or expired with the
 * store) no longer needs its files. A file younger than the grace is never
 * swept, because the session is only written by the thread's first message
 * and an attachment may arrive before it. A released file nothing else uses
 * (no media minted from it) is set temporary again here, since core's usage
 * delete leaves it permanent unless the site opted into
 * make_unused_managed_files_temporary, and a file only a conversation held
 * is the conversation's to let go.
 */
final class Attachments {

  /**
   * The file.usage module and type under which a thread holds a file.
   */
  public const USAGE_MODULE = 'daedalus_ai';
  public const USAGE_TYPE = 'thread';

  /**
   * The directory the per-conversation directories sit in.
   */
  private const ROOT = 'public://daedalus-ai/attachments';

  /**
   * The key-value collection keeping each conversation's directory token.
   *
   * Keyed "<uid>:<thread id>", the shape of the thread's session key, so two
   * users naming one thread id never share a directory.
   */
  private const DIRECTORY_COLLECTION = 'daedalus_ai.attachment_directory';

  /**
   * The ai_assistant_api private tempstore collection holding thread sessions.
   *
   * AiAssistantApiRunner::getTempStore() opens 'ai_assistant_api'; the
   * private tempstore factory prefixes the key-value collection and stores a
   * user's thread under "<uid>:<thread id>".
   */
  private const SESSION_COLLECTION = 'tempstore.private.ai_assistant_api';

  /**
   * How young a held file must be to survive a sweep whose thread is absent.
   */
  public const SWEEP_GRACE = 3600;

  public function __construct(
    private readonly FileUsageInterface $fileUsage,
    private readonly DialectAliasMap $aliasMap,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * The directory the current user's files of a thread are saved in.
   *
   * The one answer every writer hands the file lane. The name is 32 random
   * bytes, minted on the thread's first ask and kept until the thread's
   * files are released; the lane creates the directory when it saves.
   *
   * @param string $thread
   *   The conversation's thread id.
   *
   * @return string
   *   The directory's URI, without a trailing slash.
   */
  public function directory(string $thread): string {
    $tokens = $this->keyValue->get(self::DIRECTORY_COLLECTION);
    $key = $this->currentUser->id() . ':' . $thread;
    // Two first writes may race: whichever token lands is the one both use.
    $tokens->setIfNotExists($key, Crypt::randomBytesBase64(32));
    return self::ROOT . '/' . $tokens->get($key);
  }

  /**
   * Holds a saved file for a thread under its filename, settled at once.
   *
   * For a file held outside any batch (a chat attachment, a render shot):
   * no batch is open, and the alias layer settles only with the outermost
   * batch, so the name is flushed here or dies with the request.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file the lane saved.
   * @param string $thread
   *   The conversation's thread id.
   *
   * @return string
   *   The name the file answers to in this conversation: the lane's clean
   *   filename (renamed on collision).
   */
  public function attach(FileInterface $file, string $thread): string {
    $name = $this->hold($file, $thread);
    $this->aliasMap->persist();
    return $name;
  }

  /**
   * Holds a saved file for a thread on the alias overlay, unsettled.
   *
   * For a file held inside a batch (a declaration the model wrote in a
   * page_edit batch or a page_create document): the usage row lands now,
   * the name lands on the overlay, and the outermost batch settles the
   * overlay (BlueprintOperations::batchOperations(): persisted when the
   * gesture stands, discarded when it rolls back). Never call persist()
   * on this road; a held-but-unnamed file (its batch failed) is released
   * with the thread's other files.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file the lane saved.
   * @param string $thread
   *   The conversation's thread id.
   * @param string|null $name
   *   The name to hold the file under (a declaration's own name, whatever
   *   the lane saved it as), or NULL for the lane's clean filename (renamed
   *   on collision).
   *
   * @return string
   *   The name the file answers to in this conversation.
   */
  public function hold(FileInterface $file, string $thread, ?string $name = NULL): string {
    $this->fileUsage->add($file, self::USAGE_MODULE, self::USAGE_TYPE, $thread);
    $name ??= $file->getFilename();
    $this->aliasMap->recordFile($thread, $name, $file->uuid());
    return $name;
  }

  /**
   * Resolves a name to the current user's file in a thread.
   *
   * @param string $thread
   *   The conversation's thread id.
   * @param string $name
   *   The name the file was attached under.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file, or NULL when the thread holds no such name or the file is
   *   not the current user's.
   */
  public function resolve(string $thread, string $name): ?FileInterface {
    $uuid = $this->aliasMap->resolveFile($thread, $name);
    if ($uuid === NULL) {
      return NULL;
    }
    $file = $this->loadByUuid($uuid);
    if ($file === NULL || (int) $file->getOwnerId() !== (int) $this->currentUser->id()) {
      return NULL;
    }
    return $file;
  }

  /**
   * Releases a thread's files: usage dropped, names forgotten.
   *
   * @param string $thread
   *   The conversation's thread id.
   * @param \Drupal\Core\Session\AccountInterface|null $owner
   *   When given, only that account's files are released (the chat's Clear
   *   History acts for one user); NULL releases every file the thread holds
   *   (the sweep).
   *
   * @return int
   *   How many files were released.
   */
  public function release(string $thread, ?AccountInterface $owner = NULL): int {
    $released = 0;
    $owners = $owner === NULL ? [] : [(int) $owner->id()];
    foreach ($this->heldFiles($thread) as $file) {
      if ($owner !== NULL && (int) $file->getOwnerId() !== (int) $owner->id()) {
        continue;
      }
      $this->letGo($file, $thread);
      $owners[] = (int) $file->getOwnerId();
      $released++;
    }
    // Every file of these owners in the thread is let go, so their
    // directory is too: the next conversation on this thread id mints its
    // own.
    $tokens = $this->keyValue->get(self::DIRECTORY_COLLECTION);
    foreach (array_unique($owners) as $uid) {
      $tokens->delete($uid . ':' . $thread);
    }
    if ($released > 0) {
      $this->aliasMap->persist();
    }
    return $released;
  }

  /**
   * Releases every held file whose thread is gone (the cron sweep).
   *
   * A thread is gone when its owner's session is absent from
   * ai_assistant_api's private tempstore: the chat reset deleted it or it
   * expired with the store. A file younger than SWEEP_GRACE is kept
   * regardless, since a session exists only after the thread's first
   * message and an attachment may precede it. A directory token whose
   * thread holds no file of its user afterwards is forgotten with them.
   *
   * @return int
   *   How many files were released.
   */
  public function sweep(): int {
    $sessions = $this->keyValueExpirable->get(self::SESSION_COLLECTION);
    $cutoff = $this->time->getRequestTime() - self::SWEEP_GRACE;
    $released = 0;
    foreach ($this->heldFilesByThread() as $thread => $files) {
      foreach ($files as $file) {
        if ($file->getCreatedTime() > $cutoff) {
          continue;
        }
        if ($sessions->has($file->getOwnerId() . ':' . $thread)) {
          continue;
        }
        $this->letGo($file, $thread);
        $released++;
      }
    }
    if ($released > 0) {
      $this->aliasMap->persist();
    }
    $this->forgetEmptyDirectories();
    return $released;
  }

  /**
   * A file's bytes, read from its uri.
   *
   * The one read every surface that delivers a file to the model uses (the
   * ATTACHMENTS block, view_file); a file whose bytes are gone is an error,
   * never an empty delivery.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file.
   * @param int|null $limit
   *   The most bytes to read from the file's start, NULL for all of it.
   *
   * @throws \RuntimeException
   *   When the file cannot be read.
   */
  public function bytes(FileInterface $file, ?int $limit = NULL): string {
    $bytes = file_get_contents($file->getFileUri(), FALSE, NULL, 0, $limit);
    if ($bytes === FALSE) {
      throw new \RuntimeException(sprintf('File %s could not be read from %s.', $file->getFilename(), $file->getFileUri()));
    }
    return $bytes;
  }

  /**
   * Drops a thread's hold on a file: usage, name, and permanence if unused.
   */
  private function letGo(FileInterface $file, string $thread): void {
    $this->fileUsage->delete($file, self::USAGE_MODULE, self::USAGE_TYPE, $thread);
    $this->aliasMap->forgetFile($thread, $file->uuid());
    if ($file->isPermanent() && $this->fileUsage->listUsage($file) === []) {
      $file->setTemporary();
      $file->save();
    }
  }

  /**
   * Forgets the directory token of every thread that holds no file.
   *
   * Covers the threads the sweep just emptied and a token minted for a
   * write the lane then refused. A thread that writes again mints a new
   * token, so forgetting early costs nothing but a second directory.
   */
  private function forgetEmptyDirectories(): void {
    $tokens = $this->keyValue->get(self::DIRECTORY_COLLECTION);
    $keys = array_keys($tokens->getAll());
    if ($keys === []) {
      return;
    }
    $holding = [];
    foreach ($this->heldFilesByThread() as $thread => $files) {
      foreach ($files as $file) {
        $holding[$file->getOwnerId() . ':' . $thread] = TRUE;
      }
    }
    $tokens->deleteMultiple(array_values(array_filter($keys, fn ($key): bool => !isset($holding[$key]))));
  }

  /**
   * The files a thread holds.
   *
   * @return list<\Drupal\file\FileInterface>
   *   The held files.
   */
  private function heldFiles(string $thread): array {
    return $this->heldFilesByThread($thread)[$thread] ?? [];
  }

  /**
   * The held files, grouped by thread.
   *
   * Core's file usage service lists usage per file only; the sweep and the
   * release need the other direction, so the usage table is read for this
   * module's rows.
   *
   * @param string|null $thread
   *   One thread, or NULL for every thread.
   *
   * @return array<string, list<\Drupal\file\FileInterface>>
   *   Files keyed by thread id (core deletes a file's usage rows with the
   *   file, so every row loads).
   */
  private function heldFilesByThread(?string $thread = NULL): array {
    $query = $this->database->select('file_usage', 'u')
      ->fields('u', ['fid', 'id'])
      ->condition('u.module', self::USAGE_MODULE)
      ->condition('u.type', self::USAGE_TYPE);
    if ($thread !== NULL) {
      $query->condition('u.id', $thread);
    }
    $rows = $query->execute()->fetchAll();
    if ($rows === []) {
      return [];
    }
    $files = $this->entityTypeManager->getStorage('file')->loadMultiple(array_column($rows, 'fid'));
    $held = [];
    foreach ($rows as $row) {
      $held[$row->id][] = $files[$row->fid];
    }
    return $held;
  }

  /**
   * Loads a file by uuid.
   */
  private function loadByUuid(string $uuid): ?FileInterface {
    $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uuid' => $uuid]);
    $file = reset($files);
    return $file instanceof FileInterface ? $file : NULL;
  }

}
