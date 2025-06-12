<?php

declare(strict_types=1);

/**
 * Terms Of Service App
 *
 * @copyright Copyright (c) 2023 MURENA SAS <dev@murena.io>
 *
 * @author Ronak Patel <ronak.patel.ext@murena.io>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\TermsOfService\BackgroundJob;

use OCA\TermsOfService\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Util;
use Psr\Log\LoggerInterface;

class SendReminderInAdvanceMailJob extends TimedJob {
	/** @var ITimeFactory */
	protected $timeFactory;

	/** @var ILogger */
	private $logger;

	/** @var IMailer */
	private $mailer;

	/** @var IL10N */
	private $l10n;

	/** @var IUserManager */
	private $userManager;

	/** @var IConfig */
	private $config;

	/** @var INotificationManager */
	protected $notificationManager;

	/** @var IFactory */
	protected $l10nFactory;

	/** @var Defaults */
	private $defaults;

	public function __construct(
		ITimeFactory $timeFactory,
		IMailer $mailer,
		IL10N $l10n,
		IUserManager $userManager,
		LoggerInterface $logger,
		IConfig $config,
		INotificationManager $notificationManager,
		IFactory $l10nFactory,
		Defaults $defaults
	) {
		parent::__construct($timeFactory);
		$this->logger = $logger;
		$this->mailer = $mailer;
		$this->l10n = $l10n;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->timeFactory = $timeFactory;
		$this->l10nFactory = $l10nFactory;
		$this->defaults = $defaults;
		$this->setInterval(24 * 60 * 60);
	}


	protected function run($arguments) {
		$userIds = $this->getUserIdsToDelete();
		$cloudName = $this->defaults->getName();
		$deletionDays = $this->config->getSystemValue('account_deletion_days', '');
		foreach ($userIds as $userId) {
			$user = $this->userManager->get($userId);
			if ($user instanceof IUser && $user->isEnabled()) {
				try {
					$email = $user->getEMailAddress();
					$displayName = $user->getDisplayName();
					$language = $this->config->getUserValue($userId, 'core', 'lang', null);

					$firstDeclineDate = $this->config->getUserValue($userId, Application::APPNAME, 'first_decline_date', null);
					$expiryDate = '';
					if (!empty($firstDeclineDate)) {
						$expiryDate = date('F j, Y', strtotime($firstDeclineDate. ' + '.$deletionDays.' days'));
					}
					if (is_null($language)) {
						$language = 'en';
					}
					$this->l10n = $this->l10nFactory->get(Application::APPNAME, $language);
					$expiryDateTrans = $this->l10n->l('date', strtotime($expiryDate));
					$datetime = $this->timeFactory->getDateTime();
					$notification = $this->notificationManager->createNotification();
					$notification->setApp(Application::APPNAME)
								->setDateTime($datetime)
								->setObject(Application::APPNAME, $userId);
					$notificationSubject = "We're sorry to see you go!";
					$notificationMsg = "We noticed you declined our Terms of Service, which means that your %s Cloud account is scheduled to be deleted on %s.\nBefore you go, we wanted to remind you to {back up any important data} you may have in your account. Once your account is deleted, your data will be lost forever.\nIf you've had a change of heart and want to continue using %s Cloud, all you need to do is log into your account and accept our Terms of Service. We'd be thrilled to have you back!";

					$this->sendNotificationToUser($user, $notification, $notificationSubject, $notificationMsg);
					$title = $this->l10n->t("We're sorry to see you go!");
					$emailTemplate = $this->mailer->createEMailTemplate(Application::APPNAME . '::sendAccountDeletionMail');
					$emailTemplate->setSubject($title);
					$emailTemplate->addHeader();
					$emailTemplate->addHeading($title);
					$plainMessage = $this->l10n->t("Hi  %s,\nWe wanted to reach out and let you know that we noticed you declined our Terms of Service, which means that your %s Cloud account is scheduled to be deleted on %s. We understand that our terms may not be a fit for everyone, and we're sorry to see you go.\nBut before you go, we wanted to remind you to back up any important data you may have in your account. Once your account is deleted, your data will be lost forever.\nIf you've had a change of heart and want to continue using %s Cloud, all you need to do is log into your account and accept our Terms of Service. We'd be thrilled to have you back!\nIf you have any feedback or questions, please don't hesitate to reach out. We're here to help.\nThanks for giving %s Cloud a try, and we wish you all the best!\nWarm regards,\nThe %s Team", array($displayName,$cloudName,$expiryDateTrans,$cloudName,$cloudName,$cloudName));
					$this->setMailBody($emailTemplate, $plainMessage);
					$emailTemplate->addFooter();
					try {
						$message = $this->mailer->createMessage();
						$message->setFrom([Util::getDefaultEmailAddress('noreply')]);
						$message->setTo([$email]);
						$message->useTemplate($emailTemplate);
						$this->mailer->send($message);
					} catch (\Throwable $e) {
						$this->logger->error($e->getMessage(), ['exception' => $e]);
					}
				} catch (\Throwable $e) {
					// Log the error or handle the exception as needed
					$this->logger->error("Send Reminder In Advance Job Failed to notify user with ID: {$userId}. Error: " . $e->getMessage(), ['exception' => $e]);
				}
			}
		}
	}

	/**
	 * Special-treat multiple lines and strip empty lines
	 *
	 * @param IEMailTemplate $template
	 * @param string         $message
	 *
	 * @return void
	 */
	protected function setMailBody(IEMailTemplate $template, string $message): void {
		$lines = explode("\n", $message);
		$finalHtml = "";
		$finalText = "";
		foreach ($lines as $line) {
			if (trim($line) === '') {
				continue;
			}
			$finalHtml .= "<p>".$line."</p>";
			$finalText .= $line;
		}
		$template->addBodyText($finalHtml, $finalText);
	}

	/**
	 * @param IUser $user
	 * @param INotification $notification
	 * @param string $notificationSubject
	 * @param string $notificationMsg
	 * @param string $expiryDateTrans
	 * @param string $cloudName
	 *
	 * @return void
	 */
	protected function sendNotificationToUser(IUser $user, INotification $notification, string $notificationSubject, string $notificationMsg): void {
		try {
			$uid = $user->getUID();
			$username = $user->getDisplayName();
			$notification->setSubject('reminder', [$notificationSubject, $username])->setMessage('reminder', [$notificationMsg, $username])->setUser($uid);

			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 *
	 *
	 * @return string[] an array of all userIds
	 */
	private function getUserIdsToDelete(): array {
		$deletionDays = $this->config->getSystemValue('account_deletion_days', '');
		$firstReminderDays = $this->config->getSystemValue('account_deletion_reminder_one', '');
		$secondReminderDays = $this->config->getSystemValue('account_deletion_reminder_two');

		// Check if the account deletion days and the first reminder days are set. If not, log an error and return.
		if (empty($deletionDays)) {
			$this->logger->error('account_deletion_days config value is not set.');
			return array();
		}

		if (empty($firstReminderDays)) {
			$this->logger->error('account_deletion_reminder_one config value is not set.');
			return array();
		}

		// Calculate the date of the first decline based on the deletion days and the first reminder days.
		$firstReminderDaysLeft = $deletionDays - $firstReminderDays;
		$firstDeclineDate = date('Y-m-d', strtotime(date('Y-m-d') . ' - ' . $firstReminderDaysLeft . ' days'));

		// Get the user IDs with the first decline date.
		$firstDeclinedUserIds = $this->config->getUsersForUserValue(Application::APPNAME, 'first_decline_date', $firstDeclineDate);

		// Calculate the date of the second decline based on the deletion days and the second reminder days.
		$secondReminderDaysLeft = $deletionDays - $secondReminderDays;
		$secondDeclineDate = date('Y-m-d', strtotime(date('Y-m-d') . ' - ' . $secondReminderDaysLeft . ' days'));

		// Get the user IDs with the second decline date and merge them with the user IDs with the first decline date.
		$secondDeclinedUserIds = $this->config->getUsersForUserValue(Application::APPNAME, 'first_decline_date', $secondDeclineDate);
		$userIds = array_merge($firstDeclinedUserIds, $secondDeclinedUserIds);
		return $userIds;
	}
}
