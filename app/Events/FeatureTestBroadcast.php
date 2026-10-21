<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class FeatureTestBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Create a new event instance.
     */
    public function __construct(public string $token, public string $sentAt) {}

    /**
     * Get the name of the public channel the feature test listens on.
     */
    public static function channelName(string $token): string
    {
        return "feature-tests.{$token}";
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel(self::channelName($this->token)),
        ];
    }

    /**
     * Get the event name the client listens for.
     */
    public function broadcastAs(): string
    {
        return 'feature-test.ping';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{token: string, sent_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'token' => $this->token,
            'sent_at' => $this->sentAt,
        ];
    }
}
