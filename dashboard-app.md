# 📊 Dashboard App

## Overview

The **Dashboard App** is a custom-built application designed to serve as a unified launchpad for all apps enabled for a user in the Murena Cloud environment. It gathers and displays shortcuts for internal apps, OnlyOffice tools, and external links in a user-friendly dashboard interface.

---

## 🏗️ App Structure

```
dashboard_app/
│
├── appinfo/
│   ├── info.xml               # App metadata
│   └── routes.php             # Routes definition
│
├── controller/
│   └── PageController.php     # Handles dashboard rendering logic
│
├── service/
│   └── AppsService.php        # Core business logic for collecting and ordering app entries
│
├── templates/
│   └── dashboard.php          # Blade/Twig-like template used in TemplateResponse
│
├── src/
│   ├── components/            # Vue components used in the frontend
│   ├── views/                 # Vue views/pages
│   ├── store/                 # Vuex state (if any)
│   └── main.js                # Main JS entry point (bundled by Vite/Webpack)
│
└── dashboard.js               # Vue bootstrapping logic
```

---

## 🔧 Controller Logic

**File**: `controller/PageController.php`

### Method: `index()`

This method handles the loading of the dashboard page and populates it with necessary initial state variables for Vue.

**Key Responsibilities:**

* Fetches user-specific and system configuration values.
* Retrieves app entries via `AppsService`.
* Provides state to frontend using `InitialState` provider.
* Renders the `dashboard` template.

```php
public function index() {
	$referralUrl = $this->config->getSystemValue('shop_referral_program_url', '');
	$storageUrl = $this->config->getAppValue('increasestoragebutton', 'link', '');
	$entries = $this->appsService->getAppEntries();
	$displayName = $this->userSession->getUser()->getDisplayName();
	$isReferralProgramActive = $this->config->getSystemValue('is_referral_program_active', false);
	$documentsBaseDirectory = $this->appsService->getDocumentsFolder();

	// Provide state to frontend Vue app
	$this->initialState->provideInitialState('shopReferralProgramUrl', $referralUrl);
	$this->initialState->provideInitialState('increaseStorageUrl', $storageUrl);
	$this->initialState->provideInitialState('entries', $entries);
	$this->initialState->provideInitialState('displayName', $displayName);
	$this->initialState->provideInitialState('isReferralProgramActive', $isReferralProgramActive);
	$this->initialState->provideInitialState('documentsBaseDirectory', $documentsBaseDirectory);

	return new TemplateResponse($this->appName, 'dashboard');
}
```

---

## 🧠 Service Logic

**File**: `service/AppsService.php`

This service provides the data and logic required to generate the app list shown on the dashboard.

### Highlights:

#### ✅ `getAppEntries()`

* Collects all registered apps from `INavigationManager`.
* Appends custom OnlyOffice links (e.g., new document, spreadsheet, presentation) if the app is enabled.
* Orders apps based on saved user preference, falling back to `DEFAULT_ORDER` if not available.
* Filters out system/hidden/internal apps like:

  * `/apps/dashboard/`
  * `/apps/murena-dashboard/`
  * `/apps/photos/` (replaced with `/apps/memories/`)
* Adds beta flag for users part of a configured beta group.

#### 📄 `getOnlyOfficeEntries()`

Returns predefined entries for:

* Document (`onlyoffice_docx`)
* Spreadsheet (`onlyoffice_xlsx`)
* Presentation (`onlyoffice_pptx`)

#### 🔄 `getAppOrder()`

Fetches user's preferred order from:

* `murena_launcher`
* Fallback: `apporder`
* Fallback: `DEFAULT_ORDER`

#### 🔄 `updateOrder($order)`

Saves the given order back to the user preferences.

#### 🔍 `isBetaUser()`

Checks if the user is part of the beta group defined by `beta_group_name` system config.

