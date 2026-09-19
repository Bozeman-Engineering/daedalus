<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

/**
 * Runs a module's pure-JS node suites under PHPUnit.
 *
 * Pure models live in tests/js/*.test.mjs (node:test suites) and only count
 * if something runs them. This trait gives a PHPUnit test the two moves:
 * a data provider that enumerates every suite by globbing (an explicit list
 * rots: a new suite silently never runs in CI), and a runner that fails the
 * PHPUnit case on a non-zero node exit. Scripts that are not suites but
 * mirrors fed over stdin (a battery printed by PHP and by JS, compared
 * byte-for-byte) use runNodeScript() from a named test method instead.
 *
 * Paths are relative to the using class's module root (tests/src/<tier>/
 * three levels up), so the trait serves any module in the suite.
 */
trait NodeSuitesTrait {

  /**
   * Every node:test suite under tests/js/, keyed by file name.
   *
   * @return array<string, array{string}>
   *   Data-provider rows: the script path relative to the module root.
   */
  public static function nodeSuiteProvider(): array {
    $module_root = static::nodeModuleRoot();
    $rows = [];
    foreach (glob($module_root . '/tests/js/*.test.mjs') ?: [] as $path) {
      $rows[basename($path, '.test.mjs')] = ['tests/js/' . basename($path)];
    }
    return $rows;
  }

  /**
   * Runs one node:test suite and fails on a non-zero exit.
   *
   * The file form (`node --test <file>`), not the directory form: node 26
   * rejects a directory argument, the file form is what CI and a hand run
   * share.
   */
  protected function runNodeSuite(string $script): void {
    $this->runNodeProcess([$this->findNode(), '--test', static::nodeModuleRoot() . '/' . $script], $script, '');
    // A non-zero exit already failed the test; reaching here is a pass.
    $this->addToAssertionCount(1);
  }

  /**
   * Runs a node script with the given stdin and returns its stdout.
   *
   * For stdin mirrors (tests/js/*-cases.mjs): the PHP side prints a battery,
   * the script prints the same battery through the JS side, the caller
   * compares.
   */
  protected function runNodeScript(string $script, string $stdin): string {
    return $this->runNodeProcess([$this->findNode(), static::nodeModuleRoot() . '/' . $script], $script, $stdin);
  }

  /**
   * Locates the node binary, or skips (CI images without node).
   */
  protected function findNode(): string {
    foreach (['node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
      $path = trim((string) shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'));
      if ($path !== '') {
        return $path;
      }
    }
    $this->markTestSkipped('node is not available; the JS suites cannot run.');
  }

  /**
   * The using class's module root: tests/src/<tier>/Class.php, three up.
   */
  protected static function nodeModuleRoot(): string {
    return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 4);
  }

  /**
   * Runs a node process from the module root and fails on a non-zero exit.
   *
   * @param string[] $command
   *   The argv.
   * @param string $script
   *   The script, for the failure message.
   * @param string $stdin
   *   What to feed the process.
   *
   * @return string
   *   The process's stdout.
   */
  private function runNodeProcess(array $command, string $script, string $stdin): string {
    $process = proc_open(
      $command,
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      static::nodeModuleRoot(),
    );
    $this->assertIsResource($process, 'node failed to start.');
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $this->assertSame(0, $exit, "node $script failed:\n$stderr\n$stdout");
    return $stdout;
  }

}
