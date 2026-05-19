<?php

interface RoundcubeImapSyncClient
{
    public function connect(string $host, int $port, string $user, string $password, array $options): void;

    public function close(): void;

    public function listFolders(): array;

    public function getHierarchyDelimiter(): string;

    public function createFolder(string $folder): bool;

    public function selectFolder(string $folder): int;

    public function fetchMessageIdentities(string $folder): array;

    public function fetchMessageRaw(string $folder, int $uid): ?array;

    public function appendMessage(string $folder, string $rawMessage, array $flags, ?string $internalDate): bool;
}

class RoundcubeImapSyncGenericClient implements RoundcubeImapSyncClient
{
    public function __construct(private readonly rcube_imap_generic $imap)
    {
    }

    public function connect(string $host, int $port, string $user, string $password, array $options): void
    {
        $options['port'] = $port;

        if (!$this->imap->connect($host, $user, $password, $options)) {
            throw new RoundcubeImapSyncException($this->getErrorMessage('Could not connect to IMAP server.'));
        }
    }

    public function close(): void
    {
        $this->imap->closeConnection();
    }

    public function listFolders(): array
    {
        $folders = $this->imap->listMailboxes('', '*');
        if ($folders === false) {
            throw new RoundcubeImapSyncException($this->getErrorMessage('Could not list IMAP folders.'));
        }

        return array_values(array_map('strval', $folders));
    }

    public function getHierarchyDelimiter(): string
    {
        $delimiter = $this->imap->getHierarchyDelimiter();

        return is_string($delimiter) && $delimiter !== '' ? $delimiter : '/';
    }

    public function createFolder(string $folder): bool
    {
        return $this->imap->createFolder($folder);
    }

    public function selectFolder(string $folder): int
    {
        $status = $this->imap->status($folder, ['MESSAGES']);
        if ($status === false) {
            throw new RoundcubeImapSyncException($this->getErrorMessage("Could not inspect folder {$folder}."));
        }

        return (int) ($status['MESSAGES'] ?? 0);
    }

    public function fetchMessageIdentities(string $folder): array
    {
        $totalMessages = $this->selectFolder($folder);
        if ($totalMessages === 0) {
            return [];
        }

        $headers = $this->imap->fetchHeaders($folder, '1:*', false, false, ['MESSAGE-ID']);
        if ($headers === false) {
            throw new RoundcubeImapSyncException($this->getErrorMessage("Could not fetch message headers for {$folder}."));
        }

        $identities = [];
        foreach ($headers as $sequenceId => $header) {
            $uid = (int) ($header->uid ?? $sequenceId);
            $messageId = $this->normalizeMessageId($header->messageID ?? null);
            $subject = isset($header->subject) ? (string) $header->subject : null;
            $date = isset($header->date) ? (string) $header->date : null;

            $identities[$uid] = [
                'message_id' => $messageId,
                'size' => isset($header->size) ? (int) $header->size : null,
                'date' => $date,
                'subject_hash' => $subject !== null ? sha1($subject) : null,
            ];
        }

        return $identities;
    }

    public function fetchMessageRaw(string $folder, int $uid): ?array
    {
        $messages = $this->imap->fetch($folder, (string) $uid, true, ['UID', 'RFC822', 'FLAGS', 'INTERNALDATE']);
        if ($messages === false) {
            throw new RoundcubeImapSyncException($this->getErrorMessage("Could not fetch message {$uid} from {$folder}."));
        }

        $message = array_shift($messages);
        if (!$message || !isset($message->body)) {
            return null;
        }

        return [
            'raw' => (string) $message->body,
            'flags' => array_keys((array) ($message->flags ?? [])),
            'internaldate' => isset($message->internaldate) ? (string) $message->internaldate : null,
        ];
    }

    public function appendMessage(string $folder, string $rawMessage, array $flags, ?string $internalDate): bool
    {
        $message = $rawMessage;
        $result = $this->imap->append($folder, $message, $flags, $internalDate, false);
        if (!$result && $this->imap->error) {
            throw new RoundcubeImapSyncException($this->getErrorMessage("Could not append message to {$folder}."));
        }

        return $result;
    }

    private function normalizeMessageId(?string $messageId): ?string
    {
        $messageId = trim((string) $messageId);
        if ($messageId === '' || str_starts_with($messageId, 'mid:')) {
            return null;
        }

        return $messageId;
    }

    private function getErrorMessage(string $fallback): string
    {
        $message = trim((string) ($this->imap->error ?? ''));

        return $message !== '' ? $message : $fallback;
    }
}
