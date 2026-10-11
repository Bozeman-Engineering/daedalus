<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_stylish\Controller\FontApiController;
use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the font entity: identity, faces, files, access, the gestures.
 *
 * A font is a self-hosted family, the type material, on the animation
 * pattern. Its faces store canonical (weights and styles folded, defaults
 * filled, sorted); its files enter through the font store (the daedalus_ui
 * file lane narrowed to woff2 and woff, permanent) and are held by file
 * usage for the font's life. Access follows the swatch governance grid,
 * the repository resolves references and the published set once per
 * request, and the material gestures (save_material, update_material,
 * remove_material with kind font) ride the provider with the roster,
 * the delta and the schema following.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishFontTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
    // Ships test_sans and test_serif with their files, never synced here
    // (enableModules() runs no install hook): the living disk source the
    // distro lock reads.
    'daedalus_stylish_fonts_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('daedalus_font');
    // The roster reads every kind the provider serves, so the gesture
    // legs need the sibling entity types and their usage tables too.
    foreach (['daedalus_skin', 'daedalus_swatch', 'daedalus_variant', 'daedalus_animation'] as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Saves font bytes through the font store.
   */
  protected function fontFile(string $name = 'inter.woff2'): FileInterface {
    return $this->container->get('daedalus_stylish.font_files')->fromBytes($name, 'wOF2' . str_repeat("\0", 16));
  }

  /**
   * Creates and saves a font over one regular face.
   */
  protected function createFont(array $values = [], ?array $faces = NULL): DaedalusStylishFont {
    $font = DaedalusStylishFont::create($values + ['label' => 'Inter', 'family' => 'Inter']);
    $font->setFaces($faces ?? [
      ['weight' => 400, 'style' => 'normal', 'source' => ['file' => $this->fontFile()->uuid()]],
    ]);
    $font->save();
    return $font;
  }

  /**
   * Identity: machine name generation, faces canonical, published default.
   */
  public function testFontIdentity(): void {
    $regular = $this->fontFile('inter-regular.woff2');
    $bold = $this->fontFile('inter-bold.woff');
    $italic = $this->fontFile('inter-italic.woff2');
    $font = DaedalusStylishFont::create(['label' => 'Inter', 'family' => 'Inter']);
    $font->setDescription('The body face.');
    // Faces canonicalize: keywords fold, defaults fill, keys settle, and
    // the list sorts by weight then style whatever order the writer used.
    $font->setFaces([
      ['source' => ['file' => $bold->uuid()], 'weight' => 'bold', 'style' => 'Normal'],
      ['source' => ['file' => $italic->uuid()], 'style' => 'italic'],
      ['source' => ['file' => $regular->uuid()]],
    ]);
    $font->save();

    $this->assertSame('inter', $font->getMachineName());
    $this->assertSame('Inter', $font->getFamily());
    $this->assertTrue($font->isPublished());
    $this->assertFalse($font->isShipped());
    $this->assertSame('The body face.', $font->getDescription());
    $reloaded = $this->container->get('entity_type.manager')->getStorage('daedalus_font')->loadUnchanged($font->id());
    $this->assertInstanceOf(DaedalusStylishFont::class, $reloaded);
    $this->assertSame([
      ['weight' => '400', 'style' => 'normal', 'source' => ['file' => $regular->uuid()]],
      ['weight' => '400', 'style' => 'italic', 'source' => ['file' => $italic->uuid()]],
      ['weight' => '700', 'style' => 'normal', 'source' => ['file' => $bold->uuid()]],
    ], $reloaded->getFaces());
    $this->assertSame([$regular->uuid(), $italic->uuid(), $bold->uuid()], $reloaded->fileUuids());
    // The one derivation rule: the family quoted, quotes escaped.
    $this->assertSame('"Inter"', StyleGuide::fontFamily($font->getFamily()));
    $this->assertSame('"Source Serif"', StyleGuide::fontFamily(' Source Serif '));
    $this->assertSame('"Say \\"Hi\\""', StyleGuide::fontFamily('Say "Hi"'));

    // Collisions suffix like every machine-named daedalus_stylish entity.
    $second = $this->createFont();
    $this->assertSame('inter_2', $second->getMachineName());
  }

  /**
   * A font IS its faces and its family: saving without either is malformed.
   */
  public function testFacesAndFamilyAreRequired(): void {
    try {
      DaedalusStylishFont::create(['label' => 'Empty', 'family' => 'Empty'])->save();
      $this->fail('A face-less font saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('requires at least one face', $e->getMessage());
    }
    try {
      $font = DaedalusStylishFont::create(['label' => 'Nameless']);
      $font->setFaces([['source' => ['file' => $this->fontFile()->uuid()]]]);
      $font->save();
      $this->fail('A family-less font saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('requires a family name', $e->getMessage());
    }
  }

  /**
   * Authored machine names are charset-gated; hyphens are legal.
   */
  public function testMachineNameCharset(): void {
    $font = $this->createFont(['machine_name' => 'inter-display']);
    $this->assertSame('inter-display', $font->getMachineName());

    try {
      $this->createFont(['machine_name' => 'Inter Display!']);
      $this->fail('A charset-unsafe machine name saved.');
    }
    catch (EntityStorageException $e) {
      $this->assertInstanceOf(EntityMalformedException::class, $e->getPrevious());
      $this->assertStringContainsString('not a font machine name', $e->getMessage());
    }
  }

  /**
   * The five-grade permission grid maps onto entity access; view is open.
   */
  public function testAccess(): void {
    // Burn uid 1, the superuser bypass.
    $this->createUser();
    $font = $this->createFont();
    $handler = $this->container->get('entity_type.manager')->getAccessControlHandler('daedalus_font');

    $nobody = $this->createUser();
    $this->assertFalse($font->access('update', $nobody));
    $this->assertFalse($font->access('delete', $nobody));
    $this->assertFalse($handler->createAccess(NULL, $nobody));
    // Naming a font in a stack is permissionless (an ordinary host-entity
    // edit), so anyone may see published fonts: their CSS is public.
    $this->assertTrue($font->access('view', $nobody));

    $creator = $this->createUser(['create fonts']);
    $this->assertTrue($handler->createAccess(NULL, $creator));
    $this->assertFalse($font->access('update', $creator));

    $updater = $this->createUser(['update fonts']);
    $this->assertTrue($font->access('update', $updater));
    $this->assertFalse($font->access('delete', $updater));

    $deleter = $this->createUser(['delete fonts']);
    $this->assertTrue($font->access('delete', $deleter));
    $in_use_deleter = $this->createUser(['delete fonts in use']);
    $this->assertTrue($font->access('delete', $in_use_deleter));

    $admin = $this->createUser(['administer fonts']);
    $this->assertTrue($font->access('update', $admin));
    $this->assertTrue($font->access('delete', $admin));

    // Unpublished fonts hide from everyone but administrators.
    $font->setUnpublished()->save();
    $handler->resetCache();
    $this->assertFalse($font->access('view', $nobody));
    $this->assertTrue($font->access('view', $admin));
  }

  /**
   * The shipped-source lane: one hash over family and faces, two comparisons.
   *
   * The faces hash by CONTENT (the file named by the sha256 of its bytes,
   * FontFiles::contentAddressed()): the site holds a face's file by uuid
   * and a module by basename, and only the bytes cross the disk lane.
   */
  public function testShippedSource(): void {
    $files = $this->container->get('daedalus_stylish.font_files');
    $font = $this->createFont();
    $shipped = ['family' => 'Inter', 'faces' => $files->contentAddressed($font->getFaces())];
    $this->assertSame([
      ['weight' => '400', 'style' => 'normal', 'source' => ['hash' => hash('sha256', 'wOF2' . str_repeat("\0", 16))]],
    ], $shipped['faces']);
    $font->setShippedSource('daedalus_stylish_fonts_test', $shipped)->save();
    $this->assertTrue($font->isShipped());
    $this->assertSame('shipped', $font->getOrigin());
    $this->assertFalse($font->isModified());
    // The hash is over content: key order never matters.
    $this->assertFalse($font->hasNewDefaults(array_reverse($shipped, TRUE)));
    $this->assertTrue($font->hasNewDefaults(['family' => 'Inter Display'] + $shipped));

    $font->setFamily('Inter Display');
    $this->assertTrue($font->isModified());

    // The same bytes under another name are the same face; other bytes
    // under the same name are a modification.
    $font->setFamily('Inter');
    $font->setFaces([['source' => ['file' => $this->fontFile('renamed.woff2')->uuid()]]]);
    $this->assertFalse($font->isModified());
    $font->setFaces([['source' => ['file' => $files->fromBytes('inter.woff2', 'wOF2' . str_repeat("\1", 16))->uuid()]]]);
    $this->assertTrue($font->isModified());
  }

  /**
   * The repository: reference resolution and the published universe.
   */
  public function testRepository(): void {
    $repository = $this->container->get('daedalus_stylish.font_repository');
    $inter = $this->createFont();
    $serif = $this->createFont(['label' => 'Source Serif', 'family' => 'Source Serif']);
    $draft = $this->createFont(['label' => 'Draft', 'family' => 'Draft']);
    $draft->setUnpublished()->save();

    // Machine name first, then uuid; misses memoize as NULL.
    $this->assertSame($inter->id(), $repository->resolve('inter')?->id());
    $this->assertSame($serif->id(), $repository->resolve($serif->uuid())?->id());
    $this->assertNull($repository->resolve('nope'));
    $this->assertNull($repository->resolve('nope'));

    // The published set, label-sorted; drafts are not vocabulary.
    $this->assertSame(['inter', 'source_serif'], array_map(static fn ($font) => $font->getMachineName(), $repository->publishedFonts()));
  }

  /**
   * The file door: the lane narrowed to font kinds, permanent, held by usage.
   */
  public function testFontFiles(): void {
    $files = $this->container->get('daedalus_stylish.font_files');
    $usage = $this->container->get('file.usage');

    $file = $this->fontFile('inter.woff2');
    $this->assertTrue($file->isPermanent(), 'A font file is site vocabulary from the moment it exists.');
    $this->assertStringStartsWith(FontFiles::DIRECTORY . '/', $file->getFileUri());
    $this->assertSame('inter.woff2', $file->getFilename());
    $this->assertSame('woff2', FontFiles::format($file));
    $this->assertSame('woff', FontFiles::format($this->fontFile('inter.woff')));
    $this->assertSame($file->id(), $files->load($file->uuid())?->id());
    $this->assertNull($files->load('11111111-1111-1111-1111-111111111111'));

    // The narrowing: a textual kind the lane admits elsewhere is not a
    // font, and neither is a legacy font format.
    foreach (['notes.txt', 'inter.ttf', 'inter.otf'] as $name) {
      try {
        $files->fromBytes($name, 'bytes');
        $this->fail("$name entered the font store.");
      }
      catch (FileLaneException $e) {
        $this->assertStringContainsString('extension', $e->getMessage());
      }
    }

    // A font holds its files through usage, synced to the faces on every
    // save: a face swap releases the old file and holds the new one, and
    // a delete releases everything.
    $second = $this->fontFile('inter-bold.woff2');
    $font = $this->createFont([], [['source' => ['file' => $file->uuid()]]]);
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$font->id() => '1']]], $usage->listUsage($file));
    $this->assertSame([], $usage->listUsage($second));
    $font->setFaces([['weight' => 700, 'source' => ['file' => $second->uuid()]]]);
    $font->save();
    $this->assertSame([], $usage->listUsage($file));
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$font->id() => '1']]], $usage->listUsage($second));
    // A save that leaves the files alone counts nothing twice.
    $font->setDescription('Bold only.');
    $font->save();
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$font->id() => '1']]], $usage->listUsage($second));
    $font->delete();
    $this->assertSame([], $usage->listUsage($second));
  }

  /**
   * The material gestures: mint, update and remove with kind font.
   */
  public function testGestures(): void {
    // Burn uid 1, then act as an editor holding the three grades.
    $this->createUser();
    $this->setUpCurrentUser([], ['create fonts', 'update fonts', 'delete fonts']);
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $change_set = $this->container->get('daedalus_blueprint.materials_change_set');
    $regular = $this->fontFile('inter-regular.woff2');
    $bold = $this->fontFile('inter-bold.woff2');

    // The gesture line teaches the kind, last in roster order.
    $lines = $roster->gestureLines();
    $this->assertSame('font', array_key_last($lines));
    $this->assertStringContainsString('faces [{weight, style, source: {file: uuid}}]', $lines['font']);

    // Mint: the family defaults to the label, the faces validate, the
    // result carries the entity, the delta the record.
    $result = $provider->capture(MaterialKind::Font, [
      'label' => 'Inter',
      'faces' => [
        ['weight' => 'bold', 'source' => ['file' => $bold->uuid()]],
        ['source' => ['file' => $regular->uuid()]],
      ],
      'description' => 'The body face.',
    ], NULL, []);
    $this->assertSame(MaterialKind::Font, $result->kind);
    $this->assertSame('inter', $result->id);
    $font = $result->entity;
    $this->assertInstanceOf(DaedalusStylishFont::class, $font);
    $this->assertSame('Inter', $font->getFamily());
    $this->assertSame(MaterialRecord::ORIGIN_USER, $font->getOrigin());
    $delta = $change_set->delta();
    $this->assertSame([], $delta['remove']);
    $this->assertCount(1, $delta['upsert']);
    $this->assertSame('font', $delta['upsert'][0]['kind']);
    $this->assertSame('inter', $delta['upsert'][0]['id']);

    // The roster record: value {family, faces}, the quoted family in the
    // font slot, the AI schema's fonts group following.
    $record = $roster->build()->fonts()['inter'] ?? NULL;
    $this->assertInstanceOf(MaterialRecord::class, $record);
    $this->assertSame('Inter', $record->value['family']);
    $this->assertSame(['400', '700'], array_column($record->value['faces'], 'weight'));
    $this->assertSame(['font' => '"Inter"'], $record->spellings);
    $this->assertSame('The body face.', $record->description);
    $this->assertSame($record, $roster->build()->bySpelling(MaterialRecord::SLOT_FONT, '"Inter"'));
    $fragment = $this->container->get('daedalus_blueprint.materials_schema')->fragment();
    $this->assertSame('fonts', array_key_last($fragment));
    $this->assertSame('Inter', $fragment['fonts']['inter']['family']);
    $this->assertSame('font-family: "Inter"', $fragment['fonts']['inter']['spelling']);
    $this->assertCount(2, $fragment['fonts']['inter']['faces']);
    $this->assertStringContainsString('"fonts" are the published self-hosted font families', $fragment['guidance']);

    // Update: a family change, a faces change, one new revision.
    $revision = (int) $font->getRevisionId();
    $provider->update(MaterialKind::Font, 'inter', [
      'family' => 'Inter Variable',
      'faces' => [['weight' => '100 900', 'source' => ['file' => $regular->uuid()]]],
    ], NULL, []);
    $font = $this->container->get('entity_type.manager')->getStorage('daedalus_font')->loadUnchanged($font->id());
    $this->assertInstanceOf(DaedalusStylishFont::class, $font);
    $this->assertSame('Inter Variable', $font->getFamily());
    $this->assertSame([['weight' => '100 900', 'style' => 'normal', 'source' => ['file' => $regular->uuid()]]], $font->getFaces());
    $this->assertGreaterThan($revision, (int) $font->getRevisionId());
    $this->assertSame(['font' => '"Inter Variable"'], $roster->build()->fonts()['inter']->spellings);

    // A bad face refuses in the gesture's own parameter space.
    try {
      $provider->update(MaterialKind::Font, 'inter', [
        'faces' => [['weight' => 'heavy', 'source' => ['file' => $regular->uuid()]]],
      ], NULL, []);
      $this->fail('An invalid weight updated.');
    }
    catch (MaterialGestureException $e) {
      $this->assertSame('INVALID_FONT_WEIGHT', $e->getErrors()[0]->getCode());
      $this->assertSame('faces.0.weight', $e->getErrors()[0]->getPath());
    }

    // The AI's mint meets the ai_minting gate.
    $this->config('daedalus_stylish.settings')->set('ai_minting', FALSE)->save();
    try {
      $provider->capture(MaterialKind::Font, [
        'label' => 'Robot',
        'faces' => [['source' => ['file' => $regular->uuid()]]],
      ], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
      $this->fail('The AI minted through a closed gate.');
    }
    catch (MaterialGestureException $e) {
      $this->assertSame('STYLE_FONT_AI_MINTING_DISABLED', $e->getErrors()[0]->getCode());
    }

    // Remove: gone now, the delta says so.
    $result = $provider->remove(MaterialKind::Font, 'inter', []);
    $this->assertNull($result->entity);
    $this->assertSame(0, $result->staged);
    $this->assertNull($this->container->get('daedalus_stylish.font_repository')->resolve('inter'));
    $this->assertSame([['kind' => 'font', 'id' => 'inter']], $change_set->delta()['remove']);
    try {
      $provider->remove(MaterialKind::Font, 'inter', []);
      $this->fail('A missing font removed.');
    }
    catch (MaterialGestureException $e) {
      $this->assertSame('STYLE_FONT_NOT_FOUND', $e->getErrors()[0]->getCode());
    }

    // A shipped font with a LIVING disk source (the fixture module ships
    // test_sans) is read-only on a consuming site (the distro lock); one
    // whose provider ships no entry under its name is site content again.
    $shipped = $this->createFont(['label' => 'Test Sans', 'family' => 'Test Sans', 'machine_name' => 'test_sans']);
    $shipped->setShippedSource('daedalus_stylish_fonts_test', ['family' => 'Test Sans', 'faces' => []])->save();
    try {
      $provider->update(MaterialKind::Font, 'test_sans', ['description' => 'Mine now.'], NULL, []);
      $this->fail('A shipped font updated on a consuming site.');
    }
    catch (MaterialGestureException $e) {
      $this->assertSame('STYLE_FONT_SHIPPED_LOCKED', $e->getErrors()[0]->getCode());
    }
    $orphaned = $this->createFont(['label' => 'Orphaned', 'family' => 'Orphaned']);
    $orphaned->setShippedSource('daedalus_stylish_fonts_test', ['family' => 'Orphaned', 'faces' => []])->save();
    $provider->update(MaterialKind::Font, 'orphaned', ['description' => 'Mine now.'], NULL, []);
    $this->assertSame('Mine now.', $this->container->get('daedalus_stylish.font_repository')->resolve('orphaned')?->getDescription());

    // The grades gate each verb.
    $this->setUpCurrentUser();
    try {
      $provider->capture(MaterialKind::Font, [
        'label' => 'Nope',
        'faces' => [['source' => ['file' => $regular->uuid()]]],
      ], NULL, []);
      $this->fail('A user without the grade minted.');
    }
    catch (MaterialGestureException $e) {
      $this->assertSame('PERMISSION_DENIED', $e->getErrors()[0]->getCode());
    }
  }

  /**
   * The Fonts tab's routes: usage, the delivery read, delete.
   *
   * The animation API's grammar: the management read answers the live
   * count beside the shipped facts, delete is immediate while nothing
   * names the family and a 202 batch hand-off once something does (the
   * in-use grade gating it), and the stylesheet read serves the current
   * @font-face print for the canvas to stamp after a write.
   */
  public function testRoutes(): void {
    $this->createUser();
    $this->setUpCurrentUser([], ['create fonts', 'update fonts', 'delete fonts']);
    $controller = FontApiController::create($this->container);

    $this->assertSame(404, $controller->usage('missing')->getStatusCode());
    $this->assertSame(404, $controller->delete('missing')->getStatusCode());

    // The delivery read prints the published set, the compiled file's
    // own text; nothing published prints nothing.
    $this->assertSame(['css' => ''], json_decode((string) $controller->stylesheet()->getContent(), TRUE));
    $font = $this->createFont();
    $css = json_decode((string) $controller->stylesheet()->getContent(), TRUE)['css'];
    $this->assertStringContainsString('@font-face', $css);
    $this->assertStringContainsString('font-family: "Inter"', $css);
    $this->assertSame($this->container->get('daedalus_stylish.font_stylesheet')->css(), $css);

    // The management read: a site font has no provider and no shipped
    // state; the count is the usage table's.
    $this->assertSame([
      'machine_name' => 'inter',
      'label' => 'Inter',
      'family' => 'Inter',
      'spelling' => '"Inter"',
      'usages' => 0,
      'provider' => NULL,
      'shipped_state' => NULL,
    ], json_decode((string) $controller->usage('inter')->getContent(), TRUE));
    $variant = DaedalusStylishVariant::create(['label' => 'Card', 'machine_name' => 'card', 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['font-family' => '"Inter", sans-serif']]]);
    $variant->save();
    $this->assertSame(1, json_decode((string) $controller->usage('inter')->getContent(), TRUE)['usages']);

    // A stamped font with a living source reads its shipped state.
    $shipped = $this->createFont(['label' => 'Test Sans', 'family' => 'Test Sans', 'machine_name' => 'test_sans']);
    $shipped->setShippedSource('daedalus_stylish_fonts_test', ['family' => 'Test Sans', 'faces' => []])->save();
    $payload = json_decode((string) $controller->usage('test_sans')->getContent(), TRUE);
    $this->assertSame('daedalus_stylish_fonts_test', $payload['provider']);
    $this->assertSame('defaults_changed', $payload['shipped_state']);

    // Named without the in-use grade: 403 with the corrective, untouched.
    $response = $controller->delete('inter');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('STYLE_FONT_IN_USE', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
    $this->assertNotNull($this->container->get('daedalus_stylish.font_repository')->resolve('inter'));

    // Named with the grade: 202, the batch URL, the removal the batch is
    // committed to; the font still stands until the client drives it.
    $this->setUpCurrentUser([], ['delete fonts in use']);
    $response = $controller->delete('inter');
    $this->assertSame(202, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertFalse($payload['deleted']);
    $this->assertSame(1, $payload['references']);
    $this->assertStringContainsString('batch', $payload['batch_url']);
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'font', 'id' => 'inter']]], $payload['materials']);
    $this->assertNotNull($this->container->get('daedalus_stylish.font_repository')->resolve('inter'));

    // Unnamed: deleted in this request, the delta carrying the removal.
    $this->createFont(['label' => 'Loner', 'family' => 'Loner']);
    $response = $controller->delete('loner');
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($payload['deleted']);
    $this->assertSame(0, $payload['references']);
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'font', 'id' => 'loner']]], $payload['materials']);
    $this->assertNull($this->container->get('daedalus_stylish.font_repository')->resolve('loner'));

    // Shipped fonts refuse whatever the grade (the distro lock).
    $response = $controller->delete('test_sans');
    $this->assertSame(422, $response->getStatusCode());
    $this->assertSame('STYLE_FONT_SHIPPED_LOCKED', json_decode((string) $response->getContent(), TRUE)['errors'][0]['code']);
  }

}
