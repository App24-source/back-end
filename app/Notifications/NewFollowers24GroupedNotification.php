<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use App\Models\User;
use App\Models\Etablissement;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NewFollowers24GroupedNotification extends BaseNotification implements ShouldQueue
{
    use Queueable;
    
    protected $profileId;
    protected $profileType;
    protected $notificationCreatedAt;

    public function __construct($profile) {
        // Store only profile reference and creation time
        $this->profileId = $profile->id;
        $this->profileType = $profile->etab_id ? 'etablissement' : 'user';
        $this->notificationCreatedAt = now();
    }

    protected function getType(): string { return 'partage_publication_grouped'; }
    
    protected function getTitle(): string { 
        $followerNames = $this->getFollowerNames();
        $count = count($followerNames);
        
        if ($count === 0) {
            return '';
        }
        
        if ($count === 1) {
            return ($followerNames[0] ?? '') . $this->getFollowsText(true);
        } else {
            return $this->generateTitleText($followerNames, $count) . $this->getFollowsText(false);
        }
    }

    protected function getFollowerNames(): array
    {
        try {
            // Calculate the 24-hour window from when notification should be sent
            $notificationSendTime = now(); // This is when the notification is actually being processed
            $startTime = $notificationSendTime->copy()->subDay();
            $endTime = $notificationSendTime;
            
            $etabFollowers = DB::table('follows')
                ->join('etablissements', 'follows.etab_follower_id', '=', 'etablissements.id')
                ->where(function($query) {
                    if ($this->profileType === 'user') {
                        $query->where('follows.user_following_id', $this->profileId);
                    } else {
                        $query->where('follows.etab_following_id', $this->profileId);
                    }
                })
                ->whereBetween('follows.created_at', [$startTime, $endTime])
                ->pluck('etablissements.nom')
                ->toArray();

            $userFollowers = DB::table('follows')
                ->join('users', 'follows.user_follower_id', '=', 'users.id')
                ->where(function($query) {
                    if ($this->profileType === 'user') {
                        $query->where('follows.user_following_id', $this->profileId);
                    } else {
                        $query->where('follows.etab_following_id', $this->profileId);
                    }
                })
                ->whereBetween('follows.created_at', [$startTime, $endTime])
                ->pluck('users.last_name')
                ->toArray();

            return array_merge($etabFollowers, $userFollowers);
        } catch (\Exception $e) {
            // Log error and return empty array
            \Log::error('Error getting follower names: ' . $e->getMessage());
            return [];
        }
    }

    protected function generateTitleText(array $followerNames, int $count): string
    {        
        if ($count === 0) {
            return '';
        }
        
        if ($count === 1) {
            return $followerNames[0] ?? '';
        }
        
        $langue = $this->getLangue();
        $and = $langue === 'fr' ? ' et ' : ($langue === 'ar' ? ' و ' : ' and ');
        
        if ($count === 2) {
            return ($followerNames[0] ?? '') . $and . ($followerNames[1] ?? '');
        }
        
        // For 3+ followers
        $moreText = $langue === 'fr' ? ' autre' : ($langue === 'ar' ? ' آخر' : ' other');
        $moreText .= ($count - 2) > 1 ? ($langue === 'ar' ? 'ين' : 's') : '';
        
        return ($followerNames[0] ?? '') . ', ' . ($followerNames[1] ?? '') . $and . ($count - 2) . $moreText;
    }

    protected function getFollowsText($isSingular): string
    {
        $langue = $this->getLangue();
        switch ($langue) {
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
        $langue = $this->getLangue();
        switch ($langue) {
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
        if (!$profile) {
            return "https://flink.ma";
        }
        return "https://flink.ma/profile/@" . $profile->slug; 
    }

    public function via($notifiable): array {
        $count = $this->getCount();
        if($count == 0) return [];
        return ['database', FcmChannel::class];
    }

    protected function getAuthorName(): string
    {
        $profile = $this->getProfile();
        if (!$profile) {
            return 'Unknown';
        }
        return $profile->nom ?? $profile->last_name ?? 'Unknown';
    }

    protected function getLangue(): string
    {
        $profile = $this->getProfile();
        if (!$profile) {
            return 'fr'; // Default to French
        }
        return $profile->langue ?? 'fr';
    }

    protected function getCount(): int
    {
        try {
            $followerNames = $this->getFollowerNames();
            return count($followerNames);
        } catch (\Exception $e) {
            \Log::error('Error in getCount(): ' . $e->getMessage());
            return 0; // Always return integer, never null
        }
    }

    protected function getProfile()
    {
        try {
            if ($this->profileType === 'etablissement') {
                return Etablissement::find($this->profileId);
            } else {
                return User::find($this->profileId);
            }
        } catch (\Exception $e) {
            \Log::error('Error getting profile: ' . $e->getMessage());
            return null;
        }
    }

    protected function getData(): array {
        $profile = $this->getProfile();
        $followerNames = $this->getFollowerNames();
        $count = $this->getCount();
        
        return [
            'type' => $this->getType(),
            'profile' => [
                'id' => $profile ? $profile->id : null,
                'name' => $this->getAuthorName(),
                'slug' => $profile ? $profile->slug : null,
                'avatar' => $profile ? ($profile->avatar ?? "https://media.flink.ma/design-icons/flink-icon-fit.png") : "https://media.flink.ma/design-icons/flink-icon-fit.png",
                'type' => $this->profileType,
            ],
            'followers' => $followerNames,
            'count' => $count,
            'image' => $this->getImage(),
            'link' => $this->getLink(),
            'partage_count' => $count,
            'notification_created_at' => $this->notificationCreatedAt->toISOString(),
            'notification_processed_at' => now()->toISOString(),
        ];
    }
}