<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;
use Drupal\ai\OperationType\Chat\Tools\ToolsPropertyResultInterface;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\OutputInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\EntryType;
use Drupal\daedalus_ai_memory\Transcript\Prefix;
use Drupal\daedalus_ai_memory\Transcript\ResultKey;
use Drupal\daedalus_ai_memory\Transcript\Segment;
use Drupal\daedalus_ai_memory\Transcript\ToolCall;
use Drupal\daedalus_ai_memory\Transcript\Transcript;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the transcript model.
 *
 * Typed entries, keyed results, segments, the prefix hash, absorb() of a
 * wrapper's leg and the persisted record. The renderer (T4) and the runner
 * (T6) build on this shape; what is load-bearing is that a wrapper's
 * appended messages come back as entries of their role with the result
 * keys and opaque content in place, and that a record read back is the
 * transcript that wrote it.
 */
#[Group('daedalus_ai_memory')]
class TranscriptTest extends UnitTestCase {

  /**
   * A user turn keeps its blocks typed, in the order stated.
   */
  public function testUserTurnWithBlocks(): void {
    $transcript = new Transcript();
    $this->assertTrue($transcript->isEmpty());
    $segment = $transcript->openSegment();
    $this->assertSame(1, $segment->number());
    $this->assertNull($segment->carryOver());

    $entry = $transcript->appendUserTurn('Make the hero bolder', [
      new Block(BlockKind::CurrentPage, 'node 12'),
      new Block(BlockKind::Print, 'd-container > d-block'),
      new Block(BlockKind::Selection, 'hero'),
    ]);

    $this->assertSame(EntryType::User, $entry->type);
    $this->assertSame('user', $entry->type->role());
    $this->assertSame('Make the hero bolder', $entry->text);
    $this->assertSame(
      [BlockKind::CurrentPage, BlockKind::Print, BlockKind::Selection],
      array_map(static fn (Block $b) => $b->kind, $entry->blocks),
    );
    $this->assertSame([$entry], $transcript->entries());
    $this->assertSame($entry, $segment->last());
    $this->assertSame([], $transcript->loadedKeys());
  }

