<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface;

/**
 * An operation handler whose ops only make sense on a page.
 *
 * A page-only op targets the layout tree (or the styles worn by its
 * elements), so on a site where the page language speaks for pages (the
 * catalog declares a container layout) the op has no JSON-door work left:
 * eb_edit refuses page entities outright, and the page language spells
 * the gesture its own way (variant wear is set's class attribute, styles
 * are CSS, a stencil stamps as <pb-stencil>). Declaring an op here takes
 * it off eb_edit's always-on roll-call on such a site, so the cached
 * surface never teaches a second grammar for a page gesture.
 *
 * Ops declared through PageLanguageOperationsInterface are page-only by
 * definition (page_edit is their surface); implement THIS interface only
 * for page ops the page language superseded rather than admitted.
 */
interface PageOnlyOperationsInterface extends OperationHandlerInterface {

  /**
   * The subset of this handler's ops that only apply to pages.
   *
   * @return string[]
   *   Operation names.
   */
  public function getPageOnlyOperations(): array;

}
