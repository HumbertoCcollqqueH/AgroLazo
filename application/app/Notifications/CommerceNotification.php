<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CommerceNotification extends Notification
{
    public function __construct(public string $body, public string $orderCode, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['body' => $this->body, 'order_code' => $this->orderCode, 'url' => $this->url];
    }
}
