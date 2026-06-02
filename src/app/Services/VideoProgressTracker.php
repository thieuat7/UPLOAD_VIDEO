<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class VideoProgressTracker
{
    private string $videoId;
    private string $keyPrefix = 'video:';

    public function __construct(int|string $videoId)
    {
        $this->videoId = (string)$videoId;
    }

    private function getKey(): string
    {
        return $this->keyPrefix . $this->videoId;
    }

    public function setStatus(string $status, array $additionalData = []): void
    {
        $data = array_merge(
            ['status' => $status, 'updated_at' => now()->toDateTimeString()],
            $additionalData
        );

        $this->setHash($data);
    }

    public function updateProgress(int $progress, ?string $message = null): void
    {
        $data = ['progress' => $progress, 'updated_at' => now()->toDateTimeString()];

        if ($message) {
            $data['current_step'] = $message;
        }

        $this->setHash($data);
    }

    private function setHash(array $data): void
    {
        $flattenedData = [];
        foreach ($data as $field => $value) {
            $flattenedData[] = $field;
            $flattenedData[] = $value;
        }

        call_user_func_array([Redis::class, 'hmset'], array_merge([$this->getKey()], $flattenedData));
    }

    public function setProcessing(): void
    {
        $this->setStatus('processing', ['progress' => 0]);
    }

    public function setSuccess(string $hlsPath): void
    {
        $this->setStatus('done', [
            'progress' => 100,
            'hls_path' => $hlsPath,
        ]);
    }

    public function setFailed(string $errorMessage): void
    {
        $this->setStatus('failed', [
            'error_message' => $errorMessage,
        ]);
    }

    public function getStatus(): ?array
    {
        $data = Redis::hgetall($this->getKey());
        return !empty($data) ? $data : null;
    }

    public function delete(): void
    {
        Redis::del($this->getKey());
    }

    public function setExpiry(int $seconds = 86400): void
    {
        Redis::expire($this->getKey(), $seconds);
    }
}
