<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\EmailRecovery\BackgroundJob;

use OCA\EmailRecovery\AppInfo\Application;
use OCA\EmailRecovery\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface;

class WeeklyRecoveryNotificationJob extends TimedJob {
	private IConfig $config;
	private IMailer $mailer;
	private LoggerInterface $logger;
	private IUserManager $userManager;
	private NotificationService $notificationService;
	private $uids = [];
	private ITimeFactory $timeFactory;
	private INotificationManager $notificationManager;
	private IURLGenerator $urlGenerator;

	public function __construct(
		IConfig $config,
		IMailer $mailer,
		IUserManager $userManager,
		LoggerInterface $logger,
		NotificationService $notificationService,
		ITimeFactory $timeFactory,
		INotificationManager $notificationManager,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($timeFactory);

		// $this->setInterval(2 * 60); // Run every 2 minutes
		// $this->setInterval(60 * 60 * 24); // Run once per day
		$this->setInterval(7 * 24 * 60 * 60); // Run for 7 days
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);

		$this->config = $config;
		$this->mailer = $mailer;
		$this->logger = $logger;
		$this->userManager = $userManager;
		$this->notificationService = $notificationService;
		$this->timeFactory = $timeFactory;
		$this->notificationManager = $notificationManager;
		$this->urlGenerator = $urlGenerator;
	}

	protected function run($argument): void {
		try {
			$this->prepareValidUserIds();
			$messageId = $this->config->getSystemValue('weekly_reminder_messageid');
			$this->sendCloudNotifications($messageId);
			$this->sendEmails($messageId);
		} catch (\Exception $e) {
			$this->logger->error('Error sending notification emails to users', ['exception' => $e]);
			return;
		}
	}

	/**
	 * Prepares valid user IDs and stores them in the 'uids' array.
	 * @return void
	 */
	private function prepareValidUserIds(): void {
		$users = $this->config->getUsersForUserValue(Application::APP_ID, 'recovery-email', '');
		foreach ($users as $username) {
			$user = $this->userManager->get($username);
			if ($this->isUserValid($user)) {
				array_push($this->uids, $user->getUID());
			}
		}
	}
	/**
	 * Send cloud notification to users
	 *
	 * @return void
	 */
	private function sendCloudNotifications(string $messageId): void {
		try {
			$datetime = $this->timeFactory->getDateTime();
			$notification = $this->notificationManager->createNotification();
			$notificationType = 'important';
			$notification->setApp(Application::APP_ID)
				->setDateTime($datetime)
				->setObject(Application::APP_ID . '-' . strtolower($notificationType), $messageId)
				->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, strtolower($notificationType) . '.svg')));

			$this->sendNotificationToUsers($messageId, $notification, $this->uids);
		} catch (\Exception $e) {
			$this->logger->error('Error sending recovery email cloud notifications to users. Error:' . $e->getMessage(), ['exception' => $e]);
		}
	}
	/**
	 * Sends a notification with the specified messageId and notification object to a list of users.
	 *
	 * @param string $messageId The identifier for the notification message.
	 * @param INotification $notification The notification object to be sent.
	 * @param array $users An array of usernames to whom the notification will be sent.
	 *
	 * @return void
	 */
	protected function sendNotificationToUsers(string $messageId, INotification $notification, array $users): void {
		foreach ($users as $username) {
			try {
				$user = $this->userManager->get($username);
				$this->sendNotificationToUser($messageId, $user, $notification);
			} catch (\Exception $e) {
				$this->logger->error('Error sending recovery email cloud notifications to users. Error:' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}
	/**
	 * Sends a notification with the specified messageId and notification object to a user.
	 *
	 * @param string $messageId The identifier for the notification message.
	 * @param IUser $user The user to whom the notification will be sent.
	 * @param INotification $notification The notification object to be sent.
	 *
	 * @return void
	 */
	protected function sendNotificationToUser(string $messageId, IUser $user, INotification $notification): void {
		$uid = $user->getUID();
		$displayName = $user->getDisplayName();
		try {
			$notification->setSubject('cli', [$messageId, $displayName])->setMessage('cli', [$messageId, $displayName])->setUser($uid);
			$this->notificationManager->notify($notification);
			$this->logger->debug('Notificaiton sent to ' . $uid . ' successfully.');
		} catch (\Exception $e) {
			$this->logger->error('Failed to send notification to ' . $uid . '. Error:' . $e->getMessage(), ['exception' => $e]);
		}
	}
	/**
	 * Sends recovery emails to all users.
	 *
	 * @param string $messageId The identifier for the notification message.
	 *
	 * @return void
	 */
	private function sendEmails(string $messageId): void {
		foreach ($this->uids as $uid) {
			try {
				$user = $this->userManager->get($uid);
				$username = $user->getDisplayName();
				$emailAddress = $user->getEMailAddress();

				$language = $this->config->getUserValue($uid, 'core', 'lang', null);
				$translations = $this->notificationService->getTranslatedSubjectAndMessage($messageId, $language);
				$subject = $translations['subject'];
				$message = $translations['message'];

				$parsedSubject = $this->notificationService->getParsedString($subject, $username);
				$subject = $parsedSubject['message'];
				$parsedMessage = $this->notificationService->getParsedString($message, $username);
				$message = $parsedMessage['message'];

				$this->sendEmail($subject, $message, $emailAddress);
				$this->logger->debug('Recovery email sent to ' . $emailAddress . ' successfully.');
			} catch (\Exception $e) {
				$this->logger->error('Error sending notification email to user ' . $uid.'. Error:' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}

	/**
	 * Send an email.
	 *
	 * @param string $subject The subject of the email.
	 * @param string $message The body/content of the email.
	 * @param string $emailAddress The recipient's email address.
	 *
	 * @return void
	 */
	private function sendEmail(string $subject, string $message, string $emailAddress): void {
		// Convert Markdown-style links to HTML anchor tags
		$message = preg_replace('/\[(.*?)\]\((.*?)\)/', "<a href='$2'>$1</a>", $message);
		$template = $this->mailer->createEMailTemplate(Application::APP_ID . '::sendMail');
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$this->setMailBody($template, $message);
		$template->addFooter();

		$email = $this->mailer->createMessage();
		$email->useTemplate($template);
		$email->setTo([$emailAddress]);

		$this->mailer->send($email);
	}
	/**
	 * Special-treat list items and strip empty lines
	 *
	 * @param IEMailTemplate $template
	 * @param string         $message
	 *
	 * @return void
	 */
	private function setMailBody(IEMailTemplate $template, string $message): void {
		$lines = explode("\n", $message);
		$finalHtml = "";
		$finalText = "";
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$finalHtml .= "<p>" . $line . "</p>";
			$finalText .= $line;
		}
		$template->addBodyText($finalHtml, $finalText);
	}
	/**
	 * Validate a user.
	 *
	 * @param IUser|null $user The user to be validated.
	 *
	 * @return bool Returns true if the user is valid, false otherwise.
	 */
	private function isUserValid(?IUser $user): bool {
		if (!($user instanceof IUser)) {
			return false;
		}
		$emailAddress = $user->getEMailAddress();
		return ($emailAddress && $user->isEnabled() && $this->mailer->validateMailAddress($emailAddress));
	}
}
