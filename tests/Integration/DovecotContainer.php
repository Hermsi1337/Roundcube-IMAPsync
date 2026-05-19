<?php

use Testcontainers\Container\GenericContainer;
use Testcontainers\Container\StartedGenericContainer;

class DovecotContainer
{
    private const IMAP_PORT = 143;
    private const TEST_USER = 'testuser';
    private const TEST_PASSWORD = 'testpass';
    private const TEST_PASSWORD_HASH = '{BLF-CRYPT}$2y$05$JPDS3nhFpLNY5AROFYxe4OwyT6wf2FXF8P8pUqmWb/CXAHn4r/yaG';

    private ?StartedGenericContainer $container = null;
    private ?string $configDirectory = null;

    public function __construct(private readonly string $imageTag = 'dovecot/dovecot:latest-root')
    {
    }

    public function start(): self
    {
        if ($this->container !== null) {

            return $this;
        }

        $this->configDirectory = $this->createConfigDirectory();
        $container = new GenericContainer($this->imageTag);

        try {
            // Mail storage stays inside the container's writable layer: we read
            // it only over IMAP, and letting it live in the container avoids a
            // bind-mount whose contents would be owned by root on teardown and
            // unremovable by the unprivileged host test process.
            // We also don't pass a withWait() strategy — testcontainers-php's
            // WaitForHostPort emits foreach-over-null warnings during polling
            // (library bug). Our own waitForTcpConnection() below is enough.
            $this->container = $container
                ->withEnvironment(['USER_PASSWORD' => self::TEST_PASSWORD_HASH])
                ->withMount($this->configDirectory, '/etc/dovecot/conf.d')
                ->withExposedPorts(self::IMAP_PORT)
                ->start();

            $this->waitForTcpConnection();
        } catch (Throwable $containerStartError) {
            $this->removeConfigDirectory();

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

        // Mail lives under /tmp/ inside the container so we don't need a host
        // bind mount. /tmp exists in every Linux base image, the :latest-root
        // image runs as root and can create subdirs there, and everything is
        // discarded automatically when the container stops.
        $config = <<<'DOVECOT'
mail_home = /tmp/dovecot/%{user}
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

    private function requireStartedContainer(): StartedGenericContainer
    {
        if ($this->container === null) {
            throw new RuntimeException('Dovecot container is not started.');
        }

        return $this->container;
    }
}
