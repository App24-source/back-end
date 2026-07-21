<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

class MarketingNotification extends Notification
{
    public function __construct(public $profile, public $title, public $message, public $image, public $link) {}

    protected function getType(): string { return 'marketing'; }
    protected function getTitle(): string { 
        switch ($this->$profile->langue) {
            case 'fr':
                return $this->title->fr;
            case 'ar':
                return $this->title->ar;
            default:
                return $this->title->en;
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->$profile->langue) {
            case 'fr':
                return $this->message->fr;
            case 'ar':
                return $this->message->ar;
            default:
                return $this->message->en;
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/design-icons/flink-icon-fit.png"; // Optional: child classes don't have to implement this
    }
    public function getGreeting(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    public function getSalutation(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    protected function getLink(): string { return 'https://flink.ma/profile/@' . $this->profile->slug; }

    public function via($notifiable): array {
        return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->profile->nom) return $this->profile->nom;
        return $this->profile->last_name;
    }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                // 'id' => $this->profile->id,
                // 'name' => $this->getAuthorName(),
                // 'slug' => $this->profile->slug,
                'avatar' => "https://media.flink.ma/design-icons/flink-icon-fit.png",
                // 'type' => $this->profile->nom ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }

}