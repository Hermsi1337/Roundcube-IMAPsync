<?php

class FakeImapClient implements RoundcubeImapSyncClient
{
    public array $folders = [];
    public bool $connectShouldFail = false;
    public array $appendFailures = [];
    public array $createFolderFailures = [];
    private string $delimiter;

    public function __construct(string $delimiter = '/')
    {
        $this->delimiter = $delimiter;
    }

    public function seed(string $folder, array $messages): void
    {
        $this->folders[$folder] = $messages;
    }

    public function connect(string $host, int $port, string $user, string $password, array $options): void
    {
        if ($this->connectShouldFail) {
            throw new RoundcubeImapSyncException('Source connect failed.');
        }
    }

    public function close(): void
    {
    }

    public function listFolders(): array
    {
        return array_keys($this->folders);
    }

    public function getHierarchyDelimiter(): string
    {
        return $this->delimiter;
    }

    public function createFolder(string $folder): bool
    {
        if (isset($this->createFolderFailures[$folder])) {
            throw new RoundcubeImapSyncException($this->createFolderFailures[$folder]);
        }

        if (!isset($this->folders[$folder])) {
            $this->folders[$folder] = [];

            return true;
        }

        return false;
    }

    public function selectFolder(string $folder): int
    {
        if (!isset($this->folders[$folder])) {
            throw new RoundcubeImapSyncException("Folder {$folder} does not exist.");
        }

        return count($this->folders[$folder]);
    }

    public function fetchMessageIdentities(string $folder): array
    {
        if (!isset($this->folders[$folder])) {
            throw new RoundcubeImapSyncException("Folder {$folder} does not exist.");
        }

        $identities = [];
        foreach ($this->folders[$folder] as $uid => $message) {
            $identities[$uid] = $message['identity'];
        }

        return $identities;
    }

    public function fetchMessageRaw(string $folder, int $uid): ?array
    {
        if (!isset($this->folders[$folder][$uid])) {
            return null;
        }

        return [
            'raw' => $this->folders[$folder][$uid]['raw'],
            'flags' => $this->folders[$folder][$uid]['flags'] ?? [],
            'internaldate' => $this->folders[$folder][$uid]['internaldate'] ?? null,
        ];
    }

    public function appendMessage(string $folder, string $rawMessage, array $flags, ?string $internalDate): bool
    {
        if (isset($this->appendFailures[$folder])) {
            throw new RoundcubeImapSyncException($this->appendFailures[$folder]);
        }

        if (!isset($this->folders[$folder])) {
            throw new RoundcubeImapSyncException("Folder {$folder} does not exist.");
        }

        $uid = $this->nextUid($folder);
        $this->folders[$folder][$uid] = [
            'raw' => $rawMessage,
            'flags' => $flags,
            'internaldate' => $internalDate,
            'identity' => $this->identityFromRaw($rawMessage),
        ];

        return true;
    }

    private function nextUid(string $folder): int
    {
        if ($this->folders[$folder] === []) {
            return 1;
        }

        return max(array_keys($this->folders[$folder])) + 1;
    }

    private function identityFromRaw(string $rawMessage): array
    {
        return [
            'message_id' => '<' . sha1($rawMessage) . '@example.test>',
            'size' => strlen($rawMessage),
            'date' => null,
            'subject_hash' => null,
        ];
    }
}
