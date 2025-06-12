<?php

declare(strict_types=1);

namespace OCA\EmailRecovery\Service;

use Exception;
use OCA\EmailRecovery\Exception\BlacklistedEmailException;
use OCA\EmailRecovery\Exception\InvalidRecoveryEmailException;
use OCA\EmailRecovery\Exception\MurenaDomainDisallowedException;
use OCA\EmailRecovery\Exception\RecoveryEmailAlreadyFoundException;
use OCA\EmailRecovery\Exception\SameRecoveryEmailAsEmailException;
use OCA\EmailRecovery\Exception\TooManyVerificationAttemptsException;
use OCA\EmailRecovery\Db\ConfigMapper;
use OCP\Defaults;
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ILogger;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Security\VerificationToken\IVerificationToken;
use OCP\Util;
use OCA\EcloudAccounts\Service\LDAPConnectionService;

class RecoveryEmailService {
	private ILogger $logger;
	private IConfig $config;
	private string $appName;
	private IUserManager $userManager;
	private IMailer $mailer;
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;
	private Defaults $themingDefaults;
	private IVerificationToken $verificationToken;
	private ICacheFactory $cacheFactory;
	private CurlService $curl;
	private IClientService $httpClientService;
	private ConfigMapper $configMapper;
	private array $apiConfig;
	protected const TOKEN_LIFETIME = 60 * 30; // 30 minutes
	private const ATTEMPT_KEY = "recovery_email_attempts";
	private const CACHE_KEY = 'recovery_email_rate_limit';
	private const VERIFYMAIL_API_URL = 'https://verifymail.io/api/%s?key=%s';
	private const RATE_LIMIT_EMAIL = 'verifymail_email_ratelimit';
	private const RATE_LIMIT_DOMAIN = 'verifymail_domain_ratelimit';
	private $cache;

	private DomainService $domainService;
	private IL10N $l;
	private ISession $session;
	private LDAPConnectionService $LDAPConnectionService;

