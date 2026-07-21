<?php

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use App\Models\User;
use App\Models\Etablissement;

class NewFollowersGroupedNotification extends BaseNotification implements ShouldQueue
{








    use Queueable;
    
    protected $profileId;
    protected $profileType;
    protected $titleText;
    protected $followerNames;
   
    public function __construct($profile)
    {
        // Store the profile ID and type instead of the full object
        $this->profileId = $profile->id;
        $this->profileType = get_class($profile) === 'App\Models\User' ? 'user' : 'etablissement';
        
        // Don't initialize data here to avoid timing issues
    }


    protected function getProfile()
    {
        if ($this->profileType === 'user') {
            return User::find($this->profileId);
        } else {
            return Etablissement::find($this->profileId);
        }
    }

    protected function initializeFollowersData(): void
    {
        $profile = $this->getProfile();
        if (!$profile) {
            $this->followerNames = [];
            $this->titleText = '';
            return;
        }

        $twentyFourHoursAgo = now()->subDay();
        
        $followersEtablissements = $profile->followersEtablissements()
            ->where('follows.created_at', '>=', $twentyFourHoursAgo)
            ->pluck('nom')
            ->toArray();
        
        $followersUsers = $profile->followersUsers()
            ->where('follows.created_at', '>=', $twentyFourHoursAgo)
            ->pluck('last_name')
            ->toArray();
        
        $this->followerNames = array_merge($followersEtablissements, $followersUsers);
        $this->titleText = $this->generateTitleText($profile->langue);
    }

    protected function getType(): string { return 'new_followers_grouped'; }
    
    protected function getTitle(): string { 
        $this->initializeFollowersData();
        $language = $this->getLangue();
        
        if ($this->getCount() === 1) {
            return $this->titleText . $this->getFollowsText(true);
        } else {
            return $this->titleText . $this->getFollowsText(false);
        }
    }
    
    protected function getFollowsText($language, $isSingular): string
    {
        switch ($language) {
            case 'fr':
                return $isSingular ? ' vous suit' : ' vous suivent';
            case 'ar':
                return $isSingular ? ' يتبعك' : ' يتبعونك';
            default:
                return $isSingular ? " starts following you" : " start following you";
        }
    }
    
    protected function getMessage(): string
    {
        switch ($this->getLangue()) {
            case 'fr':
                return 'Continue à publier pour attirer encore plus d\'abonnés.';
            case 'ar':
                return 'زيد نشر باش تزيد المتابعين ديالك';
            default:
                return "Keep posting to gain more followers.";
        }
    }

    protected function getImage(): string {
        return "https://media.flink.ma/design-icons/flink-icon-fit.png";
    }

    public function getGreeting(): ?string {
        return null;
    }

    public function getSalutation(): ?string {
        return null;
    }

    protected function getLink(): string { 
        $profile = $this->getProfile();
        if(!$profile) {
            return "https://flink.ma";
        }
        return "https://flink.ma/profile/@" . $profile->slug; 
    }

    public function via($notifiable): array {
        $this->initializeFollowersData();
        if($this->getCount() == 0) return [];
        return  ['database', FcmChannel::class];
    }

    protected function generateTitleText($language): string
    {
        $count = $this->getCount();
        
        if ($count === 0) {
            return $language === 'fr' ? '0 followers' : ($language === 'ar' ? '0 متابع' : '0 followers');
        }
        
        if ($count === 1) {
            return $this->followerNames[0];
        }
        
        $and = $language === 'fr' ? ' et ' : ($language === 'ar' ? ' و ' : ' and ');
        
        if ($count === 2) {
            return $this->followerNames[0] . $and . $this->followerNames[1];
        }
        
        // For 3+ followers
        $moreText = $language === 'fr' ? ' autre' : ($language === 'ar' ? ' آخر' : ' other');
        $moreText .= ($count - 2) > 1 ? ($language === 'ar' ? 'ين' : 's') : '';
        
        return $this->followerNames[0] . ', ' . $this->followerNames[1] . $and . ($count - 2) . $moreText;
    }

    protected function getLangue(): string
    {
        $profile = $this->getProfile();
        if(!$profile) {
            return 'fr'; // default language
        }
        return $profile->langue;
    }

    protected function getCount(): int
    {
        return (string)DB::table('follows')
            ->join('publications_flink', 'statistiques_flink.publication_id', '=', 'publications_flink.id')
            ->where('follows.user_following_id', $this->profile->id)
            ->where('follows.created_at', '>=', now()->subHours(24))
            ->count();
    }

    protected function getData(): array {
        $this->initializeFollowersData();
        
        if ($this->getCount() == 0) {
            return [];
        }
        
        $profile = $this->getProfile();
        
        return [
            'type' => $this->getType(),
            'title' => $this->getTitle(),
            'message' => $this->getMessage(),
            'profile' => [
                'avatar' => "https://media.flink.ma/design-icons/flink-icon-fit.png",
            ],
            'image' => $this->getImage(),
            'link' => $this->getLink(),
            'followers_count' => $this->getCount(),
        ];
    }
} 
