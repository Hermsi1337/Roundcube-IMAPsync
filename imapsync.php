<?php

require_once __DIR__ . '/lib/RoundcubeImapSyncException.php';
require_once __DIR__ . '/lib/RoundcubeImapSyncClient.php';
require_once __DIR__ . '/lib/RoundcubeImapSyncJob.php';
require_once __DIR__ . '/lib/RoundcubeImapSyncResult.php';
require_once __DIR__ . '/lib/RoundcubeImapSyncEngine.php';

class imapsync extends rcube_plugin
{
    public $task = 'settings';

    private rcmail $rc;

    public function init(): void
    {
        $this->rc = rcmail::get_instance();

        $this->load_config();
        $this->add_texts('localization/', true);
        $this->add_hook('settings_actions', [$this, 'settings_actions']);
        $this->register_action('plugin.imapsync', [$this, 'action_form']);
        $this->register_action('plugin.imapsync.start', [$this, 'action_start']);
        $this->register_action('plugin.imapsync.status', [$this, 'action_status']);
    }

    public function settings_actions(array $args): array
    {
        $args['actions'][] = [
            'action' => 'plugin.imapsync',
            'class' => 'imapsync',
            'label' => 'pagetitle',
            'domain' => 'imapsync',
            'title' => 'pagetitle',
        ];

        return $args;
    }

    public function action_form(): void
    {
        $this->include_stylesheet($this->local_skin_path() . '/imapsync.css');
        $this->include_script('imapsync.js');
        $this->rc->output->add_handler('plugin.imapsyncform', [$this, 'render_form']);
        $this->rc->output->add_label(
            'imapsync.confirmstart',
            'imapsync.progresstitle',
            'imapsync.summarytitle',
            'imapsync.folderssynced',
            'imapsync.messagescopied',
            'imapsync.messagesskipped',
            'imapsync.errors',
            'imapsync.donesuccess',
            'imapsync.donewitherrors',
            'imapsync.errorvalidation',
        );
        $this->rc->output->set_env('imapsync_allow_insecure', $this->allowInsecure());
        $this->rc->output->set_pagetitle($this->gettext('pagetitle'));
        $this->rc->output->send('imapsync.imapsync');
    }

    public function render_form(array $attrib): string
    {
        $host = new html_inputfield([
            'name' => '_host',
            'id' => 'imapsync-host',
            'class' => 'form-control',
            'required' => true,
        ]);
        $port = new html_inputfield([
            'name' => '_port',
            'id' => 'imapsync-port',
            'class' => 'form-control',
            'type' => 'number',
            'min' => 1,
            'max' => 65535,
            'required' => true,
        ]);
        $user = new html_inputfield([
            'name' => '_source_user',
            'id' => 'imapsync-user',
            'class' => 'form-control',
            'required' => true,
            'autocomplete' => 'username',
        ]);
        $password = new html_passwordfield([
            'name' => '_source_password',
            'id' => 'imapsync-password',
            'class' => 'form-control',
            'required' => true,
            'autocomplete' => 'current-password',
        ]);
        $encryption = new html_select([
            'name' => '_encryption',
            'id' => 'imapsync-encryption',
            'class' => 'custom-select',
        ]);
        $encryption->add($this->gettext('encryptionssl'), 'ssl');
        $encryption->add($this->gettext('encryptiontls'), 'tls');

        if ($this->allowInsecure()) {
            $encryption->add($this->gettext('encryptionnone'), 'none');
        } else {
            $encryption->add($this->gettext('encryptionnone'), 'none', ['disabled' => 'disabled']);
        }

        $table = new html_table(['class' => 'propform imapsync-form-table', 'cols' => 2]);
        $this->addFormRow($table, 'imapsync-host', 'sourcehost', $host->show());
        $this->addFormRow($table, 'imapsync-port', 'sourceport', $port->show('993'));
        $this->addFormRow($table, 'imapsync-encryption', 'encryption', $encryption->show('ssl'));
        $this->addFormRow($table, 'imapsync-user', 'sourceuser', $user->show());
        $this->addFormRow($table, 'imapsync-password', 'sourcepassword', $password->show());

        $button = html::tag('button', [
            'type' => 'submit',
            'id' => 'imapsync-submit',
            'class' => 'button mainaction submit',
        ], rcube::Q($this->gettext('startsync')));
        $cancelButton = html::tag('button', [
            'type' => 'button',
            'id' => 'imapsync-cancel',
            'class' => 'button cancel',
            'hidden' => 'hidden',
        ], rcube::Q($this->gettext('cancelsync')));
        $form = $this->rc->output->form_tag([
            'id' => 'imapsync-form',
            'name' => 'imapsync-form',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.imapsync.start',
        ], $table->show() . html::p(['class' => 'formbuttons footerleft'], $button . ' ' . $cancelButton));

        $notice = html::div(['class' => 'boxinformation imapsync-notice'],
            html::tag('strong', ['class' => 'imapsync-notice-title'], rcube::Q($this->gettext('noticetitle')))
            . html::tag('ul', ['class' => 'imapsync-notice-list'],
                html::tag('li', [], rcube::Q($this->gettext('noticesynchronous')))
                . html::tag('li', [], rcube::Q($this->gettext('noticeretry')))
                . html::tag('li', [], rcube::Q($this->gettext('noticeduration')))
            )
        );

        return html::div(['id' => 'prefs-title', 'class' => 'boxtitle'], rcube::Q($this->gettext('pagetitle')))
            . html::div(['class' => 'box formcontainer scroller'],
                html::div(['class' => 'boxcontent formcontent'],
                    html::p(['class' => 'imapsync-intro'], rcube::Q($this->gettext('intro')))
                    . $notice
                    . $form
                )
            );
    }

