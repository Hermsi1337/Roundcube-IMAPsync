<?php

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SyncEngineIntegrationTest extends TestCase
{
    private const MESSAGE_IDS = [
        '<integration-inbox-one@example.test>',
        '<integration-inbox-two@example.test>',
        '<integration-old-one@example.test>',
    ];

    private static ?DovecotContainer $sourceContainer = null;
    private static ?DovecotContainer $destinationContainer = null;
    private static string $sourceSubfolder = '';

    public static function setUpBeforeClass(): void
    {
        if (getenv('DOVECOT_INTEGRATION_SKIP') === '1') {
            self::markTestSkipped('DOVECOT_INTEGRATION_SKIP=1; skipping Dovecot integration tests.');
        }

        exec('docker info 2>&1', $dockerOutput, $dockerExitCode);
        if ($dockerExitCode !== 0) {
            self::markTestSkipped('Docker is not available; skipping Dovecot integration tests.');
        }

        if (!class_exists(Testcontainers\Container\GenericContainer::class)) {
            self::markTestSkipped('testcontainers-php is not installed; skipping Dovecot integration tests.');
        }

        self::$sourceContainer = (new DovecotContainer())->start();
        self::$destinationContainer = (new DovecotContainer())->start();

        self::seedSourceContainer();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            self::$sourceContainer?->stop();
        } finally {
            self::$destinationContainer?->stop();
            self::$sourceContainer = null;
            self::$destinationContainer = null;
        }
    }

    public function testSyncCopiesMessagesFromSourceToDestination(): void
    {
        $result = self::runSync();

        self::assertNull($result->fatalError);
        self::assertSame([], $result->errors);
        self::assertGreaterThanOrEqual(2, $result->foldersSynced);
        self::assertSame(3, $result->messagesCopied);
        self::assertSame(0, $result->messagesSkipped);

        $destinationImap = self::connectToContainer(self::requireDestinationContainer());

        try {
            $messageIds = array_merge(
                self::fetchMessageIds($destinationImap, 'INBOX'),
                self::fetchMessageIds($destinationImap, self::$sourceSubfolder),
            );
        } finally {
            $destinationImap->closeConnection();
        }

        sort($messageIds);
        $expectedMessageIds = self::MESSAGE_IDS;
        sort($expectedMessageIds);

        self::assertSame($expectedMessageIds, $messageIds);
    }

    #[Depends('testSyncCopiesMessagesFromSourceToDestination')]
    public function testRerunSkipsAlreadyCopiedMessages(): void
    {
        $result = self::runSync();

        self::assertNull($result->fatalError);
        self::assertSame([], $result->errors);
        self::assertSame(0, $result->messagesCopied);
        self::assertSame(3, $result->messagesSkipped);
    }

    #[Depends('testSyncCopiesMessagesFromSourceToDestination')]
    public function testSyncCreatesMissingDestinationSubfolders(): void
    {
        $destinationImap = self::connectToContainer(self::requireDestinationContainer());

        try {
            $folders = $destinationImap->listMailboxes('', '*');
        } finally {
            $destinationImap->closeConnection();
        }

        self::assertIsArray($folders);
        self::assertContains(self::$sourceSubfolder, $folders);
    }

    private static function seedSourceContainer(): void
    {
        $sourceImap = self::connectToContainer(self::requireSourceContainer());

        try {
            $delimiter = $sourceImap->getHierarchyDelimiter();
            self::$sourceSubfolder = 'INBOX' . ($delimiter ?: '/') . 'Old';

            if (!$sourceImap->createFolder(self::$sourceSubfolder)) {
                self::fail('Could not create source subfolder: ' . $sourceImap->error);
            }

            self::appendMessage(
                $sourceImap,
                'INBOX',
                self::buildMessage(self::MESSAGE_IDS[0], 'Inbox one', 'First inbox body.'),
            );
            self::appendMessage(
                $sourceImap,
                'INBOX',
                self::buildMessage(self::MESSAGE_IDS[1], 'Inbox two', 'Second inbox body.'),
            );
            self::appendMessage(
                $sourceImap,
                self::$sourceSubfolder,
                self::buildMessage(self::MESSAGE_IDS[2], 'Old folder', 'Old folder body.'),
            );

            self::consumeRecentFlags($sourceImap, 'INBOX');
            self::consumeRecentFlags($sourceImap, self::$sourceSubfolder);
        } finally {
            $sourceImap->closeConnection();
        }
    }

    private static function runSync(): RoundcubeImapSyncResult
    {
        $sourceContainer = self::requireSourceContainer();
        $destinationContainer = self::requireDestinationContainer();
        $sourceClient = new RoundcubeImapSyncGenericClient(new rcube_imap_generic());
        $destinationClient = new RoundcubeImapSyncGenericClient(new rcube_imap_generic());
        $destinationClient->connect(
            $destinationContainer->getHost(),
            $destinationContainer->getMappedImapPort(),
            $destinationContainer->getUser(),
            $destinationContainer->getPassword(),
            ['ssl_mode' => null, 'timeout' => 10],
        );

        try {
            $job = new RoundcubeImapSyncJob(
                $sourceContainer->getHost(),
                $sourceContainer->getMappedImapPort(),
                'none',
                $sourceContainer->getUser(),
                $sourceContainer->getPassword(),
            );
            $engine = new RoundcubeImapSyncEngine($sourceClient, $destinationClient);

            return $engine->run($job, static function (string $folder, int $current, int $total): void {
            });
        } finally {
            $destinationClient->close();
        }
    }

    private static function appendMessage(rcube_imap_generic $imap, string $folder, string $message): void
    {
        $messageToAppend = $message;

        if (!$imap->append($folder, $messageToAppend, ['SEEN'], '19-May-2026 12:00:00 +0000', false)) {
            self::fail("Could not append message to {$folder}: " . $imap->error);
        }
    }

    private static function consumeRecentFlags(rcube_imap_generic $imap, string $folder): void
    {
        if ($imap->fetchHeaders($folder, '1:*', false, false, ['MESSAGE-ID']) === false) {
            self::fail("Could not consume recent flags in {$folder}: " . $imap->error);
        }
    }

    private static function fetchMessageIds(rcube_imap_generic $imap, string $folder): array
    {
        $status = $imap->status($folder, ['MESSAGES']);
        if ($status === false) {
            self::fail("Could not inspect destination folder {$folder}: " . $imap->error);
        }

        if ((int) ($status['MESSAGES'] ?? 0) === 0) {

            return [];
        }

        $headers = $imap->fetchHeaders($folder, '1:*', false, false, ['MESSAGE-ID']);
        if ($headers === false) {
            self::fail("Could not fetch destination headers from {$folder}: " . $imap->error);
        }

        $messageIds = [];
        foreach ($headers as $header) {
            $messageIds[] = trim((string) ($header->messageID ?? ''));
        }

        return $messageIds;
    }

    private static function buildMessage(string $messageId, string $subject, string $body): string
    {
        return implode("\r\n", [
            'Message-ID: ' . $messageId,
            'Date: Tue, 19 May 2026 12:00:00 +0000',
            'From: Source <source@example.test>',
            'To: Destination <destination@example.test>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
        ]);
    }

    private static function connectToContainer(DovecotContainer $container): rcube_imap_generic
    {
        $imap = new rcube_imap_generic();

        if (!$imap->connect(
            $container->getHost(),
            $container->getUser(),
            $container->getPassword(),
            [
                'port' => $container->getMappedImapPort(),
                'ssl_mode' => null,
                'timeout' => 10,
            ],
        )) {
            self::fail('Could not connect to Dovecot: ' . $imap->error);
        }

        return $imap;
    }

    private static function requireSourceContainer(): DovecotContainer
    {
        if (self::$sourceContainer === null) {
            self::fail('Source Dovecot container was not started.');
        }

        return self::$sourceContainer;
    }

    private static function requireDestinationContainer(): DovecotContainer
    {
        if (self::$destinationContainer === null) {
            self::fail('Destination Dovecot container was not started.');
        }

        return self::$destinationContainer;
    }
}
