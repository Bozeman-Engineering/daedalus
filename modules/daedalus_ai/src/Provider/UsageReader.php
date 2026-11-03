<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\OutputInterface;

/**
 * Reads one leg's usage from a provider output, whatever shape carries it.
 *
 * Versions of drupal/ai and providers disagree on where usage lives: the
 * TokenUsageDto has a cachedWrite field from ai 1.5 that the Anthropic 1.3
 * provider does not fill (it puts cache_creation_tokens in the output's
 * metadata instead), ai 1.4 has no such field at all, and the raw output
 * (the Anthropic SDK Message, or an array response) is the only place the
 * write is split by TTL and the thinking tokens are counted apart. The
 * reader answers every count through one API, reading each from the first
 * source that reports it: the raw output when it is in a shape the reader
 * knows, then the DTO, then the metadata. The raw output comes first
 * because it is the provider's own complete response, while the DTO is a
 * projection a provider may fill lossily: gemini_provider's
 * setChatTokenUsage() fills input with the cached part included and output
 * without the thoughts, so a Gemini chat leg read off its DTO would bill
 * the cached tokens twice and the thinking at nothing. A raw output in no
 * known shape answers nothing, so the DTO-then-metadata order holds there,
 * and a known-shaped raw that lacks one count falls through to the DTO for
 * that count alone; nothing keys on the provider. For Anthropic the order
 * changes nothing: the SDK Message and the wire array report every count
 * the DTO does with the same values. The provider-reported cost comes from
 * the plugin's ReportedCostInterface when it declares one.
 *
 * The raw output is read by shape, not by class, so the module never
 * depends on a provider's SDK. Two shapes are known. Anthropic's: an object
 * with a usage property whose fields are camelCase (the SDK's Message and
 * Usage models), or an array with a usage key whose fields are snake_case
 * (the wire shape). Gemini's: a usageMetadata member (the array
 * GenerateContentResponse::toArray() gives, which gemini_provider passes as
 * the raw output of chat() and textToImage() alike, or the response object
 * itself), whose promptTokenCount includes the cached part of the prompt
 * (the API reference: "when cachedContent is set, this is still the total
 * effective prompt size"), so input is the prompt count less
 * cachedContentTokenCount; output is candidatesTokenCount plus
 * thoughtsTokenCount, since Gemini counts thinking apart and bills it as
 * output, and reasoning is the thoughts count alone; no cache write is
 * ever reported there (Gemini bills explicit caches by storage, not per
 * write), so cacheWrite stays NULL.
 */
final class UsageReader {

  /**
   * Reads the usage of one leg.
   *
   * @param \Drupal\ai\OperationType\OutputInterface $output
   *   The leg's output.
   * @param object|null $provider
   *   The provider plugin or its proxy, for the reported cost; NULL reads
   *   no cost.
   */
  public function read(OutputInterface $output, ?object $provider = NULL): Usage {
    $sources = [
      $this->fromRaw($output->getRawOutput()),
      $this->fromDto($output),
      $this->fromMetadata($output->getMetadata()),
    ];
    $first = static function (string $key) use ($sources): ?int {
      foreach ($sources as $source) {
        if (isset($source[$key])) {
          return $source[$key];
        }
      }
      return NULL;
    };

    $write_5m = $first('cacheWrite5m');
    $write_1h = $first('cacheWrite1h');
    $write = $first('cacheWrite');
    if ($write === NULL && ($write_5m !== NULL || $write_1h !== NULL)) {
      $write = ($write_5m ?? 0) + ($write_1h ?? 0);
    }

    $cost = NULL;
    if ($provider !== NULL) {
      $cost = ProviderCapabilities::as($provider, ReportedCostInterface::class)?->reportedCostUsd($output);
    }

    return new Usage(
      input: $first('input'),
      cachedRead: $first('cachedRead'),
      cacheWrite: $write,
      cacheWrite5m: $write_5m,
      cacheWrite1h: $write_1h,
      output: $first('output'),
      reasoning: $first('reasoning'),
      reportedCostUsd: $cost,
    );
  }

  /**
   * The counts the output's TokenUsageDto carries.
   *
   * Only ChatOutput has a DTO; other output types answer nothing here. The
   * cachedWrite field exists from ai 1.5, so it is read only where the
   * property exists and was filled.
   *
   * @return array<string, int|null>
   *   Counts keyed by Usage field name.
   */
  private function fromDto(OutputInterface $output): array {
    if (!method_exists($output, 'getTokenUsage')) {
      return [];
    }
    $dto = $output->getTokenUsage();
    if (!$dto instanceof TokenUsageDto) {
      return [];
    }
    return [
      'input' => self::int($dto->input),
      'output' => self::int($dto->output),
      'reasoning' => self::int($dto->reasoning),
      'cachedRead' => self::int($dto->cached),
      'cacheWrite' => property_exists($dto, 'cachedWrite') ? self::int($dto->cachedWrite) : NULL,
    ];
  }

