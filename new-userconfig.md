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


## Patch created as below

```
--- ./lib/private/AllConfig.php	2024-03-28 01:02:39
+++ ./lib/private/AllConfig-new.php	2024-04-15 16:36:23
@@ -38,6 +38,8 @@
 use OCP\IConfig;
 use OCP\IDBConnection;
 use OCP\PreConditionNotMetException;
+use OCP\EventDispatcher\IEventDispatcher;
+use OCP\User\Events\UserConfigChangedEvent;
 
 /**
  * Class to combine all the configuration options ownCloud offers
@@ -278,6 +280,7 @@
 				$qb->executeStatement();
 
 				$this->userCache[$userId][$appName][$key] = (string)$value;
+				$this->triggerUserValueChange($userId, $appName, $key, $value, $prevValue);
 				return;
 			}
 		}
@@ -304,8 +307,15 @@
 			}
 			$this->userCache[$userId][$appName][$key] = (string)$value;
 		}
+		$this->triggerUserValueChange($userId, $appName, $key, $value, $prevValue);
 	}
 
+	private function triggerUserValueChange($userId, $appId, $key, $value, $oldValue = null) {
+		if (\OC::$server instanceof \OCP\IServerContainer) {
+			$dispatcher = \OC::$server->get(IEventDispatcher::class);
+			$dispatcher->dispatchTyped(new UserConfigChangedEvent($userId, $appId, $key, $value, $oldValue));
+		}
+	}
 	/**
 	 * Getting a user defined value
 	 *


--- ./lib/composer/composer/autoload_static.php	2024-03-28 01:02:39
+++ ./lib/composer/composer/autoload_static-new.php	2024-04-15 16:34:18
@@ -710,6 +710,7 @@
         'OCP\\User\\Events\\PasswordUpdatedEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/PasswordUpdatedEvent.php',
         'OCP\\User\\Events\\PostLoginEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/PostLoginEvent.php',
         'OCP\\User\\Events\\UserChangedEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/UserChangedEvent.php',
+        'OCP\\User\\Events\\UserConfigChangedEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/UserConfigChangedEvent.php',
         'OCP\\User\\Events\\UserCreatedEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/UserCreatedEvent.php',
         'OCP\\User\\Events\\UserDeletedEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/UserDeletedEvent.php',
         'OCP\\User\\Events\\UserLiveStatusEvent' => __DIR__ . '/../../..' . '/lib/public/User/Events/UserLiveStatusEvent.php',


--- ./lib/composer/composer/autoload_classmap.php	2024-03-28 01:02:39
+++ ./lib/composer/composer/autoload_classmap-new.php	2024-04-15 16:33:19
@@ -683,6 +683,7 @@
     'OCP\\User\\Events\\UserLoggedInEvent' => $baseDir . '/lib/public/User/Events/UserLoggedInEvent.php',
     'OCP\\User\\Events\\UserLoggedInWithCookieEvent' => $baseDir . '/lib/public/User/Events/UserLoggedInWithCookieEvent.php',
     'OCP\\User\\Events\\UserLoggedOutEvent' => $baseDir . '/lib/public/User/Events/UserLoggedOutEvent.php',
+    'OCP\\User\\Events\\UserConfigChangedEvent' => $baseDir . '/lib/public/User/Events/UserConfigChangedEvent.php',
     'OCP\\User\\GetQuotaEvent' => $baseDir . '/lib/public/User/GetQuotaEvent.php',
     'OCP\\Util' => $baseDir . '/lib/public/Util.php',
     'OCP\\WorkflowEngine\\EntityContext\\IContextPortation' => $baseDir . '/lib/public/WorkflowEngine/EntityContext/IContextPortation.php',


--- /dev/null	2024-04-16 13:44:50
+++ ./lib/public/User/Events/UserConfigChangedEvent.php	2024-04-16 13:43:17
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+/**
+ * @copyright Copyright (c) 2023 Murena SAS <ronak.patel.ext@murena.com>
+ *
+ * @author Murena SAS <ronak.patel.ext@murena.com>
+ *
+ * @license GNU AGPL version 3 or any later version
+ *
+ * This program is free software: you can redistribute it and/or modify
+ * it under the terms of the GNU Affero General Public License as
+ * published by the Free Software Foundation, either version 3 of the
+ * License, or (at your option) any later version.
+ *
+ * This program is distributed in the hope that it will be useful,
+ * but WITHOUT ANY WARRANTY; without even the implied warranty of
+ * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
+ * GNU Affero General Public License for more details.
+ *
+ * You should have received a copy of the GNU Affero General Public License
+ * along with this program. If not, see <http://www.gnu.org/licenses/>.
+ *
+ */
+
+namespace OCP\User\Events;
+
+use OCP\EventDispatcher\Event;
+
+class UserConfigChangedEvent extends Event {
+	private string $userId;
+	private string $appId;
+	private string $key;
+	private mixed $value;
+	private mixed $oldValue;
+
+	public function __construct(string $userId,
+		string $appId,
+		string $key,
+		mixed $value,
+		mixed $oldValue = null) {
+		parent::__construct();
+		$this->userId = $userId;
+		$this->appId = $appId;
+		$this->key = $key;
+		$this->value = $value;
+		$this->oldValue = $oldValue;
+	}
+
+	public function getUserId(): string {
+		return $this->userId;
+	}
+
+	public function getAppId(): string {
+		return $this->appId;
+	}
+	public function getKey(): string {
+		return $this->key;
+	}
+
+	public function getValue() {
+		return $this->value;
+	}
+
+	public function getOldValue() {
+		return $this->oldValue;
+	}
+}

```
