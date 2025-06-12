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
