<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Drupal\Core\Url;
use Drupal\daedalus_ui\PathProcessor\DaedalusUiOutboundPathProcessor;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that the outbound processor consults the router only for its own route.
 *
 * The processor runs for every outbound URL. Matching each one against the
 * router costs a full route match with param conversion per generated URL,
 * and the daedalus_tempstore entity converter that conversion runs compares
 * entities through computed fields; a computed field that generates a URL
 * (metatag's [node:url]) re-entered this processor and the render recursed
 * until PHP ran out of memory. So the router must be untouched for any path
 * outside the load-editable-page prefix.
 *
 * @group daedalus_ui
 */
#[Group('daedalus_ui')]
final class DaedalusUiOutboundPathProcessorTest extends UnitTestCase {

  /**
   * Installs a container whose path validator and router are the given mocks.
   */
  private function installContainer(PathValidatorInterface $validator, RequestMatcherInterface $router): void {
    $container = new ContainerBuilder();
    $container->set('path.validator', $validator);
    $container->set('router', $router);
    \Drupal::setContainer($container);
  }

  /**
   * A path outside the prefix never reaches the path validator or the router.
   */
  public function testOrdinaryPathBypassesTheRouter(): void {
    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->expects($this->never())->method('getUrlIfValidWithoutAccessCheck');
    $router = $this->createMock(RequestMatcherInterface::class);
    $router->expects($this->never())->method('matchRequest');
    $this->installContainer($validator, $router);

    $processor = new DaedalusUiOutboundPathProcessor();
    $options = ['query' => ['navigationMode' => 'edit']];
    $this->assertSame('/node/1', $processor->processOutbound('/node/1', $options));
    $this->assertSame(['query' => ['navigationMode' => 'edit']], $options, 'Options outside the prefix are left alone.');
  }

  /**
   * A load-editable-page path is rewritten to the entity's own URL.
   */
  public function testLoadEditablePagePathBecomesTheEntityUrl(): void {
    $path = DaedalusUiOutboundPathProcessor::LOAD_EDITABLE_PAGE_PREFIX . 'node/1/full';

    $validator = $this->createMock(PathValidatorInterface::class);
    $validator->expects($this->once())
      ->method('getUrlIfValidWithoutAccessCheck')
      ->with(ltrim($path, '/'))
      ->willReturn(Url::fromRoute('daedalus_ui.load_editable_page', [
        'entity_type' => 'node',
        'entity' => 1,
        'view_mode' => 'full',
      ]));

    $entity_url = $this->createMock(Url::class);
    $entity_url->method('toString')->willReturn('/node/1');
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('toUrl')->willReturn($entity_url);

    $router = $this->createMock(RequestMatcherInterface::class);
    $router->expects($this->once())
      ->method('matchRequest')
      ->with($this->callback(fn (Request $request) => $request->getPathInfo() === $path))
      ->willReturn(['entity' => $entity]);
    $this->installContainer($validator, $router);

    $processor = new DaedalusUiOutboundPathProcessor();
    $options = ['query' => ['navigationMode' => 'edit', '_wrapper_format' => 'drupal_ajax']];
    $this->assertSame('/node/1', $processor->processOutbound($path, $options));
    $this->assertSame(['query' => []], $options, 'The mode and the ajax wrapper are dropped from the rewritten URL.');
  }

}
