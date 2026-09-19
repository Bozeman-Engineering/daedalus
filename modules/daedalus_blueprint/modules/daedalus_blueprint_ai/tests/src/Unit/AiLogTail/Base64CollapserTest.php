<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Unit\AiLogTail;

// cspell:ignore AQABAAAAA
use Drupal\daedalus_blueprint_ai\AiLogTail\Base64Collapser;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the value-based base64 collapse behind the ai-tail command.
 */
#[CoversClass(Base64Collapser::class)]
#[Group('daedalus_blueprint')]
class Base64CollapserTest extends UnitTestCase {

  /**
   * Builds a deterministic, unpadded base64 run of the given length.
   *
   * @param int $length
   *   The run length.
   *
   * @return string
   *   A string that walks the whole base64 alphabet.
   */
  private function blob(int $length): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $run = '';
    for ($i = 0; $i < $length; $i++) {
      $run .= $alphabet[$i % 64];
    }
    return $run;
  }

  /**
   * The collapsed form of a match.
   *
   * @param string $whole
   *   The whole match (prefix and payload).
   * @param int $payload
   *   The payload length.
   *
   * @return string
   *   The head plus the byte count.
   */
  private function head(string $whole, int $payload): string {
    return substr($whole, 0, Base64Collapser::KEEP) . '... [' . $payload . ' bytes base64]';
  }

  /**
   * Tests that a data URI collapses with its media type in the head.
   */
  public function testDataUri(): void {
    $run = $this->blob(4000);
    $uri = 'data:image/png;base64,' . $run;

    $collapsed = (new Base64Collapser())->collapse($uri);

    $this->assertSame($this->head($uri, 4000), $collapsed);
    $this->assertStringStartsWith('data:image/png;base64,', $collapsed);
    $this->assertLessThan(100, strlen($collapsed));

    // The prefix is proof enough: a payload with no digits still collapses.
    $letters = 'data:image/jpeg;base64,' . str_repeat('abcdefghijklmnopqrstuvwxyz', 10);
    $this->assertSame($this->head($letters, 260), (new Base64Collapser())->collapse($letters));
  }

  /**
   * Tests that a bare run collapses at the floor and keeps its padding.
   */
  public function testLongRun(): void {
    $collapser = new Base64Collapser();
    $floor = Base64Collapser::MIN_RUN;

    $run = $this->blob($floor);
    $this->assertSame($this->head($run, $floor), $collapser->collapse($run));

    $padded = $this->blob(200) . '==';
    $this->assertSame($this->head($padded, 202), $collapser->collapse($padded));

    $short = $this->blob($floor - 1);
    $this->assertSame($short, $collapser->collapse($short));
  }

  /**
   * Tests that a blob inside prose collapses and the prose survives.
   */
  public function testRunInsideText(): void {
    $run = $this->blob(600);
    $text = "Here is the image: {$run} and a second one {$run}, done.";

    $collapsed = (new Base64Collapser())->collapse($text);

    $head = $this->head($run, 600);
    $this->assertSame("Here is the image: {$head} and a second one {$head}, done.", $collapsed);
  }

  /**
   * Tests that nested arrays collapse in place and keep keys and scalars.
   */
  public function testNestedArrays(): void {
    $run = $this->blob(2048);
    $data = [
      'candidates' => [
        [
          'content' => [
            'parts' => [
              ['inlineData' => ['mimeType' => 'image/png', 'data' => $run]],
              ['text' => 'A red bicycle.'],
            ],
          ],
          'finishReason' => 'STOP',
          'index' => 0,
          'safe' => TRUE,
          'score' => 0.5,
          'nothing' => NULL,
        ],
      ],
      'b64_json' => $run,
    ];

    $collapsed = (new Base64Collapser())->collapse($data);

    $head = $this->head($run, 2048);
    $part = $collapsed['candidates'][0]['content']['parts'];
    $this->assertSame($head, $part[0]['inlineData']['data']);
    $this->assertSame('image/png', $part[0]['inlineData']['mimeType']);
    $this->assertSame('A red bicycle.', $part[1]['text']);
    $this->assertSame('STOP', $collapsed['candidates'][0]['finishReason']);
    $this->assertSame(0, $collapsed['candidates'][0]['index']);
    $this->assertTrue($collapsed['candidates'][0]['safe']);
    $this->assertSame(0.5, $collapsed['candidates'][0]['score']);
    $this->assertNull($collapsed['candidates'][0]['nothing']);
    $this->assertSame($head, $collapsed['b64_json']);
    $this->assertSame(array_keys($data), array_keys($collapsed));
  }

  /**
   * Tests that short strings and non-string values pass through as they are.
   */
  public function testShortStringsUntouched(): void {
    $collapser = new Base64Collapser();

    $short = [
      '',
      'hello',
      'user',
      base64_encode('a small secret'),
      'data:image/gif;base64,R0lGODlhAQABAAAAACw=',
      str_repeat('x', Base64Collapser::MIN_RUN - 1),
    ];
    foreach ($short as $string) {
      $this->assertSame($string, $collapser->collapse($string));
    }
    $this->assertSame(7, $collapser->collapse(7));
    $this->assertNull($collapser->collapse(NULL));
    $this->assertFalse($collapser->collapse(FALSE));
  }

  /**
   * Tests that long strings that are not a base64 blob pass through.
   */
  public function testNonBase64LongStringsUntouched(): void {
    $collapser = new Base64Collapser();

    $long = [
      'prose' => str_repeat('The quick brown fox jumps over the lazy dog. ', 40),
      'url' => 'https://example.com/' . implode('/', array_fill(0, 40, 'segment')) . '?q=1',
      'path' => implode('/', array_fill(0, 60, 'segment')),
      'json' => json_encode(array_fill(0, 40, ['name' => 'page_create', 'ok' => TRUE])),
      'hex' => hash('sha512', 'x') . hash('sha512', 'y'),
      'HEX' => strtoupper(hash('sha512', 'x') . hash('sha512', 'y')),
      'uuids' => implode(',', array_fill(0, 10, '7c9a2b4e-1d3f-4a5b-8c6d-9e0f1a2b3c4d')),
      'css' => str_repeat('.card-title-large { margin-block: 0.5rem; }', 10),
      'word' => str_repeat('abcdefghijklmnopqrstuvwxyz', 20),
      'digits' => str_repeat('0123456789', 40),
    ];
    foreach ($long as $name => $string) {
      $this->assertGreaterThanOrEqual(Base64Collapser::MIN_RUN, strlen($string), $name);
      $this->assertSame($string, $collapser->collapse($string), $name);
    }
  }

}
