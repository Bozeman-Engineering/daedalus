<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The alias layer's files namespace: names over file uuids, per conversation.
 *
 * The element namespace's lifecycle is pinned end to end through the op
 * spine in DomOperationsTest; this class pins the files namespace on the
 * map itself: a recording resolves and lists, only within its
 * conversation; the overlay answers before persist() (an op later in a
 * batch sees a file declared earlier in it) while tempstore still holds
 * nothing; discard() drops the overlay and leaves tempstore untouched (a
 * rolled back batch leaves no file alias behind); persist() writes the
 * map under `<conversation>|file` and a fresh map instance reads it back;
 * forgetFile() hides a released file before persist() and drops it from
 * tempstore after; and the element namespace never sees a file pairing
 * or a file removal, nor the reverse.
 */
#[CoversClass(DialectAliasMap::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DialectAliasMapFilesTest extends BlueprintKernelTestBase {

  private const COLLECTION = 'daedalus_blueprint.dialect_aliases';

  /**
   * The alias map under test (the container's instance).
   */
  protected DialectAliasMap $map;

  /**
   * A host entity for the element namespace.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->map = $this->container->get('daedalus_blueprint.dialect_alias_map');
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Host']);
    $this->entity->save();
  }

  /**
   * Record and resolve: a name answers its file uuid in its conversation only.
   */
  public function testRecordAndResolve(): void {
    $hero = $this->uuid();
    $icon = $this->uuid();

    $this->assertNull($this->map->resolveFile('conv-1', 'hero.svg'));
    $this->assertSame([], $this->map->fileAliases('conv-1'));

    $this->map->recordFile('conv-1', 'hero.svg', $hero);
    $this->map->recordFile('conv-1', 'icon.svg', $icon);

    $this->assertSame($hero, $this->map->resolveFile('conv-1', 'hero.svg'));
    $this->assertSame($icon, $this->map->resolveFile('conv-1', 'icon.svg'));
    $this->assertSame(['hero.svg' => $hero, 'icon.svg' => $icon], $this->map->fileAliases('conv-1'));

    // Names are exact: the lane's collision rename keeps hero-2.svg apart.
    $this->assertNull($this->map->resolveFile('conv-1', 'hero-2.svg'));
    $this->assertNull($this->map->resolveFile('conv-1', 'Hero.svg'));

    // Another conversation shares nothing.
    $this->assertNull($this->map->resolveFile('conv-2', 'hero.svg'));
    $this->assertSame([], $this->map->fileAliases('conv-2'));

    // Re-recording a name repoints it (the newest declaration wins).
    $again = $this->uuid();
    $this->map->recordFile('conv-1', 'hero.svg', $again);
    $this->assertSame($again, $this->map->resolveFile('conv-1', 'hero.svg'));
  }

  /**
   * Within a batch the overlay answers while tempstore still holds nothing.
   */
  public function testOverlayVisibleWithinBatch(): void {
    $hero = $this->uuid();
    $this->map->recordFile('conv-1', 'hero.svg', $hero);

    $this->assertSame($hero, $this->map->resolveFile('conv-1', 'hero.svg'));
    $this->assertNull($this->stored('conv-1|file'));

    // A second instance (the next request's fresh process) sees nothing
    // until the batch settles.
    $this->assertNull($this->freshMap()->resolveFile('conv-1', 'hero.svg'));
  }

  /**
   * Discard drops the overlay: a rolled back batch leaves no file alias.
   */
  public function testDiscardDrops(): void {
    $kept = $this->uuid();
    $this->map->recordFile('conv-1', 'kept.svg', $kept);
    $this->map->persist();

    $this->map->recordFile('conv-1', 'rolled.svg', $this->uuid());
    $this->map->forgetFile('conv-1', $kept);
    $this->assertNull($this->map->resolveFile('conv-1', 'kept.svg'));
    $this->assertSame(['rolled.svg'], array_keys($this->map->fileAliases('conv-1')));

    $this->map->discard();

    // The rolled back recording and removal are both gone; what the
    // earlier batch persisted stands untouched.
    $this->assertNull($this->map->resolveFile('conv-1', 'rolled.svg'));
    $this->assertSame($kept, $this->map->resolveFile('conv-1', 'kept.svg'));
    $this->assertSame(['kept.svg' => $kept], $this->stored('conv-1|file'));
  }

  /**
   * Persist keeps: the map lands under `<conversation>|file` in tempstore.
   */
  public function testPersistKeeps(): void {
    $hero = $this->uuid();
    $icon = $this->uuid();
    $this->map->recordFile('conv-1', 'hero.svg', $hero);
    $this->map->recordFile('conv-2', 'icon.svg', $icon);
    $this->map->persist();

    $this->assertSame(['hero.svg' => $hero], $this->stored('conv-1|file'));
    $this->assertSame(['icon.svg' => $icon], $this->stored('conv-2|file'));

    // A fresh instance reads the pairing back from tempstore alone.
    $fresh = $this->freshMap();
    $this->assertSame($hero, $fresh->resolveFile('conv-1', 'hero.svg'));
    $this->assertSame($icon, $fresh->resolveFile('conv-2', 'icon.svg'));
    $this->assertNull($fresh->resolveFile('conv-1', 'icon.svg'));

    // The overlay is empty after persist: a discard changes nothing.
    $this->map->discard();
    $this->assertSame($hero, $this->map->resolveFile('conv-1', 'hero.svg'));

    // A later batch layers onto the persisted map rather than replacing it.
    $more = $this->uuid();
    $this->map->recordFile('conv-1', 'more.csv', $more);
    $this->map->persist();
    $this->assertSame(['hero.svg' => $hero, 'more.csv' => $more], $this->stored('conv-1|file'));
  }

  /**
   * Forget: a released file's names stop resolving, then leave tempstore.
   */
  public function testForgetFile(): void {
    $hero = $this->uuid();
    $this->map->recordFile('conv-1', 'hero.svg', $hero);
    $this->map->recordFile('conv-1', 'same.svg', $hero);
    $this->map->recordFile('conv-1', 'other.svg', $this->uuid());
    $this->map->persist();

    $this->map->forgetFile('conv-1', $hero);
    $this->assertNull($this->map->resolveFile('conv-1', 'hero.svg'));
    $this->assertNull($this->map->resolveFile('conv-1', 'same.svg'));
    $this->assertSame(['other.svg'], array_keys($this->map->fileAliases('conv-1')));
    // Tempstore still carries it until the batch settles.
    $this->assertSame(['hero.svg', 'same.svg', 'other.svg'], array_keys($this->stored('conv-1|file')));

    $this->map->persist();
    $this->assertSame(['other.svg'], array_keys($this->stored('conv-1|file')));
    $this->assertNull($this->freshMap()->resolveFile('conv-1', 'hero.svg'));

    // Forgetting an overlay-only pairing drops it before it ever lands.
    $draft = $this->uuid();
    $this->map->recordFile('conv-1', 'draft.svg', $draft);
    $this->map->forgetFile('conv-1', $draft);
    $this->map->persist();
    $this->assertSame(['other.svg'], array_keys($this->stored('conv-1|file')));

    // Recording after a forget resurrects the uuid under the new name.
    $this->map->forgetFile('conv-1', $hero);
    $this->map->recordFile('conv-1', 'hero-again.svg', $hero);
    $this->assertSame($hero, $this->map->resolveFile('conv-1', 'hero-again.svg'));
  }

  /**
   * The element namespace is untouched by the files namespace and vice versa.
   */
  public function testElementNamespaceUntouched(): void {
    $element = $this->uuid();
    $file = $this->uuid();

    // The same word in both namespaces of one conversation.
    $this->map->record($this->host($this->entity), 'conv-1', 'hero', $element);
    $this->map->recordFile('conv-1', 'hero', $file);

    $this->assertSame(['hero' => $element], $this->map->aliases($this->host($this->entity), 'conv-1'));
    $this->assertSame(['hero' => $file], $this->map->fileAliases('conv-1'));
    $this->assertSame($element, $this->map->resolve($this->host($this->entity), 'conv-1', 'hero'));
    $this->assertSame($file, $this->map->resolveFile('conv-1', 'hero'));
    $this->assertSame([$element => 'hero'], $this->map->printAliases($this->host($this->entity), 'conv-1'));

    // One persist settles both, each under its own key.
    $this->map->persist();
    $this->assertSame(['hero' => $element], $this->stored('conv-1|entity_test|' . $this->entity->uuid()));
    $this->assertSame(['hero' => $file], $this->stored('conv-1|file'));

    // A file removal never reaches the element map, nor the reverse, even
    // when the uuid were the same.
    $shared = $this->uuid();
    $this->map->record($this->host($this->entity), 'conv-1', 'twin', $shared);
    $this->map->recordFile('conv-1', 'twin.png', $shared);
    $this->map->forgetFile('conv-1', $shared);
    $this->assertSame($shared, $this->map->resolve($this->host($this->entity), 'conv-1', 'twin'));
    $this->assertNull($this->map->resolveFile('conv-1', 'twin.png'));

    $this->map->recordFile('conv-1', 'twin.png', $shared);
    $this->map->forget($this->host($this->entity), 'conv-1', $shared);
    $this->assertNull($this->map->resolve($this->host($this->entity), 'conv-1', 'twin'));
    $this->assertSame($shared, $this->map->resolveFile('conv-1', 'twin.png'));

    $this->map->persist();
    $this->assertSame(['hero' => $element], $this->stored('conv-1|entity_test|' . $this->entity->uuid()));
    $this->assertSame(['hero' => $file, 'twin.png' => $shared], $this->stored('conv-1|file'));
  }

  /**
   * A fresh map over the same tempstore: the next request's view.
   */
  protected function freshMap(): DialectAliasMap {
    return new DialectAliasMap($this->container->get('tempstore.shared'));
  }

  /**
   * What tempstore holds under a key, or NULL.
   */
  protected function stored(string $key): ?array {
    return $this->container->get('tempstore.shared')->get(self::COLLECTION)->get($key);
  }

  /**
   * A minted uuid.
   */
  protected function uuid(): string {
    return (new PhpUuid())->generate();
  }

}
