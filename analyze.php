<?php

require 'config-analysis.php';

$mysqldumpslow_cmd = 'mysqldumpslow';

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

class PDO_Extended extends PDO {
    private $current_database = null;

    public function set_global_variable($variable_name, $variable_value) {
        $this->query("SET GLOBAL $variable_name = $variable_value");
    }

    public function database_exists($database_name) {
        $show_databases_query = $this->query('SHOW DATABASES');
        $databases = $show_databases_query->fetchAll(PDO::FETCH_COLUMN);
        return in_array($database_name, $databases);
    }

    public function show_databases() {
        $show_databases_query = $this->query('SHOW DATABASES');
        return $show_databases_query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function use_database($database_name) : bool {
        if ($this->current_database == $database_name) {
            return true;
        }
        if (!$this->database_exists($database_name)) {
            return false;
        }
        $this->query("USE $database_name");
        return true;
    }

    public function explain($query) {
        $explain_query = $this->query("EXPLAIN $query");
        return $explain_query->fetchAll(PDO::FETCH_ASSOC);
    }
}

function is_root_user() {
    return posix_getuid() == 0;
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
                preg_match($metadata_regex, $current_query_metadata, $metadata_matches);
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

// if not root user, echo error message and exit
if (!is_root_user()) {
    echo "You must be root to run this script\n";
    exit(1);
} else {
    echo "You are root\n";
}

// connect to MySQL instance as root, without password
$user = 'root';
$pass = '';
$db = new PDO_Extended('mysql:host=localhost', $user, $pass);

$mysql_global_variables_query = $db->query('SHOW GLOBAL VARIABLES');
$mysql_global_variables = [];

foreach ($mysql_global_variables_query->fetchAll(PDO::FETCH_ASSOC) as $variable) {
    $mysql_global_variables[$variable['Variable_name']] = $variable['Value'];
}

if ($mysql_global_variables['log_output'] == 'FILE') {
    $slow_query_log_file = $mysql_global_variables['slow_query_log_file'];
    echo "MySQL is configured to log to a file: $slow_query_log_file\n";

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
    echo "MySQL is not configured to log to a file\n";
}

if ($mysql_global_variables['log_queries_not_using_indexes'] == 'ON') {
    echo "MySQL is configured to log queries not using indexes\n";
} else {
    echo "MySQL is not configured to log queries not using indexes\n";
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
    echo "Purging slow query log file\n";
    file_put_contents($slow_query_log_file, '');   

    echo "Taking sample of slow queries from last $number_of_seconds seconds\n";

    $db->set_global_variable('long_query_time', $number_of_seconds);
    $db->set_global_variable('slow_query_log', 'ON');

    // pause for $number_of_seconds seconds
    sleep($number_of_seconds);

    $db->set_global_variable('slow_query_log', 'OFF');
}

function analyze_mysqldumpslow_results($results) {
    global $db;

    echo "Slow queries analysis:\n";

    foreach ($results as $slow_query) {
        echo "--- Slow query analysis ---\n";
        echo "Query: \n\tUSE " . $slow_query['database'] . "; EXPLAIN " . $slow_query['query'] . "\n";
        if ($db->use_database($slow_query['database']) === false) {
            echo "Database {$slow_query['database']} does not exist\n";
            echo "Raw query header: \n\t" . $slow_query['raw'] . "\n";
            continue;
        }
        $explain = $db->explain($slow_query['query']);
    
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