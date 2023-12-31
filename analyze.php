<?php

require 'config-analysis.php';
require 'pdo-extended.php';

// check if mysqldumpslow command exists
if (shell_exec("which $mysqldumpslow_cmd") === null) {
    echo "mysqldumpslow command not found\n";
    exit(1);
}

// if argument is used, save it as number of seconds
if (isset($argv[1])) {
    $number_of_seconds = $argv[1];
} else {
    $number_of_seconds = 0;
}

function mysqldumpslow($log_file_path, $sort_type = 'at', $num_of_queries = 10) {

    // execute mysqldumpslow command and process its output to json array
    global $mysqldumpslow_cmd;
    $cmd = "$mysqldumpslow_cmd -a -s $sort_type -t $num_of_queries $log_file_path";
    echo "Executing command: $cmd\n";
    $output = shell_exec($cmd);

    $result = [];
    $query_reading_mode = false;
    $current_query = '';
    $current_query_metadata = '';

    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        if ($query_reading_mode === false) {
            if (str_starts_with($line, 'Count')) {
                $current_query_metadata = $line;
                $query_reading_mode = true;
            } else {
                continue;
            }
        } else {
            if (trim($line) == '') {
                // parse metadata with regex
                // sample metadata: Count: 2  Time=0.00s (0s)  Lock=0.00s (0s)  Rows_sent=6.0 (12), Rows_examined=4595.0 (9190), Rows_affected=0.0 (0), travel[travel]@localhost
                $metadata_regex = '/Count: (?<count>\d+)  Time=(?<time>\d+\.\d+)s \((?<time_in_seconds>\d+)s\)  Lock=(?<lock>\d+\.\d+)s \((?<lock_in_seconds>\d+)s\)  Rows_sent=(?<rows_sent>\d+\.\d+) \((?<rows_sent_total>\d+)\), Rows_examined=(?<rows_examined>\d+\.\d+) \((?<rows_examined_total>\d+)\), Rows_affected=(?<rows_affected>\d+\.\d+) \((?<rows_affected_total>\d+)\), (?<user>[a-zA-Z0-9._-]+)\[(?<database>[a-zA-Z0-9._-]+)\]@/';
                if (preg_match($metadata_regex, $current_query_metadata, $metadata_matches)) {
                    $metadata = [
                        'count' => $metadata_matches['count'],
                        'time' => $metadata_matches['time'],
                        'time_in_seconds' => $metadata_matches['time_in_seconds'],
                        'lock' => $metadata_matches['lock'],
                        'lock_in_seconds' => $metadata_matches['lock_in_seconds'],
                        'rows_sent' => $metadata_matches['rows_sent'],
                        'rows_sent_total' => $metadata_matches['rows_sent_total'],
                        'rows_examined' => $metadata_matches['rows_examined'],
                        'rows_examined_total' => $metadata_matches['rows_examined_total'],
                        'rows_affected' => $metadata_matches['rows_affected'],
                        'rows_affected_total' => $metadata_matches['rows_affected_total'],
                        'user' => $metadata_matches['user'],
                        'database' => $metadata_matches['database'],
                        'raw' => $current_query_metadata,
                    ];
                } else {
                    $metadata = [
                        'raw' => $current_query_metadata,
                    ];
                }
                
                $result[] = $metadata + [
                    'query' => $current_query,
                ];
                $query_reading_mode = false;
                $current_query = '';
            } else {
                $current_query .= $line;
            }
        }
    }
    return $result;
}

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

if ($mysql_global_variables['log_output'] == 'FILE') {
    echo "MySQL is configured to log to a file\n";
} else {
    echo "MySQL is not configured to log to a file\n";
}

if (isset($mysql_global_variables['slow_query_log_file'])) {
    $slow_query_log_file = $mysql_global_variables['slow_query_log_file'];
    echo "MySQL slow log path is defined as: $slow_query_log_file\n";

    // check if file exists
    if (file_exists($slow_query_log_file)) {
        echo "Slow query log file exists\n";

        // check if file is readable
        if (is_readable($slow_query_log_file)) {
            echo "Slow query log file is readable\n";
        } else {
            echo "Slow query log file is not readable\n";
        }

        // check if file is writable
        if (is_writable($slow_query_log_file)) {
            echo "Slow query log file is writable\n";
        } else {
            echo "Slow query log file is not writable\n";
        }
    } else {
        echo "Slow query log file does not exist\n";
    }
} else {
    echo "MySQL slow log path is not defined\n";
    $slow_query_log_file = null;
}

