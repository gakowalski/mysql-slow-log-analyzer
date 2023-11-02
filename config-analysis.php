<?php

function get_analysis_parameter($name) {
    $parameters = [
        'acceptable_number_of_rows' => 200,
    ];
    return $parameters[$name];
};