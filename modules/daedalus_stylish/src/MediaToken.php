<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * The media token grammar: url('media:<uuid>') inside CSS values.
 *
 * The one spine for CSS values that reference media entities. The token is
 * valid CSS url() syntax, so the printers, the code editor, and csstree
 * need no changes; the payload is the media entity's uuid, so the value
 * stays semantic and portable (shipped variants, workspaces, site moves)
 * and composes with gradients and comma layers naturally. Storage keeps
 * the token; resolution to a file URL is a pre-print map transform — the
 * printers never see a resolved URL.
 *
 * The canonical spelling is SINGLE-quoted (dialect 0.4): one form valid
 * inside style="" attributes (a double quote would close the attribute),
 * in <style> blocks, and in JSON strings, so storage, prints and teaching
 * share one spelling. Reading stays quote-agnostic: PATTERN accepts
 * double-quoted and unquoted tokens, so values stored before the flip
 * keep resolving and re-canonicalize on their next write — no migration.
 *
 * Pure string logic, no Drupal dependencies: PHP validation
 * (PropertyGroupBase), the server-side resolver, and tests all speak this
 * grammar through here so it can never fork. The client mirror lives in
 * js/src; PHP is authoritative.
 */
final class MediaToken {

  /**
   * The URL scheme marking a media reference.
   */
  public const SCHEME = 'media:';

  /**
   * Matches one token: url() wrapping the media scheme, quotes optional.
   *
   * The payload capture is deliberately loose ([^"'()\s]*) — extraction
   * hands back whatever sits in the uuid slot, and the caller decides
   * whether it is a valid uuid. Matching strictly here would silently skip
   * malformed tokens, and a skipped token would reach the printers as a
   * literal.
   */
  private const PATTERN = '/url\(\s*(["\']?)media:([^"\'()\s]*)\s*\1\s*\)/i';

  /**
   * Builds the canonical token for a media uuid.
   */
  public static function build(string $uuid): string {
    return sprintf("url('media:%s')", strtolower($uuid));
  }

  /**
   * Whether a value mentions the media scheme at all.
   *
   * The cheap gate in front of extraction, and — after substitution — the
   * remnant detector: a mention that extraction cannot account for is a
   * malformed reference (bare scheme outside url(), unclosed or
   * mismatched-quote url()).
   */
  public static function mentions(string $value): bool {
    return stripos($value, self::SCHEME) !== FALSE;
  }

  /**
   * Extracts every token's payload, in source order.
   *
   * @return string[]
   *   The uuid slots of all tokens in the value — not validated as uuids;
   *   the caller owns that judgment. Empty when the value carries no
   *   well-formed token.
   */
  public static function extract(string $value): array {
    preg_match_all(self::PATTERN, $value, $matches);
    return $matches[2];
  }

  /**
   * Replaces every token via a callable receiving the token's payload.
   *
   * @param string $value
   *   The CSS value.
   * @param callable $replacement
   *   fn (string $uuid): string — the CSS to substitute for the token
   *   (a resolved url(...), or "none" to blank it).
   *
   * @return string
   *   The value with all tokens substituted; everything else untouched.
   */
  public static function substitute(string $value, callable $replacement): string {
    return preg_replace_callback(self::PATTERN, static fn (array $matches): string => $replacement($matches[2]), $value);
  }

  /**
   * Whether a value contains media-scheme text the token grammar cannot parse.
   */
  public static function hasMalformedReference(string $value): bool {
    return self::mentions(self::substitute($value, static fn (): string => ''));
  }

}
