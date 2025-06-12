# Nextcloud Custom Notifications App

## Overview

A custom Notifications app was developed to deliver internal messages like news, announcements, or platform updates to customers. This system allows administrators to notify users through the Nextcloud interface without requiring any frontend or user interface (UI). Notifications are triggered solely through a CLI command (`occ`).

## Why This Was Done

Nextcloud does provide a notification system, but for specific use cases like sending periodic news or updates to users without developing a complex UI, a simpler, backend-driven solution was needed.

This custom app fulfills that gap with flexibility to:

* Send custom messages
* Target specific users or groups (if needed later)
* Easily integrate into scheduled cron jobs or deployment pipelines

## How It Works

The app consists of two main parts:

### 1. `Command/Announce.php`

* An OCC command that can be run manually or scheduled via cron
* Example: `php occ notify:announce --title="Update" --message="System maintenance at midnight."`
* Handles input validation and dispatches messages to the service

Implements message filtering, validation, and dispatch based on CLI flags:

* `--cloud-only` or `--mail-only` restrict delivery method
* `--users`, `--groups` specify recipients
* `--message-id` identifies the content block to be sent

It triggers appropriate jobs or services:

* Adds email notification job to the job list if not `--cloud-only`
* Calls `sendNotificationToUsers`, `sendNotificationToGroups`, or `sendNotificationToEveryone` if not `--mail-only`

Includes error handling for email validity, message ID translation validation, and user/group lookup.

