<?php
require_once("guiconfig.inc");
include("head.inc");
include("fbegin.inc");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('BASE_DIR', '/usr/local/etc/sing-box/sub');
define('ENV_FILE', BASE_DIR . '/env');
define('SCRIPT_FILE', BASE_DIR . '/sub.sh');
define('LOG_FILE', '/var/log/sub.log');
define('LOCK_FILE', '/var/run/sing-box-sub.lock');
define('LOG_TAIL_LINES', 100);
define('LOG_MAX_BYTES', 262144);
define('CSRF_TOKEN_KEY', 'sing_box_sub_csrf_token');
define('SUBSCRIBE_ACTION', 'start_subscribe');
define('CLEAR_LOG_ACTION', 'clear_log');
define('ALLOWED_URL_SCHEMES', ['http', 'https']);

action_generate_csrf_token();

function log_message($message, $log_file = LOG_FILE) {
    $time = date("Y-m-d H:i:s");
    $log_entry = "[{$time}] {$message}\n";
    try {
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to write log: " . $e->getMessage());
    }
}

function action_generate_csrf_token() {
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
}

function get_csrf_token() {
    return $_SESSION[CSRF_TOKEN_KEY] ?? '';
}

function verify_csrf_token($token) {
    $session_token = $_SESSION[CSRF_TOKEN_KEY] ?? '';
    return !empty($session_token) && is_string($token) && hash_equals($session_token, $token);
}

function add_message(array &$messages, $type, $text) {
    $messages[] = [
        'type' => $type,
        'text' => $text,
    ];
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function is_valid_env_key($key) {
    return is_string($key) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1;
}

function quote_env_value($value) {
    return str_replace("'", "'\\''", (string)$value);
}

function save_env_variable($key, $value, $env_file = ENV_FILE) {
    if (!is_valid_env_key($key)) {
        return false;
    }

    $lines = file_exists($env_file) ? file($env_file, FILE_IGNORE_NEW_LINES) : [];
    $new_lines = [];
    $pattern = '/^export\\s+' . preg_quote($key, '/') . '=.*/';

    foreach ($lines as $line) {
        if (!preg_match($pattern, $line)) {
            $new_lines[] = $line;
        }
    }

    $quoted_value = quote_env_value($value);
    $new_lines[] = "export {$key}='{$quoted_value}'";

    try {
        file_put_contents($env_file, implode("\n", $new_lines) . "\n", LOCK_EX);
        return true;
    } catch (Exception $e) {
        error_log("Failed to save environment variable: " . $e->getMessage());
        return false;
    }
}

function parse_env_value($value) {
    $value = trim((string)$value);
    if (preg_match("/^'(.*)'$/s", $value, $matches)) {
        return str_replace("'\\''", "'", $matches[1]);
    }
    if (preg_match('/^"(.*)"$/s', $value, $matches)) {
        return stripcslashes($matches[1]);
    }
    return $value;
}

function load_env_variables($env_file = ENV_FILE) {
    $env_vars = [];
    if (!file_exists($env_file)) {
        return $env_vars;
    }

    $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (preg_match('/^export\s+([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
            $env_vars[$matches[1]] = parse_env_value($matches[2]);
        }
    }

    return $env_vars;
}

function is_private_or_reserved_ip($ip) {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function resolve_hostname_ips($host) {
    $ips = [];

    $a_records = @dns_get_record($host, DNS_A);
    if (is_array($a_records)) {
        foreach ($a_records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }
    }

    $aaaa_records = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa_records)) {
        foreach ($aaaa_records as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
    }

    return array_values(array_unique($ips));
}

function validate_subscribe_url($url, &$error_message = '') {
    if (!is_string($url)) {
        $error_message = 'Invalid subscription URL format!';
        return false;
    }

    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        $error_message = 'Invalid subscription URL format!';
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        $error_message = 'Failed to parse subscription URL!';
        return false;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ALLOWED_URL_SCHEMES, true)) {
        $error_message = 'Subscription URL only allows HTTP or HTTPS.';
        return false;
    }

    $host = $parts['host'] ?? '';
    if ($host === '') {
        $error_message = 'Subscription URL is missing hostname.';
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (is_private_or_reserved_ip($host)) {
            $error_message = 'Subscription URL cannot use private or reserved addresses.';
            return false;
        }
        return true;
    }

    $resolved_ips = resolve_hostname_ips($host);
    if (empty($resolved_ips)) {
        $error_message = 'Failed to resolve subscription URL hostname.';
        return false;
    }

    foreach ($resolved_ips as $ip) {
        if (is_private_or_reserved_ip($ip)) {
            $error_message = 'Subscription URL resolved to a private or reserved address, saving denied.';
            return false;
        }
    }

    return true;
}

