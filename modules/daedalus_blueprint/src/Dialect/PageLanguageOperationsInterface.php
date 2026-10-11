<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\OperationHandler\OperationHandlerInterface;

/**
 * An operation handler whose ops belong to the page language's op set.
 *
 * The page language is the ONE grammar the AI surface speaks for pages:
 * page_edit admits exactly the ops the registered handlers declare here
 * (the DOM vocabulary plus the gestures whose only home is a page, such as
 * capturing a blueprint), and the cached catalog teaches the same set. A
 * handler declaring an op here takes refs the page language spells (a
 * printed uuid or a live data-alias) wherever it names an element, the
 * alias map being the spine's to resolve.
 */
interface PageLanguageOperationsInterface extends OperationHandlerInterface {

  /**
   * The subset of this handler's ops that page_edit admits.
   *
   * @return string[]
   *   Operation names.
   */
  public function getPageLanguageOperations(): array;

}
