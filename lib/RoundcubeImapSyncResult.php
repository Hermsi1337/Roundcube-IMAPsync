<?php

class RoundcubeImapSyncResult
{
    public int $foldersSynced = 0;
    public int $messagesCopied = 0;
    public int $messagesSkipped = 0;
    public array $errors = [];
    public ?string $fatalError = null;
    public float $startedAt;
    public float $finishedAt = 0.0;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function toArray(): array
    {
        return [
            'foldersSynced' => $this->foldersSynced,
            'messagesCopied' => $this->messagesCopied,
            'messagesSkipped' => $this->messagesSkipped,
            'errors' => $this->errors,
            'fatalError' => $this->fatalError,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
        ];
    }
}