  /**
   * A wrapper's leg absorbs as entries of their role.
   *
   * Results are keyed from the calls they answer and the opaque content
   * lands on the leg's assistant. Leg one answers with two tool calls; leg
   * two's history append is the two results, the screenshot message the
   * wrapper adds, its reminder and the reply, in that order.
   */
  public function testAbsorbWrapperLeg(): void {
    $transcript = new Transcript();
    $transcript->openSegment();
    $transcript->appendUserTurn('Build a page');

    $calling = new ChatMessage('assistant', '');
    $calling->setTools([
      $this->functionOutput('call_1', 'eb_read_schema', ['entity_type' => 'node', 'bundle' => 'page']),
      $this->functionOutput('call_2', 'eb_skill', ['skill' => 'design', 'args' => '']),
    ]);
    $output = new ChatOutput($calling, NULL, ['thinking' => 'hm']);

    $absorbed = $transcript->absorb(
      [$calling],
      $output,
      self::opaqueProvider(['thinking' => 'hm', 'signature' => 'sig1']),
      'claude-x',
    );
    $this->assertCount(1, $absorbed);
    $assistant = $absorbed[0];
    $this->assertSame(EntryType::Assistant, $assistant->type);
    $this->assertSame(['thinking' => 'hm', 'signature' => 'sig1'], $assistant->opaque);
    $this->assertSame('anthropic__claude-x', $assistant->opaquePair, 'The content is stamped with the provider and model that produced it.');
    $this->assertEquals([
      new ToolCall('call_1', 'eb_read_schema', ['entity_type' => 'node', 'bundle' => 'page']),
      new ToolCall('call_2', 'eb_skill', ['skill' => 'design', 'args' => '']),
    ], $assistant->toolCalls);
    $this->assertSame('eb_skill', $assistant->toolCall('call_2')?->name);
    $this->assertNull($assistant->toolCall('call_9'));

    $schema = new ChatMessage('tool', 'fields: ...');
    $schema->setToolsId('call_1');
    $skill = new ChatMessage('tool', 'Design doctrine ...');
    $skill->setToolsId('call_2');
    $screenshot = new ChatMessage('user', 'Screenshot(s) from the tool results above:');
    $screenshot->setImage(new ImageFile('png!', 'image/png', 'shot.png'));
    $reminder = new ChatMessage('system', 'Reminder: use page_edit.');
    $reply = new ChatMessage('assistant', 'Here is the plan.');

    $absorbed = $transcript->absorb(
      [$schema, $skill, $screenshot, $reminder, $reply],
      new ChatOutput($reply, NULL, []),
      self::opaqueProvider(['signature' => 'sig2']),
      'claude-x',
    );

    $this->assertSame(
      [EntryType::ToolResult, EntryType::ToolResult, EntryType::User, EntryType::System, EntryType::Assistant],
      array_map(static fn (Entry $e) => $e->type, $absorbed),
    );
    $this->assertSame('call_1', $absorbed[0]->toolId);
    $this->assertSame('schema:node.page', $absorbed[0]->key);
    $this->assertSame('skill:design', $absorbed[1]->key);
    $this->assertSame('Screenshot(s) from the tool results above:', $absorbed[2]->text);
    $this->assertSame([], $absorbed[2]->blocks, 'A wrapper-added user message carries no blocks and no bytes.');
    $this->assertSame('Reminder: use page_edit.', $absorbed[3]->text);
    $this->assertSame(['signature' => 'sig2'], $absorbed[4]->opaque, 'The opaque content lands on the leg\'s assistant.');
    $this->assertSame('anthropic__claude-x', $absorbed[4]->opaquePair);
    $this->assertSame([], $absorbed[4]->toolCalls);
    $this->assertSame(['schema:node.page', 'skill:design'], $transcript->loadedKeys());
    $this->assertCount(7, $transcript->entries());
    $this->assertSame(
      ['thinking' => 'hm', 'signature' => 'sig1'],
      $transcript->entries()[1]->opaque,
      'An earlier assistant entry keeps its own opaque content.',
    );
  }

  /**
   * A later copy of a keyed fact supersedes the earlier one.
   *
   * A result whose call is unknown, or a tool with no key, stays unkeyed.
   */
  public function testKeyedResultsSupersedeAndUnknownStayUnkeyed(): void {
    $transcript = new Transcript();
    $transcript->openSegment();
    $first = new ChatMessage('assistant', '');
    $first->setTools([$this->functionOutput('c1', 'page_read', ['entity_type' => 'node', 'entity_id' => 12])]);
    $transcript->absorb([$first]);
    $print_1 = new ChatMessage('tool', 'print v1');
    $print_1->setToolsId('c1');
    $second = new ChatMessage('assistant', '');
    $second->setTools([
      $this->functionOutput('c2', 'page_edit', ['entity_type' => 'node', 'entity_id' => '12', 'ops' => '[]']),
      $this->functionOutput('c3', 'eb_validate', ['yaml' => '']),
    ]);
    $transcript->absorb([$print_1, $second]);
    $print_2 = new ChatMessage('tool', 'print v2');
    $print_2->setToolsId('c2');
    $validation = new ChatMessage('tool', 'ok');
    $validation->setToolsId('c3');
    $orphan = new ChatMessage('tool', 'from a call this segment never saw');
    $orphan->setToolsId('c_elsewhere');
    $direct = new ChatMessage('tool', 'returned directly, no id');
    $absorbed = $transcript->absorb([$print_2, $validation, $orphan, $direct, new ChatMessage('assistant', 'done')]);

    $this->assertSame('print:node:12', $absorbed[0]->key);
    $this->assertNull($absorbed[1]->key, 'eb_validate states no keyed fact.');
    $this->assertNull($absorbed[2]->key, 'A result whose call is not in the segment has no key.');
    $this->assertNull($absorbed[3]->toolId);
    $this->assertNull($absorbed[3]->key);
    $this->assertSame(['print:node:12'], $transcript->loadedKeys());
    $this->assertSame(
      'print v2',
      $transcript->currentSegment()->resultsByKey()['print:node:12']->text,
      'The latest copy is the one by key.',
    );
  }

