<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Asset;

use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * Decorates ajax_response.attachments_processor to deliver plain links.
 *
 * The add_css command only carries attribute maps (the client rebuilds each
 * entry as a <link>), so the renderer decorator's <style>@import ... layer()>
 * rewrite cannot ride an AJAX response: core drops the attribute-less
 * elements from the command, losing the stylesheets entirely, and a response
 * whose new CSS is all layered yields an add_css command with EMPTY data,
 * which never resolves client-side and hangs the whole command queue behind
 * it (loadjs.ready([]) never fires). Suppressing the rewrite while the inner
 * processor renders the collection makes AJAX-delivered stylesheets load as
 * core's plain links, unlayered until the next full page render, which is
 * the closest the add_css contract allows. Aggregated responses are
 * unaffected: aggregates carry the layer inside the file and always render
 * as links.
 *
 * @see \Drupal\daedalus_stylish_layers\Layers::withLinkRewriteSuppressed()
 * @see \Drupal\Core\Ajax\AjaxResponseAttachmentsProcessor::buildAttachmentsCommands()
 */
final class UnlayeredAjaxAttachmentsProcessor implements AttachmentsResponseProcessorInterface {

  public function __construct(
    private readonly AttachmentsResponseProcessorInterface $inner,
    private readonly Layers $layers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processAttachments(AttachmentsInterface $response) {
    return $this->layers->withLinkRewriteSuppressed(fn () => $this->inner->processAttachments($response));
  }

}