  /**
   * The counts the output's metadata carries.
   *
   * The Anthropic 1.3 provider writes the cache write there as
   * cache_creation_tokens; the wire name cache_creation_input_tokens is
   * accepted beside it.
   *
   * @return array<string, int|null>
   *   Counts keyed by Usage field name.
   */
  private function fromMetadata(mixed $metadata): array {
    if (!is_array($metadata)) {
      return [];
    }
    return [
      'cacheWrite' => self::int($metadata['cache_creation_tokens'] ?? $metadata['cache_creation_input_tokens'] ?? NULL),
    ];
  }

  /**
   * The counts the raw output carries, by the shape it is in.
   *
   * @return array<string, int|null>
   *   Counts keyed by Usage field name.
   */
  private function fromRaw(mixed $raw): array {
    $usage = self::member($raw, 'usage', 'usage');
    if ($usage !== NULL) {
      return self::fromAnthropicUsage($usage);
    }
    $metadata = self::member($raw, 'usageMetadata', 'usageMetadata');
    if ($metadata !== NULL) {
      return self::fromGeminiUsageMetadata($metadata);
    }
    return [];
  }

  /**
   * The counts of Anthropic's usage member, SDK object or wire array.
   *
   * @return array<string, int|null>
   *   Counts keyed by Usage field name.
   */
  private static function fromAnthropicUsage(mixed $usage): array {
    $creation = self::member($usage, 'cache_creation', 'cacheCreation');
    $details = self::member($usage, 'output_tokens_details', 'outputTokensDetails');
    return [
      'input' => self::int(self::member($usage, 'input_tokens', 'inputTokens')),
      'output' => self::int(self::member($usage, 'output_tokens', 'outputTokens')),
      'cachedRead' => self::int(self::member($usage, 'cache_read_input_tokens', 'cacheReadInputTokens')),
      'cacheWrite' => self::int(self::member($usage, 'cache_creation_input_tokens', 'cacheCreationInputTokens')),
      'cacheWrite5m' => self::int(self::member($creation, 'ephemeral_5m_input_tokens', 'ephemeral5mInputTokens')),
      'cacheWrite1h' => self::int(self::member($creation, 'ephemeral_1h_input_tokens', 'ephemeral1hInputTokens')),
      'reasoning' => self::int(self::member($details, 'thinking_tokens', 'thinkingTokens')),
    ];
  }

  /**
   * The counts of Gemini's usageMetadata member, response object or array.
   *
   * The SDK names the fields the same way in both (camelCase). The prompt
   * count includes the cached part, so the uncached input is the
   * difference; the output is what Gemini bills as output, the candidates
   * plus the thoughts it counts apart. A count the response does not
   * carry stays NULL; a prompt count with no cached count is all input.
   *
   * @return array<string, int|null>
   *   Counts keyed by Usage field name.
   */
  private static function fromGeminiUsageMetadata(mixed $metadata): array {
    $prompt = self::int(self::member($metadata, 'promptTokenCount', 'promptTokenCount'));
    $cached = self::int(self::member($metadata, 'cachedContentTokenCount', 'cachedContentTokenCount'));
    $candidates = self::int(self::member($metadata, 'candidatesTokenCount', 'candidatesTokenCount'));
    $thoughts = self::int(self::member($metadata, 'thoughtsTokenCount', 'thoughtsTokenCount'));
    return [
      'input' => $prompt === NULL ? NULL : $prompt - ($cached ?? 0),
      'cachedRead' => $cached,
      'output' => $candidates === NULL && $thoughts === NULL ? NULL : ($candidates ?? 0) + ($thoughts ?? 0),
      'reasoning' => $thoughts,
    ];
  }

  /**
   * A member of an array (by its snake key) or an object (by its property).
   */
  private static function member(mixed $container, string $key, string $property): mixed {
    if (is_array($container)) {
      return $container[$key] ?? NULL;
    }
    if (is_object($container)) {
      return $container->$property ?? NULL;
    }
    return NULL;
  }

  /**
   * A count as an integer, or NULL when the source did not report one.
   */
  private static function int(mixed $value): ?int {
    return is_numeric($value) ? (int) $value : NULL;
  }

}
