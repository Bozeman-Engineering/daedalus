<?php

/**
 * @file
 * Hooks provided by the Daedalus AI module.
 */

/**
 * The current page is a host.
 *
 * @section current_host Pages, templates and saved blueprints, one resolver
 *
 * Everything this module tells the assistant about the page on screen is
 * read through the page document's host
 * (\Drupal\daedalus_blueprint\Host\HostInterface), resolved once from the
 * route by \Drupal\daedalus_ai\RouteEntityResolver and worded by its
 * describe(): the host CLASS decides the wording (a DisplayHost is "the
 * default template for page nodes", a BlueprintHost is 'the block
 * blueprint "Card" for page nodes', read from the host's scope, label
 * and display target; a page is neither), never which fields the host
 * answers, since a saved blueprint answers its entity's fields like a page
 * and is still not one. The current-page context line, the selected
 * element context and the Context Tags protocol carry that sentence, and
 * the page tools in daedalus_blueprint_ai address the same host as
 * `entity_type: blueprint` by id. This module depends on neither
 * daedalus_blueprint_library nor its classes: the scope, subject and label
 * it needs are the spine's BlueprintHost answers.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the deep-chat style parameters and shadow-DOM CSS for the chat panel.
 *
 * The always-on chat sidebar (ChatSidebar) derives its visual styling from
 * ai_chatbot's toolbar.yml, narrows the spacing for the slim sidebar, and
 * builds a small block of CSS injected into deep-chat's shadow DOM. This hook
 * runs after those defaults are assembled but before they are JSON-encoded
 * onto the <deep-chat> element, so implementations work with the structured
 * style array and a plain CSS string rather than encoded attribute values.
 *
 * Use it to apply site or theme branding (colors, fonts, message bubbles)
 * without patching contrib. CSS custom properties defined on the host document
 * inherit into deep-chat's shadow DOM, so referencing theme variables (e.g.
 * var(--my-brand-color)) from $auxiliary_style is the recommended approach.
 *
 * @param array &$params
 *   The deep-chat style parameters, keyed as deep-chat expects (e.g.
 *   'messageStyles', 'textInput', 'submitButtonStyles', 'avatars', 'names',
 *   'mixedFiles' for the attach button and its accepted formats,
 *   'dragAndDrop' for the drop zone). Modify nested values to restyle
 *   messages, the input, the attach button, and so on.
 * @param string &$auxiliary_style
 *   CSS injected into deep-chat's shadow DOM. Append rules to it; later rules
 *   of equal specificity win, so same-selector overrides of the base layout
 *   styles work without rewriting the existing string.
 */
function hook_daedalus_ai_deepchat_style_alter(array &$params, string &$auxiliary_style): void {
  // Drop the toolbar's hardcoded AI text color so a theme variable applies,
  // and bump the message font size.
  if (isset($params['messageStyles']['default']['ai'])) {
    unset($params['messageStyles']['default']['ai']['bubble']['color']);
    $params['messageStyles']['default']['ai']['bubble']['fontSize'] = '15px';
  }

  // Append brand CSS that references theme-provided custom properties.
  $auxiliary_style .= <<<'CSS'

    .message-bubble.ai-message-text { color: var(--my-brand-text); }
    .message-bubble a { text-decoration: underline; }
    CSS;
}

/**
 * @} End of "addtogroup hooks".
 */
