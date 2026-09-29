<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Drush\Commands\DiscoverCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the daedalus_stylish:discover command without its optional rendering half.
 *
 * The command lives in the base module but renders through the optional
 * daedalus_stylish_discover sub-module; with it absent the command must fail with
 * instructions, not a container crash. The happy path is covered in
 * daedalus_stylish_discover's ElementDiscoveryTest.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DiscoverCommandTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * Without daedalus_stylish_discover the command instructs, never crashes.
   */
  public function testRequiresDiscoverModule(): void {
    $command = DiscoverCommand::create($this->container);
    $tester = new CommandTester($command);
    $this->assertSame(Command::FAILURE, $tester->execute(['block_type' => 'anything']));
    $this->assertStringContainsString('drush en daedalus_stylish_discover', $tester->getDisplay());
  }

}
