<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Exception;

/**
 * The style guide failed build-time validation.
 *
 * Thrown while building the layered guide when a declaration is wrong in a
 * way that would otherwise only surface later as bounced runtime writes or a
 * dead inspector control — e.g. a controls: entry naming a widget that does
 * not exist, binding an undeclared part, or binding a part whose property
 * groups cannot cover the widget's owned properties. The guide is code that
 * ships next to templates, so a broken declaration is a developer error and
 * failing the build loudly is the feature.
 *
 * The message lists every problem found in the build, one per line.
 */
class StyleGuideException extends \RuntimeException {}
