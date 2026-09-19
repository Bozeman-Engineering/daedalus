<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Unit\AiLogTail;

use Drupal\daedalus_blueprint_ai\AiLogTail\Message;
use Drupal\daedalus_blueprint_ai\AiLogTail\Transcript;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the prompt column parser behind the ai-tail command.
 */
#[CoversClass(Transcript::class)]
#[CoversClass(Message::class)]
#[Group('daedalus_blueprint')]
class TranscriptTest extends UnitTestCase {

  /**
   * A chat prompt in the exact shape ChatInput::toString() writes.
   */
  private const CHAT = "user\nMake a hero.\nassistant\nCalling page_create.\ntool\n{\"ok\": true,\n\"id\": 7}\nuser\nNow a footer.\n";

  /**
   * Tests that role lines split a chat prompt into messages.
   */
  public function testParseChat(): void {
    $transcript = Transcript::parse(self::CHAT);

    $this->assertTrue($transcript->isChat());
    $this->assertSame(4, $transcript->count());
    $this->assertSame(
      [
        ['user', 'Make a hero.'],
        ['assistant', 'Calling page_create.'],
        ['tool', "{\"ok\": true,\n\"id\": 7}"],
        ['user', 'Now a footer.'],
      ],
      array_map(fn(Message $m): array => [$m->role, $m->text], $transcript->messages),
    );
  }

  /**
   * Tests that a prompt without role lines is one free-text message.
   */
  public function testParseNonChat(): void {
    $transcript = Transcript::parse("A watercolor of a lighthouse\nat dusk\n");

    $this->assertFalse($transcript->isChat());
    $this->assertSame(1, $transcript->count());
    $this->assertNull($transcript->messages[0]->role);
    $this->assertSame("A watercolor of a lighthouse\nat dusk", $transcript->messages[0]->text);
  }

  /**
   * Tests that an empty prompt has no messages.
   */
  public function testParseEmpty(): void {
    $transcript = Transcript::parse('');

    $this->assertFalse($transcript->isChat());
    $this->assertSame(0, $transcript->count());
    $this->assertSame('', $transcript->toString());
  }

  /**
   * Tests that text before the first role line survives as a NULL-role message.
   */
  public function testParsePreamble(): void {
    $transcript = Transcript::parse("context line\nuser\nhello\n");

    $this->assertTrue($transcript->isChat());
    $this->assertSame(2, $transcript->count());
    $this->assertNull($transcript->messages[0]->role);
    $this->assertSame('context line', $transcript->messages[0]->text);
    $this->assertSame('user', $transcript->messages[1]->role);
  }

  /**
   * Tests that a role line with no text and a message with blank lines parse.
   */
  public function testParseEdges(): void {
    $transcript = Transcript::parse("user\nassistant\nline one\n\nline three\n");

    $this->assertSame(2, $transcript->count());
    $this->assertSame('user', $transcript->messages[0]->role);
    $this->assertSame('', $transcript->messages[0]->text);
    $this->assertSame("line one\n\nline three", $transcript->messages[1]->text);
  }

  /**
   * Tests that only a whole line is a boundary, never a role word inline.
   */
  public function testOnlyWholeLinesAreBoundaries(): void {
    $transcript = Transcript::parse("user\nthe user said tool\n user\nassistant\nok\n");

    $this->assertSame(2, $transcript->count());
    $this->assertSame("the user said tool\n user", $transcript->messages[0]->text);
  }

  /**
   * Tests that parse and toString round-trip a ChatInput prompt exactly.
   */
  public function testRoundTrip(): void {
    $this->assertSame(self::CHAT, Transcript::parse(self::CHAT)->toString());
    $nonChat = "A lighthouse\n";
    $this->assertSame($nonChat, Transcript::parse($nonChat)->toString());
  }

  /**
   * Tests that slice keeps the trailing messages of a chat.
   */
  public function testSlice(): void {
    $transcript = Transcript::parse(self::CHAT);

    $last2 = $transcript->slice(2);
    $this->assertSame(2, $last2->count());
    $this->assertSame('tool', $last2->messages[0]->role);
    $this->assertSame('user', $last2->messages[1]->role);
    $this->assertSame("tool\n{\"ok\": true,\n\"id\": 7}\nuser\nNow a footer.\n", $last2->toString());

    $this->assertSame($transcript, $transcript->slice(0), 'Zero disables slicing.');
    $this->assertSame($transcript, $transcript->slice(4), 'A limit at the count hides nothing.');
    $this->assertSame($transcript, $transcript->slice(9), 'A limit past the count hides nothing.');
    $this->assertSame(4, $transcript->count(), 'Slicing does not mutate the source.');
  }

  /**
   * Tests that a non-chat transcript is never sliced.
   */
  public function testSliceLeavesNonChatAlone(): void {
    $transcript = Transcript::parse("A lighthouse\nat dusk\n");

    $this->assertSame($transcript, $transcript->slice(1));
  }

  /**
   * Tests building a transcript from messages directly.
   */
  public function testFromMessages(): void {
    $transcript = Transcript::fromMessages([
      3 => new Message('user', 'a'),
      7 => new Message('assistant', 'b'),
    ]);

    $this->assertSame([0, 1], array_keys($transcript->messages));
    $this->assertSame("user\na\nassistant\nb\n", $transcript->toString());
  }

}
