<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\file\FileInterface;

/**
 * The font validation spine: the family name and the faces grammar.
 *
 * The one gate every font write path converges on (the material gestures
 * through FontManager, the disk lane's importer, the tab's routes as
 * adapters): a family that a font-family value can name (no quotes,
 * separators or control characters; never a generic family or a CSS-wide
 * keyword, which would name something else in every stack), and faces
 * that print (a non-empty list; each a map of weight, style and a typed
 * source; the weight and style through FontFacesItem's one grammar; the
 * source `{file: uuid}` resolving to a managed file the file lane admits,
 * woff2 or woff; one face per weight and style).
 *
 * Errors carry the gesture's own paths ('family', 'faces',
 * 'faces.{i}', 'faces.{i}.weight', 'faces.{i}.style', 'faces.{i}.source',
 * 'faces.{i}.source.file'), so nothing re-roots.
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
  ) {}

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
      $weight = FontFacesItem::canonicalWeight($face['weight'] ?? NULL);
      if ($weight === NULL) {
        $errors[] = new BlueprintError("$at.weight", 'INVALID_FONT_WEIGHT', sprintf('"%s" is not a font weight.', is_scalar($face['weight']) ? (string) $face['weight'] : gettype($face['weight'])), [], 'A weight is a number from 1 to 1000 ("400"), a keyword ("normal", "bold"), or a variable-font range of two ascending numbers ("100 900").');
      }
      $style = FontFacesItem::canonicalStyle($face['style'] ?? NULL);
      if ($style === NULL) {
        $errors[] = new BlueprintError("$at.style", 'INVALID_FONT_STYLE', sprintf('"%s" is not a font style.', is_scalar($face['style']) ? (string) $face['style'] : gettype($face['style'])), ['styles' => FontFacesItem::STYLES], 'A style is one of normal, italic or oblique.');
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
    if (!is_array($source) || array_keys($source) !== [FontFacesItem::SOURCE_FILE]) {
      return [
        new BlueprintError($at, 'INVALID_FONT_SOURCE', 'A face\'s source is a typed map naming its file.', ['kinds' => [FontFacesItem::SOURCE_FILE]], 'Pass "source" as {"file": "<uuid>"}, the uuid of a font file the site holds (upload one first, or take it from the FONTS roster); no other source kind exists yet.'),
      ];
    }
    $uuid = $source[FontFacesItem::SOURCE_FILE];
    $path = $at . '.' . FontFacesItem::SOURCE_FILE;
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
    $extension = strtolower(pathinfo($file->getFilename() ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, FileLane::FONT_EXTENSIONS, TRUE)) {
      return [
        new BlueprintError($path, 'INVALID_FONT_FILE', sprintf('"%s" is not a font file.', $file->getFilename()), ['extensions' => FileLane::FONT_EXTENSIONS], sprintf('A face\'s file is one of: %s.', implode(', ', FileLane::FONT_EXTENSIONS))),
      ];
    }
    return [];
  }

}
