# Hide My Email App

The **Hide My Email** app provides a privacy-focused feature allowing users to mask their real email addresses with aliases. For example, a user may create a cloud account with `iamuser@xys.com` but choose to use an alias like `lorem@abc.com` for external communication and security purposes.

## Key Features

* Automatically generates a hidden email alias upon successful user registration.
* Users can manage their aliases from the **Hide My Email** section under **Settings**.
* Admins can view aliases for any user via API or UI if authorized.

---

## 📁 Important Files and Their Roles

### 1. `Controller/AliasesController.php`

Handles API-level logic to **retrieve email aliases** for a user.

```php
public function getEmailAliases(string $uid) {
		$response = new DataResponse();
		$currentUser = $this->userSession->getUser();

		if (!$this->userHasAccess($uid, $currentUser)) {
			throw new OCSException('', OCSController::RESPOND_UNAUTHORISED);
		}

		$user = $this->aliasesService->getUser($uid);
		if (is_null($user)) {
			throw new OCSNotFoundException('User does not exist');
		}

		$aliases = $this->aliasesService->getEmailAliases($uid);
		$response->setData(['aliases' => $aliases]);

		return $response;
	}
```

* Validates access: only the current user or admins can fetch aliases.
* Uses `AliasesService` to load aliases from user config.

---

### 2. `Services/AliasesService.php`

Handles **business logic and data access** related to aliases.

```php
public function setEmailAliases(string $uid, string $emailaliases): bool {
		try {
			$this->config->setUserValue($uid, $this->appName, 'email-aliases', $emailaliases);
			return true;
		} catch (UnexpectedValueException $e) {
			return false;
		}
	}

	public function getEmailAliases(string $uid): array {
		try {
			$aliases = $this->config->getUserValue($uid, $this->appName, 'email-aliases', '');
			$aliases = json_decode($aliases, true);
			return !is_null($aliases) ? $aliases : [];
		} catch (UnexpectedValueException $e) {
			return [];
		}
	}
```

* Stores/retrieves aliases using the user config system.
* Ensures proper error handling and returns empty list if none found.

---

### 3. `Settings/AliasesSection.php`

Registers the **new section** in the user settings UI.

```php
public function getID() { return 'hide-my-email'; }
public function getName() { return $this->l->t('Hide My Email'); }
```

* Appears under Settings with a custom icon.
* Set with medium display priority (70).

---

### 4. `Settings/EmailAliasesSetting.php`

Displays **UI content** for the Hide My Email settings section.

```php
public function getForm(): TemplateResponse
```

* Loads email aliases via the service.
* Injects them into the settings template (`email_aliases_settings`).
* Enqueues a custom JS script (`hide-my-email-copy.js`) for UI functionality.

---

## 🧠 How It Works

* On account creation, a hidden alias is generated.
* Alias is saved using `setEmailAliases()` method.
* Users can view and copy their aliases from the UI.
* Admins or users themselves can fetch aliases via controller endpoints.

---

## 🔐 Access Control

Only:

* The authenticated user, or
* Admins (via `GroupManager->isAdmin()`)

can access or modify alias data of a user.

---

## 📦 Configuration Keys

* Aliases are stored in the user config under the key `email-aliases` (as JSON string).
* Stored per-user using:

  ```php
  $this->config->setUserValue($uid, 'hide-my-email', 'email-aliases', $jsonEncoded);
  ```
