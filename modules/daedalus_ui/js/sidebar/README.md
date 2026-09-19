# Sidebar Manager

The Sidebar Manager provides a centralized way to manage sidebar panels in the
Daedalus UI system. At most one panel is open **per (side, slot)**: sides
(left/right) and slots within a side (top/bottom) are independent, so a
left-rail tree (top slot) and palette (bottom slot) coexist while panels sharing
the same side and slot stay mutually exclusive. A panel's slot rides its
`data-sidebar-slot` attribute (daedalus_edit's runtime-appended forms declare
`bottom`, so they stack under a top-slot inspector rather than evicting it) and
defaults to `top` when absent. The open panel is derived from the DOM (the
visible panel in a side and slot), not stored, so state cannot drift. It
provides lifecycle hooks for opening and closing sidebars.

## Architecture

### Core Components

- **SidebarManager** - Central manager that derives the open panel per (side,
  slot) from the DOM and coordinates opening/closing
- **SidebarPluginBase** - Base class that all sidebar plugins extend
- **DefaultSidebar** - Default implementation for simple sidebars with multiple
  instances
- **Sidebar Plugins** - Individual implementations for each sidebar type
  (settings, edit form, etc.)

### Key Principles

1. **The DOM is the Single Source of Truth** - The open panel in a (side, slot)
   is the one not carrying `daedalus-ui-hidden`; nothing stores that state
   twice. Plugins are stateless.
2. **Plugins Receive Context** - Manager passes `id` parameter to `open(id)` and
   `close(id)` methods.
3. **Promise-Based Lifecycle** - All operations return Promises for async
   control flow.
4. **Context-Aware Error Handling** - Callers decide what to log; manager just
   rejects cleanly.

### Files

```
daedalus_ui/js/sidebar/
├── plugins/
│   ├── sidebar-plugin-base.js            # Base class all plugins extend
│   ├── default-sidebar.js                # Default implementation
│   └── notifications-sidebar.js          # Notifications panel
└── sidebar-manager.js                    # Core manager
```

## Server-Side Integration

### Adding `data-sidebar-type` Attribute

All server-rendered sidebars must include a `data-sidebar-type` attribute that
matches their sidebar plugin type:

**PHP Example (Edit.php):**
```php
$right_sidebars = [
  'edit_mode_settings' => [
    '#type' => 'container',
    '#attributes' => [
      'id' => 'edit_mode_settings',
      'class' => ['daedalus-ui-sidebar', 'right-sidebar'],
      'data-sidebar-type' => 'default',  // Matches DefaultSidebar plugin
    ],
    // ...
  ],
];
```

**PHP Example (DaedalusEditFormTrait.php):**
```php
return [
  '#type' => 'container',
  '#attributes' => [
    'id' => sprintf(
      'daedalus-edit-form-%s-%s',
      $entity->getEntityTypeId(),
      $entity->id(),
    ),
    'class' => ['daedalus-edit-form', 'daedalus-ui-sidebar', 'right-sidebar'],
    'data-sidebar-type' => 'daedalus_edit_form',  // Matches DaedalusEditSidebar plugin
    'data-sidebar-slot' => 'bottom',  // Stacks under top-slot panels
    'data-daedalus-edit-form-id' => sprintf(
      '%s::%s',
      $entity->getEntityTypeId(),
      $entity->id(),
    ),
  ],
  'form' => $form,
];
```

## Creating a New Sidebar Plugin

### Option 1: Simple Sidebar (Extend DefaultSidebar)

For sidebars that just need basic open/close functionality:

```javascript
(($, Drupal) => {
  const DefaultSidebar = Drupal.Daedalus.DefaultSidebar;
  const sidebarManager = Drupal.Daedalus.SidebarManager;

  class MySimpleSidebar extends DefaultSidebar {
    type = 'my_sidebar';
  }

  // Register the plugin
  sidebarManager.registerPlugin(new MySimpleSidebar());

})(jQuery, Drupal);
```

That's it! DefaultSidebar provides:
- Automatic element registration via `registerSidebar(id, element)`
- Cookie support for server-side rendering
- Button state management
- Multiple instance support via Map

### Option 2: Custom Sidebar (Extend SidebarPluginBase)

For sidebars that need custom validation or complex behavior:

```javascript
(($, Drupal) => {
  const SidebarPluginBase = Drupal.Daedalus.SidebarPluginBase;
  const sidebarManager = Drupal.Daedalus.SidebarManager;

  class MyCustomSidebar extends SidebarPluginBase {
    type = 'my_custom_sidebar';

    // Store multiple instances
    sidebars = new Map();

    /**
     * Register a sidebar element
     */
    registerSidebar(id, element) {
      this.sidebars.set(id, element);
    }

    /**
     * Get specific sidebar by id
     */
    getElement(id) {
      return this.sidebars.get(id);
    }

    /**
     * Open sidebar
     *
     * @param {string|null} id - Sidebar instance ID from manager
     */
    open(id = null) {
      return new Promise((resolve) => {
        const sidebar = this.getElement(id);
        if (sidebar) {
          sidebar.classList.remove('daedalus-ui-hidden');
          const side = sidebar.classList.contains('left-sidebar')
            ? 'left'
            : 'right';
          sidebar.setAttribute(`data-offset-${side}`, '');
          Drupal.displace();
        }
        resolve();
      });
    }

    /**
     * Close sidebar with validation
     *
     * @param {string|null} id - Sidebar instance ID from manager
     */
    close(id = null) {
      return new Promise((resolve, reject) => {
        // Check if sidebar can be closed
        if (this.hasUnsavedChanges(id)) {
          // Show user message
          const editMode = Drupal.Daedalus.ModeManager.getPlugin('edit');
          editMode.message('Save or discard your changes first', 'warning');

          // Reject - manager will handle this
          reject('Unsaved changes in sidebar');
          return;
        }

        const sidebar = this.getElement(id);
        if (sidebar) {
          sidebar.classList.add('daedalus-ui-hidden');
          const side = sidebar.classList.contains('left-sidebar')
            ? 'left'
            : 'right';
          sidebar.removeAttribute(`data-offset-${side}`);
          Drupal.displace();
        }
        resolve();
      });
    }

    /**
     * Custom validation logic
     */
    hasUnsavedChanges(id) {
      // Your validation logic.
      return false;
    }
  }

  // Register the plugin
  sidebarManager.registerPlugin(new MyCustomSidebar());

})(jQuery, Drupal);
```

### Server-Side Markup

Ensure your PHP code adds the required attributes:

```php
'#attributes' => [
  'id' => 'my-sidebar-123',  // Unique ID for this instance
  'class' => ['daedalus-ui-sidebar', 'right-sidebar'],
  'data-sidebar-type' => 'my_custom_sidebar',  // Must match plugin type
],
```

### Load the JavaScript

Add to your `*.libraries.yml`:

```yaml
my_module.sidebar:
  js:
    js/sidebars/my-sidebar.js: {}
  dependencies:
    - daedalus_ui/sidebar
```

## Using the Sidebar Manager

The plugin type is read from the panel's `data-sidebar-type`, so openers pass
only the panel id (its DOM id). Side is read from the `{side}-sidebar` class.

### Opening a Sidebar

```javascript
// Opens the panel, closing whatever currently holds its side.
try {
  await Drupal.Daedalus.SidebarManager.openSidebar('edit_mode_settings');
  console.log('Settings sidebar opened');
} catch (error) {
  // Rejects only if the same-side panel refused to close (e.g. invalid form).
  console.error('Cannot open settings:', error);
}
```

### Closing Sidebars

```javascript
// Close one specific panel (its own close control, a toggle switching off).
await Drupal.Daedalus.SidebarManager.closeSidebar('edit_mode_settings');

// Close whatever is active on one side.
await Drupal.Daedalus.SidebarManager.closeSide('right');

// Close the active panel on both sides (e.g. leaving Edit Mode).
await Drupal.Daedalus.SidebarManager.closeAll();
```

### Rail Width

The rail width is a wrapper-level CSS custom property, `--daedalus-ui-sidebar-width`
(default `17rem`), that every panel consumes for its `inline-size`. A panel may
still declare its own width by id (e.g. the AI chat's `24rem`). Two writers:

- **The user.** `sidebar-resize.js` injects a drag handle on each wrapper's
  page-facing edge. Dragging sets the property live; release persists it in the
  `{side}_sidebar_width` cookie, which `DaedalusUi::buildSidebars()`
  stamps back onto the wrapper on page load (the cookie-driven SSR pattern —
  no flash). Double-click resets to the default.
- **Code.** A panel that needs more room than the rail gives it can request a
  temporary per-panel floor:

```javascript
// Effective width: min(max(rail width, 600px), 50vw). Never shrinks a rail
// the user already dragged wider.
Drupal.Daedalus.SidebarManager.setPanelWidth(panelElement, 600);
// Back to the rail width.
Drupal.Daedalus.SidebarManager.releasePanelWidth(panelElement);
```

Page content follows width changes automatically — panels carry
`data-offset-{side}`, so both writers just call `Drupal.displace()`.

### Inspecting Active Sidebars

```javascript
// {plugin, id} or null, per side.
const right = Drupal.Daedalus.SidebarManager.active.right;
if (right) {
  console.log('Active right panel:', right.id, right.plugin.type);
}
```
