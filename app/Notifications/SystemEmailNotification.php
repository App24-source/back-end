<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

class SystemEmailNotification extends BaseNotification
{
    public function __construct(public $profile, public $type, public $title, public $message, public $image, public $link) {}

    protected function getType(): string { return $type; }
    protected function getTitle(): string { 
        switch ($this->profile->langue) {
            case 'fr':
                return $this->title['fr'];
            case 'ar':
                return $this->title['ar'];
            default:
                return $this->title['en'];
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->profile->langue) {
            case 'fr':
                return $this->message['fr'];
            case 'ar':
                return $this->message['ar'];
            default:
                return $this->message['en'];
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . $this->image; // Optional: child classes don't have to implement this
    }
    public function getGreeting(): string {
        switch ($this->profile->langue) {
            case 'fr':
                return 'Bonjour ' . $this->getAuthorName();
            case 'ar':
                return 'مرحبا ' . $this->getAuthorName();
            default:
                return 'Hi ' . $this->getAuthorName();
        }
    }

    public function getSalutation(): string {
        switch ($this->profile->langue) {
            case 'fr':
                return '— L’équipe Flink.ma';
            case 'ar':
                return '— فريق Flink.ma';
            default:
                return '— The Flink.ma Team';
        }

    }

    protected function getLink(): string { return $this->link ?? ""; }

    public function via($notifiable): array {
        return  ['mail'];
    }

    protected function getAuthorName(): string
    {
        if($this->profile->nom) return $this->profile->nom;
        return $this ->profile->first_name . ' ' . $this->profile->last_name;
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