```

	private const DEFAULT_ORDER = [
		"/apps/snappymail/",
		"/apps/calendar/",
		"/apps/contacts/",
		"/apps/memories/",
		"/apps/files/",
		"https://vault.murena.io/login",
		"/apps/onlyoffice/new?id=onlyoffice_docx",
		"/apps/onlyoffice/new?id=onlyoffice_xlsx",
		"/apps/onlyoffice/new?id=onlyoffice_pptx",
		"/apps/notes/",
		"/apps/tasks/",
		"https://murena.com"
	];
	public function __construct(
		$appName,
		IConfig $config,
		INavigationManager $navigationManager,
		IAppManager $appManager,
		IFactory $l10nFac,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IURLGenerator $urlGenerator,
		IRootFolder $rootFolder,
		$userId
	) {
		$this->appName = $appName;
		$this->userId = $userId;
		$this->config = $config;
		$this->navigationManager = $navigationManager;
		$this->appManager = $appManager;
		$this->l10nFac = $l10nFac;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->urlGenerator = $urlGenerator;
		$this->rootFolder = $rootFolder;
	}

	public function getOnlyOfficeEntries() {
		$l = $this->l10nFac->get("onlyoffice");
		$onlyOfficeEntries = array(
			array(
				"id" => "onlyoffice_docx",
				"icon" => $this->urlGenerator->imagePath('onlyoffice', 'docx/app-color.svg'),
				"name" => $l->t("Document"),
				"type" => "link",
				"active" => false,
				"href" => "/apps/onlyoffice/new?id=onlyoffice_docx"
			),
			array(
				"id" => "onlyoffice_xlsx",
				"icon" => $this->urlGenerator->imagePath('onlyoffice', 'xlsx/app-color.svg'),
				"name" => $l->t("Spreadsheet"),
				"type" => "link",
				"active" => false,
				"href" => "/apps/onlyoffice/new?id=onlyoffice_xlsx"
			),
			array(
				"id" => "onlyoffice_pptx",
				"icon" => $this->urlGenerator->imagePath('onlyoffice', 'pptx/app-color.svg'),
				"name" => $l->t("Presentation"),
				"type" => "link",
				"active" => false,
				"href" => "/apps/onlyoffice/new?id=onlyoffice_pptx"
			),
		);
		return $onlyOfficeEntries;
	}

	public function getAppOrder() {
		$order_raw = $this->config->getUserValue($this->userId, 'murena_launcher', 'order');
		// If order raw empty try to get from 'apporder' app config
		$order_raw = !$order_raw ? $this->config->getUserValue($this->userId, 'apporder', 'order') : $order_raw;
		// If order raw is still empty, return empty array
		if (!$order_raw) {
			return self::DEFAULT_ORDER;
		}
		return json_decode($order_raw);
	}

	public function getAppEntries() {
		$entries = array_values($this->navigationManager->getAll());
		$order = $this->getAppOrder();
		$entriesByHref = array();
		if ($this->appManager->isEnabledForUser("onlyoffice")) {
			$office_entries = $this->getOnlyOfficeEntries();
			$entries = array_merge($entries, $office_entries);
		}
		$betaGroupName = $this->config->getSystemValue("beta_group_name");
		$isBeta = $this->isBetaUser();
		foreach ($entries as &$entry) {
			try {
				$entry["icon"] = $this->urlGenerator->imagePath($entry["id"], 'app-color.svg');
			} catch (\Throwable $th) {
				//exception - continue execution
			}
			if (strpos($entry["id"], "external_index") !== 0) {
				$entry["target"] = "";
			} else {
				$entry["target"] = "_blank";
			}
			$entry["class"] = "";
			if (strpos($entry["icon"], "/custom_apps/") === 0) {
				$entry["class"] = "icon-invert";
			}
			$entry["iconOffsetY"] = 0;
			$entry["is_beta"] = 0;
			$appEnabledGroups = $this->config->getAppValue($entry['id'], 'enabled', 'no');
			if ($isBeta && str_contains($appEnabledGroups, $betaGroupName)) {
				$entry["is_beta"] = 1;
			}
			$entriesByHref[$entry["href"]] = $entry;
		}
		// Remove photos, replace in order correctly with memories
		$order = str_replace('/apps/photos/', '/apps/memories/', $order);
		$order = array_unique($order);
		unset($entriesByHref['/apps/photos/']);
		/*
		 Sort apps according to order
		 Since "entriesByHref" is indexed by "href", simply reverse the order array and prepend in "entriesByHref"
		 Prepend is done by using each "href" in the reversed order array and doing a union of the "entriesByHref"
		 array with the current element
		*/
		if ($order) {
			$order = array_reverse($order);
			foreach ($order as $href) {
				if (!empty($entriesByHref[$href])) {
					$entriesByHref = array($href => $entriesByHref[$href]) + $entriesByHref;
				}
			}
		}
		unset($entriesByHref['/apps/dashboard/']);
		unset($entriesByHref['/apps/murena-dashboard/']);
		unset($entriesByHref['']);

		return array_values($entriesByHref);
	}

	public function updateOrder(string $order) {
		$this->config->setUserValue($this->userId, $this->appName, 'order', $order);
	}

	private function isBetaUser() {
		$uid = $this->userSession->getUser()->getUID();
		$gid = $this->config->getSystemValue("beta_group_name");
		return $this->groupManager->isInGroup($uid, $gid);
	}
```

---

## 🎨 Frontend (Vue) – `src/`

The frontend uses Vue.js for dynamic rendering. Key parts:

* `src/main.js` — Bootstraps the Vue application.
* `src/components/` — Contains reusable UI components (e.g., AppCard, GridLayout).
* `src/views/` — Likely includes `Dashboard.vue` as the main view.
* `src/store/` — Vuex store for state management (if used).

The initial state (populated from PHP) is consumed in Vue using:

```js
OC.getInitialState('entries')
OC.getInitialState('displayName')
OC.getInitialState('shopReferralProgramUrl')
// etc.
```

---

## 🗂️ Default App Order

The following is the default fallback ordering used if no user-defined sort is saved:

```php
private const DEFAULT_ORDER = [
	"/apps/snappymail/",
	"/apps/calendar/",
	"/apps/contacts/",
	"/apps/memories/",
	"/apps/files/",
	"https://vault.murena.io/login",
	"/apps/onlyoffice/new?id=onlyoffice_docx",
	"/apps/onlyoffice/new?id=onlyoffice_xlsx",
	"/apps/onlyoffice/new?id=onlyoffice_pptx",
	"/apps/notes/",
	"/apps/tasks/",
	"https://murena.com"
];
```
