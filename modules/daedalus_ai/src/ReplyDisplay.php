<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\ai\Service\HostnameFilter;
use Drupal\ai_chatbot\Controller\DeepChatApi;
use Drupal\Component\Utility\Xss;
use League\CommonMark\CommonMarkConverter;

/**
 * What a chat message passes before the browser renders it as HTML.
 *
 * The one definition for both moments a message is shown: a live reply
 * (the chat endpoint converts and tag-filters it, then the response
 * listener asks boundary() here) and the history the panel replays on a
 * page load (html() here, the same three steps in the same order). The
 * chat widget renders the `html` of a message as markup, so neither moment
 * may hand it text that did not pass this class.
 */
class ReplyDisplay {

  public function __construct(
    protected CommonMarkConverter $converter,
    protected HostnameFilter $hostnameFilter,
  ) {}

  /**
   * The display boundary for html the chat endpoint already tag-filtered.
   *
   * The page-builder agent runs with transport hostname filtering disabled
   * (so tool arguments reach the op spine byte-intact), and the link
   * protection that filtering gave lands here instead, with
   * settings-derived defaults (ai.settings allowed_hosts).
   *
   * @param string $html
   *   A reply's html, converted and tag-filtered by the chat endpoint.
   *
   * @return string
   *   The html with links and images on disallowed hostnames removed.
   */
  public function boundary(string $html): string {
    return $this->hostnameFilter->filterText($html);
  }

  /**
   * The html of a stored message, as a live reply would have shown it.
   *
   * The chat endpoint's own steps (the ai module's converter service and
   * its allowed tag list, read from the endpoint's class so the list is
   * never restated), then the boundary.
   *
   * @param string $message
   *   A stored message's text: markdown, by either party.
   *
   * @return string
   *   The html the chat widget may render.
   */
  public function html(string $message): string {
    $html = $this->converter->convert($message)->getContent();
    return $this->boundary(Xss::filter($html, DeepChatApi::ALLOWED_TAGS));
  }

}
