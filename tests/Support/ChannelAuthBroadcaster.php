<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Channel authorization exactly as Pusher/Reverb do it, without a socket
 * server: tests `/broadcasting/auth` and routes/channels.php before Reverb
 * arrives (spec 5). The `log`/`null` drivers authorize nothing.
 */
final class ChannelAuthBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * @param  Request  $request
     */
    public function auth($request): mixed
    {
        $channel = $request->input('channel_name');

        if (! is_string($channel) || $channel === '') {
            throw new AccessDeniedHttpException;
        }

        $channelName = $this->normalizeChannelName($channel);

        if ($this->isGuardedChannel($channel) && ! $this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * @param  Request  $request
     * @return array{authorized: true}
     */
    public function validAuthenticationResponse($request, $result): array
    {
        return ['authorized' => true];
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void {}
}
