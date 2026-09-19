<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Render;

use Drupal\ai\OperationType\GenericType\ImageFile;

/**
 * Images a tool hands back to the model alongside its text output.
 *
 * This is the tool half of ai_agents' duck-typed image contract: after
 * executing tools, AiAgentEntityWrapper probes each tool for
 * getPluginInstance() and getResult(), then reads the result's
 * getContextValues()['_images'] and delivers every ImageFile to the model
 * as a user message on the next loop iteration (images cannot ride
 * tool-role messages). A tool that wants the model to SEE something
 * implements those two methods and returns one of these.
 *
 * @see \Drupal\ai_agents\PluginBase\AiAgentEntityWrapper::determineSolvability()
 */
final class ToolImagesResult {

  /**
   * Constructs the result.
   *
   * @param \Drupal\ai\OperationType\GenericType\ImageFile[] $images
   *   The images to deliver to the model.
   */
  public function __construct(
    private readonly array $images,
  ) {
    foreach ($this->images as $image) {
      assert($image instanceof ImageFile);
    }
  }

  /**
   * The context values the ai_agents wrapper reads images from.
   *
   * @return array{_images: \Drupal\ai\OperationType\GenericType\ImageFile[]}
   *   The '_images' list.
   */
  public function getContextValues(): array {
    return ['_images' => $this->images];
  }

}
