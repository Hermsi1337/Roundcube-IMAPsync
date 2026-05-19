<?php

use PHPUnit\Framework\TestCase;

final class ImapSyncJobTest extends TestCase
{
    public function testJobStoresValues(): void
    {
        $job = new RoundcubeImapSyncJob('imap.example.test', 993, 'ssl', 'user', 'secret', [
            'skip_folders' => ['Trash'],
            'folder_prefix' => 'Imported/',
        ]);

        self::assertSame('imap.example.test', $job->getHost());
        self::assertSame(993, $job->getPort());
        self::assertSame('ssl', $job->getEncryption());
        self::assertSame('user', $job->getSourceUser());
        self::assertSame('secret', $job->getSourcePassword());
        self::assertSame(['Trash'], $job->getSkipFolders());
        self::assertSame('Imported/', $job->getFolderPrefix());
    }

    public function testInvalidEncryptionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoundcubeImapSyncJob('imap.example.test', 993, 'plain', 'user', 'secret');
    }

    public function testInvalidPortThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoundcubeImapSyncJob('imap.example.test', 70000, 'ssl', 'user', 'secret');
    }
}
