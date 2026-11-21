<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_stylish\Controller\SkinMaterialApiController;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;
use Drupal\daedalus_stylish\Exception\SkinMaterialValidationException;
use Drupal\daedalus_stylish\FontFamilies;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinSiteFontsItem;
use Drupal\daedalus_stylish\SkinMaterialDeleteBatch;
use Drupal\daedalus_stylish\SkinMaterialManager;
use Drupal\daedalus_stylish\SkinMaterialUsage;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the skin's fonts section: layers, files, gestures, usage, delivery.
 *
 * A font is a record {family, faces, description, offered} of the skin
 * (SkinFontsItem), merged over two layers like a token, validated through
 * FontValidator::validateFonts(), minted, changed, renamed, reverted and
 * removed through SkinMaterialManager (the material gestures with kind
 * font and the /daedalus_stylish/skin/fonts routes), its files held by
 * the skin through core's file usage, tracked in the one skin material
 * usage table as its quoted family, removed from every stack before a
 * named one goes, served on the roster from the active skin, printed
 * as @font-face blocks at the head of the compiled skin file, and carried by
 * the skins disk lane with its faces' bytes under assets/fonts/.
 */
#[CoversClass(SkinFontsItem::class)]
#[CoversClass(SkinSiteFontsItem::class)]
#[CoversClass(FontFiles::class)]
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinFontsTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * A regular font file the faces may name.
   */
  protected FileInterface $regular;

  /**
   * A bold font file the faces may name.
   */
  protected FileInterface $bold;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish', 'daedalus_blueprint']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    // The font scale, bound to font-family: its tokens are stacks, so the
    // usage lane reads them as family references.
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'font' => ['label' => 'Font', 'shape' => 'text'],
      ],
      'constraint_defaults' => [
        'font-family' => ['scale' => 'font'],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
    $this->setUpCurrentUser([], [], TRUE);
    $files = $this->container->get('daedalus_stylish.font_files');
    $this->regular = $files->fromBytes('inter-regular.woff2', 'wOF2' . str_repeat("\0", 16) . 'regular');
    $this->bold = $files->fromBytes('inter-bold.woff2', 'wOF2' . str_repeat("\0", 16) . 'bold');
  }

  /**
   * The manager, resolved late.
   */
  protected function manager(): SkinMaterialManager {
    return \Drupal::service('daedalus_stylish.skin_material_manager');
  }

  /**
   * A face over one of the fixture files.
   */
  protected function face(FileInterface $file, array $face = []): array {
    return $face + ['source' => ['file' => $file->uuid()]];
  }

  /**
   * Loads a skin by machine name, fresh from storage.
   */
  protected function loadSkin(string $machine_name): DaedalusStylishSkinInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $storage->resetCache();
    $skins = $storage->loadByProperties(['machine_name' => $machine_name]);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Creates and activates the site's own skin with Inter and a stack naming it.
   */
  protected function activateOwnSkin(): DaedalusStylishSkinInterface {
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->setPackageTokens(['font' => ['body' => '"Inter", sans-serif']]);
    $skin->setPackageFonts([
      'inter' => [
        'family' => 'Inter',
        'faces' => [$this->face($this->regular), $this->face($this->bold, ['weight' => 'bold'])],
        'description' => 'The body face.',
      ],
    ]);
    $skin->save();
    $this->activateSkin($skin);
    return $this->loadSkin('mine');
  }

  /**
   * The error codes by path a gesture refuses with.
   */
  protected function refusal(callable $gesture): array {
    try {
      $gesture();
    }
    catch (SkinMaterialValidationException | MaterialGestureException $exception) {
      $codes = [];
      foreach ($exception->getErrors() as $error) {
        $codes[$error->getPath()] = $error->getCode();
      }
      return $codes;
    }
    $this->fail('The gesture was expected to refuse.');
  }

  /**
   * Drives the staged batch's operations by hand and returns the context.
   */
  protected function driveBatch(string $tail, int $count): array {
    $batch = &batch_get();
    $set = end($batch['sets']);
    $operations = $set['operations'] ?? [];
    if ($operations === [] && isset($set['queue'])) {
      $queue = _batch_queue($set);
      while ($item = $queue->claimItem()) {
        assert(is_object($item));
        $operations[] = $item->data;
        $queue->deleteItem($item);
      }
    }
    $this->assertCount($count, $operations);
    $this->assertSame([SkinMaterialDeleteBatch::class, $tail], end($operations)[0]);
    $context = [];
    foreach ($operations as [$callback, $arguments]) {
      call_user_func_array($callback, [...$arguments, &$context]);
    }
    $batch = [];
    return $context;
  }

  /**
   * A saved map host wearing a font-family stack.
   */
  protected function createWearer(string $value): EntityTest {
    $host = EntityTest::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $this->container->get('uuid')->generate() => ['default' => ['font-family' => $value]],
      ]),
    ]);
    $host->save();
    return $host;
  }

  /**
   * The compiled skin file's text.
   */
  protected function skinCss(): string {
    return file_get_contents(\Drupal::state()->get(SkinStylesheet::STATE_KEY));
  }

  /**
   * The store canonicalizes records and faces, merges layers and holds files.
   */
  public function testLayersCanonicalizationAndHold(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => 1]);
    $skin->setPackageFonts([
      'inter' => [
        'family' => ' Inter ',
        'faces' => [$this->face($this->bold, ['weight' => 'bold']), $this->face($this->regular)],
      ],
      'serif' => [
        'family' => 'Test Serif',
        'faces' => [$this->face($this->regular)],
        'description' => 'Quiet.',
        'offered' => FALSE,
      ],
    ]);
    $skin->setSiteFonts([
      'inter' => ['description' => 'Described by the site.'],
      'mono' => [
        'family' => 'Test Mono',
        'faces' => [$this->face($this->bold, ['weight' => '700', 'style' => 'Italic'])],
      ],
    ]);
    $skin->save();
    $skin = $this->loadSkin('aurora');

    $package = $skin->packageFonts();
    $this->assertSame([
      'family' => 'Inter',
      'faces' => [
        ['weight' => '400', 'style' => 'normal', 'source' => ['file' => $this->regular->uuid()]],
        ['weight' => '700', 'style' => 'normal', 'source' => ['file' => $this->bold->uuid()]],
      ],
      'description' => '',
      'offered' => TRUE,
    ], $package['inter'], 'A record fills its facts; faces canonicalize and sort by weight.');
    $this->assertFalse($package['serif']['offered']);
    $site = $skin->siteFonts();
    $this->assertSame(['description' => 'Described by the site.'], $site['inter'], 'A site record keeps exactly the facts it states.');
    $this->assertSame([
      'family' => 'Test Mono',
      'faces' => [['weight' => '700', 'style' => 'italic', 'source' => ['file' => $this->bold->uuid()]]],
    ], $site['mono']);

    $worn = $skin->fonts();
    $this->assertSame(['inter', 'serif', 'mono'], array_keys($worn), 'Package order, the site\'s additions after.');
    $this->assertSame('Described by the site.', $worn['inter']['description'], 'A site fact replaces, the rest show through.');
    $this->assertSame($package['inter']['faces'], $worn['inter']['faces']);
    $this->assertSame([
      'family' => 'Test Mono',
      'faces' => $site['mono']['faces'],
      'description' => '',
      'offered' => TRUE,
    ], $worn['mono'], 'A site-minted font fills its defaults.');
    $this->assertSame([$this->regular->uuid(), $this->bold->uuid()], SkinFontsItem::fileUuids($worn));

    // The skin holds every file a face of either layer names, once.
    $usage = $this->container->get('file.usage');
    foreach ([$this->regular, $this->bold] as $file) {
      $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$skin->id() => '1']]], $usage->listUsage($file));
    }
    // A save that drops a face keeps its file held: a revision restore may
    // bring the face back.
    $skin->setSiteFonts([])->save();
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$skin->id() => '1']]], $usage->listUsage($this->bold));
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$skin->id() => '1']]], $usage->listUsage($this->regular), 'The regular face is still worn; one row, not two.');

    // The shipped payload carries the fonts tier content-addressed; the
    // site layer is outside it.
    $files = $this->container->get('daedalus_stylish.font_files');
    $addressed = $files->contentAddressed($skin->packageFonts());
    $this->assertSame(hash('sha256', 'wOF2' . str_repeat("\0", 16) . 'regular'), $addressed['inter']['faces'][0]['source']['hash']);
    $this->assertSame(['hash'], array_keys($addressed['inter']['faces'][1]['source']));
    $skin->setShippedSource('example', ['tokens' => [], 'animations' => [], 'fonts' => $addressed, 'defaults' => []])->save();
    $this->assertFalse($skin->isModified());
    $skin->setSiteFonts(['inter' => ['faces' => [$this->face($this->regular)]]])->save();
    $this->assertFalse($skin->isModified(), 'A site re-facing is not a modification of what the package shipped.');
    $skin->setPackageFonts(['inter' => $skin->packageFonts()['inter']]);
    $this->assertTrue($skin->isModified(), 'The package layer is the payload the hash covers.');

    // Deleting the skin releases every file it held.
    $skin->delete();
    $this->assertSame([], $usage->listUsage($this->regular));
    $this->assertSame([], $usage->listUsage($this->bold));

    // A corrupt layer fails loudly.
    $this->expectException(\UnexpectedValueException::class);
    SkinFontsItem::mergeLayers([], ['ghost' => ['description' => 'no faces']]);
  }

  /**
   * A font file is temporary until a font names it, and a font names its own.
   */
  public function testFileLifeAndWhoMayNameIt(): void {
    $files = $this->container->get('daedalus_stylish.font_files');
    $this->assertTrue($this->regular->isTemporary(), 'An uploaded font file no font names is temporary.');
    $this->assertFalse($files->isHeld($this->regular));

    // A mint by the uploader holds the file, which makes it permanent.
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->save();
    $this->activateSkin($skin);
    $manager = $this->manager();
    $manager->mintFont($this->loadSkin('mine'), 'inter', 'Inter', [$this->face($this->regular)]);
    $this->assertTrue(File::load($this->regular->id())->isPermanent(), 'The file a font names is permanent.');
    $this->assertTrue($files->isHeld($this->regular));
    $this->assertTrue(File::load($this->bold->id())->isTemporary(), 'The file no font names stays temporary.');

    // Another writer may name a file the site's fonts hold, never an
    // upload that is someone else's and held by no font.
    $this->setUpCurrentUser([], [], TRUE);
    $this->assertSame(
      ['faces.0.source.file' => 'FONT_FILE_NOT_FOUND'],
      $this->refusal(fn () => $manager->mintFont($this->loadSkin('mine'), 'heavy', 'Heavy', [$this->face($this->bold, ['weight' => 'bold'])])),
    );
    $this->assertFalse($manager->hasFont($this->loadSkin('mine'), 'heavy'));
    $this->assertTrue(File::load($this->bold->id())->isTemporary(), 'A refused mint holds nothing.');
    $manager->mintFont($this->loadSkin('mine'), 'copy', 'Copy', [$this->face($this->regular)]);
    $this->assertTrue($manager->hasFont($this->loadSkin('mine'), 'copy'), 'A held font file is site vocabulary.');

    // The same writer's own upload is theirs to name.
    $own = $files->fromBytes('heavy.woff2', 'wOF2' . str_repeat("\0", 16) . 'heavy');
    $manager->mintFont($this->loadSkin('mine'), 'heavy', 'Heavy', [$this->face($own, ['weight' => 'bold'])]);
    $this->assertTrue(File::load($own->id())->isPermanent());
  }

  /**
   * The gestures on the site's own skin: mint, update, rename, delete, deliver.
   */
  public function testGesturesOnTheOwnSkin(): void {
    $skin = $this->activateOwnSkin();
    $manager = $this->manager();

    // The roster and the compiled skin file read the skin's fonts.
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $record = $roster->fonts()['inter'];
    $this->assertSame(['inter', 'inter', 'The body face.', MaterialRecord::ORIGIN_USER, TRUE, $skin->getDesignSystem()], [
      $record->id,
      $record->label,
      $record->description,
      $record->origin,
      $record->updatable,
      $record->designSystem,
    ]);
    $this->assertSame(['family' => 'Inter', 'faces' => $skin->fonts()['inter']['faces']], $record->value);
    $this->assertSame(['font' => '"Inter"'], $record->spellings);
    $css = $this->skinCss();
    $this->assertStringStartsWith("@font-face {\n  font-family: \"Inter\";\n  font-style: normal;\n  font-weight: 400;\n  font-display: swap;\n  src: url(\"", $css, 'The skin file opens with the font faces.');
    $this->assertSame(2, substr_count($css, '@font-face'));
    $this->assertSame(1, substr_count($css, 'font-weight: 700;'));
    $this->assertSame(2, substr_count($css, 'format("woff2")'));
    $this->assertLessThan(strpos($css, '--font-body:'), strpos($css, '@font-face'), 'The faces lead the tokens.');
    $this->assertSame($this->container->get('daedalus_stylish.skin_stylesheet')->fontFacesCss(), substr($css, 0, strlen($this->container->get('daedalus_stylish.skin_stylesheet')->fontFacesCss())), 'The delivery read is the file\'s head.');

    // Mint: the record lands canonical in the one layer.
    $manager->mintFont($skin, 'mono', 'Test Mono', [$this->face($this->regular, ['weight' => 500])], ' Code. ', FALSE);
    $skin = $this->loadSkin('mine');
    $this->assertSame([
      'family' => 'Test Mono',
      'faces' => [['weight' => '500', 'style' => 'normal', 'source' => ['file' => $this->regular->uuid()]]],
      'description' => 'Code.',
      'offered' => FALSE,
    ], $skin->packageFonts()['mono']);
    $this->assertSame('package', $manager->fontLayer($skin, 'mono'));
    $this->assertNull($manager->fontShippedState($skin, 'mono'));
    $this->assertStringContainsString('font-family: "Test Mono";', $this->skinCss());

    // Refusals in the gesture's parameter space.
    $this->assertSame(['label' => 'STYLE_FONT_EXISTS'], $this->refusal(fn () => $manager->mintFont($skin, 'mono', 'Other', [$this->face($this->regular)])));
    $this->assertSame(['label' => 'INVALID_FONT_NAME'], $this->refusal(fn () => $manager->mintFont($skin, 'Mono Two', 'Other', [$this->face($this->regular)])));
    $this->assertSame(['family' => 'DUPLICATE_FONT_FAMILY'], $this->refusal(fn () => $manager->mintFont($skin, 'inter_2', 'Inter', [$this->face($this->regular)])), 'One font per family.');
    $this->assertSame(['faces' => 'INVALID_FONT_FACES'], $this->refusal(fn () => $manager->mintFont($skin, 'empty', 'Empty', [])));
    $this->assertSame(['' => 'MISSING_PARAMETER'], $this->refusal(fn () => $manager->updateFont($skin, 'mono')));

    // Update: faces, family and facts, each a revisioned save.
    $manager->updateFont($skin, 'mono', 'Test Mono Two', [$this->face($this->bold, ['weight' => 'bold'])], NULL, TRUE);
    $skin = $this->loadSkin('mine');
    $this->assertSame([
      'family' => 'Test Mono Two',
      'faces' => [['weight' => '700', 'style' => 'normal', 'source' => ['file' => $this->bold->uuid()]]],
      'description' => 'Code.',
      'offered' => TRUE,
    ], $skin->fonts()['mono']);
    $this->assertStringContainsString('font-family: "Test Mono Two";', $this->skinCss());

    // Rename: the record moves in this request; no reference names a font
    // by its name.
    $manager->renameFont($skin, 'mono', 'code');
    $skin = $this->loadSkin('mine');
    $this->assertSame(['inter', 'code'], array_keys($skin->fonts()));
    $this->assertSame(['label' => 'STYLE_FONT_EXISTS'], $this->refusal(fn () => $manager->renameFont($skin, 'code', 'inter')));
    $this->assertSame(['label' => 'INVALID_FONT_NAME'], $this->refusal(fn () => $manager->renameFont($skin, 'code', 'Code!')));

    // Delete an unnamed font: gone from the skin and the file; its file
    // stays held for the skin's revisions.
    $this->assertSame(0, $manager->deleteFont($skin, 'code'));
    $skin = $this->loadSkin('mine');
    $this->assertSame(['inter'], array_keys($skin->fonts()));
    $this->assertStringNotContainsString('Test Mono', $this->skinCss());
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$skin->id() => '1']]], $this->container->get('file.usage')->listUsage($this->bold));
  }

  /**
   * A named font: usage, the in-use grade, the remove-the-family batch.
   */
  public function testNamedFontIsRewrittenBeforeItGoes(): void {
    $skin = $this->activateOwnSkin();
    $manager = $this->manager();
    $usage = $this->container->get('daedalus_stylish.skin_material_usage');
    $this->assertSame(['font'], $usage->fontScales());
    $this->assertSame(1, $manager->fontUsages($skin, 'inter'), 'The skin\'s own font-bound token names the family.');
    $this->createWearer('"Inter", serif');
    $this->createWearer("Georgia, 'Inter'");
    $this->createWearer('Inter, sans-serif');
    $this->assertSame(3, $manager->fontUsages($skin, 'inter'), 'Two hosts quote the family (either quote); an unquoted Inter is a theme\'s own.');
    $variant = DaedalusStylishVariant::create(['label' => 'Body', 'machine_name' => 'body', 'scope' => 'kind:container']);
    $variant->setStyles([
      '' => ['default' => ['font-family' => 'var(--font-body)', 'color' => 'red']],
      '::h2' => ['default' => ['font-family' => '"Inter"']],
    ]);
    $variant->save();
    $this->assertSame(4, $manager->fontUsages($skin, 'inter'));
    $this->assertSame(1, $usage->countReferencesTo('--font-body'), 'The var() reference is tracked beside the family.');

    // The value grammar: quoted components only, the one spelling.
    $this->assertSame(['"Inter"', '"Source Serif"'], SkinMaterialUsage::valueFamilies('font-family', "'Inter', Georgia, \"Source Serif\", serif"));
    $this->assertSame([], SkinMaterialUsage::valueFamilies('color', '"Inter"'));
    $this->assertSame('sans-serif', FontFamilies::removeFamily('font-family', '"Inter", sans-serif', 'Inter'));
    $this->assertSame('Georgia, serif', FontFamilies::removeFamily('font-family', 'Georgia, "Inter", serif', 'Inter'));
    $this->assertSame('initial', FontFamilies::removeFamily('font-family', ' "Inter" , \'Inter\' ', 'Inter'));
    $this->assertSame('Inter, sans-serif', FontFamilies::removeFamily('font-family', 'Inter, sans-serif', 'Inter'));
    $this->assertSame('var(--font-body, "Inter")', FontFamilies::removeFamily('font-family', 'var(--font-body, "Inter")', 'Inter'));

    // The op spine refuses a named font's removal with the count; the
    // in-use grade gates the batch.
    $this->assertSame(['id' => 'STYLE_FONT_IN_USE'], $this->refusal(fn () => $manager->deleteFont($skin, 'inter', FALSE)));
    $this->setUpCurrentUser([], ['delete skin materials']);
    $this->assertSame(['id' => 'STYLE_FONT_IN_USE'], $this->refusal(fn () => $manager->deleteFont($skin, 'inter')));

    // The route's management read, then the delete staged and driven.
    $this->setUpCurrentUser([], ['delete skin materials', 'delete skin materials in use', 'update skin materials']);
    $controller = SkinMaterialApiController::create($this->container);
    $this->assertSame(404, $controller->usage('fonts', 'ghost')->getStatusCode());
    $read = json_decode((string) $controller->usage('fonts', 'inter')->getContent(), TRUE);
    $this->assertSame(['inter', 'inter', 'Inter', '"Inter"', 4, 'package', FALSE, NULL, NULL], [
      $read['id'],
      $read['name'],
      $read['family'],
      $read['spelling'],
      $read['usages'],
      $read['layer'],
      $read['locked'],
      $read['provider'],
      $read['shipped_state'],
    ]);
    $response = $controller->rename('fonts', 'inter', Request::create('/', 'POST', [], [], [], [], json_encode(['label' => 'body_face'])));
    $this->assertSame(200, $response->getStatusCode(), 'A font\'s rename is always immediate.');
    $this->assertSame([TRUE, 'body_face', 0], array_values(array_intersect_key(json_decode((string) $response->getContent(), TRUE), [
      'renamed' => 1,
      'id' => 1,
      'references' => 1,
    ])));
    $this->assertSame(4, $manager->fontUsages($this->loadSkin('mine'), 'body_face'), 'The references name the family, not the name.');

    $response = $controller->delete('fonts', 'body_face');
    $this->assertSame(202, $response->getStatusCode());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame([FALSE, 4], [$body['deleted'], $body['references']]);
    $this->assertSame(['body_face'], array_column($body['materials']['remove'], 'id'));
    $context = $this->driveBatch('deleteSkinMaterial', 5);
    $this->assertSame(4, $context['results']['rewritten']);
    $this->assertSame('body_face', $context['results']['deleted']);
    $skin = $this->loadSkin('mine');
    $this->assertSame([], $skin->fonts());
    $this->assertSame('sans-serif', $skin->tokens()['font']['body']['value'], 'The font-bound token lost the family.');
    $values = array_map(static fn (EntityTest $host): string => array_values(json_decode($host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE))[0]['default']['font-family'], EntityTest::loadMultiple());
    $this->assertSame(['serif', 'Georgia', 'Inter, sans-serif'], array_values($values), 'Each stack falls through to its next family; the unquoted one is untouched.');
    $variant = DaedalusStylishVariant::load($variant->id());
    $this->assertSame('initial', $variant->getStyles()['::h2']['default']['font-family'], 'A stack that named nothing else becomes the initial value.');
    $this->assertSame('var(--font-body)', $variant->getStyles()['']['default']['font-family']);
    $this->assertSame(0, $usage->countReferencesTo('"Inter"'));
    $this->assertStringNotContainsString('@font-face', $this->skinCss());
  }

  /**
   * The gestures on a shipped skin: files landed, site layer, lock, revert.
   */
  public function testShippedSkinCarriesFonts(): void {
    \Drupal::service('module_installer')->install(['daedalus_stylish_fonts_test']);
    $typeset = $this->loadSkin('typeset');
    $this->activateSkin($typeset);
    $manager = $this->manager();
    $files = $this->container->get('daedalus_stylish.font_files');
    $module_path = $this->container->get('extension.list.module')->getPath('daedalus_stylish_fonts_test');

    // The disk lane landed both fonts: every face a permanent managed file
    // holding the asset's bytes, held by the skin.
    $fonts = $typeset->packageFonts();
    $this->assertSame(['test_sans', 'test_serif'], array_keys($fonts));
    $this->assertSame(['Test Sans', 'The body face of the fonts test.', TRUE], [
      $fonts['test_sans']['family'],
      $fonts['test_sans']['description'],
      $fonts['test_sans']['offered'],
    ]);
    $this->assertSame(['Test Serif', '', FALSE], [
      $fonts['test_serif']['family'],
      $fonts['test_serif']['description'],
      $fonts['test_serif']['offered'],
    ]);
    $this->assertSame(['400 normal woff2', '700 normal woff'], array_map(fn (array $face): string => $face['weight'] . ' ' . $face['style'] . ' ' . FontFiles::format($files->load($face['source']['file'])), $fonts['test_sans']['faces']));
    foreach ($fonts as $record) {
      foreach ($record['faces'] as $face) {
        $file = $files->load($face['source']['file']);
        $this->assertTrue($file->isPermanent());
        $this->assertSame(file_get_contents("$module_path/assets/fonts/{$file->getFilename()}"), file_get_contents($file->getFileUri()));
        $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$typeset->id() => '1']]], $this->container->get('file.usage')->listUsage($file));
      }
    }
    $this->assertFalse($typeset->isModified(), 'The content-addressed hash matches the module\'s assets.');
    $this->assertSame('unmodified', $manager->fontShippedState($typeset, 'test_sans'));
    $this->assertSame('package', $manager->fontLayer($typeset, 'test_sans'));
    $this->assertTrue($manager->writesSiteLayer($typeset));
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame(MaterialRecord::ORIGIN_SHIPPED, $roster->fonts()['test_sans']->origin);
    $this->assertFalse($roster->fonts()['test_serif']->offered);
    $css = $this->skinCss();
    $this->assertStringStartsWith('@font-face {', $css);
    $this->assertSame(3, substr_count($css, '@font-face'));
    $this->assertSame(1, substr_count($css, 'format("woff")'));

    // A sync by hand reads unchanged; the shipped payload is the file's.
    $importer = $this->container->get('daedalus_stylish.skin_importer');
    $report = $importer->sync(['daedalus_stylish_fonts_test']);
    $this->assertSame(['daedalus_stylish_fonts_test' => ['typeset' => $importer::UNCHANGED]], $report->outcomes());
    $shipped = $importer->getShippedData($typeset);
    $this->assertSame(['tokens', 'animations', 'fonts', 'defaults'], array_keys($shipped));
    $this->assertSame(hash_file('sha256', "$module_path/assets/fonts/test-sans-bold.woff"), $shipped['fonts']['test_sans']['faces'][1]['source']['hash']);

    // The shipper reads the definition back with the files' names, and
    // the file grammar refuses a face naming no asset.
    $definition = $this->container->get('daedalus_stylish.skin_shipper')->definition($typeset);
    $this->assertSame(['label', 'design_system', 'tokens', 'animations', 'fonts', 'defaults'], array_keys($definition));
    $this->assertSame('test-serif-regular.woff2', $definition['fonts']['test_serif']['faces'][0]['source']['file']);
    $importer->checkDefinitionShape('x', 'typeset', $definition);
    try {
      $importer->checkDefinitionShape('x', 'typeset', [
        'label' => 'T',
        'fonts' => ['sans' => ['family' => 'Sans', 'faces' => [['source' => ['file' => '../etc/passwd.woff']]]]],
      ]);
      $this->fail('A face outside assets/fonts/ passed.');
    }
    catch (ShippedSkinException $exception) {
      $this->assertSame('INVALID_SHIPPED_SKIN', $exception->getErrors()[0]->getCode());
    }
    try {
      $importer->shippedPayload('daedalus_stylish_fonts_test', 'typeset', [
        'label' => 'T',
        'fonts' => ['sans' => ['family' => 'Sans', 'faces' => [['source' => ['file' => 'missing.woff2']]]]],
      ]);
      $this->fail('A missing asset passed.');
    }
    catch (ShippedSkinException $exception) {
      $this->assertSame('SHIPPED_FONT_FILE_MISSING', $exception->getErrors()[0]->getCode());
    }

    // The site's facts layer over a shipped font; revert drops them.
    $manager->updateFont($typeset, 'test_sans', NULL, NULL, 'Mine.');
    $typeset = $this->loadSkin('typeset');
    $this->assertSame(['description' => 'Mine.'], $typeset->siteFonts()['test_sans']);
    $this->assertSame('Mine.', $typeset->fonts()['test_sans']['description']);
    $this->assertSame('modified', $manager->fontShippedState($typeset, 'test_sans'));
    $this->assertSame('both', $manager->fontLayer($typeset, 'test_sans'));
    $this->assertFalse($typeset->isModified(), 'The site layer is outside the shipped payload.');
    $manager->revertFont($typeset, 'test_sans');
    $typeset = $this->loadSkin('typeset');
    $this->assertSame([], $typeset->siteFonts());
    $this->assertSame('unmodified', $manager->fontShippedState($typeset, 'test_sans'));

    // Identity is the package's on a consuming site.
    $this->assertSame(['id' => 'STYLE_FONT_SHIPPED_LOCKED'], $this->refusal(fn () => $manager->deleteFont($typeset, 'test_sans')));
    $this->assertSame(['id' => 'STYLE_FONT_SHIPPED_LOCKED'], $this->refusal(fn () => $manager->renameFont($typeset, 'test_sans', 'sans')));

    // A site mint lands in the site layer; the provider's gestures ride
    // the same manager with kind font.
    $provider = $this->container->get('daedalus_stylish.materials_provider');
    $provider->capture(MaterialKind::Font, [
      'label' => 'inter',
      'family' => 'Inter',
      'faces' => [$this->face($this->regular)],
      'description' => 'Mine.',
    ], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI]);
    $typeset = $this->loadSkin('typeset');
    $this->assertSame([
      'family' => 'Inter',
      'faces' => [['weight' => '400', 'style' => 'normal', 'source' => ['file' => $this->regular->uuid()]]],
      'description' => 'Mine.',
      'offered' => TRUE,
    ], $typeset->siteFonts()['inter']);
    $this->assertSame('site', $manager->fontLayer($typeset, 'inter'));
    $this->assertFalse($typeset->isModified());
    $this->assertSame(MaterialRecord::ORIGIN_USER, $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->fonts()['inter']->origin);
    $this->assertSame([FontFiles::USAGE_MODULE => [FontFiles::USAGE_TYPE => [$typeset->id() => '1']]], $this->container->get('file.usage')->listUsage($this->regular), 'A site-minted font\'s file is held by the skin too.');
    $this->assertSame(['id' => 'SHIPPED_DEFAULTS_MISSING'], $this->refusal(fn () => $manager->revertFont($typeset, 'inter')));
    $this->assertSame(['global' => 'INVALID_PARAMETER'], $this->refusal(fn () => $provider->capture(MaterialKind::Font, [
      'label' => 'x',
      'family' => 'X',
      'faces' => [$this->face($this->regular)],
      'global' => TRUE,
    ], NULL, [])));
    $this->assertSame(['label' => 'MISSING_PARAMETER'], $this->refusal(fn () => $provider->capture(MaterialKind::Font, [
      'family' => 'X',
      'faces' => [$this->face($this->regular)],
    ], NULL, [])));
    $this->assertSame(['family' => 'MISSING_PARAMETER'], $this->refusal(fn () => $provider->capture(MaterialKind::Font, [
      'label' => 'x',
      'faces' => [$this->face($this->regular)],
    ], NULL, [])));
    $this->config('daedalus_stylish.settings')->set('ai_minting', FALSE)->save();
    $this->assertSame(['faces' => 'STYLE_FONT_AI_MINTING_DISABLED'], $this->refusal(fn () => $provider->capture(MaterialKind::Font, [
      'label' => 'y',
      'family' => 'Y',
      'faces' => [$this->face($this->bold)],
    ], NULL, [MaterialGestureOperationHandler::ORIGIN_OPTION => MaterialRecord::ORIGIN_AI])));
    $provider->update(MaterialKind::Font, 'inter', ['offered' => FALSE], NULL, []);
    $this->assertFalse($this->loadSkin('typeset')->fonts()['inter']['offered']);
    $this->assertSame(['id' => 'STYLE_FONT_NOT_FOUND'], $this->refusal(fn () => $provider->update(MaterialKind::Font, 'nope', ['description' => 'x'], NULL, [])));
    $result = $provider->remove(MaterialKind::Font, 'inter', []);
    $this->assertNull($result->entity);
    $this->assertArrayNotHasKey('inter', $this->loadSkin('typeset')->fonts());
  }

}
