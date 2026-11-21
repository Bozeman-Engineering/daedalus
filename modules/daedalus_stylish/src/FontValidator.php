<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;

/**
 * The font validation spine: the family name and the faces grammar.
 *
 * The one gate every font write path converges on (the material gestures
 * through SkinMaterialManager, the disk lane's importer landing a skin's
 * fonts tier, the skin tab's routes as adapters): a family that a
 * font-family value can name (no quotes, separators or control
 * characters; never a generic family or a CSS-wide keyword, which would
 * name something else in every stack), and faces that print (a non-empty
 * list; each a map of weight, style and a typed source; the weight and
 * style through SkinFontsItem's one grammar; the source `{file: uuid}`
 * resolving to a managed file the file lane admits, woff2 or woff, that
 * the writer uploaded or a skin already holds; one face per weight and
 * style).
 *
 * validateFonts() is the seam over a skin's fonts section as worn
 * (StyleMapValidator::validateAnimations()'s twin): the name grammar, the
 * record shape, then the two grammars per record, errors at
 * "{name}", "{name}.family", "{name}.faces", "{name}.faces.{i}",
 * "{name}.faces.{i}.weight", ".style", ".source", ".source.file",
 * "{name}.description", "{name}.offered", so the gesture re-roots them
 * onto its own keys and the disk lane under its field path.
 */
class FontValidator {

  /**
   * The families a stack names without a font: never a font's own name.
   */
  public const RESERVED_FAMILIES = [
    'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui',
    'ui-serif', 'ui-sans-serif', 'ui-monospace', 'ui-rounded', 'emoji',
    'math', 'fangsong', 'inherit', 'initial', 'unset', 'revert', 'revert-layer',
  ];

  public function __construct(
    protected EntityRepositoryInterface $entityRepository,
    protected AccountInterface $currentUser,
    protected FontFiles $fontFiles,
  ) {}