function clear_log($log_file = LOG_FILE) {
    try {
        file_put_contents($log_file, '', LOCK_EX);
        return true;
    } catch (Exception $e) {
        error_log('Failed to clear log: ' . $e->getMessage());
        return false;
    }
}

function read_log_tail($log_file = LOG_FILE, $max_lines = LOG_TAIL_LINES, $max_bytes = LOG_MAX_BYTES) {
    if (!file_exists($log_file) || !is_readable($log_file)) {
        return '';
    }

    $fp = @fopen($log_file, 'rb');
    if ($fp === false) {
        return '';
    }

    $file_size = filesize($log_file);
    if ($file_size === false) {
        fclose($fp);
        return '';
    }

    $read_size = min($file_size, $max_bytes);
    if ($read_size > 0) {
        fseek($fp, -$read_size, SEEK_END);
    }

    $content = fread($fp, $read_size);
    fclose($fp);

    if ($content === false || $content === '') {
        return '';
    }

    $lines = preg_split("/\r\n|\n|\r/", $content);
    if ($file_size > $read_size && !empty($lines)) {
        array_shift($lines);
    }

    $tail_lines = array_slice($lines, -$max_lines);
    return implode("\n", $tail_lines);
}

function is_subscribe_running($lock_file = LOCK_FILE) {
    if (!file_exists($lock_file)) {
        return false;
    }

    $fp = @fopen($lock_file, 'c');
    if ($fp === false) {
        return false;
    }

    $locked = !flock($fp, LOCK_EX | LOCK_NB);
    if (!$locked) {
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    return $locked;
}

function execute_subscribe_script(&$return_var, &$output_lines) {
    $return_var = 1;
    $output_lines = [];

    $lock_fp = @fopen(LOCK_FILE, 'c');
    if ($lock_fp === false) {
        log_message('Cannot create lock file, subscription task not executed.');
        return false;
    }

    if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
        fclose($lock_fp);
        log_message('Subscription task is already running, duplicate start rejected.');
        return false;
    }

    if (!file_exists(SCRIPT_FILE) || !is_executable(SCRIPT_FILE)) {
        $return_var = 127;
        log_message('Subscription script does not exist or is not executable: ' . SCRIPT_FILE);
        flock($lock_fp, LOCK_UN);
        fclose($lock_fp);
        return true;
    }

    log_message('Starting subscription operation.');
    $cmd = '/usr/bin/env bash ' . escapeshellarg(SCRIPT_FILE) . ' >> ' . escapeshellarg(LOG_FILE) . ' 2>&1';
    exec($cmd, $output_lines, $return_var);
    log_message('Subscription operation completed! Exit code: ' . $return_var);

    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);

    return true;
}

function handle_save_action(array &$messages) {
    $url = filter_input(INPUT_POST, 'subscribe_url', FILTER_UNSAFE_RAW);
    $url = is_string($url) ? trim($url) : '';

    $error_message = '';
    if (!validate_subscribe_url($url, $error_message)) {
        add_message($messages, 'danger', $error_message);
        return;
    }

    if (save_env_variable('CLASH_URL', $url)) {
        log_message('Subscription URL saved: ' . $url);
        add_message($messages, 'success', 'Address saved successfully!');
        return;
    }

    add_message($messages, 'danger', 'Failed to save subscription address!');
}