  /**
   * A provider without OpaqueContentInterface stores nothing.
   *
   * So does no provider or output at all, and a capable provider that
   * answers NULL.
   */
  public function testAbsorbWithoutOpaqueCapability(): void {
    $transcript = new Transcript();
    $transcript->openSegment();
    $reply = new ChatMessage('assistant', 'plain');
    $plain_provider = new class() {};

    [$entry] = $transcript->absorb([$reply], new ChatOutput($reply, NULL, ['thinking' => 'hm']), $plain_provider, 'm');
    $this->assertNull($entry->opaque);
    $this->assertNull($entry->opaquePair, 'No content, no pair.');

    [$entry] = $transcript->absorb([new ChatMessage('assistant', 'again')]);
    $this->assertNull($entry->opaque);
    $this->assertNull($entry->opaquePair);

    [$entry] = $transcript->absorb(
      [new ChatMessage('assistant', 'nothing to replay')],
      new ChatOutput($reply, NULL, []),
      self::opaqueProvider(NULL),
      'm',
    );
    $this->assertNull($entry->opaque);
    $this->assertNull($entry->opaquePair);
  }

  /**
   * The opaque pair is only ever the content's; it binds nothing else.
   *
   * A pair without content and an empty pair are refused on the entry;
   * content absorbed with no model id to bind it to is a logic error.
   */
  public function testOpaquePairRefusals(): void {
    try {
      Entry::assistant('x', [], NULL, 'anthropic__claude-x');
      $this->fail('A pair without opaque content must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('without opaque content takes none', $e->getMessage());
    }
    try {
      Entry::assistant('x', [], ['sig' => 1], '');
      $this->fail('An empty pair must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('non-empty string or NULL', $e->getMessage());
    }

    $transcript = new Transcript();
    $transcript->openSegment();
    $reply = new ChatMessage('assistant', 'thought');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('no model id was given to bind it to');
    $transcript->absorb([$reply], new ChatOutput($reply, NULL, []), self::opaqueProvider(['sig' => 1]));
  }

  /**
   * A record without the opaque_pair key reads NULL: content kept, unbound.
   *
   * Such a record was written before the pair was kept; the renderer never
   * replays its content, and writing the record back states the NULL pair.
   */
  public function testOpaquePairAbsentReadsNull(): void {
    $read = Transcript::fromArray([
      'version' => Transcript::VERSION,
      'segments' => [
        [
          'number' => 1,
          'carry_over' => NULL,
          'prefix_hashes' => [],
          'entries' => [
            ['type' => 'assistant', 'text' => 'thought', 'tool_calls' => [], 'opaque' => ['sig' => 'old']],
          ],
        ],
      ],
    ]);
    $entry = $read->entries()[0];
    $this->assertSame(['sig' => 'old'], $entry->opaque);
    $this->assertNull($entry->opaquePair);
    $this->assertArrayHasKey('opaque_pair', $read->toArray()['segments'][0]['entries'][0]);
    $this->assertNull($read->toArray()['segments'][0]['entries'][0]['opaque_pair']);
  }

  /**
   * Opaque content with no assistant to carry it, and an unknown role.
   *
   * The first is a logic error, the second is refused as an argument.
   */
  public function testAbsorbRefusals(): void {
    $transcript = new Transcript();
    $transcript->openSegment();
    $reply = new ChatMessage('assistant', 'x');

    try {
      $transcript->absorb([new ChatMessage('tool', 'r')], new ChatOutput($reply, NULL, []), self::opaqueProvider(['sig' => 1]), 'm');
      $this->fail('Opaque content without an assistant message must be refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('no assistant message', $e->getMessage());
    }
    $this->assertSame([], $transcript->entries(), 'A refused absorb appends nothing.');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown chat message role "developer"');
    $transcript->absorb([new ChatMessage('developer', 'x')]);
  }

  /**
   * Absorbing before a segment is open is a logic error.
   */
  public function testNoSegmentIsRefused(): void {
    $this->expectException(\LogicException::class);
    (new Transcript())->absorb([new ChatMessage('assistant', 'x')]);
  }

  /**
   * The result key grammar per page builder tool.
   */
  #[DataProvider('resultKeys')]
  public function testResultKeys(string $name, array $arguments, ?string $expected): void {
    $this->assertSame($expected, ResultKey::fromCall(new ToolCall('id', $name, $arguments)));
  }

  /**
   * Cases for testResultKeys().
   */
  public static function resultKeys(): array {
    return [
      'schema' => [
        'eb_read_schema',
        ['entity_type' => 'node', 'bundle' => 'page'],
        'schema:node.page',
      ],
      'schema narrowed' => [
        'eb_read_schema',
        ['entity_type' => 'node', 'bundle' => 'page', 'view_mode' => 'full', 'group' => 'hero'],
        'schema:node.page/full#hero',
      ],
      'schema missing bundle' => [
        'eb_read_schema',
        ['entity_type' => 'node', 'bundle' => ''],
        NULL,
      ],
      'skill' => [
        'eb_skill',
        ['skill' => 'design', 'args' => 'page'],
        'skill:design',
      ],
      'skill blank' => [
        'eb_skill',
        ['skill' => ' '],
        NULL,
      ],
      'page_read' => [
        'page_read',
        ['entity_type' => 'node', 'entity_id' => 12],
        'print:node:12',
      ],
      'page_edit' => [
        'page_edit',
        ['entity_type' => 'node', 'entity_id' => '12', 'ops' => '[]', 'save' => TRUE],
        'print:node:12',
      ],
      'page_create' => [
        'page_create',
        ['entity_type' => 'node', 'bundle' => 'page'],
        NULL,
      ],
      'read_entity' => [
        'eb_read_entity',
        ['entity_type' => 'daedalus_skin', 'entity_id' => 'warm'],
        'read:daedalus_skin:warm',
      ],
      'read_element' => [
        'eb_read_element',
        ['entity_type' => 'node', 'entity_id' => 12, 'element' => 'uuid-1'],
        'read:node:12#uuid-1',
      ],
      'read_element without element' => [
        'eb_read_element',
        ['entity_type' => 'node', 'entity_id' => 12],
        'read:node:12',
      ],
      'edit' => [
        'eb_edit',
        ['entity_type' => 'node', 'entity_id' => 12],
        NULL,
      ],
      'list_bundles' => ['eb_list_bundles', [], NULL],
      'unknown tool' => ['view_file', ['handle' => 'f1'], NULL],
    ];
  }

  /**
   * The prefix hash is byte-stable and a segment notes drift.
   *
   * Equal inputs hash equal; a changed system prompt, a reordered tool
   * list or reordered keys inside a definition hash apart.
   */
  public function testPrefixHashAndDrift(): void {
    $tools = [['name' => 'page_edit', 'parameters' => ['a' => 1]], ['name' => 'page_read']];
    $hash = Prefix::hash('system', $tools);
    $this->assertSame(64, strlen($hash));
    $this->assertSame($hash, Prefix::hash('system', $tools));
    $this->assertNotSame($hash, Prefix::hash('system ', $tools));
    $this->assertNotSame($hash, Prefix::hash('system', array_reverse($tools)), 'Tool order is part of the prefix.');
    $reordered_keys = [['parameters' => ['a' => 1], 'name' => 'page_edit'], ['name' => 'page_read']];
    $this->assertNotSame($hash, Prefix::hash('system', $reordered_keys), 'Key order inside a definition is part of the bytes sent.');

    $segment = (new Transcript())->openSegment();
    $this->assertNull($segment->prefixHash());
    $this->assertFalse($segment->notePrefix($hash), 'The first leg cannot drift.');
    $this->assertFalse($segment->notePrefix($hash));
    $this->assertFalse($segment->prefixDrifted());
    $other = Prefix::hash('system v2', $tools);
    $this->assertTrue($segment->notePrefix($other), 'A changed prefix is drift.');
    $this->assertTrue($segment->prefixDrifted());
    $this->assertSame($other, $segment->prefixHash());
    $this->assertSame([$hash, $other], $segment->prefixHashes());
    $this->assertFalse($segment->notePrefix($other));
    $this->assertTrue($segment->notePrefix($hash), 'A return to an earlier prefix is drift too: the leg before it was sent with another.');
    $this->assertSame($hash, $segment->prefixHash());
    $this->assertSame([$hash, $other], $segment->prefixHashes(), 'The distinct list does not grow on a return.');
    $this->assertFalse($segment->notePrefix($hash), 'The prefix holds again from there.');
    $this->assertTrue($segment->prefixDrifted());

    $this->expectException(\InvalidArgumentException::class);
    $segment->notePrefix('');
  }

  /**
   * The latest prefix hash is kept apart from the distinct list.
   */
  public function testLatestPrefixHashRoundTrip(): void {
    $one = Prefix::hash('s', []);
    $two = Prefix::hash('s2', []);
    $segment = (new Transcript())->openSegment();
    $segment->notePrefix($one);
    $segment->notePrefix($two);
    $segment->notePrefix($one);
    $record = $segment->toArray();
    $this->assertSame([$one, $two], $record['prefix_hashes']);
    $this->assertSame($one, $record['prefix_hash']);
    $read = Segment::fromArray($record);
    $this->assertSame($one, $read->prefixHash());
    $this->assertFalse($read->notePrefix($one), 'A record read back knows which prefix its latest leg was sent with.');
    $this->assertTrue($read->notePrefix($two));

    $older = $record;
    unset($older['prefix_hash']);
    $this->assertSame($two, Segment::fromArray($older)->prefixHash(), 'A record without the key reads the last distinct hash as the latest.');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('one of its noted hashes');
    Segment::fromArray(['prefix_hash' => Prefix::hash('s3', [])] + $record);
  }

  /**
   * Segments open in order with their carry-over.
   *
   * The entries() and loadedKeys() reads see the current one only; the
   * earlier stays whole, and its calls do not key results in the next.
   */
  public function testSegments(): void {
    $transcript = new Transcript();
    $first = $transcript->openSegment();
    $transcript->appendUserTurn('turn 1');
    $call = new ChatMessage('assistant', '');
    $call->setTools([$this->functionOutput('c1', 'eb_skill', ['skill' => 'design'])]);
    $result = new ChatMessage('tool', 'doctrine');
    $result->setToolsId('c1');
    $transcript->absorb([$call]);
    $transcript->absorb([$result, new ChatMessage('assistant', 'ok')]);
    $this->assertSame(['skill:design'], $transcript->loadedKeys());

    $second = $transcript->openSegment('The user wants a warm landing page; design loaded.');
    $this->assertSame(2, $second->number());
    $this->assertSame('The user wants a warm landing page; design loaded.', $second->carryOver());
    $this->assertSame($second, $transcript->currentSegment());
    $this->assertSame([], $transcript->entries());
    $this->assertSame([], $transcript->loadedKeys());
    $this->assertTrue($second->isEmpty());
    $this->assertNull($second->last());
    $this->assertCount(4, $first->entries(), 'The closed segment is kept whole.');
    $this->assertSame([$first, $second], $transcript->segments());

    $late = new ChatMessage('tool', 'late result');
    $late->setToolsId('c1');
    [$entry] = $transcript->absorb([$late]);
    $this->assertNull($entry->key, 'A call in a closed segment does not key a result in the next.');
  }

  /**
   * A record read back is the transcript that wrote it, byte for byte.
   */
  public function testRecordRoundTrip(): void {
    $transcript = new Transcript();
    $first = $transcript->openSegment();
    $first->notePrefix(Prefix::hash('s', []));
    $first->notePrefix(Prefix::hash('s2', []));
    $transcript->appendUserTurn('turn 1', [
      new Block(BlockKind::Skills, 'skills: design, skins'),
      new Block(BlockKind::Attachments, '- File 1: hero.png'),
    ]);
    $call = new ChatMessage('assistant', 'reading');
    $call->setTools([
      $this->functionOutput('c1', 'eb_read_schema', ['entity_type' => 'node', 'bundle' => 'page', 'group' => NULL]),
    ]);
    $opaque = ['blocks' => [['type' => 'thinking', 'signature' => 'abc']]];
    $transcript->absorb([$call], new ChatOutput($call, NULL, []), self::opaqueProvider($opaque), 'claude-x');
    $result = new ChatMessage('tool', 'schema');
    $result->setToolsId('c1');
    $transcript->absorb([
      $result,
      new ChatMessage('system', 'reminder'),
      new ChatMessage('user', 'shots'),
      new ChatMessage('assistant', 'done'),
    ]);
    $second = $transcript->openSegment('carried over');
    $second->notePrefix(Prefix::hash('s2', []));
    $transcript->appendUserTurn('turn 2', [
      new Block(BlockKind::CarryOver, 'carried over'),
      new Block(BlockKind::Selection, '- Element 1: the hero'),
    ]);

    $record = $transcript->toArray();
    $this->assertSame(Transcript::VERSION, $record['version']);
    $this->assertCount(2, $record['segments']);
    $this->assertSame(
      ['type' => 'tool', 'text' => 'schema', 'tool_id' => 'c1', 'key' => 'schema:node.page'],
      $record['segments'][0]['entries'][2],
    );
    $this->assertSame(['type' => 'system', 'text' => 'reminder'], $record['segments'][0]['entries'][3]);
    $this->assertSame($opaque, $record['segments'][0]['entries'][1]['opaque']);
    $this->assertSame('anthropic__claude-x', $record['segments'][0]['entries'][1]['opaque_pair']);
    $this->assertNull($record['segments'][0]['entries'][5]['opaque_pair'], 'An assistant entry without content records a NULL pair.');
    $this->assertSame('carried over', $record['segments'][1]['carry_over']);

    $json = json_encode($record, JSON_THROW_ON_ERROR);
    $read = Transcript::fromArray(json_decode($json, TRUE, flags: JSON_THROW_ON_ERROR));
    $this->assertSame($record, $read->toArray());
    $this->assertEquals($transcript, $read);
    $this->assertSame(['schema:node.page'], array_keys($read->segments()[0]->resultsByKey()));
    $this->assertSame('anthropic__claude-x', $read->segments()[0]->entries()[1]->opaquePair, 'The pair round-trips.');
    $this->assertTrue($read->segments()[0]->prefixDrifted());
    $this->assertSame(BlockKind::Selection, $read->entries()[0]->blocks[1]->kind);
  }

  /**
   * A malformed record is refused, never read as something else.
   */
  #[DataProvider('malformedRecords')]
  public function testMalformedRecordRefused(array $record, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    Transcript::fromArray($record);
  }

  /**
   * Cases for testMalformedRecordRefused().
   */
  public static function malformedRecords(): array {
    $record = static fn (array $entries, int $number = 1): array => [
      'version' => 1,
      'segments' => [
        ['number' => $number, 'carry_over' => NULL, 'prefix_hashes' => [], 'entries' => $entries],
      ],
    ];
    return [
      'version' => [
        ['version' => 2, 'segments' => []],
        'Unknown transcript record version 2',
      ],
      'no segments' => [
        ['version' => 1],
        'needs a segments array',
      ],
      'segment numbering' => [
        $record([], 2),
        'segment 2 sits at position 1',
      ],
      'entry type' => [
        $record([['type' => 'developer', 'text' => '']]),
        'Unknown transcript entry type "developer"',
      ],
      'entry text' => [
        $record([['type' => 'user', 'text' => 3]]),
        'entry text must be a string',
      ],
      'block kind' => [
        $record([['type' => 'user', 'text' => '', 'blocks' => [['kind' => 'mood', 'text' => '']]]]),
        'Unknown transcript block kind "mood"',
      ],
      'tool call' => [
        $record([['type' => 'assistant', 'text' => '', 'tool_calls' => [['id' => 'c', 'arguments' => []]]]]),
        'needs a string id and name',
      ],
      'opaque' => [
        $record([['type' => 'assistant', 'text' => '', 'opaque' => 'sig']]),
        'opaque content must be an array or NULL',
      ],
      'opaque pair' => [
        $record([['type' => 'assistant', 'text' => '', 'opaque' => ['sig' => 1], 'opaque_pair' => 7]]),
        'opaque_pair must be a string or NULL',
      ],
      'prefix hash' => [
        [
          'version' => 1,
          'segments' => [
            ['number' => 1, 'carry_over' => NULL, 'prefix_hashes' => [], 'prefix_hash' => 7, 'entries' => []],
          ],
        ],
        'prefix_hash must be a string or NULL',
      ],
      'tool id' => [
        $record([['type' => 'tool', 'text' => '', 'tool_id' => 7]]),
        'tool_id must be a string or NULL',
      ],
      'prefix hash' => [
        ['version' => 1, 'segments' => [['number' => 1, 'prefix_hashes' => [''], 'entries' => []]]],
        'prefix hash must be a non-empty string',
      ],
    ];
  }

  /**
   * A segment refuses a number under 1 and non-entries.
   */
  public function testSegmentConstruction(): void {
    try {
      new Segment(0);
      $this->fail('Segment 0 must be refused.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('starts at 1', $e->getMessage());
    }
    $this->expectException(\InvalidArgumentException::class);
    new Segment(1, NULL, [], ['not an entry']);
  }

  /**
   * A tool call as drupal/ai carries it on an assistant message.
   *
   * ToolsFunctionOutput and ToolsPropertyResult reach the container (the
   * hostname filter) in their constructors, so the test speaks through the
   * interfaces, which is all ToolCall::fromFunctionOutput() reads.
   */
  private function functionOutput(string $id, string $name, array $arguments): ToolsFunctionOutputInterface {
    $results = [];
    foreach ($arguments as $argument_name => $value) {
      $result = $this->createMock(ToolsPropertyResultInterface::class);
      $result->method('getName')->willReturn($argument_name);
      $result->method('getValue')->willReturn($value);
      $results[] = $result;
    }
    $function = $this->createMock(ToolsFunctionOutputInterface::class);
    $function->method('getToolId')->willReturn($id);
    $function->method('getName')->willReturn($name);
    $function->method('getArguments')->willReturn($results);
    return $function;
  }

  /**
   * A provider plugin declaring OpaqueContentInterface with a fixed answer.
   *
   * Its plugin id is "anthropic", the first half of the pair absorb()
   * stamps on the content.
   */
  private static function opaqueProvider(?array $content): OpaqueContentInterface {
    return new class($content) implements OpaqueContentInterface {

      public function __construct(private readonly ?array $content) {
      }

      /**
       * The plugin id, as a provider plugin answers it.
       */
      public function getPluginId(): string {
        return 'anthropic';
      }

      /**
       * {@inheritdoc}
       */
      public function opaqueContentOf(OutputInterface $output): ?array {
        return $this->content;
      }

    };
  }

}
