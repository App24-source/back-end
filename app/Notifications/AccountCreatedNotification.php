<?php

namespace App\Notifications;

use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class AccountCreatedNotification extends BaseNotification implements ShouldQueue
{
    public function __construct(public $profile) {}

    protected function getType(): string { return 'account_created'; }
    protected function getTitle(): string { 
        switch ($this->profile->langue) {
            case 'fr':
                return $this->getAuthorName() . ', Vous êtes inscrit';
            case 'ar':
                return $this->getAuthorName() . ', تم تسجيلك';
            default:
                return $this->getAuthorName() . ", You're registered";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->profile->langue) {
            case 'fr':
                return 'Montrez ce que vous proposez dès maintenant';
            case 'ar':
                return 'شارك ما تقدمه الآن';
            default:
                return "Show what you’ve got — publish now";
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