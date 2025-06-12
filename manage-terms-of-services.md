## 📜 Terms and Services App

The **Terms and Services app** ensures that users explicitly accept your company's Terms of Service (TOS). If a user declines or ignores the TOS prompt, the system begins a timed process to **notify, disable, and eventually delete** the account using background jobs.

---

### ✨ Purpose

* Prompt users to **agree to the Terms and Services**.
* If a user **declines or ignores**, the system:

  * Sends periodic **notifications/reminders**
  * **Disables** the account after a configured period
  * **Deletes** the account after an additional grace period

---

### ⚙️ Background Jobs Overview

This app is powered by **4 key background jobs**, each serving a specific purpose in the lifecycle of TOS enforcement:

---

#### 1. 🔥 `CleanUpAccountJob.php`

**Purpose:**
Disables and deletes accounts that declined the TOS after `account_deletion_days`.

**Key Logic:**

* Adds 1 extra day to account for timezone differences.
* Fetches users who declined TOS before the calculated date.
* Disables and deletes those user accounts.

**Code Excerpt:**

```php
$declineDate = date('Y-m-d', strtotime(date('Y-m-d').' - '.$deletionDays.' days'));
$userIds = $this->preferenceMapper->getUsersWhoDeclinedBefore($declineDate);
```

---

#### 2. 🔔 `NotificationJob.php`

**Purpose:**
Sends a **notification** to *all* users (even those who already accepted) reminding them to accept the TOS.

**Key Logic:**

* Marks previous `accept_terms` notifications as processed.
* Sends a new notification for all users using `callForSeenUsers()`.

---

#### 3. 📧 `SendReminderEmails.php`

**Purpose:**
Triggers reminders to users whose accounts are already **disabled** due to not accepting TOS.

**Key Logic:**

* Delegates email logic to the service:

  ```php
  $this->remindersService->sendRemindersToDisabledAccounts();
  ```

---

#### 4. ⏰ `SendReminderInAdvanceMailJob.php`

**Purpose:**
Sends **advance reminder emails and notifications** to users who **declined TOS but are still active**.

**Highlights:**

* Computes `expiryDate` based on `first_decline_date` + `account_deletion_days`.
* Uses config keys:

  * `account_deletion_days`
  * `account_deletion_reminder_one`
  * `account_deletion_reminder_two`
* Sends:

  * In-app notification
  * Templated email with localized expiry date

**Sample Notification Text:**

> We noticed you declined our Terms of Service, which means your Cloud account is scheduled to be deleted on **\[expiry date]**. Please back up any important data or accept the terms to avoid deletion.

**Key Method:**

```php
protected function getUserIdsToDelete(): array {
	// Calculates eligible user IDs for first and second reminders
}
```

---

### 🧩 Config Values Required

| Config Key                      | Description                                  |
| ------------------------------- | -------------------------------------------- |
| `account_deletion_days`         | Total days after TOS decline before deletion |
| `account_deletion_reminder_one` | First reminder before deletion (in days)     |
| `account_deletion_reminder_two` | Second reminder before deletion (in days)    |

---

### ✅ Acceptance Flow

1. On login, user is prompted to **accept** or **decline** TOS.
2. If **declined**:

   * `first_decline_date` is stored in user config.
   * Triggers reminders and eventual deletion via the background jobs above.
3. If **accepted later**, user’s status resets, and notifications stop.

---

### 🧪 How to run

* Manually update a user's `first_decline_date` to simulate account aging.
* Use `occ` to trigger each job:

  ```bash
  ./occ background-job:run <JobClass>
  ```

---

### 🧰 Useful Helpers

* `getUsersWhoDeclinedBefore($date)`
* `getUsersForUserValue($app, $key, $value)`
  *(Used to fetch userIds for reminders)*
