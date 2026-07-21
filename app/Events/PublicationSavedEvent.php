<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PublicationSavedEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $publicationId;
    public $action;
    public $userId;
    public $etabId;
    public $savedCount;
    public $timestamp;

    /**
     * Create a new event instance.
     */
    public function __construct($publicationId, $action, $userId, $etabId, $savedCount)
    {
        $this->publicationId = $publicationId;
        $this->action = $action;
        $this->userId = $userId;
        $this->etabId = $etabId;
        $this->savedCount = $savedCount;
        $this->timestamp = now()->toISOString();
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('publications'),
            new Channel('publication.' . $this->publicationId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'publication_id' => $this->publicationId,
            'action' => $this->action,
            'user_id' => $this->userId,
            'etab_id' => $this->etabId,
            'saved_count' => $this->savedCount,
            'timestamp' => $this->timestamp,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'publication.saved';
    }
}
