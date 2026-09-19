<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that AJAX responses deliver stamped CSS as plain links.
 *
 * The add_css command only carries attribute maps (the client rebuilds each
 * entry as a <link>), so the renderer decorator's <style>@import rewrite
 * cannot ride an AJAX response: core silently drops attribute-less elements
 * from the command, and a response whose new CSS is ALL layered produces an
 * add_css command with empty data, which never resolves client-side
 * (loadjs.ready([]) never fires) and hangs every command queued behind it,
 * including daedalus_blueprint_api's markup swaps. The AJAX attachments
 * processor decorator therefore suppresses the rewrite while attachments are
 * processed.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AjaxUnlayeredDeliveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_stylish_layers',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['daedalus_stylish_layers']);
  }

  /**
   * Stamped, unaggregated CSS rides the add_css command as link attributes.
   *
   * Without the suppression every stamped asset renders as a <style> element,
   * which the command builder drops, leaving add_css with empty data: the
   * stylesheets never load and the client's command queue hangs.
   */
  public function testAjaxAttachmentsDeliverPlainLinks(): void {
    $response = new AjaxResponse();
    $response->setAttachments([
      'library' => ['system/base'],
      'drupalSettings' => [],
    ]);
    $this->container->get('ajax_response.attachments_processor')->processAttachments($response);

    $add_css = array_values(array_filter(
      $response->getCommands(),
      fn (array $command): bool => $command['command'] === 'add_css',
    ));
    $this->assertNotEmpty($add_css, 'The response carries an add_css command for the attached library.');
    $this->assertNotEmpty($add_css[0]['data'], 'The add_css command carries assets; an empty command never resolves client-side.');
    foreach ($add_css[0]['data'] as $style) {
      $this->assertArrayHasKey('href', $style, 'Each delivered asset is an attribute-bearing link.');
    }
  }

  /**
   * The suppression is scoped: page renders keep the layered rewrite.
   */
  public function testSuppressionScopesToTheCallable(): void {
    $renderer = $this->container->get('asset.css.collection_renderer');
    $layers = $this->container->get('daedalus_stylish_layers.layers');
    $asset = [
      'type' => 'file',
      'data' => 'core/misc/dialog/off-canvas/css/reset.css',
      'media' => 'all',
      'preprocess' => FALSE,
      'license' => FALSE,
      'daedalus_stylish_layers' => 'site',
    ];

    $inside = $layers->withLinkRewriteSuppressed(fn () => $renderer->render([$asset]));
    $this->assertSame('link', $inside[0]['#tag'], 'Inside the suppression a stamped asset renders as a plain link.');

    $outside = $renderer->render([$asset]);
    $this->assertSame('style', $outside[0]['#tag'], 'Outside the suppression the layered rewrite still applies.');
  }

}
