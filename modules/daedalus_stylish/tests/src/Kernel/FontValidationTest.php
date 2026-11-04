<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore söhne
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Exception\SkinMaterialValidationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the font validation spine.
 *
 * One engine, three seams: FontValidator::validateFamily() (a name a
 * font-family stack can carry, never a generic family or a CSS keyword),
 * validateFaces() (a non-empty list of faces, each a weight and a style
 * through SkinFontsItem's one grammar and a typed source resolving to a
 * font file the lane admits, one face per weight and style) and
 * validateFonts() over a skin's fonts section as worn (the name grammar,
 * the record shape, one font per family). The skin material manager
 * converges on it: a mint with a bad face throws the gesture shape.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FontValidationTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  protected const UUID = '11111111-1111-1111-1111-111111111111';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'daedalus_blueprint',
    'filter',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * A font file the faces may name.
   */
  protected FileInterface $file;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_variant');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_skin_material_usage']);
    $this->installConfig(['daedalus_stylish', 'daedalus_blueprint']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->file = $this->container->get('daedalus_stylish.font_files')->fromBytes('inter.woff2', 'wOF2' . str_repeat("\0", 16));
  }

  /**
   * Validates faces.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The validation errors; empty when valid.
   */
  protected function validateFaces(mixed $faces): array {
    return $this->container->get('daedalus_stylish.font_validator')->validateFaces($faces);
  }

  /**
   * Validates a family name.
   */
  protected function validateFamily(mixed $family): array {
    return $this->container->get('daedalus_stylish.font_validator')->validateFamily($family);
  }

  /**
   * Validates a fonts section.
   */
  protected function validateFonts(array $fonts): array {
    return $this->container->get('daedalus_stylish.font_validator')->validateFonts($fonts);
  }

  /**
   * Asserts one error with the expected code and path.
   */
  protected function assertSingleError(array $errors, string $code, ?string $path = NULL): BlueprintError {
    $this->assertCount(1, $errors, implode(' | ', array_map(static fn (BlueprintError $e) => $e->getCode() . '@' . $e->getPath(), $errors)));
    $this->assertSame($code, $errors[0]->getCode());
    if ($path !== NULL) {
      $this->assertSame($path, $errors[0]->getPath());
    }
    return $errors[0];
  }

  /**
   * A face over the fixture file.
   */
  protected function face(array $face = []): array {
    return $face + ['source' => ['file' => $this->file->uuid()]];
  }

  /**
   * The family grammar: a name a stack can carry.
   */
  public function testFamily(): void {
    $this->assertSame([], $this->validateFamily('Inter'));
    $this->assertSame([], $this->validateFamily('Source Serif 4'));
    $this->assertSame([], $this->validateFamily(' Söhne '));

    $this->assertSingleError($this->validateFamily(''), 'INVALID_FONT_FAMILY', 'family');
    $this->assertSingleError($this->validateFamily('   '), 'INVALID_FONT_FAMILY', 'family');
    $this->assertSingleError($this->validateFamily(NULL), 'INVALID_FONT_FAMILY', 'family');
    $this->assertSingleError($this->validateFamily(['Inter']), 'INVALID_FONT_FAMILY', 'family');
    // Separators, quotes and braces would break the stack or the at-rule.
    foreach (['Inter, serif', '"Inter"', "Inter's", 'Inter; color: red', 'Inter }', 'Inter (Display)', "Inter\nDisplay"] as $family) {
      $this->assertSingleError($this->validateFamily($family), 'INVALID_FONT_FAMILY', 'family');
    }
    $this->assertSingleError($this->validateFamily(str_repeat('a', 256)), 'INVALID_FONT_FAMILY', 'family');
    // Generic families and CSS-wide keywords name something else in every
    // stack.
    foreach (['serif', 'Sans-Serif', 'system-ui', 'inherit', 'revert-layer'] as $family) {
      $error = $this->assertSingleError($this->validateFamily($family), 'INVALID_FONT_FAMILY', 'family');
      $this->assertStringContainsString('generic family or a CSS keyword', $error->getMessage());
    }
  }

  /**
   * The faces shape: a non-empty list of face maps.
   */
  public function testFacesShape(): void {
    $this->assertSame([], $this->validateFaces([$this->face()]));
    $this->assertSame([], $this->validateFaces([
      $this->face(['weight' => 400, 'style' => 'normal']),
      $this->face(['weight' => 'bold', 'style' => 'italic']),
      $this->face(['weight' => '100 900', 'style' => 'oblique']),
    ]));

    // Not a faces list: a string, an empty list, a map.
    $this->assertSingleError($this->validateFaces('inter.woff2'), 'INVALID_FONT_FACES', 'faces');
    $this->assertSingleError($this->validateFaces([]), 'INVALID_FONT_FACES', 'faces');
    $this->assertSingleError($this->validateFaces(['regular' => $this->face()]), 'INVALID_FONT_FACES', 'faces');
    // A face that is not a map, or carries keys no face has.
    $this->assertSingleError($this->validateFaces(['inter.woff2']), 'INVALID_FONT_FACE', 'faces.0');
    $this->assertSingleError($this->validateFaces([$this->face(), [$this->file->uuid()]]), 'INVALID_FONT_FACE', 'faces.1');
    $error = $this->assertSingleError($this->validateFaces([$this->face(['url' => 'https://example.com/inter.woff2'])]), 'INVALID_FONT_FACE', 'faces.0');
    $this->assertSame(['url'], $error->getContext()['keys']);
  }

  /**
   * Weights and styles: one grammar for the validator and the store.
   */
  public function testWeightAndStyle(): void {
    $this->assertSame('400', SkinFontsItem::canonicalWeight(NULL));
    $this->assertSame('400', SkinFontsItem::canonicalWeight(400));
    $this->assertSame('400', SkinFontsItem::canonicalWeight(' 400.0 '));
    $this->assertSame('400', SkinFontsItem::canonicalWeight('Normal'));
    $this->assertSame('700', SkinFontsItem::canonicalWeight('bold'));
    $this->assertSame('550.5', SkinFontsItem::canonicalWeight(550.5));
    $this->assertSame('100 900', SkinFontsItem::canonicalWeight('100  900'));
    $this->assertSame('1', SkinFontsItem::canonicalWeight('1'));
    $this->assertSame('1000', SkinFontsItem::canonicalWeight('1000'));
    foreach (['0', '1001', '-100', 'heavy', '900 100', '100 500 900', '100px', ''] as $weight) {
      $this->assertNull(SkinFontsItem::canonicalWeight($weight), $weight);
    }
    $this->assertNull(SkinFontsItem::canonicalWeight([400]));

    $this->assertSame('normal', SkinFontsItem::canonicalStyle(NULL));
    $this->assertSame('italic', SkinFontsItem::canonicalStyle(' Italic '));
    $this->assertSame('oblique', SkinFontsItem::canonicalStyle('oblique'));
    $this->assertNull(SkinFontsItem::canonicalStyle('oblique 10deg'));
    $this->assertNull(SkinFontsItem::canonicalStyle('slanted'));
    $this->assertNull(SkinFontsItem::canonicalStyle(TRUE));

    $this->assertSingleError($this->validateFaces([$this->face(['weight' => 'heavy'])]), 'INVALID_FONT_WEIGHT', 'faces.0.weight');
    $this->assertSingleError($this->validateFaces([$this->face(['weight' => '900 100'])]), 'INVALID_FONT_WEIGHT', 'faces.0.weight');
    $this->assertSingleError($this->validateFaces([$this->face(['style' => 'slanted'])]), 'INVALID_FONT_STYLE', 'faces.0.style');
    // One face per weight and style, whatever the spelling.
    $error = $this->assertSingleError($this->validateFaces([
      $this->face(['weight' => 'bold']),
      $this->face(['weight' => '700', 'style' => 'Normal']),
    ]), 'DUPLICATE_FONT_FACE', 'faces.1');
    $this->assertSame(['weight' => '700', 'style' => 'normal'], $error->getContext());
    // Distinct styles at one weight are two faces.
    $this->assertSame([], $this->validateFaces([
      $this->face(['weight' => 700]),
      $this->face(['weight' => 700, 'style' => 'italic']),
    ]));
  }

  /**
   * The typed source: {file: uuid} resolving to a font file.
   */
  public function testSource(): void {
    // The map is typed: exactly the file kind, nothing else, no bare value.
    $this->assertSingleError($this->validateFaces([['weight' => 400]]), 'INVALID_FONT_SOURCE', 'faces.0.source');
    $this->assertSingleError($this->validateFaces([['source' => $this->file->uuid()]]), 'INVALID_FONT_SOURCE', 'faces.0.source');
    $this->assertSingleError($this->validateFaces([['source' => ['url' => 'https://example.com/inter.woff2']]]), 'INVALID_FONT_SOURCE', 'faces.0.source');
    $this->assertSingleError($this->validateFaces([['source' => ['file' => $this->file->uuid(), 'url' => 'x']]]), 'INVALID_FONT_SOURCE', 'faces.0.source');
    $this->assertSingleError($this->validateFaces([['source' => ['file' => '']]]), 'INVALID_FONT_SOURCE', 'faces.0.source.file');
    $this->assertSingleError($this->validateFaces([['source' => ['file' => 12]]]), 'INVALID_FONT_SOURCE', 'faces.0.source.file');
    // The file must exist and be a font kind.
    $this->assertSingleError($this->validateFaces([['source' => ['file' => self::UUID]]]), 'FONT_FILE_NOT_FOUND', 'faces.0.source.file');
    $notes = File::create(['uri' => 'public://notes.txt', 'filename' => 'notes.txt']);
    $notes->save();
    $error = $this->assertSingleError($this->validateFaces([['source' => ['file' => $notes->uuid()]]]), 'INVALID_FONT_FILE', 'faces.0.source.file');
    $this->assertSame(['woff2', 'woff'], $error->getContext()['extensions']);
    // Every fault reports; a valid face beside them stays silent.
    $errors = $this->validateFaces([
      $this->face(),
      ['weight' => 'heavy', 'style' => 'slanted', 'source' => ['file' => self::UUID]],
    ]);
    $this->assertSame(['INVALID_FONT_WEIGHT', 'INVALID_FONT_STYLE', 'FONT_FILE_NOT_FOUND'], array_map(static fn (BlueprintError $e) => $e->getCode(), $errors));
  }

  /**
   * The fonts seam: names, records, the two grammars beneath, one family each.
   */
  public function testFonts(): void {
    $codes = static fn (array $errors): array => array_combine(array_map(static fn ($e) => $e->getPath(), $errors), array_map(static fn ($e) => $e->getCode(), $errors));
    $inter = SkinFontsItem::canonicalizeFont([
      'family' => 'Inter',
      'faces' => [$this->face(['weight' => 'bold'])],
      'description' => 'The body face.',
    ]);
    $this->assertSame([
      'family' => 'Inter',
      'faces' => [['weight' => '700', 'style' => 'normal', 'source' => ['file' => $this->file->uuid()]]],
      'description' => 'The body face.',
      'offered' => TRUE,
    ], $inter, 'A record canonicalizes: faces settle, the facts fill.');
    $this->assertSame([], $this->validateFonts(['inter' => $inter]));

    $this->assertSame(['Inter' => 'INVALID_FONT_NAME'], $codes($this->validateFonts(['Inter' => $inter])));
    $this->assertSame(['inter' => 'INVALID_FONT_RECORD'], $codes($this->validateFonts(['inter' => 'Inter'])));
    $this->assertSame(['inter' => 'INVALID_FONT_RECORD'], $codes($this->validateFonts(['inter' => ['family' => 'Inter']])), 'A record carries its faces.');
    $this->assertSame(['inter' => 'INVALID_FONT_RECORD'], $codes($this->validateFonts(['inter' => $inter + ['label' => 'Inter']])), 'The record carries family, faces, description and offered and nothing else.');
    $this->assertSame([
      'inter.description' => 'INVALID_FONT_RECORD',
      'inter.offered' => 'INVALID_FONT_RECORD',
    ], $codes($this->validateFonts(['inter' => ['description' => 3, 'offered' => 'yes'] + $inter])));
    $this->assertSame([
      'inter.family' => 'INVALID_FONT_FAMILY',
      'inter.faces.0.weight' => 'INVALID_FONT_WEIGHT',
    ], $codes($this->validateFonts([
      'inter' => [
        'family' => 'serif',
        'faces' => [$this->face(['weight' => 'heavy'])],
        'description' => '',
        'offered' => TRUE,
      ],
    ])), 'The two grammars re-root under the name.');
    // One font per family on a skin.
    $this->assertSame(['inter_2.family' => 'DUPLICATE_FONT_FAMILY'], $codes($this->validateFonts([
      'inter' => $inter,
      'inter_2' => $inter,
    ])));
    $this->assertSame([], $this->validateFonts([
      'inter' => $inter,
      'inter_tight' => ['family' => 'Inter Tight'] + $inter,
    ]));
  }

  /**
   * The manager converges on the spine: a mint throws the gesture shape.
   */
  public function testManagerConverges(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Mine', 'machine_name' => 'mine', 'status' => 1]);
    $skin->save();
    $this->activateSkin($skin);
    $manager = $this->container->get('daedalus_stylish.skin_material_manager');
    try {
      $manager->mintFont($skin, 'inter', 'serif', [['weight' => 'heavy', 'source' => ['file' => $this->file->uuid()]]]);
      $this->fail('A bad mint saved.');
    }
    catch (SkinMaterialValidationException $e) {
      $this->assertSame(['family', 'faces.0.weight'], array_map(static fn (BlueprintError $error) => $error->getPath(), $e->getErrors()));
    }
    try {
      $manager->mintFont($skin, 'Inter', 'Inter', [$this->face()]);
      $this->fail('A badly named mint saved.');
    }
    catch (SkinMaterialValidationException $e) {
      $this->assertSame('INVALID_FONT_NAME', $e->getErrors()[0]->getCode());
      $this->assertSame('label', $e->getErrors()[0]->getPath());
    }
    $manager->mintFont($skin, 'inter', 'Inter', [$this->face(['weight' => 'bold'])], 'The body face.');
    $this->assertSame([['weight' => '700', 'style' => 'normal', 'source' => ['file' => $this->file->uuid()]]], $skin->fonts()['inter']['faces']);
    try {
      $manager->updateFont($skin, 'inter');
      $this->fail('An empty update saved.');
    }
    catch (SkinMaterialValidationException $e) {
      $this->assertSame('MISSING_PARAMETER', $e->getErrors()[0]->getCode());
    }
    try {
      $manager->updateFont($skin, 'inter', family: 'monospace');
      $this->fail('A generic family updated.');
    }
    catch (SkinMaterialValidationException $e) {
      $this->assertSame('INVALID_FONT_FAMILY', $e->getErrors()[0]->getCode());
      $this->assertSame('family', $e->getErrors()[0]->getPath());
    }
  }

}