if ($mysql_global_variables['log_queries_not_using_indexes'] == 'ON') {
    echo "MySQL is configured to log queries not using indexes\n";
} else {
    echo "MySQL is not configured to log queries not using indexes\n";
}

if (($mysql_global_variables['min_examined_row_limit'] ?? 0) > 0) {
    $min_examined_row_limit = $mysql_global_variables['min_examined_row_limit'];
    echo "Queries that examine fewer than $min_examined_row_limit of rows are not logged to the slow query log.\n";
}

if ($mysql_global_variables['long_query_time'] > 0) {
    echo "MySQL is configured to log queries taking longer than {$mysql_global_variables['long_query_time']} seconds\n";
} else {
    echo "MySQL is not configured to log queries taking longer than {$mysql_global_variables['long_query_time']} seconds\n";
}

if ($mysql_global_variables['slow_query_log'] == 'ON') {
    echo "MySQL is configured to log slow queries\n";
} else {
    echo "MySQL is not configured to log slow queries\n";
}

if ($number_of_seconds > 0) {

    if ($mysql_global_variables['log_output'] != 'FILE') {
        // set log_output variable to FILE
        echo "Setting log_output variable to FILE\n";
        $db->set_global_variable('log_output', 'FILE');
    }

    if (empty($slow_query_log_file) || !file_exists($slow_query_log_file)) {
        $slow_query_log_file = '/var/log/mysql/mysql-slow.log';

        // create slow query log file with 666 permissions
        echo "Creating slow query log file $slow_query_log_file\n";

        // create folder if it does not exist
        $slow_query_log_file_folder = dirname($slow_query_log_file);

        if (!file_exists($slow_query_log_file_folder)) {
            echo "Creating folder $slow_query_log_file_folder\n";
            mkdir($slow_query_log_file_folder, 0777, true);
        }

        touch($slow_query_log_file);
        chmod($slow_query_log_file, 0666);

        echo "Setting slow_query_log_file variable to $slow_query_log_file\n";
        $db->set_global_variable('slow_query_log_file', $slow_query_log_file);
    }

    // set min_examined_row_limit to get_analysis_parameter('acceptable_number_of_rows')
    echo "Setting min_examined_row_limit to " . get_analysis_parameter('acceptable_number_of_rows') . "\n";
    $db->set_global_variable('min_examined_row_limit', get_analysis_parameter('acceptable_number_of_rows'));

    echo "Purging slow query log file $slow_query_log_file \n";
    file_put_contents($slow_query_log_file, '');   

    echo "Setting long_query_time to $long_query_time\n";
    $db->set_global_variable('long_query_time', $long_query_time);

    echo "Taking sample of slow queries from last $number_of_seconds seconds\n";
    $db->set_global_variable('slow_query_log', 'ON');

    if (get_analysis_parameter('fake_sleep_query')) {
        $fake_long_query_time = $long_query_time + 1;
        echo "Executing fake sleep query for $fake_long_query_time seconds\n";
        $db->query("SELECT SLEEP($fake_long_query_time)");
    } else {
        $fake_long_query_time = 0;
    }
    
    $waiting_seconds = $number_of_seconds - $fake_long_query_time;

    if ($waiting_seconds > 0) {
        echo "Waiting for $waiting_seconds seconds\n";
        // pause for $number_of_seconds seconds
        sleep($waiting_seconds);
    } else {
        echo "No need to wait\n";
    }

    // flush logs
    echo "Flushing slow logs\n";
    $db->query('FLUSH SLOW LOGS');

    $db->set_global_variable('slow_query_log', 'OFF');

    // pause for 2 seconds to make sure that MySQL has time to write to slow query log file
    echo "Waiting for 2 seconds for slow log to end flush\n";
    sleep(2);
}

