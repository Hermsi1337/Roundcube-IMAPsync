<?php

use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Wait\WaitForHostPort;

class DovecotContainer
{
    private const IMAP_PORT = 143;
    private const TEST_USER = 'testuser';
    private const TEST_PASSWORD = 'testpass';
    private const TEST_PASSWORD_HASH = '{BLF-CRYPT}$2y$05$JPDS3nhFpLNY5AROFYxe4OwyT6wf2FXF8P8pUqmWb/CXAHn4r/yaG';

    private ?StartedGenericContainer $container = null;
    private ?string $configDirectory = null;
    private ?string $mailDirectory = null;

    public function __construct(private readonly string $imageTag = 'dovecot/dovecot:latest-root')
    {
    }

    public function start(): self
    {
        if ($this->container !== null) {

            return $this;
        }

        $this->configDirectory = $this->createConfigDirectory();
        $this->mailDirectory = $this->createWritableDirectory('mail');
        $container = new GenericContainer($this->imageTag);

        try {
            $this->container = $container
                ->withEnvironment(['USER_PASSWORD' => self::TEST_PASSWORD_HASH])
                ->withMount($this->configDirectory, '/etc/dovecot/conf.d')
                ->withMount($this->mailDirectory, '/srv/mail')
                ->withExposedPorts(self::IMAP_PORT)
                ->withWait(new WaitForHostPort(30000, 200))
                ->start();

            $this->waitForTcpConnection();
        } catch (Throwable $containerStartError) {
            $this->removeConfigDirectory();
            $this->removeMailDirectory();

            throw new RuntimeException(
                'Dovecot container did not become ready: ' . $containerStartError->getMessage(),
                0,
                $containerStartError,
            );
        }

        return $this;
    }

    public function stop(): void
    {
        try {
            if ($this->container !== null) {
                $this->container->stop();
            }
        } finally {
            $this->container = null;
            $this->removeConfigDirectory();
            $this->removeMailDirectory();
        }
    }

    public function getHost(): string
    {
        return $this->requireStartedContainer()->getHost();
    }

    public function getMappedImapPort(): int
    {
        return $this->requireStartedContainer()->getMappedPort(self::IMAP_PORT);
    }

    public function getUser(): string
    {
        return self::TEST_USER;
    }

    public function getPassword(): string
    {
        return self::TEST_PASSWORD;
    }

    private function waitForTcpConnection(): void
    {
        $deadline = microtime(true) + 30;
        $lastConnectionError = 'connection refused';

        while (microtime(true) < $deadline) {
            $connection = @fsockopen(
                $this->getHost(),
                $this->getMappedImapPort(),
                $errorNumber,
                $errorMessage,
                2,
            );

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            $lastConnectionError = trim($errorMessage) !== '' ? $errorMessage : 'error ' . $errorNumber;
            usleep(200000);
        }

        throw new RuntimeException('Dovecot container did not become ready: ' . $lastConnectionError);
    }

    private function createConfigDirectory(): string
    {
        $configDirectory = $this->createWritableDirectory('config', 0700);

        $config = <<<'DOVECOT'
mail_home = /srv/mail/%{user}
mail_path = ~/Maildir
auth_allow_cleartext = yes
import_environment {
  USER_PASSWORD = %{env:USER_PASSWORD | default('{CRYPT}*')}
}
passdb static {
  password = %{env:USER_PASSWORD}
}
DOVECOT;

        if (file_put_contents($configDirectory . '/auth.conf', $config) === false) {
            throw new RuntimeException('Could not write Dovecot auth config.');
        }

        return $configDirectory;
    }

    private function createWritableDirectory(string $purpose, int $mode = 0777): string
    {
        $temporaryRoot = is_dir('/private/tmp') && is_writable('/private/tmp')
            ? '/private/tmp'
            : sys_get_temp_dir();
        $directory = rtrim($temporaryRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'imapsync-dovecot-'
            . $purpose
            . '-'
            . bin2hex(random_bytes(8));

        if (!mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create Dovecot {$purpose} directory.");
        }

        chmod($directory, $mode);

        return $directory;
    }

    private function removeConfigDirectory(): void
    {
        if ($this->configDirectory === null || !is_dir($this->configDirectory)) {

            return;
        }

        $files = scandir($this->configDirectory);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $this->configDirectory . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        rmdir($this->configDirectory);
        $this->configDirectory = null;
    }

    private function removeMailDirectory(): void
    {
        if ($this->mailDirectory === null || !is_dir($this->mailDirectory)) {

            return;
        }

        $this->removeDirectoryTree($this->mailDirectory);
        $this->mailDirectory = null;
    }

    private function removeDirectoryTree(string $directory): void
    {
        $files = scandir($directory);
        if ($files !== false) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $file;
                if (is_dir($path)) {
                    $this->removeDirectoryTree($path);
                    continue;
                }

                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        rmdir($directory);
    }

    private function requireStartedContainer(): StartedGenericContainer
    {
        if ($this->container === null) {
            throw new RuntimeException('Dovecot container is not started.');
        }

        return $this->container;
    }
}
