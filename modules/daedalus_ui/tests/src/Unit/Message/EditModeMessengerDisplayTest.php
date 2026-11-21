<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit\Message;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Markup;
use Drupal\daedalus_ui\Message\EditModeMessenger;
use Drupal\daedalus_ui\ModeState;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A stored message's two forms: its text and its display markup.
 *
 * @group daedalus_ui
 * @coversDefaultClass \Drupal\daedalus_ui\Message\EditModeMessenger
 */
class EditModeMessengerDisplayTest extends UnitTestCase {

  /**
   * A markup message keeps its link and emphasis and nothing else.
   *
   * @covers ::html
   * @covers ::text
   */
  public function testMarkupKeepsLinksAndEmphasisOnly(): void {
    $message = Markup::create('Page <em class="placeholder">About &amp; us</em> saved. <a href="/node/1">View</a><img src="x" onerror="go()"><script>go()</script>');

    $this->assertSame(
      'Page <em class="placeholder">About &amp; us</em> saved. <a href="/node/1">View</a>go()',
      EditModeMessenger::html($message),
    );
    $this->assertSame('Page About & us saved. Viewgo()', EditModeMessenger::text($message));
  }

  /**
   * A kept tag loses what could run: handlers, styles, script protocols.
   *
   * @covers ::html
   */
  public function testKeptTagsCarryNothingThatRuns(): void {
    $html = EditModeMessenger::html(Markup::create('<a href="javascript:go()" onclick="go()" style="position:fixed">here</a>'));

    $this->assertStringNotContainsString('javascript', $html);
    $this->assertStringNotContainsString('onclick', $html);
    $this->assertStringNotContainsString('style', $html);
    $this->assertStringContainsString('>here</a>', $html);
  }

  /**
   * A plain string and tagless markup have no display markup.
   *
   * @covers ::html
   */
  public function testTextOnlyMessagesHaveNoMarkup(): void {
    $this->assertNull(EditModeMessenger::html('<a href="/x">typed by someone</a>'));
    $this->assertNull(EditModeMessenger::html(Markup::create('Saved &amp; done.')));
    $this->assertNull(EditModeMessenger::html(Markup::create('<div><img src="x"></div>Saved.')));
  }

  /**
   * The relay answers both forms for every stored message.
   *
   * @covers ::getStoredMessages
   */
  public function testStoredMessagesCarryBothForms(): void {
    $inner = $this->createMock(MessengerInterface::class);
    $inner->method('all')->willReturn([
      'status' => [Markup::create('Saved. <a href="/node/1">View</a>'), 'A <b>plain</b> string'],
    ]);
    $messenger = new EditModeMessenger($inner, new ModeState(new RequestStack()));

    $this->assertSame([
      'status' => [
        ['text' => 'Saved. View', 'html' => 'Saved. <a href="/node/1">View</a>'],
        ['text' => 'A <b>plain</b> string', 'html' => NULL],
      ],
    ], $messenger->getStoredMessages());
  }

}
