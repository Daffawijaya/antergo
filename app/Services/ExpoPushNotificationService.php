<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PushDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpoPushNotificationService
{
    public function notify(User $user, string $type, string $title, string $body, array $data): void
    {
        try {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $body,
                'data' => $data,
            ]);

            $tokens = $user->pushDeviceTokens()->pluck('token')->all();
            if ($tokens === []) {
                return;
            }

            foreach (array_chunk($tokens, 100) as $chunk) {
                $messages = array_map(fn (string $token) => [
                    'to' => $token,
                    'sound' => 'default',
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'channelId' => 'orders',
                ], $chunk);

                $response = Http::acceptJson()
                    ->timeout(8)
                    ->post(config('services.expo_push.url'), $messages);

                if (! $response->successful()) {
                    Log::warning('Expo push request failed.', ['status' => $response->status()]);
                    continue;
                }

                foreach ($response->json('data', []) as $index => $ticket) {
                    if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($chunk[$index])) {
                        PushDeviceToken::where('token', $chunk[$index])->delete();
                    }
                }
            }
        } catch (Throwable $error) {
            Log::warning('Push notification failed without interrupting order flow.', [
                'type' => $type,
                'user_id' => $user->id,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