	public function __construct(string $appName, ILogger $logger, IConfig $config, ISession $session, IUserManager $userManager, IMailer $mailer, IFactory $l10nFactory, IURLGenerator $urlGenerator, Defaults $themingDefaults, IVerificationToken $verificationToken, CurlService $curlService, DomainService $domainService, IL10N $l, ICacheFactory $cacheFactory, IClientService $httpClientService, ConfigMapper $configMapper, LDAPConnectionService $LDAPConnectionService) {
		$this->logger = $logger;
		$this->config = $config;
		$this->appName = $appName;
		$this->session = $session;
		$this->userManager = $userManager;
		$this->mailer = $mailer;
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
		$this->themingDefaults = $themingDefaults;
		$this->verificationToken = $verificationToken;
		$this->curl = $curlService;
		$this->domainService = $domainService;
		$this->httpClientService = $httpClientService;
		$this->l = $l;
		$this->LDAPConnectionService = $LDAPConnectionService;
		$this->cacheFactory = $cacheFactory; // Initialize the cache factory
		$this->cache = $this->cacheFactory->createDistributed(self::CACHE_KEY); // Initialize the cache
		$this->configMapper = $configMapper;
		$commonServiceURL = $this->config->getSystemValue('common_services_url', '');

		if (!empty($commonServiceURL)) {
			$commonServiceURL = rtrim($commonServiceURL, '/') . '/';
		}
		$this->apiConfig = [
			'commonServicesURL' => $commonServiceURL,
			'commonServicesToken' => $this->config->getSystemValue('common_services_token', ''),
			'commonApiVersion' => $this->config->getSystemValue('common_api_version', '')
		];
	}
	public function setRecoveryEmail(string $username, string $value = '') : void {
		$this->config->setUserValue($username, $this->appName, 'recovery-email', $value);
	}
	public function getRecoveryEmail(string $username) : string {
		return $this->config->getUserValue($username, $this->appName, 'recovery-email', '');
	}
	public function setUnverifiedRecoveryEmail(string $username, string $value = '') : void {
		$this->config->setUserValue($username, $this->appName, 'unverified-recovery-email', $value);
	}
	public function getUnverifiedRecoveryEmail(string $username) : string {
		return $this->config->getUserValue($username, $this->appName, 'unverified-recovery-email', '');
	}
	public function deleteUnverifiedRecoveryEmail(string $username) : void {
		$this->config->deleteUserValue($username, $this->appName, 'unverified-recovery-email');
	}
	public function limitVerficationEmail(string $username, string $recoveryEmail) : bool {
		$user = $this->userManager->get($username);
		$email = $user->getEMailAddress();

		$attempts = $this->session->get(self::ATTEMPT_KEY);
		if (!is_array($attempts)) {
			$attempts = [];
		}
		$currentTime = time();

		// Filter out attempts older than 1 hour (3600 seconds)
		$attempts = array_filter($attempts, function ($attemptTime) use ($currentTime) {
			return ($currentTime - $attemptTime) <= 3600;
		});

		if (count($attempts) >= 3) {
			$this->logger->info("User ID $username has exceeded the maximum number of verification attempts.");
			throw new TooManyVerificationAttemptsException();
		}
		$attempts[] = $currentTime;
		$this->session->set(self::ATTEMPT_KEY, $attempts);
		
		return true;
	}
	public function validateRecoveryEmail(string $recoveryEmail, string $username = '', string $language = 'en'): bool {
		if (empty($recoveryEmail)) {
			return true;
		}
		// Fetch user email if username is provided
		$email = $this->getUserEmail($username);
	
		$l = $this->l10nFactory->get($this->appName, $language);
		$this->enforceBasicRecoveryEmailRules($recoveryEmail, $username, $email, $l);
	
		$apiKey = $this->config->getSystemValue('verify_mail_api_key', '');

		if (empty($apiKey)) {
			$this->logger->info('VerifyMail API Key is not configured.');
		}
	
		if ($this->domainService->isDomainInCustomBlacklist($recoveryEmail, $l)) {
			//throw new \Exception($l->t('The provided email domain is a disposable domain and cannot be used.'));
			throw new BlacklistedEmailException($l->t('The email address is disposable. Please provide another recovery address.'));
		}
		   
		// Check if the domain is a popular domain
		if ($this->domainService->isPopularDomain($recoveryEmail, $l)) {
			// Skip domain verification and directly validate the email
			$this->ensureRealTimeRateLimit(self::RATE_LIMIT_EMAIL, 2, $l);
			$this->ensureEmailIsValid($recoveryEmail, $username, $apiKey, $l);
		} else {
			// Verify the domain using the API
			$this->ensureRealTimeRateLimit(self::RATE_LIMIT_DOMAIN, 15, $l);
			$domain = substr(strrchr($recoveryEmail, "@"), 1);
			$this->verifyDomainWithApi($domain, $username, $apiKey, $l);
			// If domain verification succeeds, validate the email
			$this->ensureRealTimeRateLimit(self::RATE_LIMIT_EMAIL, 2, $l);
			$this->ensureEmailIsValid($recoveryEmail, $username, $apiKey, $l);
		}
		return true;
	}
	private function getUserEmail(string $username): string {
		if (empty($username)) {
			return '';
		}
		$user = $this->userManager->get($username);
		return $user->getEMailAddress();
	}

	private function enforceBasicRecoveryEmailRules(string $recoveryEmail, string $username, string $email, IL10N $l): void {
		if (!$this->isValidEmailFormat($recoveryEmail)) {
			$this->logger->info("User $username's requested recovery email does not match email format");
			throw new InvalidRecoveryEmailException($l->t('Invalid Recovery Email'));
		}
	
		if (!empty($email) && strcmp($recoveryEmail, $email) === 0) {
			$this->logger->info("User ID $username's requested recovery email is the same as email");
			throw new SameRecoveryEmailAsEmailException($l->t('Error! User email address cannot be saved as recovery email address!'));
		}
	
		if ($this->isRecoveryEmailTaken($username, $recoveryEmail)) {
			$this->logger->info("User ID $username's requested recovery email address is already taken");
			throw new RecoveryEmailAlreadyFoundException($l->t('Recovery email address is already taken.'));
		}

		if (!$this->isAliasedRecoveryEmailValid($username, $recoveryEmail)) {
			$this->logger->info("User ID $username's requested recovery extended email address is already taken");
			throw new RecoveryEmailAlreadyFoundException($l->t('This email address in invalid, please use another one.'));
		}
	
		if ($this->isRecoveryEmailDomainDisallowed($recoveryEmail)) {
			$this->logger->info("User ID $username's requested recovery email address is disallowed.");
			throw new MurenaDomainDisallowedException($l->t('You cannot set an email address with a Murena domain as recovery email address.'));
		}
	
		if ($this->domainService->isBlacklistedDomain($recoveryEmail, $l)) {
			$this->logger->info("User ID $username's requested recovery email address domain is blacklisted.");
			throw new BlacklistedEmailException($l->t('The domain of this email address is blacklisted. Please provide another recovery address.'));
		}
	}