  /**
   * Validates a skin's fonts section: canonical records by name.
   *
   * @param array $fonts
   *   The fonts as worn, {name: {family, faces, description, offered}}
   *   (SkinFontsItem::mergeLayers()).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths relative to the fonts map; empty when valid.
   */
  public function validateFonts(array $fonts): array {
    $errors = [];
    $families = [];
    foreach ($fonts as $name => $record) {
      $name = (string) $name;
      if (!preg_match(SkinFontsItem::NAME, $name)) {
        $errors[] = new BlueprintError($name, 'INVALID_FONT_NAME', sprintf('"%s" is not a valid font name.', $name), [], 'Font names are lowercase letters, digits, and inner hyphens or underscores ("inter", "display_serif"); the name is the roster id, the family is what stacks reference.');
        continue;
      }
      if (!SkinFontsItem::isRecord($record)) {
        $errors[] = new BlueprintError($name, 'INVALID_FONT_RECORD', sprintf('%s is not a font record.', $name), ['keys' => SkinFontsItem::RECORD_KEYS], 'A font is a record {"family": "Inter", "faces": [{"weight": "400", "style": "normal", "source": {"file": "<uuid>"}}], "description": "...", "offered": true}.');
        continue;
      }
      if ($unknown = array_diff(array_keys($record), SkinFontsItem::RECORD_KEYS)) {
        $errors[] = new BlueprintError($name, 'INVALID_FONT_RECORD', sprintf('Unknown key(s) %s in the record of %s.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), ['keys' => SkinFontsItem::RECORD_KEYS], sprintf('A font record carries %s and nothing else.', implode(', ', SkinFontsItem::RECORD_KEYS)));
        continue;
      }
      if (!is_string($record['description'] ?? '')) {
        $errors[] = new BlueprintError("$name.description", 'INVALID_FONT_RECORD', sprintf('The description of %s is not text.', $name), [], 'Pass "description" as a sentence on when to reach for the family, or omit it.');
      }
      if (!is_bool($record['offered'] ?? TRUE)) {
        $errors[] = new BlueprintError("$name.offered", 'INVALID_FONT_RECORD', sprintf('The offered flag of %s is not a boolean.', $name), [], 'Pass "offered" as true or false, or omit it (offered).');
      }
      $own = $this->validate($record['family'], $record['faces']);
      foreach ($own as $error) {
        $errors[] = $error->withPath("$name." . $error->getPath());
      }
      // One font per family: a stack names the FAMILY, so two records
      // delivering one family would share every reference and the usage
      // lane could not tell whose deletion drops it from the stacks.
      if (is_string($record['family']) && !in_array('family', array_map(static fn (BlueprintError $error): string => $error->getPath(), $own), TRUE)) {
        $family = trim($record['family']);
        if (isset($families[$family])) {
          $errors[] = new BlueprintError("$name.family", 'DUPLICATE_FONT_FAMILY', sprintf('%s and %s both deliver the family "%s".', $families[$family], $name, $family), ['font' => $families[$family]], 'One font per family on a skin: add the faces to the existing font (update_material with kind font), or name the family differently.');
        }
        else {
          $families[$family] = $name;
        }
      }
    }
    return $errors;
  }

  /**
   * Validates a family name and faces together.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors; empty when valid.
   */
  public function validate(mixed $family, mixed $faces): array {
    return [...$this->validateFamily($family), ...$this->validateFaces($faces)];
  }

  /**
   * Validates a family name.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at 'family'; empty when valid.
   */
  public function validateFamily(mixed $family): array {
    if (!is_string($family) || trim($family) === '') {
      return [
        new BlueprintError('family', 'INVALID_FONT_FAMILY', 'A font needs a family name.', [], 'Pass "family" as the name font-family values will reference, e.g. "Inter".'),
      ];
    }
    $family = trim($family);
    if (mb_strlen($family) > 255 || preg_match('/["\'\\\\;,{}()<>\x00-\x1F\x7F]/', $family)) {
      return [
        new BlueprintError('family', 'INVALID_FONT_FAMILY', sprintf('"%s" is not a family name.', $family), [], 'A family name is plain text without quotes, commas, semicolons, braces, parentheses or control characters (the compiled @font-face quotes it), at most 255 characters.'),
      ];
    }
    if (in_array(strtolower($family), self::RESERVED_FAMILIES, TRUE)) {
      return [
        new BlueprintError('family', 'INVALID_FONT_FAMILY', sprintf('"%s" is a generic family or a CSS keyword, not a font\'s name.', $family), ['reserved' => self::RESERVED_FAMILIES], 'Name the family after the typeface ("Inter", "Source Serif"); generic families (serif, sans-serif, ...) belong at the end of a font-family stack.'),
      ];
    }
    return [];
  }

  /**
   * Validates faces.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at 'faces' and beneath; empty when valid.
   */
  public function validateFaces(mixed $faces): array {
    if (!is_array($faces) || $faces === [] || !array_is_list($faces)) {
      return [
        new BlueprintError('faces', 'INVALID_FONT_FACES', 'The font faces are not a list of faces.', [], 'Pass "faces" as a list with at least one face, e.g. [{"weight": 400, "style": "normal", "source": {"file": "<uuid>"}}]; a face\'s weight defaults to 400 and its style to normal.'),
      ];
    }
    $errors = [];
    $seen = [];
    foreach ($faces as $index => $face) {
      $at = "faces.$index";
      if (!is_array($face) || array_is_list($face)) {
        $errors[] = new BlueprintError($at, 'INVALID_FONT_FACE', sprintf('Face %d is not a face map.', $index), [], 'A face is {"weight", "style", "source"}: the weight a number or "min max" range, the style normal, italic or oblique, the source {"file": "<uuid>"}.');
        continue;
      }
      $unknown = array_diff(array_keys($face), ['weight', 'style', 'source']);
      if ($unknown !== []) {
        $errors[] = new BlueprintError($at, 'INVALID_FONT_FACE', sprintf('Face %d carries unknown keys: %s.', $index, implode(', ', $unknown)), ['keys' => array_values($unknown)], 'A face takes "weight", "style" and "source" only.');
        continue;
      }
      $weight = SkinFontsItem::canonicalWeight($face['weight'] ?? NULL);
      if ($weight === NULL) {
        $errors[] = new BlueprintError("$at.weight", 'INVALID_FONT_WEIGHT', sprintf('"%s" is not a font weight.', is_scalar($face['weight']) ? (string) $face['weight'] : gettype($face['weight'])), [], 'A weight is a number from 1 to 1000 ("400"), a keyword ("normal", "bold"), or a variable-font range of two ascending numbers ("100 900").');
      }
      $style = SkinFontsItem::canonicalStyle($face['style'] ?? NULL);
      if ($style === NULL) {
        $errors[] = new BlueprintError("$at.style", 'INVALID_FONT_STYLE', sprintf('"%s" is not a font style.', is_scalar($face['style']) ? (string) $face['style'] : gettype($face['style'])), ['styles' => SkinFontsItem::STYLES], 'A style is one of normal, italic or oblique.');
      }
      array_push($errors, ...$this->validateSource("$at.source", $face['source'] ?? NULL));
      if ($weight !== NULL && $style !== NULL) {
        $key = "$weight $style";
        if (isset($seen[$key])) {
          $errors[] = new BlueprintError($at, 'DUPLICATE_FONT_FACE', sprintf('Faces %d and %d both declare weight %s, style %s.', $seen[$key], $index, $weight, $style), [
            'weight' => $weight,
            'style' => $style,
          ], 'One face per weight and style: merge the two, or give one a different weight or style.');
        }
        else {
          $seen[$key] = $index;
        }
      }
    }
    return $errors;
  }

  /**
   * Validates a face's typed source map.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the source path and beneath.
   */
  protected function validateSource(string $at, mixed $source): array {
    if (!is_array($source) || array_keys($source) !== [SkinFontsItem::SOURCE_FILE]) {
      return [
        new BlueprintError($at, 'INVALID_FONT_SOURCE', 'A face\'s source is a typed map naming its file.', ['kinds' => [SkinFontsItem::SOURCE_FILE]], 'Pass "source" as {"file": "<uuid>"}, the uuid of a font file the site holds (upload one first, or take it from the FONTS roster); no other source kind exists yet.'),
      ];
    }
    $uuid = $source[SkinFontsItem::SOURCE_FILE];
    $path = $at . '.' . SkinFontsItem::SOURCE_FILE;
    if (!is_string($uuid) || $uuid === '') {
      return [
        new BlueprintError($path, 'INVALID_FONT_SOURCE', 'A file source names the file by uuid.', [], 'Pass "file" as the uuid of an uploaded font file.'),
      ];
    }
    $file = $this->entityRepository->loadEntityByUuid('file', $uuid);
    if (!$file instanceof FileInterface) {
      return [
        new BlueprintError($path, 'FONT_FILE_NOT_FOUND', sprintf('No file on this site has the uuid "%s".', $uuid), [], 'Upload the font file first; the response names its uuid.'),
      ];
    }
    // A face names a file its writer uploaded, or one a skin already
    // holds as a face's file (the site's font vocabulary). Any other
    // managed file answers as if it were absent.
    if ((int) $file->getOwnerId() !== (int) $this->currentUser->id() && !$this->fontFiles->isHeld($file)) {
      return [
        new BlueprintError($path, 'FONT_FILE_NOT_FOUND', sprintf('No font file you uploaded, and none the site\'s fonts hold, has the uuid "%s".', $uuid), [], 'Upload the font file first; the response names its uuid.'),
      ];
    }
    $extension = strtolower(pathinfo($file->getFilename() ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, FileLane::FONT_EXTENSIONS, TRUE)) {
      return [
        new BlueprintError($path, 'INVALID_FONT_FILE', sprintf('"%s" is not a font file.', $file->getFilename()), ['extensions' => FileLane::FONT_EXTENSIONS], sprintf('A face\'s file is one of: %s.', implode(', ', FileLane::FONT_EXTENSIONS))),
      ];
    }
    return [];
  }

}
