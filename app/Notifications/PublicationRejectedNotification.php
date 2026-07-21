<?php

namespace App\Notifications;

use App\Models\Publication;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class PublicationRejectedNotification extends BaseNotification
{
    public function __construct(public Publication $publication) {}

    protected function getType(): string { return 'rejected_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Votre publication a été rejetée.';
            case 'ar':
                return $this->getAuthorName() . ', تم رفض إعلانك.';
            default:
                return $this->getAuthorName() . ", Your post was rejected.";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Modifiez-la pour la republier.';
            case 'ar':
                return 'قم بتعديله لإعادة نشره.';
            default:
                return "Edit it to repost.";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/" . $this->publication->image_default; // Optional: child classes don't have to implement this
    }

    public function getGreeting(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    public function getSalutation(): ?string {
        return null; // Optional: child classes don't have to implement this
    }

    protected function getLink(): string { return 'https://flink.ma/profile/@' . $this->getProfileSlug() . '/'; }

    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->last_name;
    }

    protected function getProfileSlug(): string {
        if($this->publication->etab_id) return $this->publication->etablissement->slug;
        return $this->publication->user->slug;
    }

    protected function getLangue(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->langue;
        return $this->publication->user->langue;
    }

    protected function getData(): array {
        return [
            'type' => $this->getType(),
            'profile' => [
                // 'id' => $this->publication->etab_id ? $this->publication->etablissement->id:  $this->publication->user->id,
                // 'name' => $this->getAuthorName(),
                // 'slug' => $this->publication->etab_id ? $this->publication->etablissement->slug:  $this->publication->user->slug,
                'avatar' => "https://media.flink.ma/design-icons/flink-icon-fit.png",
                // 'type' => $this->publication->etab_id ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }
}