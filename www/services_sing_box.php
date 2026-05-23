<?php
require_once("guiconfig.inc");
include("head.inc");
include("fbegin.inc");

const SINGBOX_CONFIG_FILE = "/usr/local/etc/sing-box/config.json";
const SINGBOX_BINARY = "/usr/local/bin/sing-box";
const STATUS_ENDPOINT = "/status_sing_box.php";
const LOGS_ENDPOINT = "/status_sing_box_logs.php";

$message = "";
$message_type = "info";

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function execCommand($command)
{
    exec($command . " 2>&1", $output, $return_var);
    return [$output, $return_var];
}

function readFileContent($file, $default = "")
{
    if (!file_exists($file)) {
        return $default;
    }

    $content = file_get_contents($file);
    return $content !== false ? $content : $default;
}

function validateJsonConfig($content)
{
    json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return json_last_error_msg();
    }
    return true;
}

function validateSingBoxConfig($binary, $file)
{
    if (!file_exists($file)) {
        return [false, "Configuration file does not exist, cannot validate."];
    }

    if (!file_exists($binary) || !is_executable($binary)) {
        return [false, "sing-box executable does not exist or is not executable: {$binary}"];
    }

    $command = escapeshellarg($binary) . " check -c " . escapeshellarg($file);
    list($output, $return_var) = execCommand($command);
    $result = trim(implode("\n", $output));

    if ($return_var === 0) {
        return [true, $result !== '' ? $result : "sing-box configuration validated successfully."];
    }

    return [false, $result !== '' ? $result : "sing-box configuration validation failed."];
}

function handleServiceAction($action)
{
    $messages = [
        'start' => ["sing-box service started successfully!", "sing-box service startup failed!"],
        'stop' => ["sing-box service stopped!", "sing-box service stop failed!"],
        'restart' => ["sing-box service restarted successfully!", "sing-box service restart failed!"],
    ];

    if (!isset($messages[$action])) {
        return [false, "Invalid operation!"];
    }

    list($output, $return_var) = execCommand("service sing-box " . escapeshellarg($action));

    if ($return_var === 0) {
        return [true, $messages[$action][0]];
    }

    return [false, $messages[$action][1]];
}

function saveConfig($binary, $file, $content)
{
    if (trim($content) === '') {
        return [false, "Configuration content cannot be empty!"];
    }

    $jsonValidationResult = validateJsonConfig($content);
    if ($jsonValidationResult !== true) {
        return [false, "JSON format error: {$jsonValidationResult}"];
    }

    $dir = dirname($file);
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, "Configuration directory is not writable: {$dir}"];
    }

    if (file_exists($file) && !is_writable($file)) {
        return [false, "Configuration save failed, please ensure file is writable."];
    }

    $temp_file = tempnam(sys_get_temp_dir(), 'singbox_cfg_');
    if ($temp_file === false) {
        return [false, "Cannot create temporary file."];
    }

    $backup_file = $file . '.bak';

    try {
        if (file_put_contents($temp_file, $content, LOCK_EX) === false) {
            return [false, "Failed to write temporary configuration file."];
        }

        list($isValid, $checkMessage) = validateSingBoxConfig($binary, $temp_file);
        if (!$isValid) {
            return [false, "JSON format is correct, but sing-box configuration validation failed: " . $checkMessage];
        }

        if (file_exists($file)) {
            @copy($file, $backup_file);
        }

        if (file_put_contents($file, $content, LOCK_EX) === false) {
            return [false, "Configuration save failed!"];
        }

        return [true, "Configuration saved successfully! sing-box validation result: " . $checkMessage];
    } finally {
        @unlink($temp_file);
    }
}

function renderMessage($message, $type)
{
    if ($message === '') {
        return;
    }
?>
    <div id="page-message"
        class="alert alert-<?= e($type); ?>"
        data-message-type="<?= e($type); ?>"
        style="white-space: pre-wrap; margin-bottom: 0;">
        <?= e($message); ?>
    </div>
<?php
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $posted_config = (string) ($_POST['config_content'] ?? '');

    switch ($action) {
        case 'save_config':
            list($saveSuccess, $saveMessage) = saveConfig(SINGBOX_BINARY, SINGBOX_CONFIG_FILE, $posted_config);
            $message = $saveMessage;
            $message_type = $saveSuccess ? 'success' : 'danger';
            break;

        case 'start':
        case 'stop':
        case 'restart':
            list($actionSuccess, $actionMessage) = handleServiceAction($action);
            $message = $actionSuccess ? '' : $actionMessage;
            $message_type = $actionSuccess ? 'info' : 'danger';
            break;

        default:
            $message = 'Invalid operation!';
            $message_type = 'danger';
            break;
    }
}