	private function retryApiCall(callable $callback, IL10N $l, int $maxRetries = 10, int $initialInterval = 1000): void {
		$retryInterval = $initialInterval; // Initial retry interval in milliseconds
		$retries = 0;
	
		while ($retries < $maxRetries) {
			try {
				// Execute the API call
				$result = $callback();
	
				// If successful, return immediately
				return;
			} catch (\Exception $e) {
				// Check for rate-limiting (HTTP 429)
				if ($e instanceof \RuntimeException && $e->getCode() === 429) {
					$retries++;
	
					if ($retries >= $maxRetries) {
						throw new \RuntimeException($l->t('The email could not be verified. Please try again later.'));
					}
	
					$this->logger->warning("Received 429 status code, retrying in $retryInterval ms...");
					usleep($retryInterval * 1000); // Convert to microseconds
					$retryInterval *= 2; // Exponential backoff
					continue; // Retry only on 429 errors
				}
	
				// For other exceptions, log and rethrow immediately without retrying
				$this->logger->error("API call failed on the first attempt. Error: " . $e->getMessage());
				throw $e;
			}
		}
	
		// Shouldn't reach here since retries are handled above
		throw new \RuntimeException("API call failed unexpectedly after maximum retries.");
	}
	
	private function ensureEmailIsValid(string $recoveryEmail, string $username, string $apiKey, IL10N $l): void {
		$url = sprintf(self::VERIFYMAIL_API_URL, $recoveryEmail, $apiKey);
	
		$this->retryApiCall(function () use ($url, $username, $l) {
			try {
				$httpClient = $this->httpClientService->newClient();
				// Make the API request
				$response = $httpClient->get($url, [
					'timeout' => 15, // Timeout for the API call
				]);
	
				// Process response, handle errors (e.g., disposable email, non-deliverable email)
				$responseBody = $response->getBody(); // Get the response body as a string
				$data = json_decode($responseBody, true);
				
				if ($data['disposable'] ?? false) {
					$this->logger->info("User ID $username's requested recovery email address is disposable.");
					throw new BlacklistedEmailException($l->t('The email address is disposable. Please provide another recovery address.'));
				}
	
				if (!$data['deliverable_email'] ?? true) {
					$this->logger->info("User ID $username's requested recovery email address is not deliverable.");
					throw new BlacklistedEmailException($l->t('The email address is not deliverable. Please provide another recovery address.'));
				}
			} catch (Exception $e) {
				// Optionally handle specific exceptions if needed here (e.g., timeouts, network errors)
				$this->logger->error("Error while validating email for user $username: " . $e->getMessage());
				throw $e; // Re-throw if necessary
			}
		}, $l,         // Pass the IL10N object
			10,          // Optional: Max retries (default is 10, override if necessary)
			1000);
	}
	

	
	private function verifyDomainWithApi(string $domain, string $username, string $apiKey, IL10N $l): void {
		$url = sprintf(self::VERIFYMAIL_API_URL, $domain, $apiKey);
	
		$this->retryApiCall(function () use ($url, $username, $domain, $l) {
			$httpClient = $this->httpClientService->newClient();
			// Make the API request
			$response = $httpClient->get($url, [
				'timeout' => 15, // Timeout for the API call
			]);
	
			// Process response, handle errors (e.g., disposable email, non-deliverable email)
			$responseBody = $response->getBody(); // Get the response body as a string
			$data = json_decode($responseBody, true);
				
	
			// Check if the data is properly structured
			if (!$data || !is_array($data)) {
				throw new \RuntimeException("Invalid response received while verifying domain: " . $response);
			}
	
			// Handle response data
			if ($data['disposable'] ?? false) {
				$this->logger->info("User ID $username's requested recovery email address is from a disposable domain.");
				$this->domainService->addCustomDisposableDomain($domain, $l, $data['related_domains'] ?? []);
				throw new BlacklistedEmailException($l->t('The email address is disposable. Please provide another recovery address.'));
			}
	
			if (!$data['mx'] ?? true) {
				$this->logger->info("User ID $username's requested recovery email address domain is not valid.");
				$this->domainService->addCustomDisposableDomain($domain, $l, $data['related_domains'] ?? []);
				throw new BlacklistedEmailException($l->t('The email address is not deliverable. Please provide another recovery address.'));
			}
	
			$this->logger->info("User ID $username's requested recovery email address domain is valid.");
		}, $l,         // Pass the IL10N object
			10,          // Optional: Max retries (default is 10, override if necessary)
			1000);
	}
	

	
	public function isRecoveryEmailDomainDisallowed(string $recoveryEmail): bool {
		$recoveryEmail = strtolower($recoveryEmail);
		$emailParts = explode('@', $recoveryEmail);
		$domain = $emailParts[1] ?? '';

		$legacyDomain = $this->config->getSystemValue('legacy_domain', '');
		
		$mainDomain = $this->config->getSystemValue('main_domain', '');

		$restrictedDomains = [$legacyDomain, $mainDomain];

		return in_array($domain, $restrictedDomains);
	}
	public function isRecoveryEmailTaken(string $username, string $recoveryEmail): bool {
		$recoveryEmail = strtolower($recoveryEmail);
	
		$currentRecoveryEmail = $this->getRecoveryEmail($username);
		$currentUnverifiedRecoveryEmail = $this->getUnverifiedRecoveryEmail($username);

		if ($currentRecoveryEmail === $recoveryEmail || $currentUnverifiedRecoveryEmail === $recoveryEmail) {
			return false;
		}

		$usersWithEmailRecovery = $this->config->getUsersForUserValueCaseInsensitive($this->appName, 'recovery-email', $recoveryEmail);
		if (count($usersWithEmailRecovery)) {
			return true;
		}

		$usersWithUnverifiedRecovery = $this->config->getUsersForUserValueCaseInsensitive($this->appName, 'unverified-recovery-email', $recoveryEmail);
		if (count($usersWithUnverifiedRecovery)) {
			return true;
		}

		return false;
	}

