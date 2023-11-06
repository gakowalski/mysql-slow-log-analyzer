<?php

class PDO_Extended extends PDO {
    private $current_database = null;

    public function __construct($dsn, $username = null, $password = null, $options = null) {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "Connected to MySQL\n";
    }

    public function get_innodb_status() {
        $mysql_engine_status_query = $this->query('SHOW ENGINE INNODB STATUS');
        $mysql_engine_status = $mysql_engine_status_query->fetchAll(PDO::FETCH_ASSOC);
        return $mysql_engine_status[0]['Status'];
    }

    public function get_global_variables() : array {
        $mysql_global_variables_query = $this->query('SHOW GLOBAL VARIABLES');
        $mysql_global_variables = [];

        foreach ($mysql_global_variables_query->fetchAll(PDO::FETCH_ASSOC) as $variable) {
            $mysql_global_variables[$variable['Variable_name']] = $variable['Value'];
        }

        return $mysql_global_variables;
    }

    public function set_global_variable($variable_name, $variable_value) {
        if (false === in_array($variable_value, ['ON', 'OFF']) && is_string($variable_value)) {
            $variable_value = "'$variable_value'";
        }
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