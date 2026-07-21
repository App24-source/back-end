<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Support\Facades\DB;

class PublicationClickAppelGroupedNotification extends BaseNotification implements ShouldQueue
{
    
    public function __construct(public $profile) {
        // Only store the profile ID to avoid serialization issues
        $this->profile = $profile->withoutRelations();
    }

    protected function getType(): string { return 'click_appel_publication_grouped'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Nouveau click appel';
            case 'ar':
                return $this->getAuthorName() . ', نشاط صوت جديدة';
            default:
                return $this->getAuthorName() . ", New call click";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return "Votre publication a été partagée ". $this->getCount() ." fois !";
            case 'ar':
                return "تم مشاركة منشورك " . $this->getCount() ." مرة!";
            default:
                return "Your post has been shared " . $this->getCount() . " times!";
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

    protected function getLink(): string { return "https://flink.ma/profile/@" . $this->profile->slug; }

    public function via($notifiable): array {
        if($this->getCount() == 0) return [];
        return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->profile->nom) return $this->profile->nom;
        else return $this->profile->last_name;
    }

    protected function getLangue(): string
    {
        return $this->profile->langue;
    }

    protected function getCount(): string
    {
        // Always calculate fresh count when notification is processed
        return (string)DB::table('statistiques_flink')
            ->join('publications_flink', 'statistiques_flink.publication_id', '=', 'publications_flink.id')
            ->where('publications_flink.user_id', $this->profile->id)
            ->where('statistiques_flink.date', '>=', now()->subHours(24))
            ->sum('statistiques_flink.click_tele');
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
            'partage_count' => $this->getCount(),
        ];
    }
}