	private function getUsernameAndDomain(string $email) : ?array {
		if ($email === null || empty($email)) {
			return null;
		}
		$email = strtolower($email);
		$emailParts = explode('@', $email);
		$mailUsernameParts = explode('+', $emailParts[0]);
		$mailUsername = $mailUsernameParts[0];
		$mailDomain = $emailParts[1];
		return [$mailUsername, $mailDomain];
	}

	public function isAliasedRecoveryEmailValid(string $username, string $recoveryEmail): bool {
		if (!str_contains($recoveryEmail, '+')) {
			return true;
		}
		$recoveryEmailParts = $this->getUsernameAndDomain($recoveryEmail);
		$emailAliasLimit = (int) $this->config->getSystemValue('recovery_email_alias_limit', 5);
		if ($emailAliasLimit === -1) {
			return true;
		}
		$recoveryEmailregex = $recoveryEmailParts[0]."+%@".$recoveryEmailParts[1];
		$currentRecoveryEmail = $this->getRecoveryEmail($username);
		$currentUnverifiedRecoveryEmail = $this->getUnverifiedRecoveryEmail($username);
		$currentRecoveryEmailParts = $this->getUsernameAndDomain($currentRecoveryEmail);
		$currentUnverifiedRecoveryEmailParts = $this->getUsernameAndDomain($currentUnverifiedRecoveryEmail);

		if ($currentRecoveryEmailParts !== null && $currentRecoveryEmailParts[0] === $recoveryEmailParts[0] && $currentRecoveryEmailParts[1] === $recoveryEmailParts[1]
		|| $currentUnverifiedRecoveryEmailParts !== null && $currentUnverifiedRecoveryEmailParts[0] === $recoveryEmailParts[0] && $currentUnverifiedRecoveryEmailParts[1] === $recoveryEmailParts[1]) {
			return true;
		}

		$usersWithEmailRecovery = $this->configMapper->getUsersByRecoveryEmail($recoveryEmailregex);
		if (count($usersWithEmailRecovery) > $emailAliasLimit) {
			return false;
		}

		return true;
	}

