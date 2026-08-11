<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SmsAlertSender
{
    public function send(string $to, string $message): void
    {
        $driver = config('services.sms.driver', 'log');

        if ($driver === 'log') {
            Log::info('Stock alert SMS', ['to' => $to, 'message' => $message]);

            return;
        }

        if ($driver !== 'twilio') {
            throw new RuntimeException("Unsupported SMS driver [{$driver}].");
        }

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
}
