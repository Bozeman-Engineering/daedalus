<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Render;

/**
 * A headless render failed or is unavailable.
 *
 * The message is written to be shown to the model verbatim: it names what
 * went wrong and, where there is one, the remediation (configuration, edit
 * mode access), so the model can explain the situation instead of retrying
 * blindly.
 */
class HeadlessRenderException extends \RuntimeException {

}
