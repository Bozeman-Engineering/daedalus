<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Keeps SETTINGS.md honest: every settings object declared is named in it.
 *
 * The roster at the repo root names every Daedalus setting, its owner and its
 * doors. Its truth is the schema files on disk: every top-level
 * `config_object` any `config/schema/*.schema.yml` under the repo declares is
 * a settings object a site builder may need to find, so each must appear in
 * the roster by name. A unit test over the files, not a kernel test over an
 * installed container: enabling every daedalus module in one kernel test is
 * the expensive path, and the file set is what the roster is checked against.
 *
 * Test fixture modules (under any `tests/` directory) are skipped: a config
 * object a fixture declares for one test is not a product setting.
 *
 * @group daedalus
 */
#[Group('daedalus')]
final class SettingsRosterTest extends UnitTestCase {

  /**
   * Every config object the schemas declare is named in SETTINGS.md.
   */
  public function testEveryConfigObjectIsRostered(): void {
    $root = dirname(__DIR__, 3);
    $roster_path = $root . '/SETTINGS.md';
    $this->assertFileExists($roster_path, 'SETTINGS.md sits at the daedalus repo root.');
    $roster = file_get_contents($roster_path);

    $objects = $this->configObjects($root);
    $this->assertNotEmpty($objects, 'The schema scan found at least one config object.');

    $missing = [];
    foreach ($objects as $name => $file) {
      // Verbatim, as a whole name: `daedalus_ai.settings` must not be
      // satisfied by `daedalus_ai_memory.settings` or a longer dotted name.
      $pattern = '/(?<![A-Za-z0-9_.])' . preg_quote($name, '/') . '(?![A-Za-z0-9_.])/';
      if (!preg_match($pattern, $roster)) {
        $missing[] = "$name (declared in $file)";
      }
    }
    $this->assertSame([], $missing, "Every config object is rostered in SETTINGS.md; missing:\n" . implode("\n", $missing));
  }

  /**
   * The top-level `config_object` names the repo's schema files declare.
   *
   * @param string $root
   *   The repo root.
   *
   * @return array<string, string>
   *   Config object name to the schema file (root-relative) declaring it.
   */
  private function configObjects(string $root): array {
    $objects = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $file): bool => !$file->isDir()
          || !in_array($file->getFilename(), ['node_modules', 'tests'], TRUE),
      ),
    );
    /** @var \SplFileInfo $file */
    foreach ($iterator as $file) {
      if (!str_ends_with($file->getFilename(), '.schema.yml') || basename($file->getPath()) !== 'schema' || basename(dirname($file->getPath())) !== 'config') {
        continue;
      }
      $schema = Yaml::decode(file_get_contents($file->getPathname()));
      $relative = substr($file->getPathname(), strlen($root) + 1);
      foreach ($schema as $name => $definition) {
        if (is_array($definition) && ($definition['type'] ?? NULL) === 'config_object') {
          $this->assertArrayNotHasKey($name, $objects, "$name is declared once ($relative and " . ($objects[$name] ?? '') . ').');
          $objects[$name] = $relative;
        }
      }
    }
    ksort($objects);
    return $objects;
  }

}
