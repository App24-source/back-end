<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts real-time catalogue processing progress to the owner.
 *
 * Channel: catalogue.{etabId} (Public to avoid 403 authorization issues)
 * Event:   .progress
 */
class CatalogueProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $catalogueId,
        public readonly int    $etabId,
        public readonly int    $current,
        public readonly int    $total,
        public readonly string $state     = 'running',
        public readonly string $operation = 'create',
        public readonly ?string $group    = null,
        public readonly ?array  $newPost  = null,
    ) {}

    /**
     * Public channel scoped to the établissement.
     */
    public function broadcastOn(): Channel
    {
        return new Channel("catalogue.{$this->etabId}");
    }

    /**
     * Custom event name so the frontend listens to '.progress'
     */
    public function broadcastAs(): string
    {
        return 'progress';
    }

    /**
     * Data sent to the client.
     */
    public function broadcastWith(): array
    {
        return [
            'catalogue_id' => $this->catalogueId,
            'etab_id'      => $this->etabId,
            'current'      => $this->current,
            'total'        => $this->total,
            'state'        => $this->state,
            'operation'    => $this->operation,
            'group'        => $this->group,
            'new_post'     => $this->newPost,
        ];
    }
}