```
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$cloudOnly = $mailOnly = false;
		if ($input->hasParameterOption(['--cloud-only', '-c'], true)) {
			$cloudOnly = true;
		}

		if ($input->hasParameterOption(['--mail-only', '-m'], true)) {
			$mailOnly = true;
		}
		if ($mailOnly && $cloudOnly) {
			$output->writeln('--mail-only and --cloud-only cannot be used together. Please specify only one or omit them.');
			return 1;
		}

		$messageId = $input->getOption('message-id');
		if (is_null($messageId)) {
			$output->writeln('This command requires the --message-id.');
			$output->writeln('Example: ./occ murena-notifications:announce --message-id="MESSAGE_KEY"');
			return 1;
		}
		try {
			$this->validateMessageID($messageId);
		} catch (\Exception $e) {
			$output->writeln($e->getMessage());
			return 1;
		}

		$notificationType = ($input->getOption('message-type')) ? $input->getOption('message-type') : 'default';
		if (!in_array($notificationType, self::NOTIFICATION_TYPES)) {
			$output->writeln('Invalid notification type. Correct values are ' . implode(',', self::NOTIFICATION_TYPES));
			return 1;
		}

		$sender = ($input->getOption('sender-name')) ? $input->getOption('sender-name') : 'admin';
		$groups = ($input->getOption('groups')) ? $input->getOption('groups') : '';
		$users = ($input->getOption('users')) ? $input->getOption('users') : '';
		if ($users === '' && $groups === '') {
			$output->writeln('This command requires either --users or --groups.');
			$output->writeln('To send everyone use `everyone` or `all`., e.g., --users="everyone" OR --users="all".');
			return 1;
		}
		if (strlen($users) > 4000) {
			$output->writeln('--users data cannot have more than 4000 length');
			return 1;
		}

		if (!$cloudOnly) {
			$this->jobList->add(EmailNotificationJob::class, [
				'users' => $users,
				'groups' => $groups,
				'messageId' => $messageId
			]);
		}

		if (!$mailOnly) {
			$datetime = $this->timeFactory->getDateTime();
			$notification = $this->notificationManager->createNotification();

			$notification->setApp(Application::APP_ID)
				->setDateTime($datetime)
				->setObject(Application::APP_ID . '-' . strtolower($notificationType), $messageId)
				->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, strtolower($notificationType) . '.svg')));

			if ($groups !== '') {
				$groupsArray = explode(',', $groups);
				$this->sendNotificationToGroups($sender, $messageId, $notification, $groupsArray);
			}
			if ($users !== '') {
				if ('everyone' === $users || 'all' === $users) {
					$this->sendNotificationToEveryone($sender, $messageId, $notification);
				} else {
					$usersArray = explode(',', $users);
					$this->sendNotificationToUsers($sender, $messageId, $notification, $usersArray);
				}
			}
		}

		return true;
	}
	/**
	 * @param string $authorId
	 * @param string $messageId
	 * @param INotification $notification
	 * @param array $users
	 */
	protected function sendNotificationToUsers(string $authorId, string $messageId, INotification $notification, array $users): void {
		foreach ($users as $username) {
			$user = $this->userManager->get($username);
			if (!($user instanceof IUser)) {
				continue;
			}
			$this->sendNotificationToUser($messageId, $user, $notification);
			$emailAddress = $user->getEMailAddress();
			if ($emailAddress && $user->isEnabled()) {
				if (!$this->mailer->validateMailAddress($emailAddress)) {
					$this->logger->warning('User has no valid email address: ' . $user->getUID());
					return;
				}
			}
		}
	}
	/**
	 * @param string $authorId
	 * @param string $messageId
	 * @param INotification $notification
	 */
	protected function sendNotificationToEveryone(string $authorId, string $messageId, INotification $notification): void {
		$this->userManager->callForSeenUsers(function (IUser $user) use ($authorId, $messageId, $notification) {
			if (!($user instanceof IUser)) {
				return;
			}
			if ($authorId !== $user->getUID()) {
				$this->sendNotificationToUser($messageId, $user, $notification);
				$emailAddress = $user->getEMailAddress();
				if ($emailAddress && $user->isEnabled()) {
					if (!$this->mailer->validateMailAddress($emailAddress)) {
						$this->logger->warning('User has no valid email address: ' . $user->getUID());
						return;
					}
				}
			}
		});
	}

	/**
	 * @param string $authorId.
	 * @param string $messageId
	 * @param INotification $notification
	 * @param string[] $groups
	 */
	protected function sendNotificationToGroups(string $authorId, string $messageId, INotification $notification, array $groups): void {
		foreach ($groups as $gid) {
			$group = $this->groupManager->get($gid);
			if (!($group instanceof IGroup)) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				if ($authorId === $user->getUID()) {
					continue;
				}
				$this->sendNotificationToUser($messageId, $user, $notification);
				$emailAddress = $user->getEMailAddress();
				if ($emailAddress && $user->isEnabled()) {
					if (!$this->mailer->validateMailAddress($emailAddress)) {
						$this->logger->warning('User has no valid email address: ' . $user->getUID());
						return;
					}
				}
			}
		}
	}
	/**
	 * @param string $messageId
	 * @param IUser $user
	 * @param INotification $notification
	 */
	protected function sendNotificationToUser(string $messageId, IUser $user, INotification $notification): void {
		try {
			$uid = $user->getUID();
			$username = $user->getDisplayName();
			$notification->setSubject('cli', [$messageId, $username])->setMessage('cli', [$messageId, $username])->setUser($uid);
			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}
	/**
	 * @param string $messageId
	 */
	public function validateMessageID(string $messageId) {
		foreach (self::USER_LANGUAGES as $language) {
			$translations = $this->NotificationService->getTranslatedSubjectAndMessage($messageId, $language);
			if ($messageId . '_subject' === $translations['subject']) {
				throw new \Exception('Please provide ' . $messageId . '_subject ' . 'translation in ' . $language . ' language');
			}
			if ($messageId . '_body' === $translations['message']) {
				throw new \Exception('Please provide ' . $messageId . '_body ' . 'translation in ' . $language . ' language');
			}
		}
		return true;
	}
```

### 2. `Service/NotificationService.php`

* Uses Nextcloud’s notification backend API
* Prepares and formats message strings with rich placeholders for bold text, links, and usernames
* Supports localization using Nextcloud’s l10n system

Important methods include:

