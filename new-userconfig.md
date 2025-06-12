# Nextcloud Enhancement: `UserConfigChangedEvent`

## Overview

To enhance the observability and extensibility of user-specific configurations in Nextcloud, we introduced a new event called `UserConfigChangedEvent`. This event is dispatched whenever a user config setting is modified, allowing other parts of the system (or apps) to react to those changes.

## Why This Was Done

Nextcloud lacked a dedicated event hook for tracking changes to user configuration. This made it difficult for developers or system administrators to log, monitor, or extend behavior based on user-specific config updates.

Adding this event brings better modularity and real-time response capability to config changes, such as triggering cache invalidation, syncing to external systems, or auditing.

## Key Changes

### 1. Modified `AllConfig.php`

* Added dependency on `IEventDispatcher`
* Dispatched the `UserConfigChangedEvent` inside `setUserValue()`

```php
use OCP\EventDispatcher\IEventDispatcher;
use OCP\User\Events\UserConfigChangedEvent;

private function triggerUserValueChange($userId, $appId, $key, $value, $oldValue = null) {
    if (\OC::$server instanceof \OCP\IServerContainer) {
        $dispatcher = \OC::$server->get(IEventDispatcher::class);
        $dispatcher->dispatchTyped(new UserConfigChangedEvent($userId, $appId, $key, $value, $oldValue));
    }
}
```

This method is now called after config value is written to the database/cache.

### 2. Created New Event Class

Path: `lib/public/User/Events/UserConfigChangedEvent.php`

```php
namespace OCP\User\Events;

use OCP\EventDispatcher\Event;

class UserConfigChangedEvent extends Event {
    private string $userId;
    private string $appId;
    private string $key;
    private mixed $value;
    private mixed $oldValue;

    public function __construct(string $userId, string $appId, string $key, mixed $value, mixed $oldValue = null) {
        parent::__construct();
        $this->userId = $userId;
        $this->appId = $appId;
        $this->key = $key;
        $this->value = $value;
        $this->oldValue = $oldValue;
    }

    public function getUserId(): string { return $this->userId; }
    public function getAppId(): string { return $this->appId; }
    public function getKey(): string { return $this->key; }
    public function getValue() { return $this->value; }
    public function getOldValue() { return $this->oldValue; }
}
```

### 3. Composer Autoload Updates

* Updated `autoload_static.php` and `autoload_classmap.php` to register the new event class.

## How It Works

Now, whenever `setUserValue()` is called in the core `AllConfig` class, a `UserConfigChangedEvent` is dispatched. Any app or listener registered to this event can handle it accordingly, enabling rich interaction and modular extensions.
