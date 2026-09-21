<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel\Migrate\PlusSuite;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\daedalus\Migrate\PlusSuite\Audit;
use Drupal\daedalus\Migrate\PlusSuite\Mapping;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the audit phase over a site seeded in the old suite's vocabulary.
 *
 * The fixture is OldSuiteFixtureTrait's, the trial site in miniature; the
 * audit reads stores, not code, so the old modules are never installed.
 *
 * @group daedalus
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
final class AuditTest extends KernelTestBase {

  use OldSuiteFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'block_content',
    'layout_discovery',
    'layout_builder',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $this->registerOldSuiteKeyValue($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installSchema('node', ['node_access']);
    $this->seedOldSuite();
    // The site's text formats: one restricting HTML with class allowed on
    // every identity tag it allows, one with no restrictions.
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p class> <h2 id class> <a href> <strong>'],
        ],
      ],
    ])->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();
  }

  /**
   * Audits the fixture and checks every count.
   */
  public function testReport(): void {
    $report = $this->audit()->run();

    $this->assertTrue($report->ok());
    $this->assertNull($report->refusal);
    $this->assertSame([], $report->formats);
    $this->assertSame([
      'edit_plus' => 10001,
      'lb_plus' => 10003,
      'lb_plus_section_library' => 8000,
      'navigation_plus' => 10400,
      'tempstore_plus' => 11001,
    ], $report->schema);
    $this->assertSame(['lb_plus' => 10003, 'navigation_plus' => 10400, 'edit_plus' => 10001, 'tempstore_plus' => 11001], $report->floor);
    $this->assertSame([
      'module' => ['lb_plus', 'lb_plus_section_library', 'navigation_plus', 'tempstore_plus', 'edit_plus'],
      'theme' => [],
    ], $report->enabled);
    $this->assertSame([
      'core.entity_view_display.node.landing_page.default',
      'core.extension',
      'lb_plus.settings',
      'navigation_plus.settings',
      'node.type.landing_page',
      'user.role.content_editor',
    ], $report->config);
    $this->assertSame([
      'entity.definitions.installed' => 1,
      'entity.storage_schema.sql' => 1,
      'post_update' => 1,
      'system.schema' => 5,
    ], $report->keyValue);
    $this->assertSame([
      'node.layout_builder__layout' => [
        'table' => 'node__layout_builder__layout',
        'rows' => 2,
        'old' => 2,
        'revision_rows' => 1,
        'revision_old' => 1,
      ],
    ], $report->sections);
    $this->assertSame(2, $report->oldSections());
    $this->assertSame(1, $report->oldRevisions());
    $this->assertSame(['inline_block:heading' => 2], $report->components);
    $this->assertSame(['content_editor' => ['administer layout builder + configuration']], $report->roles);
    $this->assertSame($this->oldSuiteCodeFiles(), $report->files);

    $this->assertSame(
      'audit: schema edit_plus 10001, lb_plus 10003, lb_plus_section_library 8000, navigation_plus 10400, tempstore_plus 11001; '
      . '6 config objects, 8 key_value entries, 2 sections (2 inline_block:heading), 1 revision rows, 1 roles, 3 custom-code files name the old suite.',
      $report->summary(),
    );
    $rendered = $report->render();
    $this->assertStringContainsString('  lb_plus 10003 (the migration serves 10003 and later)', $rendered);
    $this->assertStringContainsString('  lb_plus_section_library 8000', $rendered);
    $this->assertStringContainsString('Enabled (core.extension): modules lb_plus, lb_plus_section_library, navigation_plus, tempstore_plus, edit_plus; themes none', $rendered);
    $this->assertStringContainsString("Config objects naming the old suite: 6\n  core.entity_view_display.node.landing_page.default\n", $rendered);
    $this->assertStringContainsString('  node.layout_builder__layout (node__layout_builder__layout): 2 of 2 rows; revisions 1 of 1', $rendered);
    $this->assertStringContainsString('  inline_block:heading 2', $rendered);
    $this->assertStringContainsString('  content_editor: administer layout builder + configuration', $rendered);
    $this->assertStringContainsString('  themes/custom/engineering/templates/page.html.twig: navigation_plus', $rendered);
    $this->assertStringNotContainsString('REFUSED', $rendered);
  }

  /**
   * A site below the floor is refused, with the reason.
   */
  public function testFloorRefusal(): void {
    $this->registry->get('system.schema')->set('lb_plus', 10002);
    $report = $this->audit()->run();

    $this->assertFalse($report->ok());
    $this->assertSame('lb_plus is at schema version 10002; the migration serves 10003 and later (oldest system.schema version the migration serves: lb_plus 3.6.x; the audit refuses below).', $report->refusal);
    $this->assertSame(10002, $report->schema['lb_plus']);
    $this->assertStringStartsWith('audit: REFUSED. lb_plus is at schema version 10002;', $report->summary());
    $this->assertStringContainsString("REFUSED: the site cannot be migrated as it is.", $report->render());
    // The counts are still reported, so the person sees what the site holds.
    $this->assertSame(['inline_block:heading' => 2], $report->components);
  }

  /**
   * A text format allowing an identity tag without class is refused.
   *
   * The minter of daedalus_edit stamps identity classes onto the vocabulary
   * tags at every save and throws through a format that would strip them; the
   * update run would abort on the first such save, so the audit refuses
   * first. A tag the format strips entirely does not count, and a format
   * with no restrictions is fine.
   */
  public function testFormatRefusal(): void {
    $format = FilterFormat::load('basic_html');
    $format->setFilterConfig('filter_html', [
      'status' => TRUE,
      'settings' => ['allowed_html' => '<p> <h2 id> <h3 id class> <li> <a href>'],
    ])->save();
    FilterFormat::create([
      'format' => 'restricted_html',
      'name' => 'Restricted HTML',
      'status' => FALSE,
      'filters' => [
        'filter_html' => ['status' => TRUE, 'settings' => ['allowed_html' => '<p>']],
      ],
    ])->save();
    $report = $this->audit()->run();

    $this->assertFalse($report->ok());
    $this->assertSame(['basic_html' => ['p', 'h2', 'li']], $report->formats, 'h3 allows class, h4 and the rest are stripped entirely, the disabled format is not read.');
    $this->assertSame("Text format basic_html allows <p>, <h2>, <li> without the class attribute; daedalus_edit mints identity classes onto every vocabulary tag a format allows (p, h2, h3, h4, blockquote, ul, ol, li) and refuses a save through a format that would strip them. Allow class on those tags in the format's allowed HTML first.", $report->refusal);
    $this->assertStringContainsString('Text formats allowing an identity tag without its class attribute: 1', $report->render());
    $this->assertStringContainsString('  basic_html: p, h2, li', $report->render());
  }

  /**
   * A site with none of the old modules' schema rows is served.
   */
  public function testCleanSite(): void {
    $this->registry->get('system.schema')->deleteMultiple([
      'lb_plus',
      'lb_plus_section_library',
      'navigation_plus',
      'edit_plus',
      'tempstore_plus',
    ]);
    $report = $this->audit()->run();

    $this->assertTrue($report->ok());
    $this->assertSame([], $report->schema);
    $this->assertSame(['entity.definitions.installed' => 1, 'entity.storage_schema.sql' => 1, 'post_update' => 1], $report->keyValue);
    $this->assertStringStartsWith('audit: schema no old module; 6 config objects, 3 key_value entries', $report->summary());
  }

  /**
   * The audit over the fixture's stores and code tree.
   */
  private function audit(): Audit {
    return new Audit(
      Mapping::fromFile(),
      $this->container->get('config.storage'),
      $this->registry,
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->codeRoot,
    );
  }

}
