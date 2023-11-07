<?php

$admin_user = 'root';
$admin_password = '';
$admin_host = 'localhost';

$mysqldumpslow_cmd = 'mysqldumpslow';
$long_query_time = 0.5; // seconds, can be float

$innodb_monitor_minimum_calculation_time = 20; // seconds, used in tuning concurrency

function get_analysis_parameter($name) {
    $parameters = [
        'acceptable_number_of_rows' => 1000, // number of rows
        'fake_sleep_query' => false,         // boolean, true works when acceptable rows = 0
    ];
    return $parameters[$name];
};