function analyze_mysqldumpslow_results($results) {
    global $db;

    echo "Starting analysis\n";

    foreach ($results as $slow_query) {
        if (empty($slow_query['query'])) {
            continue;
        }

        // check if $slow_query['query'] contains similar commands to "use table_name;"
        // and quote table name with backticks
        /*
        if (preg_match('/use (?<table_name>[a-zA-Z0-9._-]+);/i', $slow_query['query'], $matches)) {
            $slow_query['query'] = str_replace($matches[0], "USE `{$matches['table_name']}`;", $slow_query['query']);
        }
        */

        // remove USE statements from query
        $slow_query['query'] = preg_replace('/use [a-zA-Z0-9._-]+;/i', '', $slow_query['query']);

        // remove SET statements from query
        $slow_query['query'] = preg_replace('/set [a-zA-Z0-9._-]+=[a-zA-Z0-9._-]+;/i', '', $slow_query['query']);

        echo "--- Slow query analysis ---\n";
        echo "USE `" . $slow_query['database'] . "`; EXPLAIN " . trim($slow_query['query']) . "\n";
        if ($db->use_database($slow_query['database']) === false) {
            echo "Database {$slow_query['database']} does not exist\n";
            echo "Raw query header: \n\t" . $slow_query['raw'] . "\n";
            continue;
        }

        echo PHP_EOL;

        // check if query contains "ORDER BY ... ASC LIMIT"
        if (preg_match('/ORDER BY (?<column>[a-zA-Z0-9._`-]+) (ASC|DESC) LIMIT/i', $slow_query['query'], $matches)) {
            echo "\tQuery contains ORDER BY ... ASC LIMIT -> make sure there is an index set on {$matches['column']} \n";
        }

        try {
            $explain = $db->explain($slow_query['query']);
        }
        catch (PDOException $e) {
            echo "Error: " . $e->getMessage() . "\n";
            echo "Raw query header: \n\t" . $slow_query['raw'] . "\n";
            continue;
        }
    
        $count = 1;
    
        foreach ($explain as $explain_hedaer => $explain_value) {
            $rows = $explain_value['rows'];
    
            $possible_keys = explode(',', $explain_value['possible_keys']);
            $no_possible_keys = empty($possible_keys) || trim($possible_keys[0]) == '';
    
            $used_keys = explode(',', $explain_value['key']);
            $used_keys = array_map('trim', $used_keys);
            $no_used_keys = empty($used_keys) || trim($used_keys[0]) == '';
    
            $used_search_methods = explode(';', $explain_value['Extra']);
            // trim each element
            $used_search_methods = array_map('trim', $used_search_methods);
            $no_search_methods = empty($used_search_methods) || trim($used_search_methods[0]) == '';
    
            $used_filesort_method = in_array('Using filesort', $used_search_methods);
    
            $ok = $rows < get_analysis_parameter('acceptable_number_of_rows');
            $not_ok = $no_possible_keys || $no_used_keys || $used_filesort_method;
    
            echo "Explain step $count: \n";
            $count++;
    
            if ($ok) {
                echo "\tRows: $rows\n";
                echo "\tLooks good\n";
                continue;
            }
    
            if ($not_ok === false) {
                echo "\tLooks good\n";
                continue;
            }
    
            // list referenced table
            $referenced_table = $explain_value['table'];
            echo "\tReferenced table: $referenced_table\n";
            echo "\tRows: $rows\n";
    
            // list possible keys
            echo "\tPossible keys: \n";
            if ($no_possible_keys) {
                echo "\t !!! None !!!\n";
            } else {
                foreach ($possible_keys as $possible_key) {
                    echo "\t - $possible_key\n";
                }
            }
    
            // list used keys
            echo "\tUsed keys: \n";
            if ($no_used_keys) {
                echo "\t !!! None !!!\n";
    
                if (count($possible_keys) > 2) {
                    echo "\t !!! High number of ignored possible keys suggest that the query as inherent architectural errors !!!\n";
                    echo "\t !!! Try creating multi-column index !!!\n";
                }
            } else {
                foreach ($used_keys as $used_key) {
                    echo "\t - $used_key\n";
                }
            }
    
            // List used search methods
            echo "\tUsed search methods: \n";
            if ($no_search_methods) {
                echo "\t - None\n";
            } else {
                foreach ($used_search_methods as $used_search_method) {
                    echo "\t - $used_search_method\n";
                }
                if ($used_filesort_method) {
                    echo "\t !!! Using filesort can be terribly slow !!!\n";
                }
            }
        }
    
        echo "--- End of slow query analysis ---\n";
    }
}

analyze_mysqldumpslow_results(mysqldumpslow($slow_query_log_file, 'at'));
analyze_mysqldumpslow_results(mysqldumpslow($slow_query_log_file, 'al'));
analyze_mysqldumpslow_results(mysqldumpslow($slow_query_log_file, 'ar'));
analyze_mysqldumpslow_results(mysqldumpslow($slow_query_log_file, 'c'));