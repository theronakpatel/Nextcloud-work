# Email Reovery App

### 📌 Purpose

* Every user **must set a recovery email**.
* If the recovery email is **not verified**, a **reminder notification and email** is sent **every 7 days**.
* Verification is done via a link sent to the email, valid for 30 minutes.

---

### 🔄 `WeeklyRecoveryNotificationJob.php`

1. **`run()`**

   * Gathers users without a verified recovery email.
   * Sends **cloud notification** + **email** to those users.

2. **`prepareValidUserIds()`**

   * Finds users where `'recovery-email'` is an empty string.
   * Validates if they are enabled and have a valid email address.

3. **`sendCloudNotifications()` / `sendNotificationToUser()`**

   * Sends Nextcloud-style UI notifications to the user.

4. **`sendEmails()`**

   * Translates and personalizes the reminder message.
   * Sends using HTML email with parsed subject/body placeholders.

5. **Markdown to HTML Conversion**

   * The message body uses regex to convert `[text](link)` to `<a href>`.

---

### 🛠 `RecoveryEmailService.php`

1. **Restrict / Unrestrict email**

   * Calls external service to manage email restriction (e.g., limiting unverified users).

2. **`generateVerificationEmailTemplate()`**

   * Creates an HTML email with a verification link.
   * Includes recovery link, language-based translation, and custom branding.

3. **`isAliasedRecoveryEmailValid()`**

   * Handles `+alias` email addresses.
   * Ensures users can’t spam alias registrations (e.g., `user+test1@domain.com`).
   * Limit is defined via `recovery_email_alias_limit` (default `5`).

4. **`updateRecoveryEmail()`**

   * Stores email in the unverified field.
   * Clears verified email until confirmation.

5. **`sendVerificationEmail()`**

   * Sends email with verification token and recovery email to the user.

---

## 💡 Suggested Improvements or Things to Consider

### 1. **Tracking Last Reminder Date**

To avoid spamming the same user:

* Add `last_reminder_sent` in `preferences` (e.g., as `getUserValue`/`setUserValue`) to store timestamp.
* Only send weekly email if **last sent > 7 days ago**.

```php
$lastReminder = $this->config->getUserValue($uid, Application::APP_ID, 'last_reminder_sent', 0);
if ((int)$lastReminder + (7 * 86400) < time()) {
    $this->sendEmail(...);
    $this->config->setUserValue($uid, Application::APP_ID, 'last_reminder_sent', (string)time());
}
```

---

### 2. **Improve Link Expiry Security**

Right now, the link expiry is just mentioned in the email body. If you haven’t already:

* Ensure server-side token check validates expiration (based on generated timestamp).

---

### 3. **Cloud Notification Redundancy**

You're sending both email and cloud notification. Consider:

* Making this **configurable**, or
* Skip cloud notification for users who **never log into the web interface**.

---

### 4. **Use Background Queue (optional)**

If the number of users is large, processing could become slow:

* You could split email sending into a queue (e.g., using `IJobList` or cron queue jobs).

---

### 5. **Throttle Email Service Errors**

In `sendEmails()`, you try to send even if the email address is invalid (e.g., domain issues).

* You already do this check in `isUserValid()` — good.
* Consider logging to a different channel (or notify admin) if a large number fail.

---

### 6. **Support Resending Verification Manually**

Optional: add a controller endpoint to manually **resend verification email**.
This can be useful for frontend or admin tools.

```php
POST /apps/email_recovery/resend-verification
{
  "user": "alice",
  "recoveryEmail": "alice@domain.com"
}
```

---

## ✅ Code Quality Observations

* You’re using proper dependency injection and class structure ✅
* Markdown to HTML link conversion is simple and effective ✅
* The use of `$this->notificationService->getTranslatedSubjectAndMessage()` makes it multilingual-ready ✅
* Validation of `+alias` emails is thoughtful and guards abuse ✅