	public function updateRecoveryEmail(string $username, string $recoveryEmail) : void {
		$this->setUnverifiedRecoveryEmail($username, $recoveryEmail);
		$this->setRecoveryEmail($username, '');
	}

	public function sendVerificationEmail(string $uid, string $recoveryEmailAddress) : void {
		try {
			$user = $this->userManager->get($uid);
			$emailTemplate = $this->generateVerificationEmailTemplate($user, $recoveryEmailAddress);

			$email = $this->mailer->createMessage();
			$email->useTemplate($emailTemplate);
			$email->setTo([$recoveryEmailAddress]);
			$email->setFrom([Util::getDefaultEmailAddress('no-reply') => $this->themingDefaults->getName()]);
			$this->mailer->send($email);
		} catch (Exception $e) {
			$this->logger->error('Error sending notification email to user ' . $uid, ['exception' => $e]);
		}
	}
	/**
	 * @param IUser $user
	 * @param string $recoveryEmailAddress
	 * @return IEMailTemplate
	 */
	public function generateVerificationEmailTemplate(IUser $user, string $recoveryEmailAddress) {
		$userId = $user->getUID();
		
		$lang = $this->config->getUserValue($userId, 'core', 'lang', null);
		$l10n = $this->l10nFactory->get('settings', $lang);

		$token = $this->createToken($user, $recoveryEmailAddress);
		$link = $this->urlGenerator->linkToRouteAbsolute($this->appName .'.email_recovery.verify_recovery_email', ['token' => $token,'userId' => $user->getUID()]);
		$this->logger->debug('RECOVERY EMAIL VERIFICATION URL LINK: ' . $link);
		$displayName = $user->getDisplayName();

		$emailTemplate = $this->mailer->createEMailTemplate('recovery-email.confirmation', [
			'link' => $link,
			'displayname' => $displayName,
			'userid' => $userId,
			'instancename' => $this->themingDefaults->getName(),
			'resetTokenGenerated' => true,
		]);

		$emailTemplate->setSubject($l10n->t('Recovery Email Update in Your %s Account', [$this->themingDefaults->getName()]));
		$emailTemplate->addHeader();
		$emailTemplate->addHeading($l10n->t('Hello %s', [$displayName]));
		$emailTemplate->addBodyText($l10n->t('This is to inform you that the recovery email for your %s account has been successfully updated.', [$this->themingDefaults->getName()]));
		$emailTemplate->addBodyText($l10n->t('To verify your new recovery email, please click on the following button.'));
		$leftButtonText = $l10n->t('Verify recovery email');
		$emailTemplate->addBodyButton(
			$leftButtonText,
			$link
		);
		$emailTemplate->addBodyText($l10n->t('Please note that this link will be valid for the next 30 minutes.'));
		$emailTemplate->addBodyText($l10n->t('If you did not initiate this change, please contact our support team immediately.'));
		$emailTemplate->addBodyText($l10n->t('Thank you for choosing %s.', [$this->themingDefaults->getName()]));
		$emailTemplate->addFooter('', $lang);

		return $emailTemplate;
	}
	private function createToken(IUser $user, string $recoveryEmail = ''): string {
		$ref = \substr(hash('sha256', $recoveryEmail), 0, 8);
		return $this->verificationToken->create($user, 'verifyRecoveryMail' . $ref, $recoveryEmail, self::TOKEN_LIFETIME);
	}
	public function verifyToken(string $token, IUser $user, string $verificationKey, string $email): void {
		$this->verificationToken->check($token, $user, $verificationKey, $email);
	}
	public function deleteVerificationToken(string $token, IUser $user, string $verificationKey): void {
		$this->verificationToken->delete($token, $user, $verificationKey);
	}
	public function makeRecoveryEmailVerified(string $userId): void {
		$newRecoveryEmailAddress = $this->getUnverifiedRecoveryEmail($userId);
		if ($newRecoveryEmailAddress !== '') {
			$this->setRecoveryEmail($userId, $newRecoveryEmailAddress);
			$this->deleteUnverifiedRecoveryEmail($userId);
		}
	}
	private function manageEmailRestriction(string $email, string $method, string $url) : void {
		$params = [];
	
		$token = $this->apiConfig['commonServicesToken'];
		$headers = [
			"Authorization: Bearer $token"
		];
	
		if ($method === 'POST') {
			$this->curl->post($url, $params, $headers);
		} elseif ($method === 'DELETE') {
			$this->curl->delete($url, $params, $headers);
		}
	
		if ($this->curl->getLastStatusCode() !== 200) {
			throw new Exception('Error ' . strtolower($method) . 'ing email ' . $email . ' in restricted list. Status Code: ' . $this->curl->getLastStatusCode());
		}
	}
	
