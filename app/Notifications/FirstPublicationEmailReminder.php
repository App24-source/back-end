<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class FirstPublicationEmailReminder extends BaseNotification implements ShouldQueue
{
    public function __construct(public $profile) {}

    protected function getType(): string { return 'account_created'; }
    public function getTitle(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Bienvenue sur Flink.ma';
            case 'ar':
                return 'مرحباً بك في Flink.ma';
            default:
                return "Welcome to Flink.ma";
        }
    }

    public function getMessage(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return "Merci d’avoir rejoint Flink.ma !

                    C’est le moment de vous faire connaître.
                    Publiez gratuitement votre premier produit, service ou vidéo.

                    On reste avec vous à chaque étape.

                    — L’équipe Flink.ma
                    ";
            case 'ar':
                return "شكراً لانضمامك إلى Flink.ma!

                    حان الوقت لتبدأ وتعرّف الآخرين بما تقدمه.
                    أنشر أول منتج أو خدمة أو فيديو مجاناً الآن.


                    نحن معك خطوة بخطوة.

                    — فريق Flink.ma
                    ";
            default:
                return "Thanks for joining Flink.ma!

                    It’s time to get noticed.
                    Publish your first product, service, or video — for free.

                    We’re here with you every step of the way.

                    — The Flink.ma Team";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/design-icons/flink-icon-fit.png"; // Optional: child classes don't have to implement this
    }
    
    public function getGreeting(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Bonjour ' . $this->getAuthorName();
            case 'ar':
                return 'مرحبا ' . $this->getAuthorName();
            default:
                return 'Hi ' . $this->getAuthorName();
        }
    }

    public function getSalutation(): string {
        switch ($this->getLangue()) {
            case 'fr':
                return '— L’équipe Flink.ma';
            case 'ar':
                return '— فريق Flink.ma';
            default:
                return '— The Flink.ma Team';
        }

    }

    public function getLink(): string {
         return 'https://flink.ma/profile/@' . $this->profile->slug;
    }

    public function via($notifiable): array {
        return ['mail'];
    }

    public function shouldSend($notifiable): bool
    {
        return !$notifiable->publications()->exists();
        // return true;
    }

    protected function getAuthorName(): string
    {
        if($this->profile->nom) return $this->profile->nom;
        return $this->profile->first_name . ' ' . $this->profile->last_name;
    }

    protected function getLangue(): string
    {
        return $this->profile->langue;
    }

    protected function getData(): array {
        return [];
    }
}