$config_raw_content = readFileContent(SINGBOX_CONFIG_FILE, '');
if ($config_raw_content === '' && !file_exists(SINGBOX_CONFIG_FILE) && $message === '') {
    $message = 'Configuration file not found, please create or save configuration first.';
    $message_type = 'warning';
}
?>
<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <?php if ($message !== ''): ?>
                <div class="col-xs-12">
                    <?php renderMessage($message, $message_type); ?>
                </div>
            <?php endif; ?>
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr>
                                <td><strong>Service Status</strong></td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="sing-box-status" class="alert alert-secondary status-alert">
                                        <i class="fa fa-circle-notch fa-spin"></i> Checking...
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr>
                                <td><strong>Service Control</strong></td>
                            </tr>
                            <tr>
                                <td>
                                    <form method="post" class="form-inline action-form">
                                        <button type="submit" name="action" value="start" class="btn btn-success">
                                            <i class="fa fa-play"></i> Start
                                        </button>
                                        <button type="submit" name="action" value="stop" class="btn btn-danger">
                                            <i class="fa fa-stop"></i> Stop
                                        </button>
                                        <button type="submit" name="action" value="restart" class="btn btn-warning">
                                            <i class="fa fa-refresh"></i> Restart
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr>
                                <td><strong>Configuration Management</strong></td>
                            </tr>
                            <tr>
                                <td>
                                    <form method="post" class="action-form">
                                        <textarea
                                            id="config_content"
                                            name="config_content"
                                            rows="12"
                                            spellcheck="false"
                                            autocapitalize="off"
                                            autocomplete="off"
                                            autocorrect="off"
                                            class="form-control json-editor"
                                            style="max-width:none;"><?= e($config_raw_content); ?></textarea>
                                        <button type="submit" name="action" value="save_config" class="btn btn-danger save-config-button">
                                            <i class="fa fa-save"></i> Save Configuration
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr>
                                <td><strong>Log View</strong></td>
                            </tr>
                            <tr>
                                <td>
                                    <textarea
                                        id="log-viewer"
                                        rows="11"
                                        class="form-control"
                                        style="max-width:none"
                                        readonly></textarea>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</section>

<style>
    .content-box.__mb {
        margin-bottom: 20px !important;
    }

    .json-editor {
        resize: none;
        overflow-y: auto;
        overflow-x: auto;
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
        font-size: 13px;
        line-height: 1.25;
        white-space: pre;
        overflow-wrap: normal;
        tab-size: 4;
    }

    .json-editor:focus {
        border-color: #66afe9;
        outline: 0;
        box-shadow: 0 0 0 0.2rem rgba(102, 175, 233, 0.2);
    }

    .status-alert {
        margin-bottom: 0;
        padding-top: 8px;
        padding-bottom: 8px;
    }

    .save-config-button {
        margin-top: 8px;
    }

    .action-form {
        margin-bottom: 0;
    }
</style>

