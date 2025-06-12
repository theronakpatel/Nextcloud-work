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

use Exception;
use OCA\DropAccount\Service\DeleteAccountDataService;
use OCA\TermsOfService\AppInfo\Application;
use OCA\TermsOfService\Db\Mapper\PreferenceMapper;
use OCA\TermsOfService\Db\Mapper\TermsMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class CleanUpAccountJob extends TimedJob {
	/** @var ILogger */
	private $logger;

	/** @var DeleteAccountDataService */
	private $service;

	/** @var IAppManager */
	private $appManager;

	/** @var IConfig */
	private $config;

	/** @var TermsMapper */
	private $termsMapper;

	/** @var PreferenceMapper */
	private $preferenceMapper;

	/** @var IUserManager */
	private $userManager;

	public function __construct(
		ITimeFactory $time,
		LoggerInterface $logger,
		IAppManager $appManager,
		IConfig $config,
		IUserManager $userManager,
		DeleteAccountDataService $service,
		TermsMapper $termsMapper,
		PreferenceMapper $preferenceMapper
	) {
		parent::__construct($time);
		$this->logger = $logger;
		$this->appManager = $appManager;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->service = $service;
		$this->termsMapper = $termsMapper;
		$this->preferenceMapper = $preferenceMapper;
		$this->setInterval(24 * 60 * 60);
	}


	protected function run($argument): void {
		if ($this->appManager->isEnabledForUser('drop_account')) {
			try {
				// Get the tos number of days after which user accounts will be deleted.
				$deletionDays = $this->config->getSystemValue('account_deletion_days', '');

				// If the 'account_deletion_days' configuration value is not set, throw an exception.
				if (empty($deletionDays)) {
					throw new Exception('account_deletion_days config value is not set.');
				}
			} catch (Exception $e) {
				// Log the error message and return.
				$this->logger->logException($e, ['app' => Application::APPNAME]);
				return;
			}
			//just be cover timeszone diff add one extra day
			$deletionDays = $deletionDays + 1;
			$declineDate = date('Y-m-d', strtotime(date('Y-m-d').' - '.$deletionDays.' days'));
			$userIds = $this->preferenceMapper->getUsersWhoDeclinedBefore($declineDate);
			foreach ($userIds as $userId) {
				$user = $this->userManager->get($userId);
				if ($user instanceof IUser && $user->isEnabled()) {
					try {
						$this->logger->info('Disabling the ' . $userId . ' user before deleting');
						$userDeclinedOn = $this->config->getUserValue($userId, Application::APPNAME, 'first_decline_date');
						$user->setEnabled(false);
						$this->service->delete($userId);
						$this->logger->error('Deleted user: ' . $userId . ' TOS decline date: ' . $userDeclinedOn);
					} catch (\Throwable $e) {
						// Log the error or handle the exception as needed
						$this->logger->error("Cleanup Account Job Failed to disable user with User ID: {$userId}. Error: " . $e->getMessage(), ['exception' => $e]);
					}
				}
			}

			$this->logger->info('Found and deleted all users with tos decline date of '.$declineDate.'.');
		}
	}
}
