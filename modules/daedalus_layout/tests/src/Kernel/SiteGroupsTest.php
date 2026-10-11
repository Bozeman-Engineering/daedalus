<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_layout\SiteGroups;
use Drupal\daedalus_ui\ElementModel\DeclarationException;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the site's group vocabulary: parsed here, answered by the catalog.
 *
 * Groups are declared under the reserved `site` key of the page builder
 * declarations as a `groups` map (id to label and optional description),
 * the way variant axes are declared; daedalus_layout owns the section,
 * validates it as authored and contributes it to the spine's dialect
 * catalog on its build event, which is where every surface reads it. A
 * group is a filter category and nothing else, so the whole contract is
 * the map's shape, its order on the catalog, and the one reserved id
 * (DialectCatalog::UNGROUPED, the bucket for the things in no group).
 */
#[CoversClass(SiteGroups::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class SiteGroupsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'field',
    'text',
    'filter',
    'file',
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (and its file lane
    // on file); kernel tests do not resolve info.yml dependencies, so the
    // chain is listed.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_layout_site_groups_test',
  ];

  /**
   * The declared groups answer validated and normalized, in declaration order.
   */
  public function testDeclaredGroups(): void {
    $groups = $this->container->get('daedalus_layout.site_groups');
    assert($groups instanceof SiteGroups);
    $this->assertSame([
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers for the top of a page.'],
      'bands' => ['label' => 'Bands', 'description' => ''],
    ], $groups->all(), 'The map in declaration order, the description "" when unwritten.');
  }

  /**
   * The built catalog answers the contributed groups in declaration order.
   *
   * The reader is the contributor, not the answer: the spine's catalog
   * builder dispatches its build event, daedalus_layout's subscriber adds
   * the parsed section for the site theme, and the catalog carries the
   * map beside the axes, through its offered view too. An id the site
   * does not declare is simply not there (the surface ignores it).
   */
  public function testCatalogAnswersTheContributedGroups(): void {
    $catalog = $this->container->get('daedalus_blueprint.dialect_catalog_builder')->build();
    assert($catalog instanceof DialectCatalog);
    $expected = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers for the top of a page.'],
      'bands' => ['label' => 'Bands', 'description' => ''],
    ];
    $this->assertSame($expected, $catalog->groups(), 'The catalog carries the groups in declaration order.');
    $this->assertSame($expected, $catalog->offered()->groups(), 'The offered view carries them too.');
    $this->assertArrayNotHasKey('openers', $catalog->groups(), 'An id the site does not declare: the surface ignores it.');
    $this->assertArrayNotHasKey(DialectCatalog::UNGROUPED, $catalog->groups(), 'The reserved bucket is never a declared group.');

    // The contribution is the subscriber's alone: an event the reader
    // handles carries exactly the parsed section.
    $event = new DialectCatalogBuildEvent([], '');
    $this->container->get('daedalus_layout.site_groups')->onCatalogBuild($event);
    $this->assertSame($expected, $event->getGroups());
  }

  /**
   * The section's grammar, each rule refused loudly, every problem at once.
   */
  public function testParse(): void {
    $this->assertSame([], SiteGroups::parse([]), 'No section: no groups.');
    $this->assertSame([], SiteGroups::parse(['groups' => []]), 'An empty map: no groups.');
    $this->assertSame([], SiteGroups::parse(['branding' => ['slogan' => 'x']]), 'Other site sections are not this reader\'s.');
    $trimmed = SiteGroups::parse([
      'groups' => ['cards' => ['label' => ' Cards ', 'description' => 'Boxed teasers. ']],
    ]);
    $this->assertSame(['cards' => ['label' => 'Cards', 'description' => 'Boxed teasers.']], $trimmed, 'Labels and descriptions are trimmed.');

    foreach ([
      'must be a map of group id' => ['heroes', 'bands'],
      'must be a map of group id to' => 'heroes',
      'keys the group "Heroes"' => ['Heroes' => ['label' => 'Heroes']],
      'keys the group "two-words"' => ['two-words' => ['label' => 'Two']],
      'declares the group "ungrouped"; that id is reserved' => ['ungrouped' => ['label' => 'No group']],
      'declares the group "heroes" as \'Heroes\'' => ['heroes' => 'Heroes'],
      'declares the group "heroes" as array' => ['heroes' => ['Heroes']],
      'declares the group "heroes" with unknown key(s) \'icon\'' => [
        'heroes' => ['label' => 'Heroes', 'icon' => 'x.svg'],
      ],
      'declares the group "heroes" without a label' => ['heroes' => ['description' => 'No label.']],
      'without a label; a group label is a non-empty string' => ['heroes' => ['label' => '  ']],
      'sets the description of the group "heroes" to \'\'' => ['heroes' => ['label' => 'Heroes', 'description' => '']],
      'sets the description of the group "heroes" to 3' => ['heroes' => ['label' => 'Heroes', 'description' => 3]],
    ] as $message => $section) {
      try {
        SiteGroups::parse(['groups' => $section]);
        $this->fail("The section must refuse: $message");
      }
      catch (DeclarationException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }

    // Every problem is reported at once, so a file is fixed in one pass.
    try {
      SiteGroups::parse([
        'groups' => [
          'Bad Id' => ['label' => 'x'],
          'heroes' => [],
          'bands' => ['label' => 'Bands', 'extra' => 1],
          'ungrouped' => ['label' => 'Reserved'],
        ],
      ]);
      $this->fail('Four problems must refuse.');
    }
    catch (DeclarationException $e) {
      $this->assertSame(4, substr_count($e->getMessage(), "The site declaration's groups section"));
    }

    // The validated map answers through the service against the site, so a
    // declaration a later layer breaks fails the catalog build that reads it.
    $declarations = $this->container->get('daedalus_ui.page_builder_declarations');
    $this->assertSame(['heroes', 'bands'], array_keys(SiteGroups::parse($declarations->getSite())));
  }

}
