<?php

class RoundcubeImapSyncJob
{
    private array $skipFolders;
    private string $folderPrefix;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $sourceUser,
        private readonly string $sourcePassword,
        array $options = [],
    ) {
        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('IMAP port must be between 1 and 65535.');
        }

        if (!in_array($this->encryption, ['ssl', 'tls', 'none'], true)) {
            throw new InvalidArgumentException('Unsupported IMAP encryption mode.');
        }

        $skipFolders = $options['skip_folders'] ?? [];
        if (!is_array($skipFolders)) {
            throw new InvalidArgumentException('skip_folders must be an array.');
        }

        $this->skipFolders = array_values(array_map('strval', $skipFolders));
        $this->folderPrefix = (string) ($options['folder_prefix'] ?? '');
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getEncryption(): string
    {
        return $this->encryption;
    }

    public function getSourceUser(): string
    {
        return $this->sourceUser;
    }

    /**
     * @internal Only the sync engine should read the source password.
     */
    public function getSourcePassword(): string
    {
        return $this->sourcePassword;
    }

    public function getSkipFolders(): array
    {
        return $this->skipFolders;
    }

    public function getFolderPrefix(): string
    {
        return $this->folderPrefix;
    }
}