function handle_subscribe_action(array &$messages) {
    if (is_subscribe_running()) {
        add_message($messages, 'warning', 'Subscription task is currently running, please do not submit again.');
        return;
    }

    $return_var = 1;
    $output_lines = [];
    $executed = execute_subscribe_script($return_var, $output_lines);

    if (!$executed) {
        add_message($messages, 'danger', 'Cannot acquire task lock, subscription task not executed.');
        return;
    }

    if ($return_var === 0) {
        add_message($messages, 'success', 'Subscription operation executed successfully.');
        return;
    }

    add_message($messages, 'danger', 'Subscription operation failed, exit code: ' . $return_var . '. Please check logs.');
}

function handle_clear_log_action(array &$messages) {
    if (clear_log()) {
        log_message('Log cleared.');
        add_message($messages, 'success', 'Log cleared!');
        return;
    }

    add_message($messages, 'danger', 'Failed to clear log!');
}

function handle_form_submission() {
    $messages = [];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $messages;
    }

    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        add_message($messages, 'danger', 'CSRF validation failed, please refresh the page and try again.');
        return $messages;
    }

    if (isset($_POST['save'])) {
        handle_save_action($messages);
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case SUBSCRIBE_ACTION:
                handle_subscribe_action($messages);
                break;
            case CLEAR_LOG_ACTION:
                handle_clear_log_action($messages);
                break;
        }
    }

    return $messages;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'log') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => true,
        'running' => is_subscribe_running(),
        'log_content' => read_log_tail(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$messages = handle_form_submission();
$env_vars = load_env_variables();
$current_url = $env_vars['CLASH_URL'] ?? '';
$log_content = h(read_log_tail());
$csrf_token = get_csrf_token();
$is_running = is_subscribe_running();
?>

<!-- Page Form -->
<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <!-- Alert Messages -->
            <?php if (!empty($messages)): ?>
                <div class="col-xs-12">
                    <?php foreach ($messages as $message): ?>
                        <div class="alert alert-<?= h($message['type']); ?>"><?= h($message['text']); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Subscription Management -->
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr><td><strong>Sing-Box Subscription Management</strong></td></tr>
                            <tr>
                                <td>
                                    <form method="post" class="form-group">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrf_token); ?>" />
                                        <label for="subscribe_url">Subscription URL:</label>
                                        <input type="text" id="subscribe_url" name="subscribe_url" value="<?= h($current_url); ?>" class="form-control" placeholder="Enter HTTP or HTTPS subscription URL" autocomplete="off" />
                                        <br>
                                        <button type="submit" name="save" class="btn btn-danger"><i class="fa fa-save"></i> Save Settings</button>
                                        <button type="submit" name="action" value="<?= h(SUBSCRIBE_ACTION); ?>" class="btn btn-success" onclick="return confirm('Confirm to start subscription immediately?');" <?= $is_running ? 'disabled="disabled"' : ''; ?>><i class="fa fa-sync"></i> Start Subscription</button>
                                        <button type="submit" name="action" value="<?= h(CLEAR_LOG_ACTION); ?>" class="btn btn-warning" onclick="return confirm('Confirm to clear log?');"><i class="fa fa-trash"></i> Clear Log</button>
                                        <?php if ($is_running): ?>
                                            <span class="text-warning" style="margin-left: 10px;">Subscription task is running...</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Real-time Log Display -->
            <section class="col-xs-12">
                <div class="content-box tab-content table-responsive __mb">
                    <table class="table table-striped">
                        <tbody>
                            <tr><td><strong>Log View</strong></td></tr>
                            <tr>
                                <td>
                                    <form class="form-group" onsubmit="return false;">
                                        <textarea readonly style="max-width:none" id="log_content" name="log_content" rows="20" class="form-control"><?= $log_content; ?></textarea>
                                    </form>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</section>



<?php include("foot.inc"); ?>