<script>
    const STATUS_ENDPOINT = <?= json_encode(STATUS_ENDPOINT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const LOGS_ENDPOINT = <?= json_encode(LOGS_ENDPOINT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const STATUS_CLASS_MAP = {
        running: 'alert alert-success status-alert',
        stopped: 'alert alert-danger status-alert',
        unknown: 'alert alert-warning status-alert',
        error: 'alert alert-danger status-alert'
    };
    const STATUS_HTML_MAP = {
        running: '<i class="fa fa-check-circle text-success"></i> sing-box is running',
        stopped: '<i class="fa fa-times-circle text-danger"></i> sing-box is stopped',
        unknown: '<i class="fa fa-exclamation-circle text-warning"></i> Status unknown',
        error: '<i class="fa fa-times-circle text-danger"></i> Status check failed'
    };
    const POLL_INTERVAL = 1000;

    function setStatus(state) {
        const statusElement = document.getElementById('sing-box-status');
        const normalizedState = Object.prototype.hasOwnProperty.call(STATUS_HTML_MAP, state) ? state : 'unknown';
        statusElement.innerHTML = STATUS_HTML_MAP[normalizedState];
        statusElement.className = STATUS_CLASS_MAP[normalizedState];
    }

    function insertText(textarea, text) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        textarea.value = value.slice(0, start) + text + value.slice(end);
        textarea.selectionStart = textarea.selectionEnd = start + text.length;
    }

    function wrapSelection(textarea, left, right) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;
        const selected = value.slice(start, end);
        textarea.value = value.slice(0, start) + left + selected + right + value.slice(end);
        textarea.selectionStart = start + left.length;
        textarea.selectionEnd = end + left.length;
    }

    function indentSelectedLines(textarea, direction) {
        const value = textarea.value;
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        let lineEnd = value.indexOf('\n', end);

        if (lineEnd === -1) {
            lineEnd = value.length;
        }

        const lines = value.slice(lineStart, lineEnd).split('\n');
        let newStart = start;
        let newEnd = end;

        const updatedLines = lines.map((line, index) => {
            if (direction > 0) {
                if (index === 0) {
                    newStart += 4;
                }
                newEnd += 4;
                return '    ' + line;
            }

            if (line.startsWith('    ')) {
                if (index === 0) {
                    newStart -= Math.min(4, start - lineStart);
                }
                newEnd -= 4;
                return line.slice(4);
            }

            if (line.startsWith('\t')) {
                if (index === 0) {
                    newStart -= Math.min(1, start - lineStart);
                }
                newEnd -= 1;
                return line.slice(1);
            }

            return line;
        });

        textarea.value = value.slice(0, lineStart) + updatedLines.join('\n') + value.slice(lineEnd);
        textarea.selectionStart = Math.max(lineStart, newStart);
        textarea.selectionEnd = Math.max(textarea.selectionStart, newEnd);
    }

    function refreshStatus() {
        fetch(STATUS_ENDPOINT, {
                cache: 'no-store'
            })
            .then(response => response.json())
            .then(data => setStatus(data.status))
            .catch(error => {
                console.error('Status check failed:', error.message);
                setStatus('error');
            });
    }

    function refreshLogs() {
        fetch(LOGS_ENDPOINT, {
                cache: 'no-store'
            })
            .then(response => response.text())
            .then(logContent => {
                const logViewer = document.getElementById('log-viewer');
                const shouldStickToBottom =
                    logViewer.scrollTop + logViewer.clientHeight >= logViewer.scrollHeight - 20;

                logViewer.value = logContent;

                if (shouldStickToBottom) {
                    logViewer.scrollTop = logViewer.scrollHeight;
                }
            })
            .catch(error => {
                console.error('Log refresh failed:', error.message);
                const logViewer = document.getElementById('log-viewer');
                logViewer.value = '[Error] Cannot load logs, please check network or server status.';
                logViewer.scrollTop = logViewer.scrollHeight;
            });
    }

    function initMessageFadeout() {
        const pageMessage = document.getElementById('page-message');
        if (!pageMessage || pageMessage.dataset.messageType !== 'success') {
            return;
        }

        setTimeout(() => {
            pageMessage.style.transition = 'opacity 0.4s ease';
            pageMessage.style.opacity = '0';
            setTimeout(() => {
                if (pageMessage.parentNode) {
                    pageMessage.parentNode.removeChild(pageMessage);
                }
            }, 400);
        }, 3000);
    }

    function initJsonEditor() {
        const configTextarea = document.getElementById('config_content');
        if (!configTextarea) {
            return;
        }

        const openClosePairs = {
            '{': '}',
            '[': ']',
            '"': '"'
        };

        configTextarea.addEventListener('keydown', event => {
            if (event.key === 'Tab') {
                event.preventDefault();

                if (
                    configTextarea.selectionStart !== configTextarea.selectionEnd ||
                    configTextarea.value.slice(configTextarea.selectionStart, configTextarea.selectionEnd).includes('\n')
                ) {
                    indentSelectedLines(configTextarea, event.shiftKey ? -1 : 1);
                } else if (event.shiftKey) {
                    const start = configTextarea.selectionStart;
                    const value = configTextarea.value;
                    const lineStart = value.lastIndexOf('\n', start - 1) + 1;

                    if (value.slice(lineStart, lineStart + 4) === '    ') {
                        configTextarea.value = value.slice(0, lineStart) + value.slice(lineStart + 4);
                        configTextarea.selectionStart = configTextarea.selectionEnd = Math.max(lineStart, start - 4);
                    }
                } else {
                    insertText(configTextarea, '    ');
                }
                return;
            }

            if (!Object.prototype.hasOwnProperty.call(openClosePairs, event.key)) {
                return;
            }

            const selectedText = configTextarea.value.slice(
                configTextarea.selectionStart,
                configTextarea.selectionEnd
            );

            if (selectedText.length > 0) {
                event.preventDefault();
                wrapSelection(configTextarea, event.key, openClosePairs[event.key]);
                return;
            }

            if (event.key === '"') {
                const nextChar = configTextarea.value.slice(
                    configTextarea.selectionStart,
                    configTextarea.selectionStart + 1
                );
                if (nextChar === '"') {
                    event.preventDefault();
                    configTextarea.selectionStart = configTextarea.selectionEnd = configTextarea.selectionStart + 1;
                    return;
                }
            }

            event.preventDefault();
            const start = configTextarea.selectionStart;
            insertText(configTextarea, event.key + openClosePairs[event.key]);
            configTextarea.selectionStart = configTextarea.selectionEnd = start + 1;
        });
    }

    function initFormState() {
        document.querySelectorAll('form.action-form').forEach(form => {
            form.addEventListener('submit', event => {
                const submitter = event.submitter;
                const buttons = form.querySelectorAll('button[type="submit"]');

                buttons.forEach(button => {
                    button.disabled = true;
                });

                if (!submitter) {
                    return;
                }

                submitter.disabled = false;
                submitter.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        initMessageFadeout();
        initJsonEditor();
        initFormState();
        refreshStatus();
        refreshLogs();
        setInterval(refreshStatus, POLL_INTERVAL);
        setInterval(refreshLogs, POLL_INTERVAL);
    });
</script>

<?php include("foot.inc"); ?>