    public function action_start(): void
    {
        // Release the session write-lock immediately: the sync may run for many
        // seconds and we don't want it to block every other AJAX request the
        // browser fires during that time. From here on $_SESSION is read-only
        // in-memory; nothing we set will be persisted.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            $job = $this->createJobFromRequest();
            $source = new RoundcubeImapSyncGenericClient(new rcube_imap_generic());
            $destination = $this->createDestinationClient();
            $engine = new RoundcubeImapSyncEngine($source, $destination);

            $result = $engine->run($job, static function (string $folder, int $current, int $total): void {
                // Live progress is intentionally not exposed in the synchronous
                // MVP — see AGENTS.md "Open work / known limits". The callback
                // stays so the engine signature is stable for the planned
                // worker mode.
            });
        } catch (InvalidArgumentException $validationException) {
            $message = $validationException->getMessage() !== ''
                ? $validationException->getMessage()
                : $this->gettext('errorvalidation');
            $this->rc->output->command('plugin.imapsync_error', $message);
            $this->rc->output->send();

            return;
        } catch (RoundcubeImapSyncException $syncException) {
            $result = new RoundcubeImapSyncResult();
            $result->fatalError = $syncException->getMessage();
            $result->finishedAt = microtime(true);
        }

        $this->rc->output->command('plugin.imapsync_status', [
            'running' => false,
            'progress' => [],
            'result' => $result->toArray(),
        ]);
        $this->rc->output->send();
    }

    public function action_status(): void
    {
        // Retained for the planned worker-mode iteration. In the synchronous
        // MVP the start action returns the final result directly, so this
        // endpoint always reports "nothing running, no result".
        $this->rc->output->command('plugin.imapsync_status', [
            'running' => false,
            'progress' => [],
            'result' => null,
        ]);
        $this->rc->output->send();
    }

    private function addFormRow(html_table $table, string $fieldId, string $labelKey, string $field): void
    {
        $table->add('title', html::label($fieldId, rcube::Q($this->gettext($labelKey))));
        $table->add(null, $field);
    }

    private function createJobFromRequest(): RoundcubeImapSyncJob
    {
        $host = trim(rcube_utils::get_input_string('_host', rcube_utils::INPUT_POST));
        $port = (int) rcube_utils::get_input_value('_port', rcube_utils::INPUT_POST);
        $encryption = trim(rcube_utils::get_input_string('_encryption', rcube_utils::INPUT_POST));
        $sourceUser = trim(rcube_utils::get_input_string('_source_user', rcube_utils::INPUT_POST));
        $sourcePassword = (string) rcube_utils::get_input_value('_source_password', rcube_utils::INPUT_POST);

        if ($host === '' || $sourceUser === '' || $sourcePassword === '') {
            throw new InvalidArgumentException($this->gettext('errorvalidation'));
        }

        if ($port < 1 || $port > 65535 || !in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            throw new InvalidArgumentException($this->gettext('errorvalidation'));
        }

        if ($encryption === 'none' && !$this->allowInsecure()) {
            throw new InvalidArgumentException($this->gettext('errorinsecure'));
        }

        if (!$this->hostAllowed($host)) {
            throw new InvalidArgumentException($this->gettext('errorhostblocked'));
        }

        return new RoundcubeImapSyncJob($host, $port, $encryption, $sourceUser, $sourcePassword, [
            'skip_folders' => $this->rc->config->get('imapsync_skip_folders', []),
            'folder_prefix' => $this->rc->config->get('imapsync_folder_prefix', ''),
        ]);
    }

    private function createDestinationClient(): RoundcubeImapSyncClient
    {
        if (!$this->rc->storage_connect()) {
            throw new RoundcubeImapSyncException($this->gettext('errorconnect'));
        }

        $storage = $this->rc->get_storage();
        if (!isset($storage->conn) || !$storage->conn instanceof rcube_imap_generic) {
            throw new RoundcubeImapSyncException($this->gettext('errorconnect'));
        }

        return new RoundcubeImapSyncGenericClient($storage->conn);
    }

    private function hostAllowed(string $host): bool
    {
        $allowlist = (array) $this->rc->config->get('imapsync_host_allowlist', []);
        $denylist = (array) $this->rc->config->get('imapsync_host_denylist', []);

        if ($this->matchesAnyPattern($host, $denylist)) {
            return false;
        }

        if ($allowlist === []) {
            return true;
        }

        return $this->matchesAnyPattern($host, $allowlist);
    }

    private function matchesAnyPattern(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && @preg_match($pattern, $host) === 1) {
                return true;
            }
        }

        return false;
    }

    private function allowInsecure(): bool
    {
        return (bool) $this->rc->config->get('imapsync_allow_insecure', false);
    }
}
