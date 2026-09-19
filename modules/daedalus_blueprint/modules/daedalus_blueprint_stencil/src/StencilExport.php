<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

/**
 * The result of exporting a placed element as a stencil.
 *
 * The definition is the full stencil file content (label, description,
 * element) in the grammar StencilDiscovery validates and the
 * instantiate_stencil operation consumes. Warnings name styling the file
 * grammar cannot express (dropped, never silently): the export still stands,
 * but the author should know the stamped copy will differ there. The
 * variants a shipped stencil needs beside it are the stencil shipper's
 * roster, not the export's.
 */
final class StencilExport {

  /**
   * Constructs a StencilExport.
   *
   * @param array $definition
   *   The stencil definition: label, description, and element.
   * @param string[] $warnings
   *   Human-readable warnings about styling the grammar cannot carry.
   */
  public function __construct(
    public readonly array $definition,
    public readonly array $warnings,
  ) {}

}
