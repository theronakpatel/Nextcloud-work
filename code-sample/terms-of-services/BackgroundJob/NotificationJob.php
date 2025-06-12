<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2023 MURENA SAS <dev@murena.io>
 *
 * @author Ronak Patel <ronak.patel.ext@murena.io>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\TermsOfService\BackgroundJob;

use OCA\TermsOfService\AppInfo\Application;
use OCA\TermsOfService\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

class NotificationJob extends QueuedJob {
	/** @var IConfig */
	protected $config;
	/** @var IManager */
	private $notificationsManager;
	/** @var LoggerInterface */
	private $logger;
	/** @var IUserManager */
	private $userManager;
	private $notificationService;

	public function __construct(
		IConfig $config,
		IManager $notificationsManager,
		ITimeFactory $time,
		LoggerInterface $logger,
		NotificationService $notificationService,
		IUserManager  $userManager
	) {
		parent::__construct($time);
		$this->config = $config;
		$this->notificationsManager = $notificationsManager;
		$this->logger = $logger;
		$this->userManager = $userManager;
		$this->notificationService = $notificationService;
	}

	/**
	 * @param array $argument
	 */
	public function run($argument): void {
		try {
			$notification = $this->notificationsManager->createNotification();
			$notification->setApp('terms_of_service')
				->setSubject('accept_terms')
				->setObject('terms', '1');

			// Mark all notifications as processed …
			$this->notificationsManager->markProcessed($notification);
			$notification->setDateTime(new \DateTime());

			// … so we can create new ones for every one, also users which already accepted.
			$this->userManager->callForSeenUsers(function (IUser $user) use ($notification) {
				$notification->setUser($user->getUID());
				$this->notificationsManager->notify($notification);
			});
		} catch (\Exception $e) {
			$this->logger->error('Error thrown while sending notification.', ['exception' => $e]);
			$this->logger->logException($e, ['app' => Application::APPNAME]);
			return;
		}
	}
}
