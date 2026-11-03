<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

use Drupal\ai\OperationType\Chat\ChatInput;

/**
 * What the spine asks a provider to render beyond the messages themselves.
 *
 * The context spine renders a transcript into a ChatInput's message list and
 * attaches one plan to the input's request metadata under
 * self::METADATA_KEY. The plan speaks in provider-neutral intent: a cache
 * breakpoint on the stable prefix (tools plus system prompt) with its TTL,
 * breakpoints on chosen messages, and the opaque provider-native content
 * to replay on assistant messages. Every index is a position in
 * ChatInput::getMessages() as the spine handed it over; a provider that
 * reorders messages before rendering must map the indexes itself.
 *
 * A provider reads only the parts it declares a capability for
 * (CacheBreakpointsInterface, OpaqueContentInterface) and ignores the
 * rest; the spine already rendered
 * the floor for every shape the provider lacks, so ignoring is correct.
 *
 * The object is immutable: every with*() answers a new plan. It lives in
 * metadata as its array form (self::toArray()) so the metadata stays
 * serializable wherever drupal/ai carries it (events, logging).
 */
final class RenderingPlan {

  public const TTL_5M = '5m';

  public const TTL_1H = '1h';

  public const TTLS = [self::TTL_5M, self::TTL_1H];

  public const METADATA_KEY = 'daedalus_ai.rendering_plan';

  /**
   * Constructs a plan; use empty() and the with*() methods.
   *
   * @param string|null $prefixTtl
   *   The TTL of the breakpoint on the stable prefix, or NULL for none.
   * @param array<int, string> $breakpoints
   *   Message index to TTL, ascending by index.
   * @param array<int, array> $opaque
   *   Message index to the provider-native content to replay there.
   */
  private function __construct(
    private readonly ?string $prefixTtl,
    private readonly array $breakpoints,
    private readonly array $opaque,
  ) {
  }

  /**
   * A plan that asks for nothing.
   */
  public static function empty(): self {
    return new self(NULL, [], []);
  }

  /**
   * The plan with a breakpoint on the stable prefix.
   *
   * @param string|null $ttl
   *   One of self::TTLS, or NULL to place none.
   */
  public function withPrefixTtl(?string $ttl): self {
    if ($ttl !== NULL) {
      self::assertTtl($ttl);
    }
    return new self($ttl, $this->breakpoints, $this->opaque);
  }

  /**
   * The plan with a breakpoint on one message.
   *
   * @param int $index
   *   The message's position in ChatInput::getMessages().
   * @param string $ttl
   *   One of self::TTLS.
   */
  public function withBreakpoint(int $index, string $ttl): self {
    self::assertIndex($index);
    self::assertTtl($ttl);
    $breakpoints = $this->breakpoints;
    $breakpoints[$index] = $ttl;
    ksort($breakpoints);
    return new self($this->prefixTtl, $breakpoints, $this->opaque);
  }

  /**
   * The plan with provider-native content to replay on one message.
   *
   * @param int $index
   *   The assistant message's position in ChatInput::getMessages().
   * @param array $content
   *   The content as the provider's OpaqueContentInterface answered it.
   */
  public function withOpaque(int $index, array $content): self {
    self::assertIndex($index);
    $opaque = $this->opaque;
    $opaque[$index] = $content;
    ksort($opaque);
    return new self($this->prefixTtl, $this->breakpoints, $opaque);
  }

  /**
   * The TTL of the breakpoint on the stable prefix, or NULL for none.
   */
  public function prefixTtl(): ?string {
    return $this->prefixTtl;
  }

  /**
   * Message breakpoints, index to TTL, ascending by index.
   *
   * @return array<int, string>
   *   The breakpoints.
   */
  public function breakpoints(): array {
    return $this->breakpoints;
  }

  /**
   * The TTL of the breakpoint on one message, or NULL when it has none.
   */
  public function breakpointAt(int $index): ?string {
    return $this->breakpoints[$index] ?? NULL;
  }

  /**
   * Opaque content to replay, index to content, ascending by index.
   *
   * @return array<int, array>
   *   The content by message index.
   */
  public function opaque(): array {
    return $this->opaque;
  }

