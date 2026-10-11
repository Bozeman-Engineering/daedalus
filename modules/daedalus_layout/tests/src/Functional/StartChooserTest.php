<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Functional;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The start chooser on the add form, and its bundle setting.
 *
 * A page's first document has one source, picked once at creation. The
 * chooser is the bundle's own add form: with "Offer starting designs" on
 * and at least one page blueprint offered for the bundle, a request with
 * no `start` shows the cards in place of the fields (Blank, then the
 * offered blueprints, with a Template card ahead of Blank only when the
 * bundle's template holds a document to follow, the verb's judgement;
 * each card one link to `?start=<id>`; the groups the offered blueprints
 * carry as filter links); with a valid
 * `start` the normal form shows under a "Starting from X. Change" line and
 * the form's one save lands entity and document together (the entity
 * builder applying the spine's start verb on the submit pass); an unknown
 * or unoffered `start` shows the chooser again under a message. The
 * setting off, or no page blueprint offered, is the plain form. The
 * setting itself sits in the bundle form's creation group with the count
 * of fitting page blueprints and the starts the chooser will offer (the
 * template named only when it holds a document), disabled where pages
 * share the template's layout. Any content entity type whose bundle owns
 * a document gets the same chooser; a vocabulary's terms are the second
 * type here. The fixture's Page template is blank (the recipe's), so the
 * node leg sees the chooser without its Template card first, then fills
 * the template with one band of text and sees the card, the words, the
 * preview and the saved page follow.
 *
 * A card's face is a lazily loaded frame on the preview route: the start's
 * dry run on a sample of the bundle, rendered chromeless (preview mode)
 * and saved nowhere, gated by the bundle's create access; what the chooser
 * never offers is not found there.
 *
 * This tier sees the served form, the stored setting, the saved document
 * and the preview route's answer; no browser is needed because a card is
 * a link and the start lands in the form's own save. The canvas the save
 * lands on is FJS step 1 of the landing, and the frame loading and scaling
 * inside a card is step 2 (both BlueprintPlacementBehaviorTest).
 *
 * The add form runs as the editor persona (the recipe's grant to create
 * pages is the whole gate); the bundle forms and the vocabulary run as uid
 * 1 by explicit exception: structure forms, which the editor role has no
 * business reaching.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class StartChooserTest extends BrowserTestBase {

  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    // The group vocabulary (heroes, bands) and a second bundle entity type
    // (vocabularies); neither is part of the recipe.
    \Drupal::service('module_installer')->install(['daedalus_layout_site_groups_test', 'taxonomy']);
    $this->rebuildContainer();
    // Two page blueprints for Page content: one placing the page's title
    // field (so it fits nodes alone), one of a text block only (so it fits
    // any host with the text block type). Each carries one group, and the
    // text of each block names the blueprint, so a started page says
    // where it came from.
    $this->createPageBlueprint('landing', 'Landing', 'A hero and the title.', ['heroes'], [
      ['kind' => 'block', 'block_type' => 'text', 'block_fields' => ['field_text_text' => ['value' => 'Landing intro']]],
      ['kind' => 'block', 'field' => 'title'],
    ]);
    $this->createPageBlueprint('notice', 'Notice', 'One band of text.', ['bands'], [
      ['kind' => 'block', 'block_type' => 'text', 'block_fields' => ['field_text_text' => ['value' => 'Notice band']]],
    ]);
    // A block blueprint, which no chooser ever offers.
    $this->createBlueprint('card', 'Card', 'block', [], [
      ['kind' => 'block', 'block_type' => 'text', 'block_fields' => ['field_text_text' => ['value' => 'Card text']]],
    ]);
  }

  /**
   * The setting on the bundle form, the chooser, the three starts, the rest.
   */
  public function testChooserOnNodeAddForm(): void {
    $assert_session = $this->assertSession();

    // The setting off (the recipe ships it so): the plain add form.
    $this->loginAsEditor();
    $this->drupalGet('/node/add/page');
    $assert_session->statusCodeEquals(200);
    $this->assertPlainForm();

    // The setting on the bundle form: in the creation group, with the
    // starts the chooser will offer (the Page template is blank, so no
    // template among them), the count of fitting page blueprints and the
    // listing's link; saved with the form.
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('/admin/structure/types/manage/page');
    $control = $assert_session->elementExists('css', '[data-daedalus-ui-group="creation"] input[name="daedalus_layout_offer_starts"]');
    $this->assertFalse($control->isChecked());
    $this->assertFalse($control->hasAttribute('disabled'));
    $this->assertSame('Starting designs offered', $control->getAttribute('data-daedalus-ui-summary'));
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-layout-offer-starts"]', 'Offer starting designs');
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="creation"]', 'Before the form, the author picks how the page starts: a page blueprint or blank. 2 page blueprints fit Page content.');
    $listing = $assert_session->elementExists('named', ['link', 'Manage blueprints']);
    $this->assertStringContainsString('/admin/content/blueprints', $listing->getAttribute('href'));
    $this->submitForm(['daedalus_layout_offer_starts' => TRUE], 'Save');
    $this->assertTrue(NodeType::load('page')->getThirdPartySetting('daedalus_layout', 'offer_starts'));
    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->checkboxChecked('daedalus_layout_offer_starts');

    // The chooser: in place of the fields, Blank first, then the offered
    // page blueprints and never the block one; no Template card, since the
    // Page template is blank and the two reserved starts would open the
    // same empty page; each card one link to the same address with the
    // start; the filters the offered starts carry.
    $this->loginAsEditor();
    $this->drupalGet('/node/add/page');
    $assert_session->statusCodeEquals(200);
    $assert_session->elementExists('css', '[data-daedalus-layout-start-chooser]');
    $assert_session->fieldNotExists('title[0][value]');
    $assert_session->buttonNotExists('Save');
    $assert_session->elementNotExists('css', '.daedalus-layout-start-chooser__refused');
    $assert_session->elementTextContains('css', '.daedalus-layout-start-chooser__ask', 'Choose how this page starts');
    $assert_session->elementTextContains('css', '.daedalus-layout-start-chooser__lede', 'Every start can be changed on the page afterwards.');
    $this->assertSame(['blank', 'landing', 'notice'], $this->cardIds());
    $assert_session->elementNotExists('css', 'a[data-daedalus-layout-start-card="template"]');
    foreach ($this->cardIds() as $id) {
      $card = $assert_session->elementExists('css', "a[data-daedalus-layout-start-card=\"$id\"]");
      $this->assertStringEndsWith("/node/add/page?start=$id", $card->getAttribute('href'));
      $assert_session->elementExists('css', "a[data-daedalus-layout-start-card=\"$id\"] .daedalus-layout-start-card__face[data-daedalus-layout-start-face=\"$id\"]");
    }
    $assert_session->elementTextContains('css', 'a[data-daedalus-layout-start-card="blank"]', 'An empty page. Nothing from the template.');
    $assert_session->elementTextContains('css', 'a[data-daedalus-layout-start-card="landing"] .daedalus-layout-start-card__name', 'Landing');
    $assert_session->elementTextContains('css', 'a[data-daedalus-layout-start-card="landing"] .daedalus-layout-start-card__description', 'A hero and the title.');
    $this->assertSame(['' => 'All', 'heroes' => 'Heroes', 'bands' => 'Bands'], $this->filters());
    $this->assertSame('true', $assert_session->elementExists('css', '[data-daedalus-layout-start-group=""]')->getAttribute('aria-current'));
    $this->assertFalse($assert_session->elementExists('css', '[data-daedalus-layout-start-group="heroes"]')->hasAttribute('aria-current'));
    $this->assertStringEndsWith('/node/add/page?group=heroes', $assert_session->elementExists('css', '[data-daedalus-layout-start-group="heroes"]')->getAttribute('href'));
    $assert_session->responseContains('daedalus_layout/css/start-chooser.css');

    // Each card's face holds a lazily loaded frame on the preview route,
    // chromeless through preview mode and inert (no focus, no pointer: the
    // card stays one link).
    foreach ($this->cardIds() as $id) {
      $frame = $assert_session->elementExists('css', "a[data-daedalus-layout-start-card=\"$id\"] .daedalus-layout-start-card__face[data-daedalus-layout-start-face=\"$id\"] iframe.daedalus-layout-start-card__frame");
      $this->assertStringEndsWith("/daedalus-layout/start-preview/node/page/$id?navigationMode=preview", $frame->getAttribute('src'));
      $this->assertSame('lazy', $frame->getAttribute('loading'));
      $this->assertSame('-1', $frame->getAttribute('tabindex'));
      $this->assertSame('true', $frame->getAttribute('aria-hidden'));
    }

    // The preview route: the start's dry run on a sample of the bundle,
    // rendered chromeless with the skin on, saved nowhere. A blueprint's
    // shows the blueprint's content; blank is the empty page; template is
    // the plain render (the recipe's Page template is blank, so it shows
    // neither blueprint) and stays valid here though no card offers it: it
    // is the default a page gets when nothing is chosen. No request adds a
    // node, a block or a media item.
    $counts = $this->counts();
    $this->drupalGet('/daedalus-layout/start-preview/node/page/landing', ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Landing intro');
    $assert_session->pageTextNotContains('Notice band');
    $this->assertChromeless();
    $this->drupalGet('/daedalus-layout/start-preview/node/page/notice', ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Notice band');
    $assert_session->pageTextNotContains('Landing intro');
    foreach (['blank', 'template'] as $start) {
      $this->drupalGet("/daedalus-layout/start-preview/node/page/$start", ['query' => ['navigationMode' => 'preview']]);
      $assert_session->statusCodeEquals(200);
      $assert_session->pageTextNotContains('Landing intro');
      $assert_session->pageTextNotContains('Notice band');
      $this->assertChromeless();
    }
    $this->assertSame($counts, $this->counts(), 'A preview saves nothing: the nodes, blocks and media are as they were.');
    // What the chooser never offers is not found: the block blueprint, an
    // unknown id, and any start on a bundle whose entities are no pages.
    foreach (['card', 'old_landing'] as $start) {
      $this->drupalGet("/daedalus-layout/start-preview/node/page/$start", ['query' => ['navigationMode' => 'preview']]);
      $assert_session->statusCodeEquals(404);
    }
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('/daedalus-layout/start-preview/block_content/text/template', ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(404);
    // Without the bundle's create access: forbidden, whatever the start.
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    foreach (['landing', 'template'] as $start) {
      $this->drupalGet("/daedalus-layout/start-preview/node/page/$start", ['query' => ['navigationMode' => 'preview']]);
      $assert_session->statusCodeEquals(403);
    }
    $this->loginAsEditor();

    // A group filter narrows the blueprint cards; Blank stays; a pick
    // under a filter keeps it; an undeclared group filters nothing.
    $this->drupalGet('/node/add/page', ['query' => ['group' => 'heroes']]);
    $this->assertSame(['blank', 'landing'], $this->cardIds());
    $this->assertSame('true', $assert_session->elementExists('css', '[data-daedalus-layout-start-group="heroes"]')->getAttribute('aria-current'));
    $this->assertFalse($assert_session->elementExists('css', '[data-daedalus-layout-start-group=""]')->hasAttribute('aria-current'));
    $href = $assert_session->elementExists('css', 'a[data-daedalus-layout-start-card="landing"]')->getAttribute('href');
    $this->assertStringContainsString('group=heroes', $href);
    $this->assertStringContainsString('start=landing', $href);
    $this->drupalGet('/node/add/page', ['query' => ['group' => 'bands']]);
    $this->assertSame(['blank', 'notice'], $this->cardIds());
    $this->drupalGet('/node/add/page', ['query' => ['group' => 'nope']]);
    $this->assertSame(['blank', 'landing', 'notice'], $this->cardIds());

    // A refused start: unknown, or offered nowhere (the block blueprint).
    // The chooser shows again under the message.
    foreach (['old_landing', 'card'] as $refused) {
      $this->drupalGet('/node/add/page', ['query' => ['start' => $refused]]);
      $assert_session->statusCodeEquals(200);
      $assert_session->fieldNotExists('title[0][value]');
      $assert_session->elementTextContains('css', '.daedalus-layout-start-chooser__refused', "\"$refused\" is not a starting design for Page content. Choose one below.");
      $this->assertSame(['blank', 'landing', 'notice'], $this->cardIds());
    }

    // A page blueprint's start: the form under the line, then one save
    // landing the page with the blueprint's document: its one root with
    // fresh identity and no provenance stamp, its text block saved once
    // and recorded as the page's usage, its text the blueprint's, the
    // title field resolved against the page bundle.
    $blocks_before = $this->blockIds();
    $this->drupalGet('/node/add/page', ['query' => ['start' => 'landing']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->elementNotExists('css', '[data-daedalus-layout-start-chooser]');
    $assert_session->fieldExists('title[0][value]');
    $line = $assert_session->elementExists('css', '.daedalus-layout-start-line[data-daedalus-layout-start="landing"]');
    $this->assertSame('Starting from Landing. Change', preg_replace('/\s+/', ' ', trim($line->getText())));
    $this->assertStringEndsWith('/node/add/page', $assert_session->elementExists('named', ['link', 'Change'])->getAttribute('href'));
    $this->submitForm(['title[0][value]' => 'From Landing'], 'Save');
    $assert_session->statusCodeEquals(200);
    $node = $this->nodeTitled('From Landing');
    $this->assertSame(1, $this->revisionCount($node), 'One save: the page has one revision.');
    $sections = $this->rawSections($node);
    $this->assertCount(1, $sections, 'The blueprint\'s one root landed, and nothing of the template under it.');
    $this->assertNull($sections[0]->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint'), 'A started page is not a placement.');
    $this->assertNotSame(
      $this->rawSections($this->loadBlueprint('landing'))[0]->getThirdPartySetting('daedalus_layout', 'uuid'),
      $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'),
      'Fresh identity: the root is not the blueprint\'s.',
    );
    $this->assertSame(['inline_block:text', 'field_block:node:page:title'], $this->pluginIds($sections));
    $new_blocks = array_values(array_diff($this->blockIds(), $blocks_before));
    $this->assertCount(1, $new_blocks, 'The one inline block was saved exactly once.');
    $block = BlockContent::load($new_blocks[0]);
    $this->assertSame('Landing intro', $block->get('field_text_text')->value);
    $usage = \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u', ['block_content_id'])
      ->condition('layout_entity_type', 'node')
      ->condition('layout_entity_id', $node->id())
      ->execute()->fetchCol();
    $this->assertSame($new_blocks, array_map('intval', $usage), 'The page\'s usage names its block and nothing else.');
    $this->assertCount(1, $this->host($node)->ownSections(), 'The page reads its own document, not a seed.');
    // The page's view, where the save landed, shows the blueprint's text.
    $this->drupalGet($node->toUrl());
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Landing intro');

    // A template with a document to follow: one band of text on the Page
    // display. The Template card now leads the cards, with its words, and
    // stays under every filter; the bundle form names the template among
    // the starts; the template preview shows the band and neither
    // blueprint.
    $this->fillPageTemplate();
    $this->drupalGet('/node/add/page');
    $this->assertSame(['template', 'blank', 'landing', 'notice'], $this->cardIds());
    $card = $assert_session->elementExists('css', 'a[data-daedalus-layout-start-card="template"]');
    $this->assertStringEndsWith('/node/add/page?start=template', $card->getAttribute('href'));
    $assert_session->elementTextContains('css', 'a[data-daedalus-layout-start-card="template"]', 'The Page template. Follows the template until you edit the page.');
    $assert_session->elementExists('css', 'a[data-daedalus-layout-start-card="template"] .daedalus-layout-start-card__face[data-daedalus-layout-start-face="template"] iframe.daedalus-layout-start-card__frame');
    $this->drupalGet('/node/add/page', ['query' => ['group' => 'heroes']]);
    $this->assertSame(['template', 'blank', 'landing'], $this->cardIds());
    $this->drupalGet('/daedalus-layout/start-preview/node/page/template', ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Template band');
    $assert_session->pageTextNotContains('Landing intro');
    $assert_session->pageTextNotContains('Notice band');
    $this->assertChromeless();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="creation"]', 'Before the form, the author picks how the page starts: a page blueprint, the template, or blank. 2 page blueprints fit Page content.');
    $this->loginAsEditor();

    // Blank: core's marker, persisted by the one save, read as no sections,
    // and nothing of the template on the page.
    $blocks_before = $this->blockIds();
    $this->drupalGet('/node/add/page', ['query' => ['start' => 'blank']]);
    $line = $assert_session->elementExists('css', '.daedalus-layout-start-line[data-daedalus-layout-start="blank"]');
    $this->assertStringContainsString('Starting from a blank page.', $line->getText());
    $this->submitForm(['title[0][value]' => 'From Blank'], 'Save');
    $node = $this->nodeTitled('From Blank');
    $this->assertSame(1, $this->revisionCount($node));
    $sections = $this->rawSections($node);
    $this->assertCount(1, $sections);
    $this->assertSame('layout_builder_blank', $sections[0]->getLayoutId(), 'Started, and empty: core\'s own marker.');
    $this->assertSame([], $this->host($node)->ownSections(), 'A blank page reads as no sections.');
    $this->assertSame($blocks_before, $this->blockIds(), 'Blank saved no block.');
    $this->drupalGet($node->toUrl());
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('Template band');

    // Template: the field stays empty, so the page follows its template
    // until its first edit seeds it, as every page did before starts; its
    // view shows the template's band, and the save minted nothing of the
    // template (the presave saves what the page owns, an empty document,
    // never the template its render falls through to).
    $this->drupalGet('/node/add/page', ['query' => ['start' => 'template']]);
    $line = $assert_session->elementExists('css', '.daedalus-layout-start-line[data-daedalus-layout-start="template"]');
    $this->assertStringContainsString('Starting from the Page template.', $line->getText());
    $this->submitForm(['title[0][value]' => 'From Template'], 'Save');
    $node = $this->nodeTitled('From Template');
    $this->assertSame(1, $this->revisionCount($node));
    $this->assertSame([], $this->rawSections($node), 'Template leaves the field empty.');
    $this->assertSame($blocks_before, $this->blockIds(), 'Template saved no block.');
    $this->drupalGet($node->toUrl());
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Template band');

    // No page blueprint offered for the bundle, the setting still on: the
    // plain form.
    $this->loadBlueprint('landing')->delete();
    $this->loadBlueprint('notice')->delete();
    $this->drupalGet('/node/add/page');
    $assert_session->statusCodeEquals(200);
    $this->assertPlainForm();
    $this->drupalGet('/node/add/page', ['query' => ['start' => 'landing']]);
    $this->assertPlainForm();
    $assert_session->elementNotExists('css', '.daedalus-layout-start-line');
  }

  /**
   * A vocabulary: the setting, off until terms carry a layout; the chooser.
   */
  public function testChooserOnTermAddForm(): void {
    $assert_session = $this->assertSession();
    $this->drupalLogin($this->rootUser);
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();

    // Terms share no layout at all yet: the box is disabled with the reason.
    $this->drupalGet('/admin/structure/taxonomy/manage/topics');
    $control = $assert_session->elementExists('css', '[data-daedalus-ui-group="creation"] input[name="daedalus_layout_offer_starts"]');
    $this->assertTrue($control->hasAttribute('disabled'));
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="creation"]', 'Needs pages that carry a layout of their own.');
    $assert_session->elementTextNotContains('css', '[data-daedalus-ui-group="creation"]', 'Manage blueprints');

    // Layout Builder on without overrides: a locked bundle, whose terms
    // fill the template's slots. The box is live, its starts are fills of
    // those slots (the template places none, so nothing fits yet) or the
    // template, never blank.
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => 'topics',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder();
    $display->save();
    $this->drupalGet('/admin/structure/taxonomy/manage/topics');
    $this->assertFalse($assert_session->elementExists('css', 'input[name="daedalus_layout_offer_starts"]')->hasAttribute('disabled'));
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="creation"]', 'Before the form, the author picks how the page starts: a page blueprint of slot fills, or the template. No page blueprint fits Topics content yet.');

    // Each term its own layout: the box is live, counting the one page
    // blueprint that fits a term (the title field block fits nodes alone).
    $display->setOverridable()->save();
    $this->drupalGet('/admin/structure/taxonomy/manage/topics');
    $control = $assert_session->elementExists('css', 'input[name="daedalus_layout_offer_starts"]');
    $this->assertFalse($control->hasAttribute('disabled'));
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="creation"]', '1 page blueprint fits Topics content.');
    $this->submitForm(['daedalus_ui_enabled' => TRUE, 'daedalus_layout_offer_starts' => TRUE], 'Save');
    $this->assertTrue(Vocabulary::load('topics')->getThirdPartySetting('daedalus_layout', 'offer_starts'));

    // The term add form is the chooser, offering what fits a term, and a
    // start lands the term's document in its one save. The Template card
    // is offered: enabling Layout Builder on a display with components
    // gives it core's default section of field blocks (the term's name and
    // description), a document to follow.
    $blocks_before = $this->blockIds();
    $this->drupalGet('/admin/structure/taxonomy/manage/topics/add');
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldNotExists('name[0][value]');
    $this->assertSame(['template', 'blank', 'notice'], $this->cardIds());
    $assert_session->elementTextContains('css', 'a[data-daedalus-layout-start-card="template"]', 'The Topics template.');
    $this->assertSame(['' => 'All', 'bands' => 'Bands'], $this->filters(), 'Only the groups the offered starts carry are filters.');
    // The card's frame previews the start on a sample term, and the route
    // answers it with the blueprint's content.
    $frame = $assert_session->elementExists('css', 'a[data-daedalus-layout-start-card="notice"] iframe.daedalus-layout-start-card__frame');
    $this->assertStringEndsWith('/daedalus-layout/start-preview/taxonomy_term/topics/notice?navigationMode=preview', $frame->getAttribute('src'));
    $this->drupalGet('/daedalus-layout/start-preview/taxonomy_term/topics/notice', ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Notice band');
    $this->drupalGet('/admin/structure/taxonomy/manage/topics/add', ['query' => ['start' => 'notice']]);
    $assert_session->fieldExists('name[0][value]');
    $assert_session->elementExists('css', '.daedalus-layout-start-line[data-daedalus-layout-start="notice"]');
    $this->submitForm(['name[0][value]' => 'Started term'], 'Save');
    $assert_session->statusCodeEquals(200);
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => 'Started term']);
    $term = reset($terms);
    $this->assertInstanceOf(Term::class, $term);
    $sections = $this->rawSections($term);
    $this->assertSame(['inline_block:text'], $this->pluginIds($sections));
    $new_blocks = array_values(array_diff($this->blockIds(), $blocks_before));
    $this->assertCount(1, $new_blocks);
    $this->assertSame('Notice band', BlockContent::load($new_blocks[0])->get('field_text_text')->value);
  }

  /**
   * A favorite card precedes a non-favorite whose label sorts before it.
   *
   * The chooser shows the verb's order (PageStart::starts(): the site's
   * favorites first, then the rest, each run by label, natural and
   * case-insensitive) and still leads with the reserved cards (Blank
   * alone here: the Page template is blank). Notice, marked the site's
   * favorite, moves ahead of Landing, whose label sorts before it; a
   * lowercase "aside" sorts among the rest by its letters, not its case.
   * The group filters are untouched by the order.
   */
  public function testFavoritesFirst(): void {
    $assert_session = $this->assertSession();
    NodeType::load('page')->setThirdPartySetting('daedalus_layout', 'offer_starts', TRUE)->save();
    $this->loadBlueprint('notice')->setFavorite(TRUE)->save();
    $this->createPageBlueprint('aside', 'aside', 'A side note.', [], [
      ['kind' => 'block', 'block_type' => 'text', 'block_fields' => ['field_text_text' => ['value' => 'Aside text']]],
    ]);

    $this->loginAsEditor();
    $this->drupalGet('/node/add/page');
    $assert_session->statusCodeEquals(200);
    $assert_session->elementExists('css', '[data-daedalus-layout-start-chooser]');
    $this->assertSame(['blank', 'notice', 'aside', 'landing'], $this->cardIds());
    $this->assertSame(['' => 'All', 'heroes' => 'Heroes', 'bands' => 'Bands'], $this->filters());

    // Under a filter the order holds among the cards that remain.
    $this->drupalGet('/node/add/page', ['query' => ['group' => 'heroes']]);
    $this->assertSame(['blank', 'landing'], $this->cardIds());

    // The mark withdrawn: label order alone, the blueprint's save having
    // invalidated the roster.
    $this->loadBlueprint('notice')->setFavorite(FALSE)->save();
    $this->drupalGet('/node/add/page');
    $this->assertSame(['blank', 'aside', 'landing', 'notice'], $this->cardIds());
  }

  /**
   * Gives the Page template a document to follow: one band of text.
   *
   * The recipe's Page template is core's blank marker, one
   * layout_builder_blank section, and the blank judgement
   * (DaedalusLayoutAdapter::isBlankSectionList()) reads the FIRST section,
   * so the marker is removed before the band is appended: an append onto
   * the marker would leave the template blank. The band is a value block
   * (a text block stored by its values, the way a display holds inline
   * content), so rendering it saves no block.
   */
  protected function fillPageTemplate(): void {
    $section = new Section('daedalus_stylish_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', \Drupal::service('uuid')->generate());
    $section->appendComponent(new SectionComponent(\Drupal::service('uuid')->generate(), 'content', [
      'id' => 'inline_block:text',
      'label' => 'Template band',
      'label_display' => '0',
      'block_values' => ['field_text_text' => 'Template band'],
    ]));
    $display = LayoutBuilderEntityViewDisplay::load('node.page.default');
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $display);
    $display->removeAllSections();
    $display->appendSection($section)->save();
  }

  /**
   * Asserts the served document is a preview: no editor, the skin on.
   *
   * The preview-mode stamp, none of the chrome the editing page carries
   * (the selectors daedalus_ui's PopoutDocumentTest judges by), and the
   * skin's token scope, which a pop-out document would lack.
   */
  protected function assertChromeless(): void {
    $assert_session = $this->assertSession();
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="preview"]');
    $chrome = [
      '#admin-toolbar',
      '.admin-toolbar-control-bar',
      '#daedalus-ui-right-sidebar',
      '#edit-mode-top-bar',
      'script[src*="edit-mode-app.bundle.js"]',
    ];
    foreach ($chrome as $selector) {
      $assert_session->elementNotExists('css', $selector);
    }
    $assert_session->elementExists('css', '.daedalus-stylish-token-scope');
  }

  /**
   * The number of nodes, blocks and media items in storage.
   *
   * @return array<string, int>
   *   The counts, keyed by entity type ID.
   */
  protected function counts(): array {
    $counts = [];
    foreach (['node', 'block_content', 'media'] as $entity_type) {
      $counts[$entity_type] = (int) \Drupal::entityTypeManager()->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->count()->execute();
    }
    return $counts;
  }

  /**
   * Asserts the add form is the plain one: fields, Save, no chooser.
   */
  protected function assertPlainForm(): void {
    $assert_session = $this->assertSession();
    $assert_session->fieldExists('title[0][value]');
    $assert_session->buttonExists('Save');
    $assert_session->elementNotExists('css', '[data-daedalus-layout-start-chooser]');
    $assert_session->elementNotExists('css', '.daedalus-layout-start-line');
  }

  /**
   * The ids of the chooser's cards, in page order.
   *
   * @return string[]
   *   The start ids.
   */
  protected function cardIds(): array {
    $cards = $this->getSession()->getPage()->findAll('css', '.daedalus-layout-start-cards a[data-daedalus-layout-start-card]');
    return array_map(static fn ($card) => (string) $card->getAttribute('data-daedalus-layout-start-card'), $cards);
  }

  /**
   * The chooser's group filters, group id to label, in page order.
   *
   * @return array<string, string>
   *   The filters; the "All" filter's id is ''.
   */
  protected function filters(): array {
    $filters = [];
    foreach ($this->getSession()->getPage()->findAll('css', '.daedalus-layout-start-filters a[data-daedalus-layout-start-group]') as $link) {
      $filters[(string) $link->getAttribute('data-daedalus-layout-start-group')] = trim($link->getText());
    }
    return $filters;
  }

  /**
   * Writes a page blueprint for Page content holding one root container.
   *
   * @param string $machine_name
   *   The blueprint id.
   * @param string $label
   *   The label.
   * @param string $description
   *   The description.
   * @param string[] $groups
   *   The group ids.
   * @param array $children
   *   The block nodes of the one root container.
   */
  protected function createPageBlueprint(string $machine_name, string $label, string $description, array $groups, array $children): Blueprint {
    return $this->createBlueprint($machine_name, $label, 'page', $groups, $children, $description);
  }

  /**
   * Writes a blueprint whose document is one root container.
   *
   * Written the way a capture writes one (the whole-document copy on the
   * blueprint's own host as a system batch), then committed.
   */
  protected function createBlueprint(string $machine_name, string $label, string $scope, array $groups, array $children, string $description = ''): Blueprint {
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => $description,
      'scope' => $scope,
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
      'groups' => $groups,
    ]);
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($blueprint);
    $result = \Drupal::service('daedalus_blueprint.document_instantiator')->instantiateDocument($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [['kind' => 'region', 'region' => 'content', 'children' => $children]],
      ],
    ], [], [
      'indexed' => TRUE,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]);
    $this->assertTrue($result->isSuccess(), implode("\n", array_map(static fn ($error) => $error->getPath() . ': ' . $error->getMessage(), $result->getErrors())));
    \Drupal::service('daedalus_blueprint.storage_handler')->commit($host);
    return $blueprint;
  }

  /**
   * A blueprint by machine name, uncached.
   */
  protected function loadBlueprint(string $machine_name): Blueprint {
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $storage->resetCache();
    $blueprints = $storage->loadByProperties(['machine_name' => $machine_name]);
    $blueprint = reset($blueprints);
    $this->assertInstanceOf(Blueprint::class, $blueprint);
    return $blueprint;
  }

  /**
   * The node with a title, loaded fresh after the browser saved it.
   */
  protected function nodeTitled(string $title): Node {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache();
    $nodes = $storage->loadByProperties(['title' => $title]);
    $this->assertCount(1, $nodes, "One node titled $title.");
    $node = reset($nodes);
    $this->assertInstanceOf(Node::class, $node);
    return $node;
  }

  /**
   * The number of revisions a node has.
   */
  protected function revisionCount(Node $node): int {
    return (int) \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->allRevisions()
      ->condition('nid', $node->id())
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * The raw section list of an entity's override field, as stored.
   *
   * Read off the field, not through the host, so no door seeds it: the
   * blank marker shows here, which the host's reads hide.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections.
   */
  protected function rawSections(FieldableEntityInterface $entity): array {
    $list = $entity->get(ContentHost::LAYOUT_FIELD);
    $this->assertInstanceOf(SectionListInterface::class, $list);
    return $list->getSections();
  }

  /**
   * The host of an entity.
   */
  protected function host(FieldableEntityInterface $entity) {
    return \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($entity);
  }

  /**
   * The block plugin ids placed in a list of sections, in document order.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections.
   *
   * @return string[]
   *   The plugin ids.
   */
  protected function pluginIds(array $sections): array {
    $ids = [];
    foreach ($sections as $section) {
      $this->assertInstanceOf(Section::class, $section);
      foreach ($section->getComponents() as $component) {
        $ids[] = $component->getPluginId();
      }
    }
    return $ids;
  }

  /**
   * The ids of every block_content entity in storage, sorted.
   *
   * @return int[]
   *   The ids.
   */
  protected function blockIds(): array {
    $ids = array_map('intval', array_values(\Drupal::entityTypeManager()->getStorage('block_content')->getQuery()->accessCheck(FALSE)->execute()));
    sort($ids);
    return $ids;
  }

}
