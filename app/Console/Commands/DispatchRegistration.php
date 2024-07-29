<?php

namespace App\Console\Commands;

use Exception;
use Google_Client;
use Google_Service_Gmail;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;
use TwoCaptcha\TwoCaptcha;

class DispatchRegistration extends Command
{
    protected $signature = 'register:dispatch'; // Command signature
    protected $description = 'Dispatch user registration jobs'; // Command description

    /**
     * Handle the command execution.
     */
    public function handle()
    {
        $client = $this->initializeHttpClient(); // Initialize HTTP client

        // Mimic human-like delays between requests
        sleep(rand(2, 5));

        // Get the registration page
        $response = $client->get('https://challenge.blackscale.media/register.php');
        $this->mimicUserBrowsing($client); // Mimic additional browsing

        $html = $response->getBody()->getContents(); // Get the HTML content
        $crawler = new Crawler($html); // Initialize the DOM crawler
        $token = $crawler->filter('form input[name="stoken"]')->attr('value'); // Extract the CSRF token

        // Generate a random email
        $email = 'zbogoevski+' . Str::random(10) . '@gmail.com';

        // Submit the registration form
        $response = $client->post('https://challenge.blackscale.media/verify.php', [
            'form_params' => [
                'stoken' => $token,
                'fullname' => 'Test User',
                'email' => $email,
                'password' => '123456',
                'email_signature' => base64_encode($email),
            ],
        ]);

        // Check if registration was initiated successfully
        if ($response->getStatusCode() == 200) {
            $this->info('Registration initiated for ' . $email);

            sleep(30); // Wait for the email to arrive

            // Get the email verification code
            $verificationCode = $this->getEmailVerificationCode($email);
            if ($verificationCode) {
                $this->verifyUser($client, $verificationCode); // Verify the user
            } else {
                $this->error('Verification code not found for ' . $email);
            }
        } else {
            $this->error('Registration failed for ' . $email);
        }
    }

    /**
     * Initialize the HTTP client.
     * @return Client
     */
    private function initializeHttpClient(): Client
    {
        return new Client([
            'cookies' => new CookieJar(),
            'headers' => [
                'User-Agent' => $this->getRandomUserAgent(), // Random user agent
                'Referer' => 'https://challenge.blackscale.media/',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]
        ]);
    }

    /**
     * Mimic user browsing by making additional requests.
     * @param Client $client
     */
    private function mimicUserBrowsing(Client $client): void
    {
        // Make additional requests to mimic real user behavior
        $client->get('https://challenge.blackscale.media/style.css');
        sleep(rand(1, 3));
        $client->get('https://challenge.blackscale.media/my-jquery.css');
        sleep(rand(1, 3));
        $client->get('https://challenge.blackscale.media/favicon.ico');
        sleep(rand(5, 10));
    }

    /**
     * Get a random user agent string.
     * @return string
     */
    private function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0.3 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; rv:68.0) Gecko/20100101 Firefox/68.0',
        ];

        return $userAgents[array_rand($userAgents)]; // Return a random user agent
    }

    /**
     * Get the email verification code from the Gmail API.
     * @param string $email
     * @return string|null
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function getEmailVerificationCode(string $email): ?string
    {
        $client = $this->initializeGoogleClient(); // Initialize Google client
        $service = new Google_Service_Gmail($client);
        $userId = 'me';

        // Get the list of messages from the specified sender
        $messages = $service->users_messages->listUsersMessages($userId, [
            'q' => 'from:no-reply@blackscale.media'
        ]);

        // Loop through the messages to find the verification code
        foreach ($messages->getMessages() as $message) {
            $msg = $service->users_messages->get($userId, $message->getId(), ['format' => 'full']);
            $payload = $msg->getPayload();
            $body = $payload->getBody()->getData();

            if (empty($body)) {
                $parts = $payload->getParts();
                foreach ($parts as $part) {
                    if ($part->getMimeType() === 'text/plain') {
                        $body = $part->getBody()->getData();
                        break;
                    }
                }
            }

            $body = base64_decode(str_replace(['-', '_'], ['+', '/'], $body));
            if (preg_match('/Your verification code is: (\w+)/', $body, $matches)) {
                return $matches[1]; // Return the verification code
            }
        }

        return null; // Return null if no verification code was found
    }

    /**
     * Initialize the Google client for Gmail API.
     * @return Google_Client
     * @throws Exception
     */
    private function initializeGoogleClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setApplicationName('Gmail API PHP Quickstart');
        $client->setScopes(Google_Service_Gmail::GMAIL_READONLY);
        $client->setAuthConfig(storage_path('app/credentials.json'));
        $client->setAccessType('offline');

        // Load previously authorized token from a file, if it exists.
        if (Storage::exists('token.json')) {
            $accessToken = json_decode(Storage::get('token.json'), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired, get a new one.
        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                $authUrl = $client->createAuthUrl();
                $this->info('Open the following link in your browser: ' . $authUrl);
                $authCode = trim($this->ask('Enter verification code'));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }

            // Save the token to a file.
            Storage::put('token.json', json_encode($client->getAccessToken()));
        }

        return $client;
    }

    /**
     * Verify the user by solving the reCAPTCHA.
     * @param Client $client
     * @param string $verificationCode
     * @throws GuzzleException
     */
    private function verifyUser(Client $client, string $verificationCode): void
    {
        $this->info('Verification code extracted: ' . $verificationCode);

        // Post the verification code to the server
        $response = $client->post('https://challenge.blackscale.media/captcha.php', [
            'form_params' => [
                'code' => $verificationCode,
            ],
        ]);

        $responseContent = $response->getBody()->getContents();
        $this->info('Response from captcha verification: ' . $responseContent);

        // Extract the data-sitekey from the response
        $crawler = new Crawler($responseContent);
        $siteKey = $crawler->filter('.g-recaptcha')->attr('data-sitekey');
        $this->info('Extracted site key: ' . $siteKey);

        // Solve the reCAPTCHA
        $recaptchaResponse = $this->solveRecaptcha($siteKey, 'https://challenge.blackscale.media/captcha.php');
        if ($recaptchaResponse) {
            $this->info('reCAPTCHA solved: ' . $recaptchaResponse);

            // Complete the registration
            $response = $client->post('https://challenge.blackscale.media/complete.php', [
                'form_params' => [
                    'g-recaptcha-response' => $recaptchaResponse,
                ],
                'headers' => [
                    'User-Agent' => $this->getRandomUserAgent(),
                ],
                'verify' => false, // Disable SSL certificate validation
                'timeout' => 30,
            ]);

            $responseContent = $response->getBody()->getContents();
            $this->info('Response from registration completion: ' . $responseContent);
        } else {
            $this->error('Failed to solve reCAPTCHA.');
        }
    }

    /**
     * Solve the reCAPTCHA using 2Captcha.
     * @param string $siteKey
     * @param string $url
     * @return string|null
     */
    private function solveRecaptcha(string $siteKey, string $url): ?string
    {
        $apiKey = 'YOUR_2CAPTCHA_API_KEY'; // Replace with your 2Captcha API key
        $solver = new TwoCaptcha($apiKey);

        try {
            $result = $solver->recaptcha([
                'sitekey' => $siteKey,
                'url' => $url,
            ]);
            return $result->code; // Return the reCAPTCHA solution
        } catch (Exception $e) {
            $this->error('2Captcha error: ' . $e->getMessage());
            return null; // Return null if there was an error
        }
    }
}
