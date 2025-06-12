# 📃 Nextcloud Core Changes - Login/Register Customization

## 📖 Overview

This document outlines the modifications made to the Nextcloud core codebase related to the login and registration flow. The changes primarily aim to:

* Implement a modern design for the login/register UI
* Automatically append domain names during login unless the user is an admin
* Improve the experience for username-only logins based on environment variables

---

## ✅ Goals

* Provide a seamless login experience using just the username
* Avoid requiring users to type their full email address/domain
* Keep the admin user login exempt from domain appending
* Configure domains dynamically using environment variables

---

## 👩‍💼 What Was Done

### UI Overhaul

* Redesigned the entire login/register screens to adopt a cleaner, more modern layout using updated Bootstrap + CSS.

### Core Login Flow Patch

* Injected logic into `LoginController.php`, `WebAuthnController.php`, and `User/Session.php`.
* Introduced support for dynamic domain appending logic via:

  * `main_domain` from config
  * `legacy_domain` from config
  * `NEXTCLOUD_ADMIN_USER` from environment

---

## 📝 Why These Changes Were Made

* To improve UX by allowing username-only login.
* To ensure backward compatibility with legacy domain users.
* To allow admin access to remain unaffected by domain logic.
* To centralize domain configuration and make it environment-specific.

---

## 📄 Patch Summary (Sample Code)

Below are relevant snippets from the custom patch:

### `LoginController.php`

```php
$user = trim($user);
$user = mb_strtolower($user, 'UTF-8');
$actualUser = $user;
$legacyDomain = $this->config->getSystemValue('legacy_domain', '');
$legacyDomainSuffix = !empty($legacyDomain) ? '@' . $legacyDomain : '';
$mainDomain = $this->config->getSystemValue('main_domain', '');
$mainDomainSuffix = !empty($mainDomain) ? '@'  . $mainDomain : '';
$admin_username = $_ENV["NEXTCLOUD_ADMIN_USER"];
$is_admin = strcmp($user, $admin_username) === 0;

if (!$is_admin && str_ends_with($user, $legacyDomainSuffix)) {
    $user = str_replace($legacyDomainSuffix, '', $user);
}
if (!$is_admin && str_ends_with($user, $mainDomainSuffix)) {
    $user = str_replace($mainDomainSuffix, '', $user);
}
if (!$this->userManager->userExists($user)) {
    $user = $user . $legacyDomainSuffix;
}
```

### `WebAuthnController.php`

```php
$uid = trim($uid);
$uid = mb_strtolower($uid, 'UTF-8');
$legacyDomain = \OC::$server->getConfig()->getSystemValue('legacy_domain', '');
$mainDomain = \OC::$server->getConfig()->getSystemValue('main_domain', '');
$admin_username = $_ENV["NEXTCLOUD_ADMIN_USER"];
$is_admin = strcmp($uid, $admin_username) === 0;

if (!$is_admin && str_ends_with($uid, '@' . $legacyDomain)) {
    $uid = str_replace('@' . $legacyDomain, '', $uid);
}
if (!$is_admin && str_ends_with($uid, '@' . $mainDomain)) {
    $uid = str_replace('@' . $mainDomain, '', $uid);
}
if (!\OC::$server->get(\OCP\IUserManager::class)->userExists($uid)) {
    $uid = $uid . '@' . $legacyDomain;
}
```

### `Session.php`

```php
$mainDomain = $this->config->getSystemValue('main_domain', '');
$mainDomainSuffix = !empty($mainDomain) ? '@' . $mainDomain : '';
$user = str_replace($mainDomainSuffix, '', $user);
```

### Patch used as below:

