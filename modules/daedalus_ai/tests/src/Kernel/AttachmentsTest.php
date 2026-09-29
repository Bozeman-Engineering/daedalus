<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The attachment store: a thread holds files by name, for their owner.
 *
 * The store is built from its real collaborators (core's file usage, the
 * dialect alias map over shared tempstore, the expirable key-value store
 * the ai_assistant_api private tempstore lives in) without enabling
 * daedalus_ai or daedalus_blueprint: the module's info.yml drags the whole
 * AI stack and the alias map needs nothing but tempstore.shared. The routes
 * over the store are Functional (AttachRouteTest), since a request upload
 * cannot move inside a Kernel test.
 */
#[CoversClass(Attachments::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class AttachmentsTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file'];

  /**
   * The store under test.
   */
  private Attachments $attachments;

  /**
   * The alias map the store records into.
   */
  private DialectAliasMap $aliasMap;

  /**
   * The user attaching.
   */
  private UserInterface $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'user']);
    $this->owner = $this->setUpCurrentUser(['name' => 'owner']);
    $this->aliasMap = new DialectAliasMap($this->container->get('tempstore.shared'));
    $this->attachments = $this->buildStore($this->aliasMap);
  }

  /**
   * Attach holds, the owner resolves, another user does not, release drops.
   */
  public function testHoldResolveRelease(): void {
    $file = $this->saveFile('hero.svg', $this->owner);
    $name = $this->attachments->attach($file, 'thread-a');
    $this->assertSame('hero.svg', $name);
    $this->assertSame(['daedalus_ai' => ['thread' => ['thread-a' => '1']]], $this->usage($file));
    // Core makes a used file permanent: that is what keeps GC off it.
    $this->assertTrue($this->reload($file)->isPermanent());

    // The owner resolves the name, in this thread only, and the recording
    // survived the request (a fresh map over the store reads it).
    $this->assertSame($file->id(), $this->attachments->resolve('thread-a', 'hero.svg')?->id());
    $this->assertNull($this->attachments->resolve('thread-b', 'hero.svg'));
    $this->assertNull($this->attachments->resolve('thread-a', 'other.svg'));
    $fresh = new DialectAliasMap($this->container->get('tempstore.shared'));
    $this->assertSame($file->uuid(), $fresh->resolveFile('thread-a', 'hero.svg'));

    // Another user never resolves it, and releasing as that user releases
    // nothing.
    $other = $this->createUser([], 'other');
    $this->container->get('current_user')->setAccount($other);
    $this->assertNull($this->attachments->resolve('thread-a', 'hero.svg'));
    $this->assertSame(0, $this->attachments->release('thread-a', $other));
    $this->assertSame(['daedalus_ai' => ['thread' => ['thread-a' => '1']]], $this->usage($file));
    $this->container->get('current_user')->setAccount($this->owner);

    // The owner's release drops the usage and the name, and hands the file,
    // which nothing else uses, back to temporary-file collection.
    $this->assertSame(1, $this->attachments->release('thread-a', $this->owner));
    $this->assertSame([], $this->usage($file));
    $this->assertTrue($this->reload($file)->isTemporary());
    $this->assertNull($this->attachments->resolve('thread-a', 'hero.svg'));
    $this->assertNull((new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('thread-a', 'hero.svg'));
    $this->assertSame(0, $this->attachments->release('thread-a'));
  }

  /**
   * Hold names a file on the overlay and leaves settlement to the batch.
   *
   * A declaration inside a batch holds under ITS name (the lane may have
   * saved the file as something else) with the usage row landing at once
   * and the name unsettled: the same request resolves it, a fresh map
   * does not until persist(); release forgets the declared name like any
   * other.
   */
  public function testHoldLeavesSettlementToTheBatch(): void {
    $file = $this->saveFile('logo_0.svg', $this->owner);
    $this->assertSame('logo.svg', $this->attachments->hold($file, 'thread-a', 'logo.svg'));
    $this->assertSame(['daedalus_ai' => ['thread' => ['thread-a' => '1']]], $this->usage($file));
    $this->assertSame($file->id(), $this->attachments->resolve('thread-a', 'logo.svg')?->id());
    $this->assertNull($this->attachments->resolve('thread-a', 'logo_0.svg'), 'The saved filename is not the name.');
    $this->assertNull((new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('thread-a', 'logo.svg'), 'Unsettled until the batch persists.');
    $this->aliasMap->persist();
    $this->assertSame($file->uuid(), (new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('thread-a', 'logo.svg'));
    // Without a name the saved filename is the name (attach's road).
    $other = $this->saveFile('shot.png', $this->owner);
    $this->assertSame('shot.png', $this->attachments->hold($other, 'thread-a'));
    $this->assertSame(2, $this->attachments->release('thread-a', $this->owner));
    $this->assertNull($this->attachments->resolve('thread-a', 'logo.svg'));
    $this->assertNull((new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile('thread-a', 'logo.svg'));
  }

  /**
   * The sweep releases old files of threads whose session is gone.
   */
  public function testSweep(): void {
    $old = $this->container->get('datetime.time')->getRequestTime() - Attachments::SWEEP_GRACE - 1;
    $abandoned = $this->saveFile('abandoned.png', $this->owner, $old);
    $live = $this->saveFile('live.png', $this->owner, $old);
    $young = $this->saveFile('young.png', $this->owner);
    $this->attachments->attach($abandoned, 'thread-gone');
    $this->attachments->attach($live, 'thread-live');
    $this->attachments->attach($young, 'thread-new');

    // The live thread's session, as ai_assistant_api's private tempstore
    // writes it for this owner.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $sessions */
    $sessions = $this->container->get('keyvalue.expirable')->get('tempstore.private.ai_assistant_api');
    $sessions->setWithExpire($this->owner->id() . ':thread-live', ['messages' => []], 600);

    $this->assertSame(1, $this->attachments->sweep());
    $this->assertSame([], $this->usage($abandoned));
    $this->assertTrue($this->reload($abandoned)->isTemporary());
    $this->assertTrue($this->reload($live)->isPermanent());
    $this->assertNull($this->attachments->resolve('thread-gone', 'abandoned.png'));
    $this->assertSame(['daedalus_ai' => ['thread' => ['thread-live' => '1']]], $this->usage($live));
    $this->assertSame($live->id(), $this->attachments->resolve('thread-live', 'live.png')?->id());
    $this->assertSame(['daedalus_ai' => ['thread' => ['thread-new' => '1']]], $this->usage($young));
    $this->assertSame($young->id(), $this->attachments->resolve('thread-new', 'young.png')?->id());

    // A second sweep finds nothing further.
    $this->assertSame(0, $this->attachments->sweep());
  }

  /**
   * Builds the store from the container's real collaborators.
   */
  private function buildStore(DialectAliasMap $alias_map): Attachments {
    return new Attachments(
      $this->container->get('file.usage'),
      $alias_map,
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
  }

  /**
   * Saves a temporary file the way the lane leaves one.
   */
  private function saveFile(string $name, UserInterface $owner, ?int $created = NULL): FileInterface {
    $uri = 'public://' . $name;
    file_put_contents($uri, 'bytes');
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'uid' => $owner->id(),
      'status' => 0,
      'created' => $created ?? $this->container->get('datetime.time')->getRequestTime(),
    ]);
    $file->save();
    return $file;
  }

  /**
   * The file's usage as core lists it.
   */
  private function usage(FileInterface $file): array {
    return $this->container->get('file.usage')->listUsage($file);
  }

  /**
   * The file as stored now.
   */
  private function reload(FileInterface $file): FileInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('file');
    $storage->resetCache([$file->id()]);
    return $storage->load($file->id());
  }

}
