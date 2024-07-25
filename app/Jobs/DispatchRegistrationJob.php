<?php

namespace App\Jobs;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Google_Client;
use Google_Service_Gmail;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;

class DispatchRegistrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3; // Number of attempts

    /**
     * Execute the job.
     *
     * @return void
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    public function handle()
    {
        $jar = new CookieJar();
        $client = new Client([
            'cookies' => $jar,
            'headers' => [
                'User-Agent' => $this->getRandomUserAgent(),
                'Referer' => 'https://challenge.blackscale.media/',
                'Accept-Language' => 'en-US,en;q=0.9',
            ]
        ]);

        // Mimic human-like delays between requests
        sleep(rand(2, 5));

        $response = $client->get('https://challenge.blackscale.media/register.php');

        // Additional requests to mimic a real user browsing
        $client->get('https://challenge.blackscale.media/style.css');
        sleep(rand(1, 3));
        $client->get('https://challenge.blackscale.media/my-jquery.css');
        sleep(rand(1, 3));
        $client->get('https://challenge.blackscale.media/favicon.ico');
        sleep(rand(5, 10));

        $html = $response->getBody()->getContents();
        $crawler = new Crawler($html);
        $token = $crawler->filter('form input[name="stoken"]')->attr('value');

        $email = 'zbogoevski+' . Str::random(10) . '@gmail.com';
        $response = $client->post('https://challenge.blackscale.media/verify.php', [
            'form_params' => [
                'stoken' => $token,
                'fullname' => 'Test User',
                'email' => $email,
                'password' => '123456',
                'email_signature' => base64_encode($email),
            ],
        ]);

        if ($response->getStatusCode() == 200) {
            // Wait for email to arrive
            sleep(30);
            $verificationCode = $this->getEmailVerificationCode($email);

            if ($verificationCode) {
                $this->verifyUser($client, $jar, $email, $verificationCode);
            } else {
                throw new Exception('Verification code not found for ' . $email);
            }
        } else {
            throw new Exception('Registration failed for ' . $email);
        }
    }

    private function getRandomUserAgent(): string
    {
        $userAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0.3 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; rv:68.0) Gecko/20100101 Firefox/68.0',
        ];

        return $userAgents[array_rand($userAgents)];
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function getEmailVerificationCode($email): ?string
    {
        $client = $this->getClient();
        $service = new Google_Service_Gmail($client);
        $userId = 'me';

        // Get the list of messages
        $messages = $service->users_messages->listUsersMessages($userId, [
            'q' => 'from:no-reply@blackscale.media'
        ]);

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
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @throws Exception
     */
    private function getClient(): Google_Client
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
                // Here you should handle the auth URL in a way appropriate for your application
                // For example, in a web application, you could redirect the user to the URL
                // and then handle the callback in another method.
                Log::info('Open the following link in your browser: ' . $authUrl);
                Log::info('Enter verification code: ');
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
     * @throws GuzzleException
     */
    private function verifyUser(Client $client, CookieJar $jar, $email, $verificationCode): void
    {
        // Step 1: Post the verification code
        $client->post('https://challenge.blackscale.media/captcha.php', [
            'form_params' => [
                'code' => $verificationCode,
            ],
            'cookies' => $jar
        ]);

        // Step 2: Complete the registration using the Oxylabs proxy
        $response = $client->post('https://challenge.blackscale.media/complete.php', [
            'proxy' => 'https://kalimero_Wahoi:T3gH8m_7kP~2=@unblock.oxylabs.io:60000',
            'headers' => [
                'User-Agent' => $this->getRandomUserAgent(),
            ],
            'cookies' => $jar,
            'verify' => false, // Disable SSL certificate validation
            'timeout' => 30,
        ]);

        $responseContent = $response->getBody()->getContents();
        Log::info($responseContent);

    }
}