* `getParsedString($message, $username)`: Handles formatting for rich strings
* `assignVariables()`: Resolves placeholders and formats them for output
* `getTranslatedSubjectAndMessage()`: Retrieves localized versions of subject and body based on message ID
```
	/**
	 * @param string $message
	 * @param string $username
	 */
	public function getParsedString(string $message, string $username, string $notificationType = 'notification') {
		$richString = $message;

		$data = $this->prepareRichString($message, $richString, $username, 'url' , $notificationType);
		$message = $data['message'];
		$richString = $data['richString'];

		$data = $this->prepareRichString($message, $richString, $username, 'username' , $notificationType);
		$message = $data['message'];
		$richString = $data['richString'];

		$data = $this->prepareRichString($message, $richString, $username, 'bold' , $notificationType);
		$message = $data['message'];
		$richString = $data['richString'];

		return $this->assignVariables($message, $richString, $username);
	}
	/**
	 * @param string $message
	 * @param string $richString
	 * @param string $username
	 * @param string $type
	 */
	private function prepareRichString($message, $richString, $username, $type, $notificationType = 'notification') {
		switch ($type) {
			case 'url':
				$richString = preg_replace('/\[(.*?)\]\((.*?)\)/', '{$1[$2]}', $message);
				break;

			case 'bold':
				if ($notificationType === 'email') {
					$message = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $message);
				} else {
					preg_match_all('/\*\*(.*?)\*\*/', $message, $matches);
					if (!empty($matches[1])) {
						foreach ($matches[1] as $match) {
							$richString = str_replace('**' . $match . '**', '{' . $match . '}', $richString);
							$message = str_replace('**' . $match . '**', $match, $message);
						}
					}
				}
				break;

			case 'username':
				$richString = str_replace('{username}', $username, $richString);
				$message = str_replace('{username}', $username, $message);
				break;
		}
		return ['message' => $message, 'richString' => $richString];
	}
	/**
	 * @param string $message
	 * @param string $richString
	 * @param string $username
	 */
	private function assignVariables($message, $richString, $username) {
		preg_match_all('/{(.*?)}/', $richString, $matches);
		$messageParameters = [];
		if (sizeof($matches)) {
			foreach ($matches[1] as $key => $match) {
				$result = $this->checkURL($match, $richString);
				$link = $result['link'];
				$match = $result['match'];
				$richString = $result['richString'];
				$matchKey = 'key_' . $key;

				$messageParameters[$matchKey] =
					[
						'type' => ($match === 'username') ? 'user' : 'highlight',
						'id' => '',
						'name' => ($match === 'username') ? $username : $match,
						'link' => isset($link) ? $link : ''
					];

				$richString = str_replace($match, $matchKey, $richString);
			}
		}
		$placeholders = $replacements = [];
		foreach ($messageParameters as $placeholder => $parameter) {
			$placeholders[] = '{' . $placeholder . '}';
			$replacements[] = $parameter['name'];
		}
		$message = str_replace($placeholders, $replacements, $message);

		return ['message' => $message, 'richString' => $richString, 'parameters' => $messageParameters];
	}

	/**
	 * @param string $match
	 * @param string $richString
	 */
	private function checkURL($match, $richString) {
		preg_match('#\[(.*?)\]#', $match, $result);
		$link = (sizeof($result)) ? $result[1] : '';

		if (isset($link)) {
			$match = str_replace('[' . $link . ']', '', $match);
			$richString = str_replace('[' . $link . ']', '', $richString);
		}
		return ['link' => $link, 'match' => $match, 'richString' => $richString];
	}

	/**
	 * @param string $messageId
	 * @param string|null $language
	 */
	public function getTranslatedSubjectAndMessage(string $messageId, $language) {
		if (is_null($language)) {
			$language = 'en';
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		return ['subject' => $l->t($messageId . '_subject'), 'message' => $l->t($messageId . '_body')];
	}
```

## Sample Use Case

To notify all users of an upcoming update in English and French:

1. Add translations for `update_notice_subject` and `update_notice_body` in both languages
2. Run the OCC command:

   ```sh
   php occ notify:announce --message-id="update_notice" --users="all"
   ```
## File Locations

* Command Logic: `Command/Announce.php`
* Notification Preparation: `Service/NotificationService.php`
