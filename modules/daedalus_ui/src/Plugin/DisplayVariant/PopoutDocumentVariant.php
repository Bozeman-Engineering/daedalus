<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\DisplayVariant;

use Drupal\Core\Display\Attribute\PageDisplayVariant;
use Drupal\Core\Render\Plugin\DisplayVariant\SimplePageVariant;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The pop-out document: status messages and the main content, no title.
 *
 * A pop-out (see Chromeless) is a panel or a preview surface the editing
 * page opens beside itself, not a page: it renders its own heading (the
 * stylish inspector's panel header, the responsive preview's shell) and the
 * route's _title belongs to the window's <title> only. core's simple_page
 * already skips block placement but still prints the route title as an
 * h1.page-title, a second heading in every pop-out; this variant is
 * simple_page without that title. PopoutPageVariant selects it for every
 * route that declares the pop-out option.
 *
 * The main content renders inside the pop-out's mount
 * (.daedalus-ui-popout__mount): the one marker the pop-out document
 * stylesheet (css/popout.css) anchors "every wrapper between the body and
 * the tool" on, so neutralizing the site theme's page tree around a pop-out
 * needs no theme's wrapper names and no pop-out's root selector.
 */
#[PageDisplayVariant(
  id: 'daedalus_ui_popout',
  admin_label: new TranslatableMarkup('Pop-out document'),
)]
final class PopoutDocumentVariant extends SimplePageVariant {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = parent::build();
    unset($build['content']['page_title']);
    $build['content']['main_content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['daedalus-ui-popout__mount']],
      '#weight' => -800,
      'content' => $build['content']['main_content'],
    ];
    return $build;
  }

}
