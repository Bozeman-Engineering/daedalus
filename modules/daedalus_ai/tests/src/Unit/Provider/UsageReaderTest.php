<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit\Provider;

use Anthropic\Messages\CacheCreation;
use Anthropic\Messages\Message;
use Anthropic\Messages\OutputTokensDetails;
use Anthropic\Messages\Usage as SdkUsage;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\Plugin\ProviderProxy;
use Drupal\daedalus_ai\Provider\ReportedCostInterface;
use Drupal\daedalus_ai\Provider\Usage;
use Drupal\daedalus_ai\Provider\UsageReader;
use Drupal\Tests\daedalus_ai\Unit\Provider\Fixture\CapableProvider;
use Drupal\Tests\UnitTestCase;
use Gemini\Responses\GenerativeModel\GenerateContentResponse;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the one read of a leg's usage across the shapes providers produce.
 *
 * The ledger and the segment window will both count from this reader, so
 * what is load-bearing is that the same numbers come out whatever carried
 * them in: a bare TokenUsageDto with its ai 1.5 cachedWrite field, the
 * Anthropic 1.3 provider's output (cachedWrite unfilled, the write in the
 * output metadata, the SDK Message as raw output), and a raw array in the
 * wire shape. The task's definition of done is the first pair: the
 * Anthropic 1.3 output and a bare DTO answer identically through one API.
 */
#[Group('daedalus_ai')]
class UsageReaderTest extends UnitTestCase {

  /**
   * A bare ai 1.5 DTO answers every count it carries, nothing else.
   */
  public function testBareDto(): void {
    $usage = (new UsageReader())->read(self::chatOutput(new TokenUsageDto(
      input: 4,
      output: 120,
      total: 124,
      reasoning: 30,
      cached: 15100,
      cachedWrite: 300,
    )));

    $this->assertEquals(new Usage(
      input: 4,
      cachedRead: 15100,
      cacheWrite: 300,
      output: 120,
      reasoning: 30,
    ), $usage);
    $this->assertNull($usage->cacheWrite5m, 'A DTO knows the write total, not its TTL split.');
    $this->assertNull($usage->cacheWrite1h);
    $this->assertNull($usage->reportedCostUsd);
    $this->assertSame(15404, $usage->promptTokens());
  }

  /**
   * The Anthropic 1.3 provider's output and a bare DTO read identically.
   *
   * The provider fills input, output, reasoning and cached on the DTO,
   * leaves cachedWrite NULL, puts cache_creation_tokens in the metadata and
   * keeps the SDK Message as raw output. The reader must answer the same
   * Usage as it does for a bare DTO carrying the same numbers.
   */
  public function testAnthropicProviderOutputEqualsBareDto(): void {
    $reader = new UsageReader();
    $bare = $reader->read(self::chatOutput(new TokenUsageDto(
      input: 4,
      output: 120,
      total: 124,
      reasoning: 120,
      cached: 15100,
      cachedWrite: 300,
    )));

    $anthropic = $reader->read(new ChatOutput(
      new ChatMessage('assistant', 'done'),
      self::sdkMessage(input: 4, output: 120, cachedRead: 15100, cacheWrite: 300),
      ['thinking' => 'hm', 'cache_creation_tokens' => 300],
      new TokenUsageDto(input: 4, output: 120, total: 124, reasoning: 120, cached: 15100),
    ));

    $this->assertEquals($bare, $anthropic);
    $this->assertSame(300, $anthropic->cacheWrite);
    $this->assertSame(15404, $anthropic->promptTokens());
  }

