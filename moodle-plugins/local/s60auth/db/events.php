<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => '\local_s60auth\observer::on_user_loggedin',
        'priority'  => 100,
    ],
];
