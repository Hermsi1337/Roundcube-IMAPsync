if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        var form = document.getElementById('imapsync-form'),
            submitButton = document.getElementById('imapsync-submit'),
            cancelButton = document.getElementById('imapsync-cancel'),
            startRequest = null;

        function label(name) {
            return rcmail.gettext(name, 'imapsync');
        }

        function setFormDisabled(disabled) {
            if (submitButton) {
                submitButton.disabled = disabled;
                submitButton.classList.toggle('disabled', disabled);
            }

            if (cancelButton) {
                cancelButton.hidden = !disabled;
            }
        }

        function renderProgress(progress) {
            var region = document.getElementById('imapsync-progress'),
                list = document.getElementById('imapsync-progress-list');

            if (!region || !list) {
                return;
            }

            list.innerHTML = '';
            progress.forEach(function (item) {
                var row = document.createElement('div'),
                    folder = document.createElement('span'),
                    counter = document.createElement('span');

                row.className = 'imapsync-progress-row';
                folder.className = 'imapsync-progress-folder';
                counter.className = 'imapsync-progress-counter';
                folder.textContent = item.folder;
                counter.textContent = item.current + '/' + item.total;
                row.appendChild(folder);
                row.appendChild(counter);
                list.appendChild(row);
            });

            region.hidden = progress.length === 0;
        }

        function renderResult(result) {
            var region = document.getElementById('imapsync-result'),
                content = document.getElementById('imapsync-result-content'),
                table = document.createElement('table'),
                errors = result.errors || [],
                messageKey = result.fatalError || errors.length ? 'donewitherrors' : 'donesuccess';

            if (!region || !content) {
                return;
            }

            table.className = 'imapsync-summary-table';
            content.innerHTML = '';
            summaryRow(label('folderssynced'), result.foldersSynced || 0, table);
            summaryRow(label('messagescopied'), result.messagesCopied || 0, table);
            summaryRow(label('messagesskipped'), result.messagesSkipped || 0, table);
            summaryRow(label('errors'), errors.length + (result.fatalError ? 1 : 0), table);
            content.appendChild(table);

            if (result.fatalError || errors.length) {
                content.appendChild(errorList(result.fatalError, errors));
            }

            region.hidden = false;
            rcmail.display_message(label(messageKey), result.fatalError || errors.length ? 'warning' : 'confirmation');
        }

        function summaryRow(title, value, table) {
            var row = document.createElement('tr'),
                header = document.createElement('th'),
                cell = document.createElement('td');

            header.textContent = title;
            cell.textContent = value;
            row.appendChild(header);
            row.appendChild(cell);
            table.appendChild(row);
        }

        function errorList(fatalError, errors) {
            var list = document.createElement('ul');

            list.className = 'imapsync-errors';
            if (fatalError) {
                list.appendChild(errorItem(fatalError));
            }

            errors.forEach(function (error) {
                list.appendChild(errorItem(error));
            });

            return list;
        }

        function errorItem(error) {
            var item = document.createElement('li');

            item.textContent = error;

            return item;
        }

        rcmail.addEventListener('plugin.imapsync_status', function (payload) {
            renderProgress(payload.progress || []);

            if (payload.result) {
                startRequest = null;
                setFormDisabled(false);
                renderResult(payload.result);
            } else if (payload.running) {
                setFormDisabled(true);
            }
        });

        rcmail.addEventListener('plugin.imapsync_error', function (message) {
            startRequest = null;
            setFormDisabled(false);
            rcmail.display_message(message || label('errorvalidation'), 'error');
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', function () {
                if (startRequest && startRequest.abort) {
                    startRequest.abort();
                }

                startRequest = null;
                setFormDisabled(false);
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                var lock;

                event.preventDefault();
                if (!window.confirm(label('confirmstart'))) {
                    return;
                }

                document.getElementById('imapsync-result').hidden = true;
                setFormDisabled(true);
                lock = rcmail.set_busy(true, 'loading');
                startRequest = rcmail.http_post('plugin.imapsync.start', $(form).serialize(), lock);
            });
        }
    });
}
