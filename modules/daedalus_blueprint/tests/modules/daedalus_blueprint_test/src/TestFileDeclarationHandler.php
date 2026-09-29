<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_test;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\FileDeclarationException;
use Drupal\daedalus_blueprint\Dialect\FileDeclarationHandlerInterface;

/**
 * A file declaration seam that saves nothing and records the name.
 *
 * Stands in for daedalus_ai's handler (the lane and the store) so the
 * dialect tiers prove the op layer's half of a declaration: the seam is
 * reached with the batch's conversation, name, type and content, the
 * name lands on the alias overlay the batch settles, and a refusal
 * (a body containing REFUSE) comes back as the declaration's dialect
 * error. Every declaration is remembered in a static for assertions.
 */
class TestFileDeclarationHandler implements FileDeclarationHandlerInterface {

  /**
   * The body marker that makes the stub refuse.
   */
  public const REFUSE = 'REFUSE';

  /**
   * Every declaration the seam received: [conversation, name, type, content].
   *
   * @var list<array{0: string, 1: string, 2: string, 3: string}>
   */
  public static array $declared = [];

  /**
   * The uuid each declared name was recorded under, by name.
   *
   * @var array<string, string>
   */
  public static array $uuids = [];

  public function __construct(
    private readonly DialectAliasMap $aliasMap,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function declare(string $conversation, string $name, string $type, string $content): void {
    self::$declared[] = [$conversation, $name, $type, $content];
    if (str_contains($content, self::REFUSE)) {
      throw new FileDeclarationException([
        'The body was refused by the test seam.',
        sprintf('The declared type %s was noted.', $type),
      ]);
    }
    $uuid = $this->uuid->generate();
    self::$uuids[$name] = $uuid;
    // On the overlay only: the batch settles it.
    $this->aliasMap->recordFile($conversation, $name, $uuid);
  }

}
