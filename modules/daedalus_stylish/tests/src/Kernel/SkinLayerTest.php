<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinDefaultsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinSiteTokensItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the two layers of a skin: the package's and the site's over it.
 *
 * The package layer (tokens, defaults) is what a module ships and the
 * payload the source hash covers; the site layer (site_tokens,
 * site_defaults) holds what the site added or changed, by name. Every
 * reader but the disk lane reads the merge: token records per key
 * (SkinTokensItem::mergeLayers()), defaults per scope, key, condition and
 * property (SkinDefaultsItem::mergeLayers()). Removing a site entry
 * reveals the package's value, and a site write is never a modification
 * of what the package shipped.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinLayerTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

  /**
   * A shipped package layer of tokens, full records.
   */
  protected const PACKAGE_TOKENS = [
    'space' => [
      '4' => ['value' => '1rem', 'description' => 'The base step.', 'offered' => TRUE],
      '8' => ['value' => '2rem', 'description' => '', 'offered' => TRUE],
    ],
    'color' => [
      'accent' => [
        'value' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0'],
        'description' => 'The one hue.',
        'offered' => TRUE,
      ],
    ],
  ];

  /**
   * A shipped package layer of defaults.
   */
  protected const PACKAGE_DEFAULTS = [
    'kind:container' => [
      '' => ['default' => ['padding' => '2rem', 'gap' => '1rem'], 'narrow' => ['padding' => '1rem']],
      '::.band-inner' => ['default' => ['max-width' => '80rem']],
    ],
    'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // The materials roster's daedalus_stylish provider reads all four
    // storages on every build, and every compile builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
      'base' => [
        'h2' => ['property_groups' => ['typography' => []]],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * A skin wearing the shipped package layer, saved.
   */
  protected function createSkin(): DaedalusStylishSkin {
    $skin = DaedalusStylishSkin::create(['label' => 'aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setPackageTokens(self::PACKAGE_TOKENS)->setPackageDefaults(self::PACKAGE_DEFAULTS)->save();
    return $skin;
  }

  /**
   * Token records merge per key: a site fact replaces, the rest show through.
   */
  public function testTokensMerge(): void {
    $skin = $this->createSkin();
    $this->assertSame(self::PACKAGE_TOKENS, $skin->tokens(), 'With no site layer the skin as worn is the package layer.');
    $this->assertSame([], $skin->siteTokens());

    $skin->setSiteTokens([
      'space' => [
        // A bare value is shorthand for {value}: a re-valued token.
        '4' => '1.25rem',
        // One fact changed, the value unstated.
        '8' => ['offered' => FALSE],
        // Site-minted, its unstated facts at their defaults.
        'gutter' => ['value' => '3rem', 'description' => 'The band gutter.'],
      ],
      // A scale the package leaves empty is minted whole.
      'color' => ['muted' => '#777777'],
    ])->save();

    $this->assertSame([
      'space' => [
        '4' => ['value' => '1.25rem'],
        '8' => ['offered' => FALSE],
        'gutter' => ['value' => '3rem', 'description' => 'The band gutter.'],
      ],
      'color' => ['muted' => ['value' => '#777777']],
    ], DaedalusStylishSkin::load($skin->id())->siteTokens(), 'The site layer stores exactly the facts it states, bare values wrapped.');
    $this->assertSame(self::PACKAGE_TOKENS, $skin->packageTokens(), 'A site write leaves the package layer alone.');

    $this->assertSame([
      'space' => [
        '4' => ['value' => '1.25rem', 'description' => 'The base step.', 'offered' => TRUE],
        '8' => ['value' => '2rem', 'description' => '', 'offered' => FALSE],
        'gutter' => ['value' => '3rem', 'description' => 'The band gutter.', 'offered' => TRUE],
      ],
      'color' => [
        'accent' => self::PACKAGE_TOKENS['color']['accent'],
        'muted' => ['value' => '#777777', 'description' => '', 'offered' => TRUE],
      ],
    ], $skin->tokens(), 'The merge: site facts over the package record, package order first, site additions after, full records throughout.');

    // Unsetting a site entry reveals the package's token whole.
    $skin->setSiteTokens(['space' => ['gutter' => '3rem']])->save();
    $this->assertSame(self::PACKAGE_TOKENS['space']['4'], $skin->tokens()['space']['4'], 'A removed site value reveals the package value.');
    $this->assertTrue($skin->tokens()['space']['8']['offered'], 'A removed site fact reveals the package fact.');
    $this->assertArrayNotHasKey('muted', $skin->tokens()['color']);
    $this->assertSame(['value' => '3rem', 'description' => '', 'offered' => TRUE], $skin->tokens()['space']['gutter']);
  }

  /**
   * The site layer's door grammar: what counts as a record, what as a value.
   */
  public function testSiteTokenCanonicalization(): void {
    $this->assertSame(['value' => '1rem'], SkinSiteTokensItem::canonicalizeSiteToken('1rem'));
    $conditioned = ['default' => '#111', 'dark' => '#eee'];
    $this->assertSame(['value' => $conditioned], SkinSiteTokensItem::canonicalizeSiteToken($conditioned), 'A condition map is a value: no condition is spelled like a record fact.');
    $this->assertSame(['description' => 'Why.'], SkinSiteTokensItem::canonicalizeSiteToken(['description' => 'Why.']));
    $unordered = ['offered' => FALSE, 'value' => '1rem'];
    $this->assertSame(['value' => '1rem', 'offered' => FALSE], SkinSiteTokensItem::canonicalizeSiteToken($unordered), 'Stated facts settle into stored order.');
    $foreign = ['bogus' => 1, 'value' => '1rem'];
    $this->assertSame(['value' => '1rem', 'bogus' => 1], SkinSiteTokensItem::canonicalizeSiteToken($foreign), 'A key outside the record rides along for the validator.');
    $this->assertSame(['value' => []], SkinSiteTokensItem::canonicalizeSiteToken([]), 'An empty map is a malformed value, not an empty record.');
  }

  /**
   * Defaults merge per scope, key, condition and property.
   */
  public function testDefaultsMerge(): void {
    $skin = $this->createSkin();
    $this->assertSame(self::PACKAGE_DEFAULTS, $skin->defaults());

    $skin->setSiteDefaults([
      'kind:container' => [
        // One property replaced, the sibling and the other condition show
        // through; a new condition joins.
        '' => ['default' => ['gap' => '1.5rem'], 'dark' => ['background' => '#000']],
        // A new relative key joins.
        '::> *' => ['default' => ['min-width' => '0']],
      ],
      // A new scope joins.
      'base:a' => ['' => ['default' => ['color' => 'var(--color-accent)']]],
    ])->save();

    $this->assertSame([
      'kind:container' => [
        '' => [
          'default' => ['padding' => '2rem', 'gap' => '1.5rem'],
          'narrow' => ['padding' => '1rem'],
          'dark' => ['background' => '#000'],
        ],
        '::.band-inner' => ['default' => ['max-width' => '80rem']],
        '::> *' => ['default' => ['min-width' => '0']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
      'base:a' => ['' => ['default' => ['color' => 'var(--color-accent)']]],
    ], DaedalusStylishSkin::load($skin->id())->defaults(), 'The merge: a site property replaces, everything else shows through, package order first.');
    $this->assertSame(self::PACKAGE_DEFAULTS, $skin->packageDefaults(), 'A site write leaves the package layer alone.');

    $skin->setSiteDefaults([])->save();
    $this->assertSame(self::PACKAGE_DEFAULTS, $skin->defaults(), 'An emptied site layer reveals the package layer whole.');
  }

  /**
   * The site layer is outside the shipped payload: never a modification.
   */
  public function testSiteLayerIsOutsideTheShippedPayload(): void {
    $skin = $this->createSkin();
    $skin->setShippedSource('example_package', ['tokens' => self::PACKAGE_TOKENS, 'defaults' => self::PACKAGE_DEFAULTS])->save();
    $this->assertTrue($skin->isShipped());
    $this->assertFalse($skin->isModified());

    $skin->setSiteTokens(['space' => ['4' => '9rem']])->setSiteDefaults(['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]])->save();
    $this->assertFalse($skin->isModified(), 'A site layer over a shipped skin is not a modification of what the package shipped.');
    $this->assertFalse($skin->hasNewDefaults(['tokens' => self::PACKAGE_TOKENS, 'defaults' => self::PACKAGE_DEFAULTS]));

    $skin->setPackageTokens(['space' => ['4' => '9rem']] + self::PACKAGE_TOKENS);
    $this->assertTrue($skin->isModified(), 'The package layer is the payload the hash covers.');
  }

  /**
   * Both layers are revisionable: a revert changes what a layer says.
   */
  public function testLayersAreRevisionable(): void {
    $skin = $this->createSkin();
    $first = (int) $skin->getRevisionId();

    $skin->setSiteTokens(['space' => ['4' => '1.25rem']]);
    $skin->setSiteDefaults(['base:h2' => ['' => ['default' => ['font-size' => '3rem']]]]);
    $skin->setNewRevision(TRUE);
    $skin->save();
    $this->assertNotSame($first, (int) $skin->getRevisionId());

    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_skin');
    $old = $storage->loadRevision($first);
    $this->assertSame([], $old->siteTokens(), 'The earlier revision carries the site layer as it was.');
    $this->assertSame([], $old->siteDefaults());
    $this->assertSame(self::PACKAGE_TOKENS, $old->tokens());
    $current = $storage->load($skin->id());
    $this->assertSame('1.25rem', $current->tokens()['space']['4']['value']);
    $this->assertSame('3rem', $current->defaults()['base:h2']['']['default']['font-size']);
  }

  /**
   * The site layer is off the blueprint's generic field spine.
   *
   * Its doors are the token operations and update_default; the blueprint's
   * whole-field handlers (which canonicalize to full records and validate
   * a whole map) never reach it, since the fields are internal and the
   * site tokens type is a sibling the tokens handler does not apply to.
   */
  public function testSiteLayerIsOffTheBlueprintSpine(): void {
    $definitions = $this->container->get('entity_field.manager')->getFieldDefinitions('daedalus_skin', 'daedalus_skin');
    $this->assertTrue($definitions['site_tokens']->isInternal());
    $this->assertTrue($definitions['site_defaults']->isInternal());
    $this->assertTrue($definitions['site_tokens']->isRevisionable());
    $this->assertTrue($definitions['site_defaults']->isRevisionable());
    $this->assertSame('daedalus_skin_site_tokens', $definitions['site_tokens']->getType());
    $this->assertSame('daedalus_skin_defaults', $definitions['site_defaults']->getType());

    $entity_type = $this->container->get('entity_type.manager')->getDefinition('daedalus_skin');
    $processable = $this->container->get('daedalus_blueprint.field_handler_manager')->filterFieldDefinitions($entity_type, $definitions, 'daedalus_skin');
    $this->assertArrayHasKey('tokens', $processable);
    $this->assertArrayHasKey('defaults', $processable);
    $this->assertArrayNotHasKey('site_tokens', $processable, 'The blueprint spine skips the site tokens.');
    $this->assertArrayNotHasKey('site_defaults', $processable, 'The blueprint spine skips the site defaults.');
    $this->assertFalse($this->container->get('daedalus_stylish.field_handler.skin_tokens')->applies($definitions['site_tokens']));
  }

  /**
   * Delivery, the payload and the roster read the skin as worn.
   */
  public function testMergedLayersDeliver(): void {
    $skin = $this->createSkin();
    $skin->setSiteTokens(['space' => ['4' => '1.25rem', '8' => ['offered' => FALSE], 'gutter' => '3rem']]);
    $skin->setSiteDefaults(['kind:container' => ['' => ['default' => ['gap' => '1.5rem']]]]);
    $skin->save();
    $this->activateSkin($skin);

    $css = file_get_contents($this->container->get('state')->get(DefaultsStylesheet::STATE_KEY));
    $this->assertStringContainsString('--space-4: 1.25rem;', $css, 'The site value delivers.');
    $this->assertStringNotContainsString('--space-4: 1rem;', $css, 'The overridden package value does not.');
    $this->assertStringContainsString('--space-8: 2rem;', $css, 'A withheld token still delivers: offered is a row fact, not delivery.');
    $this->assertStringContainsString('--space-gutter: 3rem;', $css, 'A site-minted token delivers.');
    $selector = DefaultsStylesheet::elementSelector(Address::fromString('kind:container'));
    $this->assertStringContainsString($selector . " {\n  padding: 2rem;\n  gap: 1.5rem;\n}", $css, 'The site property over the package default, the sibling showing through.');
    $this->assertStringContainsString("font-size: 2rem;", $css);

    $payload = $this->container->get('daedalus_stylish.client_style_guide')->build(self::THEME);
    $this->assertSame($skin->defaults(), $payload['skinDefaults'], 'The client payload serves the merge.');

    $tokens = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->tokens();
    $this->assertSame('1.25rem', $tokens['space/4']->value);
    $this->assertSame('The base step.', $tokens['space/4']->description, 'The package description shows through a site re-value.');
    $this->assertFalse($tokens['space/8']->offered);
    $this->assertSame('3rem', $tokens['space/gutter']->value);

    // Unsetting the site layer reveals the package layer on the page.
    $skin->setSiteTokens([])->setSiteDefaults([])->save();
    $css = file_get_contents($this->container->get('state')->get(DefaultsStylesheet::STATE_KEY));
    $this->assertStringContainsString('--space-4: 1rem;', $css);
    $this->assertStringNotContainsString('--space-gutter', $css);
    $this->assertStringContainsString($selector . " {\n  padding: 2rem;\n  gap: 1rem;\n}", $css);
  }

  /**
   * A corrupt layer fails loudly on read; the merge never guesses.
   */
  public function testCorruptLayersFailLoudly(): void {
    try {
      SkinTokensItem::mergeLayers(self::PACKAGE_TOKENS, ['space' => ['ghost' => ['offered' => FALSE]]]);
      $this->fail('A site-minted token without a value is corrupt.');
    }
    catch (\UnexpectedValueException $exception) {
      $this->assertStringContainsString('space.ghost states no value', $exception->getMessage());
    }
    try {
      SkinTokensItem::mergeLayers(self::PACKAGE_TOKENS, ['space' => ['4' => '1rem']]);
      $this->fail('A site token that is not a record is corrupt.');
    }
    catch (\UnexpectedValueException $exception) {
      $this->assertStringContainsString('space.4 is not a record', $exception->getMessage());
    }
    try {
      SkinDefaultsItem::mergeLayers(self::PACKAGE_DEFAULTS, ['kind:container' => ['' => 'nope']]);
      $this->fail('A site defaults level that is not a map is corrupt.');
    }
    catch (\UnexpectedValueException $exception) {
      $this->assertStringContainsString('"kind:container." is not a map', $exception->getMessage());
    }

    // The values a layer states: the package's all, a site record's only
    // when it states one; a non-record is refused in both.
    $site = ['space' => ['4' => ['value' => '1.25rem'], '8' => ['offered' => FALSE]]];
    $this->assertSame(['space' => ['4' => '1.25rem']], SkinTokensItem::statedValues($site));
    $this->expectException(\UnexpectedValueException::class);
    SkinTokensItem::statedValues(['space' => ['4' => '1rem']]);
  }

}