	public function restrictEmail(string $email) : void {
		$commonServicesURL = $this->apiConfig['commonServicesURL'];
		$commonApiVersion = $this->apiConfig['commonApiVersion'];
	
		if (!isset($commonServicesURL) || empty($commonServicesURL)) {
			return;
		}
	
		$endpoint = $commonApiVersion . '/emails/restricted/' . $email;
		$url = $commonServicesURL . $endpoint; // POST /v2/emails/restricted/@email
	
		$this->manageEmailRestriction($email, 'POST', $url);
	}
	
	public function unrestrictEmail(string $email) : void {
		$commonServicesURL = $this->apiConfig['commonServicesURL'];
		$commonApiVersion = $this->apiConfig['commonApiVersion'];
	
		if (!isset($commonServicesURL) || empty($commonServicesURL)) {
			return;
		}
	
		$endpoint = $commonApiVersion . '/emails/restricted/' . $email;
		$url = $commonServicesURL . $endpoint; // DELETE /v2/emails/restricted/@email
	
		$this->manageEmailRestriction($email, 'DELETE', $url);
	}
	/**
	 * Check if a recovery email address is in valid format
	 *
	 * @param string $recoveryEmail The recovery email address to check.
	 *
	 * @return bool True if the recovery email address is valid, false otherwise.
	 */
	public function isValidEmailFormat(string $recoveryEmail): bool {
		return filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL) !== false;
	}

	private function ensureRealTimeRateLimit(string $key, int $rateLimit, IL10N $l, int $maxRetries = 10): void {
		$now = microtime(true);
		$attempts = 0; // Track the number of attempts
		$requests = $this->cache->get($key) ?? [];
		
		// Filter out requests older than the sliding window of 1 second
		$requests = array_filter($requests, function ($timestamp) use ($now) {
			return ($now - $timestamp) <= 1;
		});
	
		// If we exceed the rate limit, delay until the next available slot
		while (count($requests) >= $rateLimit) {
			$oldestRequest = min($requests);
			$delay = 1 - ($now - $oldestRequest); // Time to wait until the sliding window resets
	
			if ($delay > 0) {
				usleep((int)($delay * 1000000)); // Sleep for the calculated delay
			}
	
			// Update current time after delay and re-check
			$now = microtime(true);
			$requests = array_filter($requests, function ($timestamp) use ($now) {
				return ($now - $timestamp) <= 1;
			});
	
			// Increment attempts and check for max retries
			$attempts++;
			if ($attempts >= $maxRetries) {
				$this->logger->info("Rate limit exceeded after $maxRetries attempts. Please try again later.");
				throw new \RuntimeException($l->t('The email could not be verified. Please try again later.'));
			}
		}
	
		// Add the current request timestamp
		$requests[] = $now;
		$this->cache->set($key, $requests, 2);
	}
	public function getCreatedAt($username): string {
		$this->logger->error('DailyRecoveryWarningNotificationJob getCreatedAt called.', ['exception' => '']);
		return $this->LDAPConnectionService->getCreateTimestamp($username);
	}

	public function getDeletionDate(string $username): string {
		try {
			// Fetch LDAP timestamp (e.g., 20220817084557Z)
			$date = $this->getCreatedAt($username);
			if (!$date) {
				throw new Exception("No date found.");
			}

			return date('Y-m-d', strtotime($date . ' +30 days'));
		} catch (Exception $e) {
			throw new Exception("Error getting deletion date");
		}
	}
}
