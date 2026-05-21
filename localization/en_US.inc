<?php

/*
 * Localization file of the Roundcube IMAPsync plugin (en_US — source of truth).
 * Copyright (c) 2026 Dennis Hermsmeier. MIT licensed — see LICENSE at repo root.
 */

$labels = [];
$labels['pagetitle'] = 'Email migration';
$labels['intro'] = 'Copy mail from another IMAP account into this mailbox.';
$labels['noticetitle'] = 'Before you start';
$labels['noticepreserves'] = 'Your existing messages stay untouched — the migration only adds new ones from the source account on top of what is already here. Nothing is overwritten or deleted.';
$labels['noticesynchronous'] = 'Keep this tab open until the migration finishes. If you close it earlier, the migration may not run to completion — and you won\'t see what was actually copied either.';
$labels['noticeretry'] = 'If something cuts the migration short — you closed the tab, your internet dropped for a moment, a timeout hit — don\'t restart right away. The previous run often keeps going for a few more minutes in the background. Check your mailbox: once new messages stop arriving and the mailbox size stops growing, it\'s done and you can safely start over. The next run skips messages that are already there.';
$labels['noticeduration'] = 'Large mailboxes can take a few minutes. You won\'t see progress while it runs — the summary shows up at the end.';
$labels['sourcehost'] = 'Source host';
$labels['sourceport'] = 'Source port';
$labels['encryption'] = 'Encryption';
$labels['encryptionssl'] = 'SSL';
$labels['encryptiontls'] = 'STARTTLS';
$labels['encryptionnone'] = 'None';
$labels['sourceuser'] = 'Source username';
$labels['sourcepassword'] = 'Source password';
$labels['startsync'] = 'Start migration';
$labels['cancelsync'] = 'Cancel';
$labels['confirmstart'] = 'Start copying mail from the source account into this mailbox?';
$labels['progresstitle'] = 'Progress';
$labels['summarytitle'] = 'Summary';
$labels['folderssynced'] = 'Folders migrated';
$labels['messagescopied'] = 'Messages copied';
$labels['messagesskipped'] = 'Messages skipped';
$labels['errors'] = 'Errors';
$labels['errorvalidation'] = 'Please check the source IMAP settings.';
$labels['errorconnect'] = 'Could not connect to the source IMAP server.';
$labels['errorinsecure'] = 'Plaintext IMAP is disabled by the administrator.';
$labels['errorhostblocked'] = 'This source host is not allowed.';
$labels['donesuccess'] = 'Migration completed successfully.';
$labels['donewitherrors'] = 'Migration completed with errors.';
