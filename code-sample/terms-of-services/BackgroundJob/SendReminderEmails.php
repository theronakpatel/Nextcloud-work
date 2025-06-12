<?php

declare(strict_types=1);

/**
 * Terms Of Service App
 *
 * @copyright Copyright (c) 2023 MURENA SAS <dev@murena.io>
 *
 * @author Ronak Patel <ronak.patel.ext@murena.com>
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

use OCP\BackgroundJob\TimedJob;
use OCA\TermsOfService\Service\AccountReactivationRemindersService;
use OCP\AppFramework\Utility\ITimeFactory;

class SendReminderEmails extends TimedJob
{
    private $remindersService;

    public function __construct(
        ITimeFactory $time,
        AccountReactivationRemindersService $remindersService
    ) {
        parent::__construct($time);
        $this->remindersService = $remindersService;
        // Run monthly
        $this->setInterval(86400 * 30);
    }

    protected function run($argument)
    {
        $this->remindersService->sendRemindersToDisabledAccounts();
    }
}
