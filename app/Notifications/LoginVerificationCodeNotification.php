<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginVerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your MotoSync login code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Use this verification code to finish signing in to MotoSync:')
            ->line($this->code)
            ->line('This code expires in 10 minutes and can only be used once.')
            ->line('If you did not try to sign in, you can safely ignore this email.');
    }
}
