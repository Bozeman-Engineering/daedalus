<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Symfony\Component\HttpFoundation\Request;

/**
 * Says whether the request is inside a mutation response's render.
 *
 * A mutation response renders the whole entity server-side to cut the
 * swapped subtrees out of it (SubtreeRenderer). Hooks and event subscribers
 * that ride an entity render cannot otherwise tell that render from a page
 * load, so a boot-only delivery (the drupalSettings blueprint envelope
 * daedalus_ui attaches in edit mode) would be rebuilt on every mutation
 * and ride the response's asset commands beside the body's own blueprint.
 * This flag is what such a delivery reads to stand down.
 *
 * The flag is a request attribute, not a service: it is request state by
 * nature, and a reader needs only the current request and this class's
 * grammar, never a container wiring to daedalus_blueprint_api. It is raised
 * only for the span of SubtreeRenderer::render() and restored in a finally,
 * so a render that throws (MutationResponseBuilder degrades a render failure
 * to a warning) cannot leave a later render of the same request marked.
 */
final class MutationRenderScope {

  /**
   * The request attribute carrying the flag.
   */
  public const ATTRIBUTE = '_daedalus_blueprint_mutation_render';

  /**
   * Whether the request is inside a mutation render right now.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request; NULL (no request on the stack) is never inside.
   */
  public static function isActive(?Request $request): bool {
    return $request?->attributes->get(self::ATTRIBUTE) === TRUE;
  }

  /**
   * Runs a render inside the scope.
   *
   * The previous state is restored whether the render returns or throws;
   * nested calls keep the flag raised until the outermost one leaves.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request to mark; NULL runs the render unmarked.
   * @param callable $render
   *   The render to run.
   *
   * @return mixed
   *   The render's return value.
   */
  public static function during(?Request $request, callable $render): mixed {
    if ($request === NULL) {
      return $render();
    }
    $previous = $request->attributes->get(self::ATTRIBUTE);
    $request->attributes->set(self::ATTRIBUTE, TRUE);
    try {
      return $render();
    }
    finally {
      if ($previous === NULL) {
        $request->attributes->remove(self::ATTRIBUTE);
      }
      else {
        $request->attributes->set(self::ATTRIBUTE, $previous);
      }
    }
  }

}
