<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Pins that every route the project defines is one of three kinds.
 *
 * A route is a read (it takes GET only), a Drupal form (core's form token
 * guards its submission), or a write that takes the session's header token
 * (`_csrf_request_header_token`) and names the methods it accepts. There is
 * no list of exempt routes: a new route of any other shape fails here, and
 * the fix is to give it one of the three.
 *
 * The roster is read from the source, not from a built router, so it covers
 * every sub-module and test module at once, including the ones a kernel
 * test cannot enable: every `*.routing.yml` under the project, plus the
 * routes each route subscriber adds to an empty collection.
 *
 * @group daedalus
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
class RouteGuardRosterTest extends KernelTestBase {

  /**
   * The methods core's header token check lets through unjudged.
   *
   * @see \Drupal\Core\Access\CsrfRequestHeaderAccessCheck::access()
   */
  private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

  /**
   * The option by which a form served by a controller names its kind.
   *
   * Core spells a form route `_form` or `_entity_form`. A form whose object
   * is only known per request (the entity type is a path parameter, the
   * block is a placement) is built by a controller through the form builder
   * and carries this option instead.
   */
  private const FORM_HOST_OPTION = '_daedalus_edit.form_host';

  /**
   * Every route in the project is a read, a form or a tokened write.
   */
  public function testEveryRouteIsGuarded(): void {
    $roster = $this->roster();

    // The two sources both answered: a routing file's route and a route
    // only a subscriber adds.
    $this->assertArrayHasKey('daedalus_edit.tempstore.save', $roster);
    $this->assertArrayHasKey('daedalus_edit.block_form', $roster);

    $refused = [];
    $kinds = [];
    foreach ($roster as $name => $route) {
      $refusal = self::refusal($route);
      if ($refusal !== NULL) {
        $refused[] = sprintf('%s (%s): %s', $name, $route->getPath(), $refusal);
        continue;
      }
      $kinds[self::kind($route)] = TRUE;
    }
    $this->assertSame([], $refused, "Every route is GET-only, a Drupal form, or takes the session's header token.");
    // All three kinds are present, so no branch of the rule went unjudged.
    ksort($kinds);
    $this->assertSame(['form', 'read', 'tokened'], array_keys($kinds));
  }

  /**
   * The rule accepts the three kinds and refuses every other shape.
   */
  #[DataProvider('shapes')]
  public function testTheRule(array $definition, bool $accepted): void {
    $route = self::route($definition);
    $this->assertSame($accepted, self::refusal($route) === NULL, (string) self::refusal($route));
  }

  /**
   * Route shapes and whether the rule accepts them.
   */
  public static function shapes(): array {
    $build = ['_controller' => '\Drupal\example\Controller\Example::build'];
    $token = ['_csrf_request_header_token' => 'TRUE'];
    $off = ['_csrf_request_header_token' => 'FALSE'];
    $host = [self::FORM_HOST_OPTION => TRUE];
    return [
      'a read' => [['defaults' => $build, 'methods' => ['GET']], TRUE],
      'a read that also answers HEAD' => [['defaults' => $build, 'methods' => ['GET', 'HEAD']], TRUE],
      'a form' => [['defaults' => ['_form' => '\Drupal\example\Form\Example']], TRUE],
      'an entity form' => [['defaults' => ['_entity_form' => 'example.edit']], TRUE],
      'a form served by a controller' => [['defaults' => $build, 'options' => $host], TRUE],
      'a tokened POST' => [['defaults' => $build, 'requirements' => $token, 'methods' => ['POST']], TRUE],
      'a tokened DELETE' => [['defaults' => $build, 'requirements' => $token, 'methods' => ['DELETE']], TRUE],
      'a controller on any method' => [['defaults' => $build], FALSE],
      'a POST with no token' => [['defaults' => $build, 'methods' => ['POST']], FALSE],
      'a read that also takes POST' => [['defaults' => $build, 'methods' => ['GET', 'POST']], FALSE],
      'a token on any method' => [['defaults' => $build, 'requirements' => $token], FALSE],
      'a token beside GET' => [['defaults' => $build, 'requirements' => $token, 'methods' => ['GET', 'POST']], FALSE],
      'a token switched off' => [['defaults' => $build, 'requirements' => $off, 'methods' => ['POST']], FALSE],
    ];
  }

