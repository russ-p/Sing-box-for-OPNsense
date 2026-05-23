<?php
$log_file = "/var/log/sing-box.log";
$max_lines = 5000;
$display_lines = 200; // Frontend only displays the most recent 200 lines

if (!file_exists($log_file)) {
    echo "[Error] Log file not found!";
    exit;
}

$log = new SplFileObject($log_file, 'r');
$log->seek(PHP_INT_MAX);
$total_lines = $log->key();

$log_content = [];
$log->rewind();

// Keep only the last $max_lines lines
$start_line = max(0, $total_lines - $max_lines);
$log->seek($start_line);

while (!$log->eof()) {
    $log_content[] = trim($log->fgets());
}

// Rewrite file only when logs exceed $max_lines
if ($total_lines > $max_lines) {
    file_put_contents($log_file, implode("\n", $log_content) . "\n");
}

// Take the most recent $display_lines lines for display
$display_content = array_slice($log_content, -$display_lines);
echo implode("\n", $display_content);
?>
