<?php

require 'config-analysis.php';
require 'pdo-extended.php';

function is_root_user() {
    return posix_getuid() == 0;
}

// if not root user, echo error message and exit
if (!is_root_user()) {
    echo "You must be root to run this script\n";
    exit(1);
} else {
    echo "You are root\n";
}

// connect to MySQL
$db = new PDO_Extended("mysql:host=$admin_host", $admin_user, $admin_password);

$mysql_global_variables = $db->get_global_variables();
$innodb_status = $db->get_innodb_status();

function parse_innodb_status($status) {
    $lines = explode("\n", $status);

    $result = [];

    foreach ($lines as $line) {
        $matches = [];

        if (preg_match('/^Per second averages calculated from the last (\d+) seconds$/', $line, $matches)) {
            $result['calculation_time'] = $matches[1];
        }

        if (preg_match('/^History list length (\d+)$/', $line, $matches)) {
            $result['history_list_length'] = $matches[1];
        }

        if (preg_match('/^srv_master_thread loops: (\d+) srv_active, (\d+) srv_shutdown, (\d+) srv_idle$/', $line, $matches)) {
            $result['srv_active'] = $matches[1];
            $result['srv_shutdown'] = $matches[2];
            $result['srv_idle'] = $matches[3];
        }

        if (preg_match('/^srv_master_thread log flush and writes: (\d+)$/', $line, $matches)) {
            $result['srv_log_flush_and_writes'] = $matches[1];
        }

        if (preg_match('/^Pending flushes \(fsync\) log: (\d+); buffer pool: (\d+)$/', $line, $matches)) {
            $result['pending_flushes_fsync_log'] = $matches[1];
            $result['pending_flushes_buffer_pool'] = $matches[2];
        }

        if (preg_match('/^(\d+) OS file reads, (\d+) OS file writes, (\d+) OS fsyncs$/', $line, $matches)) {
            $result['os_file_reads'] = $matches[1];
            $result['os_file_writes'] = $matches[2];
            $result['os_fsyncs'] = $matches[3];
        }
    }

    return $result;
}

var_dump($innodb_status);

$parsed_innodb_status = parse_innodb_status($innodb_status);
var_dump($parsed_innodb_status);

if (!isset($mysql_global_variables['innodb_thread_concurrency'])) {
    echo "[OK] innodb_thread_concurrency is not set, defaults to 0\n";
} elseif ($mysql_global_variables['innodb_thread_concurrency'] == 0) {
    echo "[OK] innodb_thread_concurrency is set to 0\n";
} else {
    echo "[!!] innodb_thread_concurrency is set to {$mysql_global_variables['innodb_thread_concurrency']}\n";
}


if ($parsed_innodb_status['calculation_time'] < $innodb_monitor_minimum_calculation_time) {
    echo "[!!] InnoDB status calculation time is less than {$innodb_monitor_minimum_calculation_time} seconds\n";
} else {
    echo "[OK] InnoDB status calculation time is {$parsed_innodb_status['calculation_time']} seconds\n";
}

if (!isset($mysql_global_variables['innodb_purge_threads'])) {
    echo "[OK] innodb_purge_threads is not set, defaults to 4\n";
} elseif ($mysql_global_variables['innodb_purge_threads'] == 4) {
    echo "[OK] innodb_purge_threads is set to 4 which is balanced setting\n";
} elseif ($mysql_global_variables['innodb_purge_threads'] > 4) {
    echo "[OK] innodb_purge_threads is greater than 4 - good for write-intensive workloads\n";
} else {
    echo "[!!] innodb_purge_threads is {$mysql_global_variables['innodb_purge_threads']} which lesser than 4\n";
}