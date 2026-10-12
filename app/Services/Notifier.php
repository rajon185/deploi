<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Fans an event out to the user's Slack / Discord / webhook channels. Never throws. */
class Notifier
{
    public static function send(User $user, string $event, string $title, string $message, ?string $url = null): void
    {
        foreach ($user->notificationChannels as $channel) {
            if (! $channel->wants($event)) {
                continue;
            }

            $text = "*{$title}*\n{$message}".($url ? "\n{$url}" : '');

            $payload = match ($channel->type) {
                'slack' => ['text' => $text],
                'discord' => ['content' => str_replace('*', '**', $text)],
                default => ['event' => $event, 'title' => $title, 'message' => $message, 'url' => $url],
            };

            try {
                Http::timeout(10)->post($channel->url, $payload);
            } catch (Throwable $e) {
                Log::warning("Notification channel {$channel->id} failed: {$e->getMessage()}");
            }
        }
    }
}