  /**
   * Names a route's kind, or NULL when it is none of the three.
   */
  private static function kind(Route $route): ?string {
    $methods = $route->getMethods();
    if ($methods && !array_diff($methods, ['GET', 'HEAD'])) {
      return 'read';
    }
    if ($route->hasDefault('_form') || $route->hasDefault('_entity_form') || $route->getOption(self::FORM_HOST_OPTION) === TRUE) {
      return 'form';
    }
    if ($route->getRequirement('_csrf_request_header_token') === 'TRUE') {
      return 'tokened';
    }
    return NULL;
  }

  /**
   * Says why a route is refused, or NULL when it is one of the three kinds.
   */
  private static function refusal(Route $route): ?string {
    $methods = $route->getMethods();
    switch (self::kind($route)) {
      case 'read':
      case 'form':
        return NULL;

      case 'tokened':
        // Core judges the token on unsafe methods only, so a tokened route
        // that also answers a safe one runs its controller unjudged there.
        if (!$methods) {
          return 'takes the header token but accepts any method; name the methods it takes';
        }
        if (array_intersect($methods, self::SAFE_METHODS)) {
          return 'takes the header token but also answers ' . implode(', ', array_intersect($methods, self::SAFE_METHODS)) . ', which the token check does not judge';
        }
        return NULL;
    }
    return sprintf('accepts %s and is neither a Drupal form nor a route that takes the header token', $methods ? implode(', ', $methods) : 'any method');
  }

  /**
   * Collects every route the project defines, keyed by route name.
   *
   * @return \Symfony\Component\Routing\Route[]
   *   The routes of every routing file and every route subscriber.
   */
  private function roster(): array {
    $project = dirname(__DIR__, 3);
    $roster = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
      new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS),
      static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), ['node_modules', 'dist', 'vendor'], TRUE),
    ));
    foreach ($files as $file) {
      $path = $file->getPathname();
      if (str_ends_with($path, '.routing.yml')) {
        foreach (Yaml::decode(file_get_contents($path)) ?? [] as $name => $definition) {
          // A callback's routes exist only once it runs; none is judged
          // here, so one appearing must be added to the roster first.
          $this->assertNotSame('route_callbacks', $name, "$path declares route callbacks, whose routes this roster does not read yet.");
          $this->assertArrayNotHasKey($name, $roster, "$name is defined twice.");
          $roster[$name] = self::route($definition);
        }
      }
      elseif (str_ends_with($path, '.php') && str_contains($path, '/src/') && !str_contains($path, '/tests/')) {
        $source = file_get_contents($path);
        if (!str_contains($source, 'extends RouteSubscriberBase')) {
          continue;
        }
        $this->assertSame(1, preg_match('/^namespace ([^;]+);/m', $source, $namespace), $path);
        $class = $namespace[1] . '\\' . $file->getBasename('.php');
        $this->assertTrue(is_subclass_of($class, RouteSubscriberBase::class), "$class is a route subscriber.");
        // A subscriber is run on an empty collection: what it adds is the
        // project's own, what it alters belongs to another project.
        $this->assertSame(0, (new \ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0, "$class needs collaborators this roster does not supply yet.");
        $collection = new RouteCollection();
        (new $class())->onAlterRoutes(new RouteBuildEvent($collection));
        foreach ($collection->all() as $name => $route) {
          $this->assertArrayNotHasKey($name, $roster, "$name is defined twice.");
          $roster[$name] = $route;
        }
      }
    }
    return $roster;
  }

  /**
   * Builds a route from a routing file's definition, as core's builder does.
   *
   * @see \Drupal\Core\Routing\RouteBuilder::rebuild()
   */
  private static function route(array $definition): Route {
    return new Route(
      $definition['path'] ?? '/example',
      $definition['defaults'] ?? [],
      $definition['requirements'] ?? [],
      $definition['options'] ?? [],
      $definition['host'] ?? '',
      $definition['schemes'] ?? [],
      $definition['methods'] ?? [],
      $definition['condition'] ?? '',
    );
  }

}
