<?php

use PHPUnit\Framework\TestCase;

final class ImapSyncEngineTest extends TestCase
{
    public function testEmptySourceProducesEmptyResult(): void
    {
        $source = new FakeImapClient();
        $destination = new FakeImapClient();

        $result = $this->runEngine($source, $destination);

        self::assertSame(0, $result->foldersSynced);
        self::assertSame(0, $result->messagesCopied);
        self::assertSame(0, $result->messagesSkipped);
        self::assertSame([], $result->errors);
        self::assertNull($result->fatalError);
    }

    public function testSingleMessageIsCopied(): void
    {
        $source = new FakeImapClient();
        $source->seed('INBOX', [1 => $this->message('<one@example.test>', 'Subject')]);
        $destination = new FakeImapClient();

        $result = $this->runEngine($source, $destination);

        self::assertSame(1, $result->foldersSynced);
        self::assertSame(1, $result->messagesCopied);
        self::assertSame(0, $result->messagesSkipped);
        self::assertCount(1, $destination->folders['INBOX']);
    }

    public function testExistingMessageIsSkipped(): void
    {
        $source = new FakeImapClient();
        $destination = new FakeImapClient();
        $message = $this->message('<same@example.test>', 'Subject');
        $source->seed('INBOX', [1 => $message]);
        $destination->seed('INBOX', [10 => $message]);

        $result = $this->runEngine($source, $destination);

        self::assertSame(1, $result->foldersSynced);
        self::assertSame(0, $result->messagesCopied);
        self::assertSame(1, $result->messagesSkipped);
        self::assertCount(1, $destination->folders['INBOX']);
    }

    public function testNestedFolderIsCreatedAndPopulated(): void
    {
        $source = new FakeImapClient('/');
        $source->seed('INBOX/Subfolder', [1 => $this->message('<nested@example.test>', 'Nested')]);
        $destination = new FakeImapClient('.');

        $result = $this->runEngine($source, $destination);

        self::assertSame(1, $result->foldersSynced);
        self::assertArrayHasKey('INBOX.Subfolder', $destination->folders);
        self::assertCount(1, $destination->folders['INBOX.Subfolder']);
    }

    public function testSkipListFiltersFolders(): void
    {
        $source = new FakeImapClient();
        $source->seed('INBOX', [1 => $this->message('<one@example.test>', 'Inbox')]);
        $source->seed('Trash', [1 => $this->message('<trash@example.test>', 'Trash')]);
        $destination = new FakeImapClient();

        $result = $this->runEngine($source, $destination, ['skip_folders' => ['Trash']]);

        self::assertSame(1, $result->foldersSynced);
        self::assertArrayHasKey('INBOX', $destination->folders);
        self::assertArrayNotHasKey('Trash', $destination->folders);
    }

    public function testAppendErrorIsRecordedAndSyncContinues(): void
    {
        $source = new FakeImapClient();
        $source->seed('INBOX', [1 => $this->message('<one@example.test>', 'Inbox')]);
        $source->seed('Archive', [1 => $this->message('<archive@example.test>', 'Archive')]);
        $destination = new FakeImapClient();
        $destination->appendFailures['INBOX'] = 'Append failed.';

        $result = $this->runEngine($source, $destination);

        self::assertSame(2, $result->foldersSynced);
        self::assertSame(1, $result->messagesCopied);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Append failed.', $result->errors[0]);
        self::assertCount(1, $destination->folders['Archive']);
    }

    public function testCreateFolderErrorSkipsFolderAndContinues(): void
    {
        $source = new FakeImapClient();
        $source->seed('INBOX', [1 => $this->message('<one@example.test>', 'Inbox')]);
        $source->seed('Archive', [1 => $this->message('<archive@example.test>', 'Archive')]);
        $destination = new FakeImapClient();
        $destination->createFolderFailures['INBOX'] = 'Create failed.';

        $result = $this->runEngine($source, $destination);

        self::assertSame(1, $result->foldersSynced);
        self::assertSame(1, $result->messagesCopied);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Create failed.', $result->errors[0]);
        self::assertArrayHasKey('Archive', $destination->folders);
    }

    public function testSourceConnectFailureSetsFatalError(): void
    {
        $source = new FakeImapClient();
        $source->connectShouldFail = true;
        $destination = new FakeImapClient();

        $result = $this->runEngine($source, $destination);

        self::assertSame(0, $result->foldersSynced);
        self::assertSame(0, $result->messagesCopied);
        self::assertSame('Source connect failed.', $result->fatalError);
    }

    public function testProgressCallbackReceivesExpectedTuples(): void
    {
        $source = new FakeImapClient();
        $source->seed('INBOX', [
            1 => $this->message('<one@example.test>', 'One'),
            2 => $this->message('<two@example.test>', 'Two'),
        ]);
        $destination = new FakeImapClient();
        $progress = [];
        $job = new RoundcubeImapSyncJob('imap.example.test', 993, 'ssl', 'user', 'secret');
        $engine = new RoundcubeImapSyncEngine($source, $destination);

        $engine->run($job, static function (string $folder, int $current, int $total) use (&$progress): void {
            $progress[] = [$folder, $current, $total];
        });

        self::assertSame([
            ['INBOX', 0, 2],
            ['INBOX', 2, 2],
        ], $progress);
    }

    private function runEngine(FakeImapClient $source, FakeImapClient $destination, array $options = []): RoundcubeImapSyncResult
    {
        $job = new RoundcubeImapSyncJob('imap.example.test', 993, 'ssl', 'user', 'secret', $options);
        $engine = new RoundcubeImapSyncEngine($source, $destination);

        return $engine->run($job, static function (string $folder, int $current, int $total): void {
        });
    }

    private function message(string $messageId, string $subject): array
    {
        $raw = "Message-ID: {$messageId}\r\nSubject: {$subject}\r\n\r\nBody";

        return [
            'raw' => $raw,
            'flags' => ['SEEN'],
            'internaldate' => '19-May-2026 12:00:00 +0000',
            'identity' => [
                'message_id' => $messageId,
                'size' => strlen($raw),
                'date' => 'Tue, 19 May 2026 12:00:00 +0000',
                'subject_hash' => sha1($subject),
            ],
        ];
    }
}
