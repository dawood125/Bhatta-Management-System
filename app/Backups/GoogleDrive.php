<?php

namespace App\Backups;

use App\Models\AppSetting;
use App\Models\User;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Copies of the backups on the owner's Google Drive. The owner connects their Google account from
 * the Backups page with a short code entered at google.com/device (no address needs registering
 * with Google for each kiln). The app may only see the files it creates itself (drive.file): one
 * folder with the newest copies.
 */
class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const OAUTH = 'https://oauth2.googleapis.com';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    public function isAvailable(): bool
    {
        return filled(config('services.google_drive.client_id')) && filled(config('services.google_drive.client_secret'));
    }

    /**
     * Start connecting: the code the owner types at the address shown.
     *
     * @return array{user_code: string, url: string, expires_at: int}
     */
    public function startConnecting(User $by): array
    {
        $response = Http::asForm()->timeout(20)->post(self::OAUTH.'/device/code', [
            'client_id' => config('services.google_drive.client_id'),
            'scope' => self::SCOPE,
        ]);
        $this->ensure($response, 'Google did not give a code');

        $state = [
            'device_code' => (string) $response->json('device_code'),
            'user_code' => (string) $response->json('user_code'),
            'url' => (string) ($response->json('verification_url') ?? 'https://www.google.com/device'),
            'interval' => max(5, (int) $response->json('interval', 5)),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 1800))->getTimestamp(),
        ];
        Cache::put($this->stateKey($by), $state, now()->setTimestamp($state['expires_at']));

        return ['user_code' => $state['user_code'], 'url' => $state['url'], 'expires_at' => $state['expires_at']];
    }

    /**
     * Ask Google whether the owner has allowed access yet.
     *
     * @return 'connected'|'waiting'|'denied'|'expired'
     */
    public function checkConnecting(User $by): string
    {
        /** @var array{device_code: string, interval: int, expires_at: int}|null $state */
        $state = Cache::get($this->stateKey($by));
        if ($state === null || $state['expires_at'] < now()->getTimestamp()) {
            return 'expired';
        }

        $response = Http::asForm()->timeout(20)->post(self::OAUTH.'/token', [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'device_code' => $state['device_code'],
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]);

        if ($response->failed()) {
            return match ((string) $response->json('error')) {
                'authorization_pending', 'slow_down' => 'waiting',
                'access_denied' => $this->forget($by, 'denied'),
                'expired_token' => $this->forget($by, 'expired'),
                default => throw new RuntimeException('Google refused: '.$response->json('error_description', $response->json('error'))),
            };
        }

        $access = (string) $response->json('access_token');
        $refresh = (string) $response->json('refresh_token');
        Cache::put('google-drive:access', $access, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

        $email = (string) $this->api($access)->get(self::API.'/about', ['fields' => 'user(emailAddress)'])->json('user.emailAddress');
        $folder = $this->api($access)->post(self::API.'/files?fields=id', [
            'name' => config('app.name').' backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $this->ensure($folder, 'The backup folder could not be made on Google Drive');

        $settings = AppSetting::query()->find(1) ?? (new AppSetting)->forceFill(['id' => 1]);
        $settings->forceFill([
            'google_drive_token' => $refresh,
            'google_drive_email' => $email ?: null,
            'google_drive_folder_id' => (string) $folder->json('id'),
            'google_drive_connected_at' => now(),
            'updated_by' => $by->id,
        ])->save();

        return $this->forget($by, 'connected');
    }

    public function cancelConnecting(User $by): void
    {
        Cache::forget($this->stateKey($by));
    }

    /**
     * Upload one backup into the folder, then delete the oldest copies beyond the number kept.
     *
     * @param  array{disk: string, path: string, file: string, size: int}  $backup
     * @return array{id: string, deleted: int}
     */
    public function copy(array $backup): array
    {
        $settings = AppSetting::current();
        if (! $settings->hasGoogleDrive()) {
            throw new RuntimeException('Google Drive is not connected.');
        }
        $token = $this->accessToken($settings);
        $name = config('backup.backup.name').'-'.$backup['file'];

        $start = $this->api($token)
            ->withHeaders(['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $backup['size']])
            ->post(self::UPLOAD.'?uploadType=resumable&fields=id', ['name' => $name, 'parents' => [$settings->google_drive_folder_id]]);
        $this->ensure($start, 'Google Drive did not accept the upload');

        $stream = Storage::disk($backup['disk'])->readStream($backup['path']);
        if (! is_resource($stream)) {
            throw new RuntimeException("The backup {$backup['file']} cannot be read.");
        }
        $upload = Http::withToken($token)->timeout(1800)
            ->withBody(Utils::streamFor($stream), 'application/zip')
            ->put((string) $start->header('Location'));
        $this->ensure($upload, 'The upload to Google Drive failed');

        return ['id' => (string) $upload->json('id'), 'deleted' => $this->prune($settings, $token)];
    }

    /** Forget the Google account (and tell Google to drop the app's access). */
    public function disconnect(): void
    {
        $settings = AppSetting::current();
        if (filled($settings->google_drive_token)) {
            Http::asForm()->timeout(20)->post(self::OAUTH.'/revoke', ['token' => $settings->google_drive_token]);
        }
        Cache::forget('google-drive:access');

        if ($settings->exists) {
            $settings->forceFill(['google_drive_token' => null, 'google_drive_email' => null, 'google_drive_folder_id' => null, 'google_drive_connected_at' => null])->save();
        }
    }

    private function prune(AppSetting $settings, string $token): int
    {
        $files = $this->api($token)->get(self::API.'/files', [
            'q' => "'{$settings->google_drive_folder_id}' in parents and trashed = false",
            'orderBy' => 'createdTime desc',
            'fields' => 'files(id)',
            'pageSize' => 1000,
        ]);
        $this->ensure($files, 'The copies on Google Drive could not be listed');

        $deleted = 0;
        foreach (array_slice((array) $files->json('files', []), max(1, (int) config('services.google_drive.keep', 30))) as $file) {
            $this->api($token)->delete(self::API.'/files/'.$file['id']);
            $deleted++;
        }

        return $deleted;
    }

    private function accessToken(AppSetting $settings): string
    {
        $cached = Cache::get('google-drive:access');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(20)->post(self::OAUTH.'/token', [
            'client_id' => config('services.google_drive.client_id'),
            'client_secret' => config('services.google_drive.client_secret'),
            'refresh_token' => $settings->google_drive_token,
            'grant_type' => 'refresh_token',
        ]);
        if ($response->json('error') === 'invalid_grant') {
            throw new RuntimeException('Google Drive access was removed or has expired. Connect Google Drive again on the Backups page.');
        }
        $this->ensure($response, 'Google did not renew the access');

        $token = (string) $response->json('access_token');
        Cache::put('google-drive:access', $token, now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)));

        return $token;
    }

    private function api(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(60);
    }

    private function ensure(Response $response, string $what): void
    {
        if ($response->failed()) {
            // Drive API errors are objects with a message; sign-in errors are a code and a description.
            $error = $response->json('error');
            $detail = is_array($error)
                ? (string) ($error['message'] ?? json_encode($error))
                : (string) ($response->json('error_description') ?? $error ?? 'HTTP '.$response->status());

            throw new RuntimeException($what.': '.$detail);
        }
    }

    /** @param 'connected'|'denied'|'expired' $result */
    private function forget(User $by, string $result): string
    {
        Cache::forget($this->stateKey($by));

        return $result;
    }

    private function stateKey(User $by): string
    {
        return 'google-drive:connecting:'.$by->id;
    }
}