```
From: Arnau <arnauvp@e.email>
Date: Thu, 04 Feb 2021 11:24:27 +0100
Subject: [PATCH] auto append domain when user logs in only with his username, except admin user

This patch auto append the domain handled by nc, configured in env var.

only the admin user (also configured in env var) will not have his login appended with a @domain suffix

diff --git ./core/Controller/LoginController.php ./core/Controller/LoginController-new.php
--- ./core/Controller/LoginController.php	2024-04-26 15:08:54.979407062 +0530
+++ ./core/Controller/LoginController-new.php	2024-04-26 15:16:48.582366408 +0530
@@ -315,7 +315,28 @@
 				self::LOGIN_MSG_CSRFCHECKFAILED
 			);
 		}
+		$user = trim($user);
+		$user = mb_strtolower($user, 'UTF-8');
+		$actualUser = $user;
+		$legacyDomain = $this->config->getSystemValue('legacy_domain', '');
+		$legacyDomainSuffix = !empty($legacyDomain) ? '@' . $legacyDomain : '';
+		$mainDomain = $this->config->getSystemValue('main_domain', '');
+		$mainDomainSuffix = !empty($mainDomain) ? '@'  . $mainDomain : '';
+		$admin_username = $_ENV["NEXTCLOUD_ADMIN_USER"];
+		$is_admin = strcmp($user, $admin_username) === 0;
 
+		if (!$is_admin && str_ends_with($user, $legacyDomainSuffix)) {
+			$user = str_replace($legacyDomainSuffix, '', $user);
+		}
+
+		if (!$is_admin && str_ends_with($user, $mainDomainSuffix)) {
+			$user = str_replace($mainDomainSuffix, '', $user);
+		}
+
+		if (!$this->userManager->userExists($user)) {
+			$user = $user . $legacyDomainSuffix;
+		}
+
 		$data = new LoginData(
 			$this->request,
 			trim($user),
@@ -328,7 +349,7 @@
 		if (!$result->isSuccess()) {
 			return $this->createLoginFailedResponse(
 				$data->getUsername(),
-				$user,
+				$actualUser,
 				$redirect_url,
 				$result->getErrorMessage()
 			);
--- ./core/Controller/WebAuthnController.php	2023-04-21 15:18:58.813220092 +0530
+++ ./core/Controller/WebAuthnController-new.php	2023-04-21 15:24:40.036538414 +0530
@@ -66,6 +66,27 @@
 
 		$this->logger->debug('Converting login name to UID');
 		$uid = $loginName;
+
+		$uid = trim($uid);
+        $uid = mb_strtolower($uid, 'UTF-8');
+        $legacyDomain = \OC::$server->getConfig()->getSystemValue('legacy_domain', '');
+        $legacyDomainSuffix = !empty($legacyDomain) ? '@' . $legacyDomain : '';
+        $mainDomain = \OC::$server->getConfig()->getSystemValue('main_domain', '');
+        $mainDomainSuffix = !empty($mainDomain) ? '@'  . $mainDomain : '';
+        $admin_username = $_ENV["NEXTCLOUD_ADMIN_USER"];
+        $is_admin = strcmp($uid, $admin_username) === 0;
+        
+		if (!$is_admin && str_ends_with($uid, $legacyDomainSuffix)) {
+            $uid = str_replace($legacyDomainSuffix, '', $uid);
+        }
+
+        if (!$is_admin && str_ends_with($uid, $mainDomainSuffix)) {
+            $uid = str_replace($mainDomainSuffix, '', $uid);
+        }
+
+        if (!\OC::$server->get(\OCP\IUserManager::class)->userExists($uid)) {
+                $uid = $uid . $legacyDomainSuffix;
+        }
 		Util::emitHook(
 			'\OCA\Files_Sharing\API\Server2Server',
 			'preLoginNameUsedAsUserName',

--- ./lib/private/User/Session.php	2023-04-21 15:27:00.417034490 +0530
+++ ./lib/private/User/Session-new.php	2023-04-21 15:28:18.309111435 +0530
@@ -430,6 +430,10 @@
 		$remoteAddress = $request->getRemoteAddress();
 		$currentDelay = $throttler->sleepDelay($remoteAddress, 'login');
 
+		$mainDomain = $this->config->getSystemValue('main_domain', '');
+		$mainDomainSuffix = !empty($mainDomain) ? '@' . $mainDomain : '';
+		$user = str_replace($mainDomainSuffix, '', $user);
+
 		if ($this->manager instanceof PublicEmitter) {
 			$this->manager->emit('\OC\User', 'preLogin', [$user, $password]);
	}
```
