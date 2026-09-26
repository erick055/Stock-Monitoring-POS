<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SmsAlertSender
{
    public function send(string $to, string $message): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver === 'log') {
            Log::info('Stock alert SMS', ['to' => $to, 'message' => $message]);
            throw new RuntimeException('SMS was not delivered because the application is in local log mode. Configure Twilio for phone delivery.');
        }

        if ($driver === 'semaphore') {
            $this->sendWithSemaphore($to, $message);

            return;
        }

        if ($driver !== 'twilio') {
            throw new RuntimeException("Unsupported SMS driver [{$driver}].");
        }

        $this->sendWithTwilio($to, $message);
    }

    private function sendWithTwilio(string $to, string $message): void
    {
        $sid = config('services.sms.twilio.account_sid');
        $token = config('services.sms.twilio.auth_token');
        $from = config('services.sms.twilio.from');

        if (! $sid || ! $token || ! $from) {
            throw new RuntimeException('Twilio SMS credentials are incomplete.');
        }

        Http::asForm()
            ->withBasicAuth($sid, $token)
            ->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ])
            ->throw();
    }

    private function sendWithSemaphore(string $to, string $message): void
    {
        $apiKey = config('services.sms.semaphore.api_key');
        $senderName = config('services.sms.semaphore.sender_name');

        if (! $apiKey || ! $senderName) {
            throw new RuntimeException('Semaphore API key or sender name is missing.');
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post('https://api.semaphore.co/api/v4/messages', [
                'apikey' => $apiKey,
                'number' => $to,
                'message' => $message,
                'sendername' => $senderName,
            ]);

        if ($response->failed()) {
            $detail = $response->json('message') ?: $response->json('error') ?: Str::limit($response->body(), 250);
            throw new RuntimeException('Semaphore rejected the SMS request: '.$detail);
        }

        $delivery = $response->json('0');
        if (! is_array($delivery) || empty($delivery['message_id'])) {
            $detail = $response->json('message') ?: $response->json('error') ?: 'Unexpected response from Semaphore.';
            throw new RuntimeException('Semaphore did not accept the SMS: '.$detail);
        }

        if (in_array(strtolower((string) ($delivery['status'] ?? '')), ['failed', 'refunded'], true)) {
            throw new RuntimeException('Semaphore reported the SMS as '.($delivery['status'] ?? 'failed').'.');
        }
    }
}
