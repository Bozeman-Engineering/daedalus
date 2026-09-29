<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the eager-identity minter for stored text field HTML.
 *
 * The minter is the server-side canonical guarantee behind eager identity:
 * every vocabulary element (p, h2-h4, blockquote, ul, ol, li) in a stored
 * field value carries a unique short s-* id class so cold-loaded pages are
 * addressable with no editor attached. The contract under test: missing
 * ids are minted at any depth (minting is universal: list items, nested
 * lists, and the elements inside them all mint), colliding ids re-mint
 * the later carrier (first carrier owns the id, even a non-vocabulary
 * one), a vocabulary tag a format allows without class is skipped (the
 * rest still mints, the save never fails) and reported by the runtime
 * requirement so the loss shows on the status report, and a value with
 * nothing to mint comes back byte-identical (no parse/serialize phantom
 * diffs).
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class IdentityMinterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The minter itself only needs filter, but compiling daedalus_edit's container
   * pulls in daedalus_ui and its invisible-to-kernel hard dependencies
   * (see PageTitleEditingTest), so the full stack is listed.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
  ];

  /**
   * The service under test.
   */
  protected IdentityMinter $minter;

  /**
   * A restricted format allowing class on the whole vocabulary.
   */
  protected FilterFormat $restricted;

  /**
   * An unrestricted format (no HTML-restricting filters).
   */
  protected FilterFormat $full;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->restricted = FilterFormat::create([
      'format' => 'restricted',
      'name' => 'Restricted',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p class> <h2 class> <h3 class> <h4 class> <blockquote class> <ul class> <ol class> <li class> <strong> <em> <a href>',
          ],
        ],
      ],
    ]);
    $this->restricted->save();
    $this->full = FilterFormat::create(['format' => 'full', 'name' => 'Full']);
    $this->full->save();
    $this->minter = $this->container->get('daedalus_edit.identity_minter');
  }

  /**
   * Extracts the minted id from an element's class attribute in $html.
   *
   * @return string[]
   *   All s-* ids in the HTML, in document order.
   */
  protected function ids(string $html): array {
    preg_match_all('/class="([^"]*)"/', $html, $matches);
    $ids = [];
    foreach ($matches[1] as $classes) {
      foreach (preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) as $class) {
        if (preg_match(IdentityMinter::CLASS_PATTERN, $class, $m)) {
          $ids[] = $m[1];
        }
      }
    }
    return $ids;
  }

  /**
   * Bare vocabulary elements are minted unique ids; others left alone.
   */
  public function testMintsMissingIds(): void {
    $html = '<p>One</p><h2>Two</h2><h3>Three</h3><h4>Four</h4><blockquote>Five</blockquote><ul><li>Six</li></ul><ol><li>Seven</li></ol><h5>Not vocabulary</h5><div>Neither</div>';
    $minted = $this->minter->mint($html, $this->restricted);

    $ids = $this->ids($minted);
    $this->assertCount(9, $ids, 'Every vocabulary element got an id, list items included.');
    $this->assertSame($ids, array_unique($ids), 'Minted ids are unique.');
    foreach ($ids as $id) {
      $this->assertMatchesRegularExpression('/^[a-z0-9]{3}$/', $id);
    }
    // Non-vocabulary elements are untouched and content survives.
    $this->assertStringContainsString('<h5>Not vocabulary</h5>', $minted);
    $this->assertStringContainsString('<div>Neither</div>', $minted);
    $this->assertStringContainsString('One', $minted);
    $this->assertStringContainsString('Five', $minted);
    // Wrappers and their items each carry their own id.
    $this->assertMatchesRegularExpression('/<ul class="s-[a-z0-9]{3}"><li class="s-[a-z0-9]{3}">Six<\/li><\/ul>/', $minted);
    $this->assertMatchesRegularExpression('/<ol class="s-[a-z0-9]{3}"><li class="s-[a-z0-9]{3}">Seven<\/li><\/ol>/', $minted);

    // The unrestricted format mints the same vocabulary.
    $this->assertCount(9, $this->ids($this->minter->mint($html, $this->full)));
  }

  /**
   * Existing classes are preserved; the id is added alongside.
   */
  public function testPreservesForeignClasses(): void {
    $minted = $this->minter->mint('<p class="lead callout">Hi</p>', $this->restricted);
    preg_match('/class="([^"]*)"/', $minted, $matches);
    $classes = explode(' ', $matches[1]);
    $this->assertContains('lead', $classes);
    $this->assertContains('callout', $classes);
    $this->assertCount(1, $this->ids($minted));
  }

  /**
   * A fully minted value comes back byte-identical, quirks and all.
   */
  public function testByteIdenticalNoOp(): void {
    // Oddities Html::load()/serialize() would normalize away (attribute
    // spacing, entities, self-closed br) prove the string is returned
    // untouched rather than round-tripped.
    $html = '<p   class="s-a1b">One&nbsp;two</p><h2 class="s-zz9" >Title</h2><blockquote class="pull s-0x0">Quote<br /></blockquote><ul class="s-u1u"><li class="s-l1l">Item</li></ul>';
    $this->assertSame($html, $this->minter->mint($html, $this->restricted));

    // Idempotence: minting output is stable under a second pass.
    $minted = $this->minter->mint('<p>One</p><h2 class="s-abc">Two</h2>', $this->restricted);
    $this->assertSame($minted, $this->minter->mint($minted, $this->restricted));
  }

  /**
   * Colliding ids re-mint the later carrier; the first keeps its id.
   */
  public function testDedupesCollisions(): void {
    $minted = $this->minter->mint('<p class="s-abc">First</p><p class="lead s-abc">Copy</p>', $this->restricted);
    $ids = $this->ids($minted);
    $this->assertCount(2, $ids);
    $this->assertSame('abc', $ids[0], 'The first carrier keeps its id.');
    $this->assertNotSame('abc', $ids[1], 'The duplicate re-minted.');
    $this->assertStringContainsString('lead', $minted, 'Foreign classes survive a re-mint.');
  }

  /**
   * List items and their content mint like everything else.
   */
  public function testMintsInsideListItems(): void {
    // Minting is universal: the wrapper, each item, and an expanded
    // item's paragraph all carry their own identity. Collapsed item text
    // holds no paragraph element, so none is invented.
    $minted = $this->minter->mint('<ul><li>One</li><li><p>Nested</p></li></ul>', $this->restricted);
    $this->assertCount(4, $this->ids($minted), 'Wrapper, both items, and the inner paragraph minted.');
    $this->assertMatchesRegularExpression('/<li class="s-[a-z0-9]{3}">One<\/li>/', $minted);
    $this->assertMatchesRegularExpression('/<li class="s-[a-z0-9]{3}"><p class="s-[a-z0-9]{3}">Nested<\/p><\/li>/', $minted);

    // First carrier owns, wherever it sits: the paragraph inside the item
    // keeps s-abc, the outside duplicate re-mints, and the wrapper and
    // item mint their own ids alongside.
    $minted = $this->minter->mint('<ol><li><p class="s-abc">In</p></li></ol><p class="s-abc">Out</p>', $this->restricted);
    $this->assertStringContainsString('<p class="s-abc">In</p>', $minted);
    $ids = $this->ids($minted);
    $this->assertCount(4, $ids);
    $this->assertSame('abc', $ids[2], 'The inside carrier kept its id.');
    $this->assertNotSame('abc', $ids[0], 'The ol minted its own id.');
    $this->assertNotSame('abc', $ids[1], 'The li minted its own id.');
    $this->assertNotSame('abc', $ids[3], 'The outside duplicate re-minted.');
  }

  /**
   * Nested lists mint at any depth.
   */
  public function testMintsNestedLists(): void {
    // Every wrapper and every item is its own addressable element, at
    // any nesting depth.
    $minted = $this->minter->mint('<ul><li>Top<ul><li>Deep<ol><li>Deeper</li></ol></li></ul></li></ul>', $this->restricted);
    $ids = $this->ids($minted);
    $this->assertCount(6, $ids, 'Three wrappers and three items minted.');
    $this->assertSame($ids, array_unique($ids), 'Minted ids are unique.');
    $this->assertMatchesRegularExpression('/^<ul class="s-[a-z0-9]{3}">/', $minted);
    $this->assertMatchesRegularExpression('/Top<ul class="s-[a-z0-9]{3}"><li class="s-[a-z0-9]{3}">Deep<ol class="s-[a-z0-9]{3}">/', $minted, 'Nested wrappers and items minted.');

    // A pre-identified nested list owns its id (first in document order):
    // the top-level duplicate re-mints, the nested carrier keeps its id.
    $minted = $this->minter->mint('<ol><li>In<ul class="s-abc"><li>Deep</li></ul></li></ol><ul class="s-abc"><li>Out</li></ul>', $this->restricted);
    $ids = $this->ids($minted);
    $this->assertCount(6, $ids);
    $this->assertSame('abc', $ids[2], 'The nested carrier kept its id.');
    $this->assertNotSame('abc', $ids[4], 'The top-level duplicate re-minted.');

    // Idempotence over minted lists.
    $this->assertSame($minted, $this->minter->mint($minted, $this->restricted));
  }

  /**
   * A list tag allowed without class is skipped; its wrapper still mints.
   */
  public function testListFormatWithoutClassSkipsTag(): void {
    $partial = FilterFormat::create([
      'format' => 'partial_list',
      'name' => 'Partial list',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p class> <ul class> <li>',
          ],
        ],
      ],
    ]);
    $partial->save();
    $this->assertSame(['li'], $this->minter->unmintable($partial));

    $minted = $this->minter->mint('<ul><li>Hi</li></ul><p>Text</p>', $partial);
    $this->assertCount(2, $this->ids($minted), 'The list wrapper and the paragraph minted.');
    $this->assertMatchesRegularExpression('/<ul class="s-[a-z0-9]{3}"><li>Hi<\/li><\/ul>/', $minted, 'The item was skipped, not stamped with an id the format would strip.');
  }

  /**
   * A vocabulary tag allowed without class is skipped; the rest mints.
   */
  public function testFormatWithoutClassSkipsTag(): void {
    $partial = FilterFormat::create([
      'format' => 'partial_p',
      'name' => 'Partial paragraph',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p> <h2 class>',
          ],
        ],
      ],
    ]);
    $partial->save();
    $this->assertSame(['p'], $this->minter->unmintable($partial));

    $minted = $this->minter->mint('<p>Hi</p><h2>Title</h2>', $partial);
    $this->assertCount(1, $this->ids($minted), 'Only the heading minted.');
    $this->assertStringContainsString('<p>Hi</p>', $minted, 'The paragraph was left untouched.');
    $this->assertMatchesRegularExpression('/<h2 class="s-[a-z0-9]{3}">Title<\/h2>/', $minted);

    // A format whose only allowed vocabulary tags lack class is a byte-
    // identical no-op, and a value-restricted class is as good as none.
    $none = FilterFormat::create([
      'format' => 'restricted_class',
      'name' => 'Restricted class',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p class="lead"> <h2>'],
        ],
      ],
    ]);
    $none->save();
    $this->assertSame(['p', 'h2'], $this->minter->unmintable($none));
    $html = '<p>Hi</p><h2>Title</h2>';
    $this->assertSame($html, $this->minter->mint($html, $none));

    // Formats that mint every tag they allow report nothing to skip.
    $this->assertSame([], $this->minter->unmintable($this->restricted));
    $this->assertSame([], $this->minter->unmintable($this->full));
  }

  /**
   * Daedalus Edit's status report entries, through the hook system.
   *
   * Invoking the hook (not the class) proves the entry reaches the status
   * report; a #[Hook] the container never registered would pass a direct
   * call and still be invisible to the site.
   */
  protected function runtimeRequirements(): array {
    return $this->container->get('module_handler')->invoke('daedalus_edit', 'runtime_requirements') ?? [];
  }

  /**
   * The runtime requirement names every enabled format that skips tags.
   */
  public function testRequirementNamesUnmintableFormats(): void {
    $this->assertSame([], $this->runtimeRequirements(), 'Formats that mint every allowed tag raise no warning.');

    FilterFormat::create([
      'format' => 'partial_p',
      'name' => 'Partial paragraph',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <h2 class> <ul class> <li>'],
        ],
      ],
    ])->save();
    // A disabled format renders nothing, so it is not reported.
    FilterFormat::create([
      'format' => 'retired',
      'name' => 'Retired',
      'status' => FALSE,
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p>'],
        ],
      ],
    ])->save();

    $requirements = $this->runtimeRequirements();
    $this->assertArrayHasKey('daedalus_edit_identity_vocabulary', $requirements);
    $requirement = $requirements['daedalus_edit_identity_vocabulary'];
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertStringContainsString('1 text format allows', (string) $requirement['value']);
    $description = (string) $requirement['description'];
    $this->assertStringContainsString('Partial paragraph (partial_p): p, li', $description, 'The format and its skipped tags, in vocabulary order.');
    $this->assertStringNotContainsString('retired', $description);
    $this->assertStringNotContainsString('h2', $description, 'Tags that mint are not listed.');
  }

  /**
   * Vocabulary tags the format strips entirely are excluded, not fatal.
   */
  public function testStrippedTagsExcluded(): void {
    $partial = FilterFormat::create([
      'format' => 'partial',
      'name' => 'Partial',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p class> <h2 class>',
          ],
        ],
      ],
    ]);
    $partial->save();
    $minted = $this->minter->mint('<p>Yes</p><blockquote>Stripped tag</blockquote>', $partial);
    $this->assertCount(1, $this->ids($minted), 'Only the allowed tag minted.');
    $this->assertStringContainsString('<blockquote>Stripped tag</blockquote>', $minted);

    // A format allowing no vocabulary at all is a vacuous no-op.
    $none = FilterFormat::create([
      'format' => 'none',
      'name' => 'None',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<a href> <em>'],
        ],
      ],
    ]);
    $none->save();
    $html = '<p>Untouchable</p>';
    $this->assertSame($html, $this->minter->mint($html, $none));
  }

}
