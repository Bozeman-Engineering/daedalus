# Daedalus Tempstore

## Problem

Drupal's core `LayoutTempstoreRepository` is designed to handle Layout
Builder section storage. However, other modules and systems need different
tempstore behaviors. Without daedalus_tempstore, each module must override
`layout_builder.tempstore_repository` directly, which:
- Creates conflicts when multiple modules try to override the same service
- Requires complex inheritance chains
- Makes it difficult to support optional dependencies

## Solution

Daedalus Tempstore uses the **Strategy Pattern** to handle different tempstore
scenarios:

1. **Strategies** implement `TempstoreStrategyInterface` and define logic for
   a specific storage type
2. **StrategySelector** checks each strategy's `supports()` method and returns
   the first match
3. **Priority** determines the order strategies are checked (higher priority =
   checked first)

## Architecture

```
TempstoreRepository (abstract base)
  └─> StrategySelector
        ├─> LayoutTempstoreStrategy (priority: 10)
        └─> EntityTempstoreStrategy (priority: 0)
```

Example with additional strategies from other modules:

```
TempstoreRepository (abstract base)
  └─> StrategySelector
        ├─> CustomTempstoreStrategy (priority: 20, from other module)
        ├─> LayoutTempstoreStrategy (priority: 10)
        └─> EntityTempstoreStrategy (priority: 0)
```

The selector checks strategies in priority order and uses the first one where
`supports($subject)` returns `TRUE`.

## Adding a Strategy

### 1. Create Your Strategy Class

```php
namespace Drupal\my_module\Strategy;

use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\daedalus_tempstore\Strategy\TempstoreStrategyInterface;

class MyCustomTempstoreStrategy implements TempstoreStrategyInterface {

  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports($subject): bool {
    // Return TRUE if this strategy should handle the subject.
    return $subject instanceof MyCustomInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function get($subject) {
    $key = $this->getKey($subject);
    $collection = $this->getCollection($subject);
    return $this->tempStoreFactory->get($collection)->get($key);
  }

  /**
   * {@inheritdoc}
   */
  public function set($subject): void {
    $key = $this->getKey($subject);
    $collection = $this->getCollection($subject);
    $this->tempStoreFactory->get($collection)->set($key, $subject);
  }

  /**
   * {@inheritdoc}
   */
  public function has($subject): bool {
    $key = $this->getKey($subject);
    $collection = $this->getCollection($subject);
    $store = $this->tempStoreFactory->get($collection);
    return $store->getMetadata($key) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function delete($subject): void {
    $key = $this->getKey($subject);
    $collection = $this->getCollection($subject);
    $this->tempStoreFactory->get($collection)->delete($key);
  }

  /**
   * {@inheritdoc}
   */
  public function getKey($subject): string {
    // Generate a unique key for this subject.
    return 'my_custom_key.' . $subject->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getCollection($subject): string {
    // Return the tempstore collection name.
    return 'my_module.custom_collection';
  }

}
```

### 2. Register as a Tagged Service

In your module's `my_module.services.yml`:

```yaml
services:
  my_module.strategy.custom:
    class: Drupal\my_module\Strategy\MyCustomTempstoreStrategy
    arguments:
      - '@tempstore.shared'
    tags:
      - { name: tempstore_strategy, priority: 15 }
```

**Priority guidelines:**
- Higher priority = checked first
- Use `priority: 20+` for strategies that should override Layout Builder
- Use `priority: 10-19` for layout-related strategies
- Use `priority: 0-9` for general/fallback strategies

### 3. Clear Cache

```bash
drush cr
```

Your strategy will now be automatically discovered and used when
`supports($subject)` returns `TRUE`.

## Workspace Awareness

If your strategy needs workspace isolation, use the `WorkspaceKeyTrait`:

```php
use Drupal\daedalus_tempstore\WorkspaceKeyTrait;

class MyCustomTempstoreStrategy implements TempstoreStrategyInterface {

  use WorkspaceKeyTrait;

  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
    protected $workspaceManager = NULL,
  ) {}

  public function getKey($subject): string {
    $key = 'my_custom_key.' . $subject->id();
    return $this->appendWorkspaceToKey($key);
  }

}
```

Register with workspace manager injection:

```yaml
services:
  my_module.strategy.custom:
    class: Drupal\my_module\Strategy\MyCustomTempstoreStrategy
    arguments:
      - '@tempstore.shared'
      - '@?workspaces.manager'
    tags:
      - { name: tempstore_strategy, priority: 15 }
```

The `?` prefix makes the workspace manager optional (won't break if the
workspaces module is disabled).

## Examples

See these strategies for reference implementations:

- **LayoutTempstoreStrategy** - Handles Layout Builder section storage with
  workspace awareness
- **EntityTempstoreStrategy** - Fallback for general entity tempstore operations

External modules can provide additional strategies:

- **PageManagerTempstoreStrategy** (page_manager_ui module) - Handles Page
  Manager's specialized storage structure
