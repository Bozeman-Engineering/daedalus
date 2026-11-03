<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The cards roster on the catalog: what each element renders as.
 *
 * A card (dialect writes paired with the HTML each renders as, or the
 * kind shells an undeclared type renders through) contributed on the
 * build event rides into the catalog the builder constructs (the live
 * round trip, through the container's dispatcher and builder) and into a
 * hand-built catalog alike; an owner no layer described answers NULL;
 * the event refuses a malformed shape, an owner that is no dialect tag
 * and a second card for one owner, loudly; the shells spell one sentence
 * for both registers.
 */
#[CoversClass(DialectCatalogBuildEvent::class)]
#[CoversClass(DialectCatalog::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DialectCatalogCardsTest extends BlueprintKernelTestBase {

  /**
   * The text block's card: the pairs the shipped declaration yields.
   */
  private const TEXT_CARD = [
    'renders' => [
      '<d-text>…</d-text>' => '<p>…</p>',
      '<d-text url="…">…</d-text>' => '<p><a class="text-block-link" href="…">…</a></p>',
      '<d-text tag="h1" url="…">…</d-text>' => '<h1><a class="text-block-link" href="…">…</a></h1>',
    ],
    'shells' => [],
  ];

  /**
   * The fallback card for a tagged bundle with no declaration.
   */
  private const FALLBACK_CARD = [
    'renders' => [],
    'shells' => ['block', 'field'],
  ];

  /**
   * A card round-trips through the event into the catalog.
   */
  public function testCardRoundTrip(): void {
    $event = new DialectCatalogBuildEvent([]);
    $event->addCard('d-text', self::TEXT_CARD);
    $event->addCard('d-notes', self::FALLBACK_CARD);
    $event->addCard('d-image', [
      'renders' => [
        '<d-image/>' => '<div></div>',
        '<d-image media="…"/> (raster media)' => '<img class="image-block-image">',
      ],
      'shells' => [],
    ]);
    $event->addCard('d-container', [
      'renders' => [
        '<d-container>…</d-container> (top-level)' => '<section>…</section>',
        '<d-container>…</d-container> (nested)' => '<div>…</div>',
      ],
      'shells' => [],
    ]);

    $cards = $event->getCards();
    $this->assertSame(['d-text', 'd-notes', 'd-image', 'd-container'], array_keys($cards), 'Cards keep their contribution order.');
    $this->assertSame(self::TEXT_CARD, $cards['d-text'], 'A card rides through as given, its pairs in order.');
    $this->assertSame(self::FALLBACK_CARD, $cards['d-notes']);
    $generic_owner = DialectCatalog::ownerForGenericBlockType('gallery');
    $this->assertSame('d-block[type=gallery]', $generic_owner, 'A generically printed bundle\'s owner is the print\'s attribute reading.');
    $event->addCard($generic_owner, self::FALLBACK_CARD);
    $this->assertSame(self::FALLBACK_CARD, $event->getCards()[$generic_owner]);

    // The live round trip: a listener on the container's dispatcher
    // contributes the cards, the builder constructs the catalog, and the
    // catalog answers them.
    $this->container->get('event_dispatcher')->addListener(DialectCatalogBuildEvent::EVENT_NAME, static function (DialectCatalogBuildEvent $event): void {
      $event->addCard('d-text', self::TEXT_CARD);
      $event->addCard('d-notes', self::FALLBACK_CARD);
    });
    $builder = $this->container->get('daedalus_blueprint.dialect_catalog_builder');
    $builder->reset();
    $catalog = $builder->build();
    $this->assertSame(self::TEXT_CARD, $catalog->cardFor('d-text'), 'The built catalog answers the contributed card.');
    $this->assertSame(self::FALLBACK_CARD, $catalog->cardFor('d-notes'));
    $this->assertSame(['d-text', 'd-notes'], array_keys($catalog->cards()));
    $this->assertSame(self::TEXT_CARD, $catalog->offered()->cardFor('d-text'), 'The offered view carries the cards.');

    // A hand-built catalog takes the cards after the groups, every earlier
    // argument unchanged.
    $groups = ['hero' => ['label' => 'Hero', 'description' => '']];
    $hand_built = new DialectCatalog('', [], [], [], groups: $groups, cards: $cards);
    $this->assertSame($cards, $hand_built->cards());
    $this->assertSame(self::TEXT_CARD, $hand_built->cardFor('d-text'));
    $this->assertSame($groups, $hand_built->groups());
  }

  /**
   * The shells of a fallback card spell one sentence.
   */
  public function testRendersThrough(): void {
    $sentence = 'the theme\'s block template, each field in its field shell';
    $this->assertSame($sentence, DialectCatalog::rendersThrough(['block', 'field']));
    $this->assertSame('the region shell', DialectCatalog::rendersThrough(['region']), 'An unknown kind is named as its shell.');
  }

  /**
   * An owner no layer described answers NULL, on every catalog.
   */
  public function testOwnerWithoutCardAnswersNull(): void {
    $event = new DialectCatalogBuildEvent([]);
    $event->addCard('d-text', self::TEXT_CARD);
    $catalog = new DialectCatalog('', [], [], [], cards: $event->getCards());
    $this->assertNull($catalog->cardFor('d-image'), 'A tagged type with no card is NULL, not an empty card.');
    $this->assertNull($catalog->cardFor('d-block'));
    $bare = new DialectCatalog('', [], [], []);
    $this->assertSame([], $bare->cards(), 'A catalog built with no cards has none.');
    $this->assertNull($bare->cardFor('d-text'));
  }

  /**
   * A malformed card shape is refused loudly.
   */
  #[DataProvider('malformedCards')]
  public function testMalformedCardThrows(array $card, string $message): void {
    $event = new DialectCatalogBuildEvent([]);
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    $event->addCard('d-text', $card);
  }

  /**
   * Cards off the shape, each with its refusal.
   */
  public static function malformedCards(): array {
    $missing_shells = self::TEXT_CARD;
    unset($missing_shells['shells']);
    $extra_key = self::TEXT_CARD + ['forms' => []];
    $renders_not_a_map = self::TEXT_CARD;
    $renders_not_a_map['renders'] = '<p>…</p>';
    $html_not_a_string = self::TEXT_CARD;
    $html_not_a_string['renders'] = ['<d-text>…</d-text>' => ['<p>']];
    $empty_html = self::TEXT_CARD;
    $empty_html['renders'] = ['<d-text>…</d-text>' => ''];
    $input_not_a_write = self::TEXT_CARD;
    $input_not_a_write['renders'] = ['<p>…</p>'];
    $shells_not_names = self::TEXT_CARD;
    $shells_not_names['shells'] = [['block']];
    $nothing = ['renders' => [], 'shells' => []];
    return [
      'a missing key' => [$missing_shells, 'carries exactly renders, shells'],
      'an unknown key' => [$extra_key, 'carries exactly renders, shells'],
      'renders that are no map' => [$renders_not_a_map, 'map each dialect write to the HTML it renders as'],
      'an html that is no string' => [$html_not_a_string, 'both non-empty strings'],
      'an empty html' => [$empty_html, 'both non-empty strings'],
      'a list, not writes paired with html' => [$input_not_a_write, 'both non-empty strings'],
      'shells that are no names' => [$shells_not_names, 'are a list of kind names'],
      'no renders and no shells' => [$nothing, 'says nothing'],
    ];
  }

  /**
   * An owner that is no dialect tag or generic spelling is refused.
   */
  public function testOwnerOutsideTheSpellingThrows(): void {
    $event = new DialectCatalogBuildEvent([]);
    foreach (['text', 'block_type:text', 'd-block[type=]', 'd-block[plugin=system_branding_block]', 'd-Text', ''] as $owner) {
      try {
        $event->addCard($owner, self::TEXT_CARD);
        $this->fail(sprintf('"%s" was accepted as a card owner.', $owner));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('Cards are declared per owner', $e->getMessage());
      }
    }
    $this->assertSame([], $event->getCards());
  }

  /**
   * Two cards for one owner are a wiring fault, refused loudly.
   */
  public function testSecondCardForOwnerThrows(): void {
    $event = new DialectCatalogBuildEvent([]);
    $event->addCard('d-text', self::TEXT_CARD);
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Two catalog contributors declared the card of d-text.');
    $event->addCard('d-text', self::TEXT_CARD);
  }

}
