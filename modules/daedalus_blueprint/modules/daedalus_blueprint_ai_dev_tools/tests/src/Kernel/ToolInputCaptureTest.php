<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai_dev_tools\Kernel;

use Drupal\daedalus_blueprint_ai_dev_tools\EventSubscriber\ToolInputCaptureSubscriber;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the armed page-tool input capture.
 *
 * The capture must record the EXACT bytes a page tool received at execute
 * time plus the parse verdict the spine reached on them, because its whole
 * point is settling "the tool parsed something other than what the log
 * shows" against ground truth. And it must be armed deliberately: a state
 * flag the Drush command sets, never on by default.
 *
 * The page-builder site shape (container layout, daedalus_stylish strict mode, the
 * basic block type) mirrors PageCreateValidationTest's setup so page_create
 * runs the real document path end to end.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class ToolInputCaptureTest extends BlueprintKernelTestBase {

  use DesignSystemActivationTrait;

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * {@inheritdoc}
   *
   * The capture writes to private://. The setting must exist before the
   * FIRST container build: CoreServiceProvider only registers the private
   * stream wrapper when file_private_path is set at compile time, and a
   * later setSetting() never re-registers the PHP-level wrapper (writes
   * would then land on a literal "private:" directory).
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_ui',
      // Section identity (the uuid the tree addresses containers by) is
      // daedalus_layout's third-party setting.
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_guide_test',
      // The eb_test_container layout: the container layout the real
      // catalog builder derives containerLayoutId from.
      'daedalus_blueprint_test',
      'daedalus_blueprint_ai_dev_tools',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // The daedalus_stylish materials provider reads all four material storages on
    // every roster build (the catalog builder projects the roster), and
    // activating a design system (the enforcement door) recompiles the
    // delivery files over the same four.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);

    // Strict enforcement so an undeclared style property is a real spine
    // refusal the verdict can record.
    $this->enforce('strict');
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
   *
   * @param string $function_name
   *   The tool's function name (e.g. page_create).
   * @param array $contexts
   *   Tool parameters.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runTool(string $function_name, array $contexts): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName($function_name);
    $this->assertNotNull($tool, "The $function_name tool is available.");
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * The decoded capture records currently on disk, ordered by filename.
   *
   * @return array[]
   *   [filename => decoded record], filename like <time>-<tool>.json.
   */
  protected function readCaptures(): array {
    // ConversationContext is '' in kernel tests; the subscriber files that
    // under the no-conversation directory.
    $directory = ToolInputCaptureSubscriber::DIRECTORY . '/no-conversation';
    $this->assertDirectoryExists($directory);
    $records = [];
    foreach ($this->container->get('file_system')->scanDirectory($directory, '/\.json$/') as $file) {
      $records[$file->filename] = json_decode(file_get_contents($file->uri), TRUE, flags: JSON_THROW_ON_ERROR);
    }
    ksort($records);
    return $records;
  }

  /**
   * Armed capture holds the exact layout bytes and the verdict.
   */
  public function testArmedCaptureHoldsLayoutBytesAndVerdict(): void {
    $this->container->get('state')->set(ToolInputCaptureSubscriber::STATE_KEY, TRUE);

    // Bytes worth proving fidelity on: multibyte text, an entity, slashes.
    $layout = '<pb-container id="hero" style="margin: 3rem"><pb-basic id="intro" heading="Café &amp; crème 100/100"></pb-basic></pb-container>';
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Captured page'],
      'layout' => $layout,
    ]);
    $this->assertStringContainsString('Created page:', $output);

    $records = $this->readCaptures();
    $this->assertCount(1, $records, 'Every dispatch of the one execution lands in ONE record. Got: ' . implode(', ', array_keys($records)));
    $filename = array_key_first($records);
    $this->assertMatchesRegularExpression('/^\d{8}-\d{6}\.\d{3}-page_create\.json$/', $filename);

    $record = reset($records);
    $this->assertSame('page_create', $record['tool']);
    $this->assertSame('', $record['conversation']);
    // The exact bytes as received, not a re-print.
    $this->assertSame($layout, $record['input']['layout']);
    $this->assertSame('entity_test', $record['input']['entity_type']);
    $this->assertSame('entity_test', $record['input']['bundle']);
    $this->assertSame(['name' => 'Captured page'], $record['input']['fields']);
    // The verdict: the spine judged these bytes whole.
    $this->assertSame(['valid' => TRUE, 'errors' => []], $record['verdict']);
  }

  /**
   * A refused document's capture carries the refusing verdict.
   */
  public function testRefusedDocumentCapturesTheRefusal(): void {
    $this->container->get('state')->set(ToolInputCaptureSubscriber::STATE_KEY, TRUE);

    // Parses fine, refused semantically: text-decoration is not in the
    // container roster under strict enforcement.
    $layout = '<pb-container id="hero" style="text-decoration: none"></pb-container>';
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Refused page'],
      'layout' => $layout,
    ]);
    $this->assertStringContainsString('Nothing was created', $output);

    $records = $this->readCaptures();
    $this->assertCount(1, $records);
    $record = reset($records);
    $this->assertSame($layout, $record['input']['layout']);
    $this->assertFalse($record['verdict']['valid']);
    $codes = array_column($record['verdict']['errors'], 'code');
    $this->assertContains('STYLE_PROPERTY_NOT_DECLARED', $codes);
  }

  /**
   * The capture is never on by default.
   */
  public function testCaptureIsDisarmedByDefault(): void {
    $output = $this->runTool('page_create', [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Uncaptured page'],
      'layout' => '<pb-container id="hero"></pb-container>',
    ]);
    $this->assertStringContainsString('Created page:', $output);
    $this->assertDirectoryDoesNotExist(ToolInputCaptureSubscriber::DIRECTORY);
  }

}
