<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;

class NewFollowerNotification extends BaseNotification
{
    public function __construct(public $followingProfile, public $followerProfile) {}

    protected function getType(): string { return 'new_follower'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', vient de te suivre !';
            case 'ar':
                return $this->getAuthorName() . ', تبعك دابا!
';
            default:
                return $this->getAuthorName() . ", just followed you!";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Continue à publier pour attirer encore plus d’abonnés.';
            case 'ar':
                return 'زيد نشر باش تزيد المتابعين ديالك';
            default:
                return "Keep posting to gain more followers.";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . ($this->followingProfile->nom ? $this->followingProfile->logo : $this->followingProfile->avatar); // Optional: child classes don't have to implement this
    }

    public function getGreeting(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    public function getSalutation(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    protected function getLink(): string { return 'https://flink.ma/profile/@' . $this->followingProfile->slug . '/'; }

    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->followingProfile->nom) return $this->followingProfile->nom;
        return $this->followingProfile->last_name;
    }

    protected function getLangue(): string
    {
        if($this->followerProfile->nom) return $this->followerProfile->langue;
        return $this->followerProfile->langue;
    }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                'id' => $this->followingProfile->id,
                'name' => $this->getAuthorName(),
                'slug' => $this->followingProfile->slug,
                'avatar' => $this->followingProfile->nom ? $this->followingProfile->logo : $this->followingProfile->avatar,
                'type' => $this->followingProfile->nom ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }
}