  /**
   * The opaque content to replay on one message, or NULL when it has none.
   */
  public function opaqueAt(int $index): ?array {
    return $this->opaque[$index] ?? NULL;
  }

  /**
   * Whether the plan asks for nothing at all.
   */
  public function isEmpty(): bool {
    return $this->prefixTtl === NULL
      && $this->breakpoints === []
      && $this->opaque === [];
  }

  /**
   * The plan as a serializable array; fromArray() reads it back.
   *
   * @return array{prefix_ttl: string|null, breakpoints: array<int, string>, opaque: array<int, array>}
   *   The plan.
   */
  public function toArray(): array {
    return [
      'prefix_ttl' => $this->prefixTtl,
      'breakpoints' => $this->breakpoints,
      'opaque' => $this->opaque,
    ];
  }

  /**
   * A plan from its array form, validated as the with*() methods validate.
   *
   * @param array $values
   *   What toArray() produced. Missing keys read as empty.
   *
   * @throws \InvalidArgumentException
   *   When a TTL, an index or a value is malformed.
   */
  public static function fromArray(array $values): self {
    $plan = self::empty()->withPrefixTtl(self::nullableString($values['prefix_ttl'] ?? NULL, 'prefix_ttl'));
    foreach (self::list($values['breakpoints'] ?? [], 'breakpoints') as $index => $ttl) {
      if (!is_string($ttl)) {
        throw new \InvalidArgumentException('A rendering plan breakpoint TTL must be a string.');
      }
      $plan = $plan->withBreakpoint(self::index($index), $ttl);
    }
    foreach (self::list($values['opaque'] ?? [], 'opaque') as $index => $content) {
      if (!is_array($content)) {
        throw new \InvalidArgumentException('Rendering plan opaque content must be an array.');
      }
      $plan = $plan->withOpaque(self::index($index), $content);
    }
    return $plan;
  }

  /**
   * Carries the plan on a chat input's request metadata.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The input the spine rendered.
   */
  public function attachTo(ChatInput $input): void {
    $input->setRequestMetadataValue(self::METADATA_KEY, $this->toArray());
  }

  /**
   * The plan a chat input carries, or NULL when the spine attached none.
   *
   * NULL and an empty plan differ: with no plan at all a provider behaves
   * as it does for any other caller; with an empty plan the spine asked it
   * to place nothing.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The input.
   *
   * @throws \InvalidArgumentException
   *   When the metadata under self::METADATA_KEY is not a plan.
   */
  public static function fromInput(ChatInput $input): ?self {
    $values = $input->getRequestMetadataValue(self::METADATA_KEY);
    if ($values === NULL) {
      return NULL;
    }
    if (!is_array($values)) {
      throw new \InvalidArgumentException(sprintf('Request metadata %s must hold a rendering plan array.', self::METADATA_KEY));
    }
    return self::fromArray($values);
  }

  /**
   * Refuses a TTL outside self::TTLS.
   */
  private static function assertTtl(string $ttl): void {
    if (!in_array($ttl, self::TTLS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown cache TTL "%s"; expected one of %s.', $ttl, implode(', ', self::TTLS)));
    }
  }

  /**
   * Refuses a negative message index.
   */
  private static function assertIndex(int $index): void {
    if ($index < 0) {
      throw new \InvalidArgumentException(sprintf('A rendering plan message index must not be negative, got %d.', $index));
    }
  }

  /**
   * Reads an array key from the array form as a message index.
   */
  private static function index(mixed $value): int {
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
      throw new \InvalidArgumentException('A rendering plan message index must be an integer.');
    }
    return (int) $value;
  }

  /**
   * Reads a list from the array form.
   */
  private static function list(mixed $value, string $key): array {
    if (!is_array($value)) {
      throw new \InvalidArgumentException(sprintf('Rendering plan key %s must be an array.', $key));
    }
    return $value;
  }

  /**
   * Reads an optional string from the array form.
   */
  private static function nullableString(mixed $value, string $key): ?string {
    if ($value !== NULL && !is_string($value)) {
      throw new \InvalidArgumentException(sprintf('Rendering plan key %s must be a string or NULL.', $key));
    }
    return $value;
  }

}
