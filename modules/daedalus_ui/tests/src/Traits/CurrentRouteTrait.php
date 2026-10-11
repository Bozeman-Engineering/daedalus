<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Traits;

use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Makes a route the current one in a kernel test.
 *
 * The UI derives its host from the current route match (DaedalusUi::
 * deriveHostFromRoute), and a mode or sidebar plugin's applies() reads
 * that host, so a test that asks a plugin whether it applies seats the
 * route it would be asked on: the upcast parameters, the mode cookie,
 * the admin flag. The derived host is cleared with every seat, since the
 * UI memoizes it per request.
 */
trait CurrentRouteTrait {

  /**
   * Makes a route the current one, with its parameters and the mode cookie.
   *
   * @param string $name
   *   The route name.
   * @param array $parameters
   *   The upcast route parameters.
   * @param string|null $mode
   *   The navigationMode cookie, or NULL for none.
   * @param bool $admin
   *   Whether the route is an admin route.
   */
  protected function setCurrentRoute(string $name, array $parameters, ?string $mode = NULL, bool $admin = FALSE): void {
    $request_stack = $this->container->get('request_stack');
    $request = Request::create('/route-host-test', 'GET', [], $mode === NULL ? [] : ['navigationMode' => $mode]);
    $request->setSession($request_stack->getSession());
    // A route match keeps only the parameters its route declares.
    $route = $this->route(array_keys($parameters));
    if ($admin) {
      $route->setOption('_admin_route', TRUE);
    }
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    foreach ($parameters as $key => $value) {
      $request->attributes->set($key, $value);
    }
    $request_stack->push($request);
    $this->container->get('daedalus_ui.ui')->clearDerivedHost();
  }

  /**
   * A route declaring the given parameter names.
   *
   * @param string[] $names
   *   The parameter names.
   */
  protected function route(array $names): Route {
    return new Route('/route-host-test' . ($names === [] ? '' : '/{' . implode('}/{', $names) . '}'));
  }

}