  /**
   * Each count comes from the first source that reports it, in order.
   *
   * A raw output in a known shape wins over the DTO, the DTO over the
   * metadata; a raw output in no known shape answers nothing, so the DTO
   * and the metadata keep their order behind it; a known-shaped raw that
   * lacks a count falls through to the DTO for that count alone; and a
   * reported zero is a report, not an absence.
   */
  public function testSourceOrder(): void {
    $reader = new UsageReader();

    $raw_wins = $reader->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      ['usage' => ['input_tokens' => 99, 'cache_creation_input_tokens' => 99]],
      ['cache_creation_tokens' => 50],
      new TokenUsageDto(input: 0, cachedWrite: 10),
    ));
    $this->assertSame(99, $raw_wins->input, 'A known-shaped raw output wins over a DTO that disagrees.');
    $this->assertSame(99, $raw_wins->cacheWrite, 'The raw write wins over the DTO and the metadata.');

    $dto_wins = $reader->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      ['foo' => 1],
      ['cache_creation_tokens' => 50],
      new TokenUsageDto(input: 0, cachedWrite: 10),
    ));
    $this->assertSame(0, $dto_wins->input, 'A DTO reporting zero input is read as zero, not skipped.');
    $this->assertSame(10, $dto_wins->cacheWrite, 'Behind an unknown raw output the DTO cachedWrite wins over the metadata.');

    $metadata_wins = $reader->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      ['foo' => 1],
      ['cache_creation_tokens' => 50],
      new TokenUsageDto(output: 7),
    ));
    $this->assertSame(50, $metadata_wins->cacheWrite, 'A count the DTO lacks falls through to the metadata.');
    $this->assertSame(7, $metadata_wins->output);
    $this->assertNull($metadata_wins->input, 'An unknown raw output reports nothing.');

    $raw_lacks_one = $reader->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      ['usage' => ['input_tokens' => 99]],
      [],
      new TokenUsageDto(output: 7),
    ));
    $this->assertSame(99, $raw_lacks_one->input, 'The raw output answers the count it carries.');
    $this->assertSame(7, $raw_lacks_one->output, 'A count the known-shaped raw lacks falls through to the DTO for that count alone.');
  }

  /**
   * The raw SDK Message is the one source that splits the write by TTL.
   */
  public function testSdkMessageSplitsTheWriteByTtl(): void {
    $usage = (new UsageReader())->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      self::sdkMessage(input: 2, output: 40, cachedRead: 9000, cacheWrite: 1200, write5m: 200, write1h: 1000, thinking: 25),
      [],
      new TokenUsageDto(input: 2, output: 40, cached: 9000),
    ));

    $this->assertSame(1200, $usage->cacheWrite);
    $this->assertSame(200, $usage->cacheWrite5m);
    $this->assertSame(1000, $usage->cacheWrite1h);
    $this->assertSame(25, $usage->reasoning, 'Thinking tokens come from the raw output when the DTO has none.');
    $this->assertSame(10202, $usage->promptTokens());
  }

  /**
   * A raw array in the wire shape answers everything, split included.
   *
   * Not every provider wraps the response in an SDK object; some hand the
   * reader the decoded HTTP body as it came off the wire. The reader takes
   * the cache split straight from that array and, when the wire carries the
   * split but not the total, sums the total itself.
   */
  public function testRawWireArray(): void {
    $reader = new UsageReader();
    $raw = [
      'id' => 'msg_1',
      'usage' => [
        'input_tokens' => 3,
        'output_tokens' => 80,
        'cache_read_input_tokens' => 14000,
        'cache_creation_input_tokens' => 500,
        'cache_creation' => ['ephemeral_5m_input_tokens' => 500, 'ephemeral_1h_input_tokens' => 0],
        'output_tokens_details' => ['thinking_tokens' => 12],
      ],
    ];
    $usage = $reader->read(new ChatOutput(new ChatMessage('assistant', ''), $raw, [], new TokenUsageDto()));
    $this->assertEquals(new Usage(
      input: 3,
      cachedRead: 14000,
      cacheWrite: 500,
      cacheWrite5m: 500,
      cacheWrite1h: 0,
      output: 80,
      reasoning: 12,
    ), $usage);

    unset($raw['usage']['cache_creation_input_tokens']);
    $raw['usage']['cache_creation'] = ['ephemeral_5m_input_tokens' => 100, 'ephemeral_1h_input_tokens' => 400];
    $summed = $reader->read(new ChatOutput(new ChatMessage('assistant', ''), $raw, [], new TokenUsageDto()));
    $this->assertSame(500, $summed->cacheWrite, 'The total is the split summed when the wire omits it.');
    $this->assertSame(100, $summed->cacheWrite5m);
    $this->assertSame(400, $summed->cacheWrite1h);
  }

  /**
   * An output carrying no usage anywhere answers NULL everywhere.
   */
  public function testNothingReported(): void {
    $usage = (new UsageReader())->read(new ChatOutput(new ChatMessage('assistant', ''), NULL, NULL));
    $this->assertTrue($usage->isEmpty());
    $this->assertNull($usage->promptTokens());

    $not_chat = new class() implements OutputInterface {

      /**
       * {@inheritdoc}
       */
      public function getNormalized() {
        return 'image bytes';
      }

      /**
       * {@inheritdoc}
       */
      public function getRawOutput() {
        return ['usage' => ['input_tokens' => 11, 'output_tokens' => 0]];
      }

      /**
       * {@inheritdoc}
       */
      public function getMetadata() {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function toArray(): array {
        return [];
      }

    };
    $usage = (new UsageReader())->read($not_chat);
    $this->assertSame(11, $usage->input, 'An output without a DTO still reads its raw usage.');
    $this->assertSame(0, $usage->output);
    $this->assertNull($usage->cachedRead);
  }

  /**
   * The reported cost comes from the provider's capability, proxied or not.
   */
  public function testReportedCost(): void {
    $reader = new UsageReader();
    $output = self::chatOutput(new TokenUsageDto(input: 1, output: 1));
    $reporter = new class() implements ReportedCostInterface {

      /**
       * {@inheritdoc}
       */
      public function reportedCostUsd(OutputInterface $output): ?float {
        return 0.0421;
      }

    };

    $this->assertNull($reader->read($output)->reportedCostUsd, 'No provider, no cost.');
    $this->assertNull($reader->read($output, new \stdClass())->reportedCostUsd, 'A provider without the capability reports none.');
    $this->assertSame(0.0421, $reader->read($output, $reporter)->reportedCostUsd);

    $proxy = (new \ReflectionClass(ProviderProxy::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(ProviderProxy::class, 'plugin'))->setValue($proxy, CapableProvider::bare());
    $this->assertSame(CapableProvider::COST_USD, $reader->read($output, $proxy)->reportedCostUsd, 'The cost is read through drupal/ai\'s proxy.');
  }

  /**
   * Gemini's usageMetadata on an image output reads through the same API.
   *
   * The Gemini provider hands the response array (GenerateContentResponse::
   * toArray()) over as the raw output of textToImage(), with no DTO and no
   * metadata, so the reader is the only thing that can count an image
   * leg. The output is the candidates plus the thoughts (what Gemini bills
   * as output), the reasoning the thoughts alone, and no cache write is
   * ever reported.
   */
  public function testGeminiUsageMetadataOnImageOutput(): void {
    $usage = (new UsageReader())->read(new TextToImageOutput([], self::geminiResponseArray(prompt: 20, candidates: 1120, thoughts: 50, cached: 0), []));

    $this->assertEquals(new Usage(
      input: 20,
      cachedRead: 0,
      output: 1170,
      reasoning: 50,
    ), $usage);
    $this->assertNull($usage->cacheWrite, 'Gemini bills explicit caches by storage, never per write.');
    $this->assertSame(20, $usage->promptTokens());
  }

  /**
   * A chat output with the same raw and an empty DTO reads the same.
   *
   * The response object gemini_provider keeps as the raw output of chat()
   * is read by the same member name as the array, so both shapes answer
   * identically.
   */
  public function testGeminiUsageMetadataOnChatOutput(): void {
    $reader = new UsageReader();
    $raw = self::geminiResponseArray(prompt: 20, candidates: 1120, thoughts: 50, cached: 0);
    $image = $reader->read(new TextToImageOutput([], $raw, []));

    $from_array = $reader->read(new ChatOutput(new ChatMessage('assistant', ''), $raw, [], new TokenUsageDto()));
    $this->assertEquals($image, $from_array);

    $from_object = $reader->read(new ChatOutput(new ChatMessage('assistant', ''), GenerateContentResponse::from($raw), [], new TokenUsageDto()));
    $this->assertEquals($image, $from_object, 'The SDK response object answers the same as its array form.');
  }

  /**
   * The prompt count includes the cached part, so input is the difference.
   *
   * The Gemini API reference: "when cachedContent is set, this is still
   * the total effective prompt size meaning this includes the number of
   * tokens in the cached content".
   */
  public function testGeminiPromptCountIncludesTheCachedPart(): void {
    $usage = (new UsageReader())->read(new TextToImageOutput([], self::geminiResponseArray(prompt: 1000, candidates: 10, thoughts: NULL, cached: 400), []));

    $this->assertSame(600, $usage->input);
    $this->assertSame(400, $usage->cachedRead);
    $this->assertSame(1000, $usage->promptTokens());
  }

  /**
   * Without a thoughts count the output is the candidates and reasoning NULL.
   */
  public function testGeminiWithoutThoughts(): void {
    $usage = (new UsageReader())->read(new TextToImageOutput([], self::geminiResponseArray(prompt: 20, candidates: 1120, thoughts: NULL, cached: NULL), []));

    $this->assertSame(1120, $usage->output);
    $this->assertNull($usage->reasoning);
    $this->assertNull($usage->cachedRead, 'A count the response does not carry is NULL, not zero.');
    $this->assertSame(20, $usage->input, 'With no cached count the whole prompt is input.');
  }

  /**
   * A Gemini chat leg is read off its raw response, not the provider's DTO.
   *
   * The provider's setChatTokenUsage() fills the DTO lossily: input is
   * promptTokenCount with the cached part included, output is the
   * candidates without the thoughts. Read off the DTO the leg would bill
   * the cached tokens twice and the thinking at nothing; the raw response
   * carries the complete counts and wins.
   */
  public function testGeminiChatLegReadsTheRawOverTheDto(): void {
    $usage = (new UsageReader())->read(new ChatOutput(
      new ChatMessage('assistant', ''),
      self::geminiResponseArray(prompt: 1000, candidates: 100, thoughts: 50, cached: 400),
      [],
      new TokenUsageDto(input: 1000, output: 100, total: 1150, reasoning: 50, cached: 400),
    ));

    $this->assertSame(600, $usage->input, 'The cached part is not counted again as input.');
    $this->assertSame(400, $usage->cachedRead);
    $this->assertSame(150, $usage->output, 'The thoughts are billed as output.');
    $this->assertSame(50, $usage->reasoning);
    $this->assertNull($usage->cacheWrite);
    $this->assertSame(1000, $usage->promptTokens());
  }

  /**
   * A Gemini response array as GenerateContentResponse::toArray() gives it.
   *
   * A NULL count is left out of usageMetadata, as the API leaves out a
   * field it does not report; the SDK's toArray() keeps the key with NULL,
   * which reads the same.
   */
  private static function geminiResponseArray(int $prompt, ?int $candidates, ?int $thoughts, ?int $cached): array {
    $metadata = [
      'promptTokenCount' => $prompt,
      'totalTokenCount' => $prompt + ($candidates ?? 0) + ($thoughts ?? 0),
    ];
    if ($candidates !== NULL) {
      $metadata['candidatesTokenCount'] = $candidates;
    }
    if ($thoughts !== NULL) {
      $metadata['thoughtsTokenCount'] = $thoughts;
    }
    if ($cached !== NULL) {
      $metadata['cachedContentTokenCount'] = $cached;
    }
    return [
      'candidates' => [],
      'promptFeedback' => NULL,
      'usageMetadata' => $metadata,
      'modelVersion' => 'gemini-test',
    ];
  }

  /**
   * A chat output carrying only a DTO.
   */
  private static function chatOutput(TokenUsageDto $dto): ChatOutput {
    return new ChatOutput(new ChatMessage('assistant', 'ok'), NULL, [], $dto);
  }

  /**
   * An SDK Message as the Anthropic provider keeps it on the raw output.
   */
  private static function sdkMessage(int $input, int $output, ?int $cachedRead, ?int $cacheWrite, ?int $write5m = NULL, ?int $write1h = NULL, ?int $thinking = NULL): Message {
    $usage = SdkUsage::with(
      cacheCreation: $write5m === NULL && $write1h === NULL ? NULL : CacheCreation::with(ephemeral1hInputTokens: $write1h ?? 0, ephemeral5mInputTokens: $write5m ?? 0),
      cacheCreationInputTokens: $cacheWrite,
      cacheReadInputTokens: $cachedRead,
      inferenceGeo: NULL,
      inputTokens: $input,
      outputTokens: $output,
      outputTokensDetails: $thinking === NULL ? NULL : OutputTokensDetails::with(thinkingTokens: $thinking),
      serverToolUse: NULL,
      serviceTier: NULL,
    );
    return Message::with(
      id: 'msg_test',
      container: NULL,
      content: [],
      model: 'claude-test',
      stopDetails: NULL,
      stopReason: 'end_turn',
      stopSequence: NULL,
      usage: $usage,
    );
  }

}
