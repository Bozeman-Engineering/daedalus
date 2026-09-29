<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\daedalus_ai_memory\FailureReason;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins which line of a raw failure message reaches the chat.
 */
#[CoversClass(FailureReason::class)]
#[Group('daedalus_ai_memory')]
class FailureReasonTest extends UnitTestCase {

  /**
   * The Anthropic SDK's message shape: a label line, then the response body.
   */
  private const ANTHROPIC = "Anthropic Bad Request Exception\n{\n    \"status\": 400,\n    \"body\": {\n        \"type\": \"error\",\n        \"error\": {\n            \"type\": \"invalid_request_error\",\n            \"message\": \"messages.0.content.1.image.source.base64.data: At least one of the image dimensions exceed max allowed size: 8000 pixels\"\n        },\n        \"request_id\": \"req_011\"\n    }\n}";

  /**
   * Each message shape and the sentence it must distill to.
   */
  public static function messages(): iterable {
    yield 'anthropic sdk body' => [
      self::ANTHROPIC,
      'messages.0.content.1.image.source.base64.data: At least one of the image dimensions exceed max allowed size: 8000 pixels',
    ];
    yield 'bare api error object' => [
      '{"error": {"type": "overloaded_error", "message": "Overloaded"}}',
      'Overloaded',
    ];
    yield 'plain exception' => [
      'provider exploded',
      'provider exploded',
    ];
    yield 'multi-line without a body' => [
      "\n  Connection refused\nat line 3",
      'Connection refused',
    ];
    yield 'body without a sentence falls back to the label' => [
      "Anthropic API Exception\n{\"status\": 500, \"body\": null}",
      'Anthropic API Exception',
    ];
    yield 'braces that are not json fall back to the first line' => [
      "Unexpected token { in prompt\nsecond line",
      'Unexpected token { in prompt',
    ];
  }

  #[DataProvider('messages')]
  public function testDistill(string $raw, string $expected): void {
    $this->assertSame($expected, FailureReason::distill($raw));
  }

}
