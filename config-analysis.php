<?php

$admin_user = 'root';
$admin_password = '';
$admin_host = 'localhost';

$mysqldumpslow_cmd = 'mysqldumpslow';
$long_query_time = 5; // seconds

$innodb_monitor_minimum_calculation_time = 20; // seconds

function get_analysis_parameter($name) {
    $parameters = [
        'acceptable_number_of_rows' => 400, // number of rows
        'fake_sleep_query' => false,       // boolean
    ];
    return $parameters[$name];
};