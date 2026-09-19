<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Render;

/**
 * The canvas state vector, one value per emulation axis.
 *
 * PHP mirror of daedalus_ui's vector grammar (js/canvas_vector/
 * vector-state.js): width is a number of CSS pixels clamped to the same
 * bounds, or NULL for the full canvas; scheme and motion are closed
 * vocabularies where 'auto' follows the real OS preference. The query
 * parameters produced here (?canvasWidth/?canvasScheme/?canvasMotion) are
 * the vector's out-of-band address: the edit-mode page boots already posed
 * at the state, which is what lets a headless renderer capture the page
 * exactly as the canvas chips would show it.
 *
 * One deliberate divergence from the JS: unrecognized values are rejected
 * loudly instead of silently ignored. The caller here is a language model,
 * and a corrective error teaches it the grammar; silent dropping would let
 * it believe it verified a state it never posed.
 */
final class StateVector {

  /**
   * Width bounds, mirroring vector-state.js MIN_WIDTH/MAX_WIDTH.
   */
  public const MIN_WIDTH = 240;
  public const MAX_WIDTH = 10000;

  /**
   * The scheme axis vocabulary.
   */
  public const SCHEMES = ['auto', 'light', 'dark'];

  /**
   * The motion axis vocabulary.
   */
  public const MOTIONS = ['auto', 'full', 'reduce'];

  private function __construct(
    public readonly ?int $width,
    public readonly string $scheme,
    public readonly string $motion,
  ) {}

  /**
   * Builds a vector from tool input, normalizing each axis.
   *
   * @param string|null $width
   *   Pixels ('375'), 'full', or NULL/'' for the full canvas.
   * @param string|null $scheme
   *   One of SCHEMES, or NULL for 'auto'.
   * @param string|null $motion
   *   One of MOTIONS, or NULL for 'auto'.
   *
   * @return self
   *   The normalized vector.
   *
   * @throws \InvalidArgumentException
   *   When a value is outside its axis vocabulary. The message is written
   *   for the model: it names the axis and spells the accepted values.
   */
  public static function fromToolInput(?string $width, ?string $scheme, ?string $motion): self {
    if ($width === NULL || $width === '' || $width === 'full') {
      $pixels = NULL;
    }
    elseif (is_numeric($width)) {
      $pixels = (int) round(min(self::MAX_WIDTH, max(self::MIN_WIDTH, (float) $width)));
    }
    else {
      throw new \InvalidArgumentException("Invalid canvas_width '$width': pass a number of CSS pixels (" . self::MIN_WIDTH . '-' . self::MAX_WIDTH . ") or 'full'.");
    }

    $scheme = ($scheme === NULL || $scheme === '') ? 'auto' : $scheme;
    if (!in_array($scheme, self::SCHEMES, TRUE)) {
      throw new \InvalidArgumentException("Invalid canvas_scheme '$scheme': pass one of " . implode(', ', self::SCHEMES) . '.');
    }

    $motion = ($motion === NULL || $motion === '') ? 'auto' : $motion;
    if (!in_array($motion, self::MOTIONS, TRUE)) {
      throw new \InvalidArgumentException("Invalid canvas_motion '$motion': pass one of " . implode(', ', self::MOTIONS) . '.');
    }

    return new self($pixels, $scheme, $motion);
  }

  /**
   * The boot query parameters, omitting axes at their default.
   *
   * An omitted parameter and the axis default are the same state to the
   * client store, so defaults stay out of the URL.
   *
   * @return array<string, string>
   *   canvasWidth/canvasScheme/canvasMotion for the posed axes only.
   */
  public function toQuery(): array {
    $query = [];
    if ($this->width !== NULL) {
      $query['canvasWidth'] = (string) $this->width;
    }
    if ($this->scheme !== 'auto') {
      $query['canvasScheme'] = $this->scheme;
    }
    if ($this->motion !== 'auto') {
      $query['canvasMotion'] = $this->motion;
    }
    return $query;
  }

  /**
   * A one-line human/model-readable description of the pose.
   */
  public function describe(): string {
    return sprintf(
      'width=%s, scheme=%s, motion=%s',
      $this->width === NULL ? 'full' : $this->width . 'px',
      $this->scheme,
      $this->motion,
    );
  }

}
