<?php

/**
 * Synchronizes from a source IMAP client into a destination client.
 *
 * The destination client is expected to be connected already by the caller.
 */
class RoundcubeImapSyncEngine
{
    private const PROGRESS_BATCH_SIZE = 10;

    public function __construct(
        private RoundcubeImapSyncClient $source,
        private RoundcubeImapSyncClient $destination,
    ) {
    }

    public function run(RoundcubeImapSyncJob $job, callable $progress): RoundcubeImapSyncResult
    {
        $result = new RoundcubeImapSyncResult();

        try {
            $this->source->connect(
                $job->getHost(),
                $job->getPort(),
                $job->getSourceUser(),
                $job->getSourcePassword(),
                $this->buildConnectionOptions($job),
            );

            $sourceDelimiter = $this->source->getHierarchyDelimiter();
            $destinationDelimiter = $this->destination->getHierarchyDelimiter();
            $folders = $this->filterFolders($this->source->listFolders(), $job->getSkipFolders());

            foreach ($folders as $sourceFolder) {
                $destinationFolder = $this->mapFolderName(
                    $sourceFolder,
                    $sourceDelimiter,
                    $destinationDelimiter,
                    $job->getFolderPrefix(),
                );

                $this->syncFolder($sourceFolder, $destinationFolder, $progress, $result);
            }
        } catch (RoundcubeImapSyncException $syncException) {
            $result->fatalError = $syncException->getMessage();
        } finally {
            $result->finishedAt = microtime(true);
            $this->source->close();
        }

        return $result;
    }

    private function syncFolder(
        string $sourceFolder,
        string $destinationFolder,
        callable $progress,
        RoundcubeImapSyncResult $result,
    ): void {
        try {
            $totalMessages = $this->source->selectFolder($sourceFolder);
            $progress($sourceFolder, 0, $totalMessages);
            $this->ensureDestinationFolder($destinationFolder);

            $destinationKeys = $this->buildDedupSet($this->destination->fetchMessageIdentities($destinationFolder));
            $sourceIdentities = $this->source->fetchMessageIdentities($sourceFolder);
        } catch (RoundcubeImapSyncException $folderException) {
            $result->errors[] = "Folder {$sourceFolder}: {$folderException->getMessage()}";

            return;
        }

        $processedMessages = 0;
        foreach ($sourceIdentities as $uid => $identity) {
            $processedMessages++;

            try {
                $dedupKey = $this->buildDedupKey($identity);
                if ($dedupKey !== null && isset($destinationKeys[$dedupKey])) {
                    $result->messagesSkipped++;
                    $this->reportProgress($progress, $sourceFolder, $processedMessages, $totalMessages);
                    continue;
                }

                $rawMessage = $this->source->fetchMessageRaw($sourceFolder, (int) $uid);
                if ($rawMessage === null) {
                    $result->errors[] = "Message {$uid} in {$sourceFolder}: message could not be fetched.";
                    $this->reportProgress($progress, $sourceFolder, $processedMessages, $totalMessages);
                    continue;
                }

                if (!$this->destination->appendMessage(
                    $destinationFolder,
                    $rawMessage['raw'],
                    $rawMessage['flags'],
                    $rawMessage['internaldate'],
                )) {
                    $result->errors[] = "Message {$uid} in {$sourceFolder}: message could not be appended.";
                    $this->reportProgress($progress, $sourceFolder, $processedMessages, $totalMessages);
                    continue;
                }

                $result->messagesCopied++;
                if ($dedupKey !== null) {
                    $destinationKeys[$dedupKey] = true;
                }
            } catch (RoundcubeImapSyncException $messageException) {
                $result->errors[] = "Message {$uid} in {$sourceFolder}: {$messageException->getMessage()}";
            }

            $this->reportProgress($progress, $sourceFolder, $processedMessages, $totalMessages);
        }

        if ($totalMessages === 0) {
            $progress($sourceFolder, 0, 0);
        }

        $result->foldersSynced++;
    }

    private function ensureDestinationFolder(string $folder): void
    {
        if (in_array($folder, $this->destination->listFolders(), true)) {
            return;
        }

        if ($this->destination->createFolder($folder)) {
            return;
        }

        if (!in_array($folder, $this->destination->listFolders(), true)) {
            throw new RoundcubeImapSyncException("Destination folder {$folder} could not be created.");
        }
    }

    private function buildConnectionOptions(RoundcubeImapSyncJob $job): array
    {
        if ($job->getEncryption() === 'none') {
            return ['ssl_mode' => null];
        }

        return ['ssl_mode' => $job->getEncryption()];
    }

    private function filterFolders(array $folders, array $skipFolders): array
    {
        $skipLookup = array_fill_keys($skipFolders, true);
        $filteredFolders = [];

        foreach ($folders as $folder) {
            if (isset($skipLookup[$folder])) {
                continue;
            }

            $filteredFolders[] = $folder;
        }

        return $filteredFolders;
    }

    private function mapFolderName(
        string $sourceFolder,
        string $sourceDelimiter,
        string $destinationDelimiter,
        string $folderPrefix,
    ): string {
        $mappedFolder = $sourceDelimiter === $destinationDelimiter
            ? $sourceFolder
            : str_replace($sourceDelimiter, $destinationDelimiter, $sourceFolder);

        return $folderPrefix . $mappedFolder;
    }

    private function buildDedupSet(array $identities): array
    {
        $dedupSet = [];

        foreach ($identities as $identity) {
            $dedupKey = $this->buildDedupKey($identity);
            if ($dedupKey !== null) {
                $dedupSet[$dedupKey] = true;
            }
        }

        return $dedupSet;
    }

    private function buildDedupKey(array $identity): ?string
    {
        $size = $identity['size'] ?? null;
        if (!empty($identity['message_id']) && $size !== null) {
            return $identity['message_id'] . '|' . $size;
        }

        if (!empty($identity['subject_hash']) && !empty($identity['date']) && $size !== null) {
            return $identity['subject_hash'] . '|' . $identity['date'] . '|' . $size;
        }

        return null;
    }

    private function reportProgress(callable $progress, string $folder, int $current, int $total): void
    {
        if ($current === $total || $current % self::PROGRESS_BATCH_SIZE === 0) {
            $progress($folder, $current, $total);
        }
    }
}
