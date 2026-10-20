<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PlatformNotification extends Notification
{
    public function __construct(public string $body, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['body' => $this->body, 'url' => $this->url];
    }
}
