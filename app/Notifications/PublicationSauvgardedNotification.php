<?php

namespace App\Notifications;

use App\Models\Publication;
use App\Models\User;
use NotificationChannels\Fcm\FcmChannel;
use Illuminate\Contracts\Queue\ShouldQueue;


class PublicationSauvgardedNotification extends BaseNotification
{
    public function __construct(public Publication $publication, public $sauvgardedProfile) {}

    protected function getType(): string { return 'sauvgarded_publication'; }
    protected function getTitle(): string { 
        switch ($this->getLangue()) {
            case 'fr':
                return $this->getAuthorName() . ', Votre annonce attire de l’intérêt';
            case 'ar':
                return $this->getAuthorName() . ', إعلانك يثير الاهتمام';
            default:
                return $this->getAuthorName() . ", Your post is gaining attention";
        }
     }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return ($this->sauvgardedProfile->nom ? $this->sauvgardedProfile->nom: $this->sauvgardedProfile->first_name . ' ' . $this->sauvgardedProfile->last_name) . ' a ajouté votre publication à ses favoris.';
            case 'ar':
                return ($this->sauvgardedProfile->nom ? $this->sauvgardedProfile->nom: $this->sauvgardedProfile->first_name . ' ' . $this->sauvgardedProfile->last_name) . ' أضاف إعلانك إلى المفضلة';
            default:
                return ($this->sauvgardedProfile->nom ? $this->sauvgardedProfile->nom: $this->sauvgardedProfile->first_name . ' ' . $this->sauvgardedProfile->last_name) . " added your post to their favorites.";
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
            'publication_id' => $this->publication->id,
            'profile' => [
                'id' => $this->sauvgardedProfile->id,
                'name' => $this->sauvgardedProfile->nom ? $this->sauvgardedProfile->nom: $this->sauvgardedProfile->first_name . ' ' . $this->sauvgardedProfile->last_name,
                'slug' => $this->sauvgardedProfile->slug,
                'avatar' => $this->sauvgardedProfile->nom ? $this->sauvgardedProfile->logo: $this->sauvgardedProfile->avatar,
                'type' => $this->sauvgardedProfile->nom ? "etablissement":  "user",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
        ];
    }
}