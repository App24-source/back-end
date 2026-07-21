<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Publication;
use NotificationChannels\Fcm\FcmChannel;

class PublicationViewMilestoneNotification extends BaseNotification
{
   public function __construct(public Publication $publication, public $viewCount) {}

    protected function getType(): string { return 'publication_views'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Ta publication attire l’œil ! Déjà ' . $this->viewCount . ' vues';
            case 'ar':
                return $this->getAuthorName() . ', إعلان ديالك شد الانتباه ! وصل لـ '. $this->viewCount . ' مشاهدات';
            default:
                return $this->getAuthorName() . ", Your post is catching attention! Already " . $this->viewCount . " views";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Continue à booster ta visibilité.';
            case 'ar':
                return 'زيد فالرواج باش توصل لأكثر';
            default:
                return "Keep boosting for more reach.";
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

    protected function getLink(): string { return 'https://flink.ma/publication/' . $this->publication->slug . '/'; }

    public function via($notifiable): array {
         return  ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        if($this->publication->etab_id) return $this->publication->etablissement->nom;
        return $this->publication->user->last_name;
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
                'id' => $this->publication->etab_id ? $this->publication->etablissement->id:  $this->publication->user->id,
                'name' => $this->getAuthorName(),
                'slug' => $this->publication->etab_id ? $this->publication->etablissement->slug:  $this->publication->user->slug,
                'avatar' => $this->publication->etab_id ? $this->publication->etablissement->logo:  $this->publication->user->avatar,
                'type' => $this->publication->etab_id ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
            'viewCount' => $this->viewCount,
        ];
    }
}