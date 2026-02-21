<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_s60auth', 'S60Auth SSO');
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_s60auth/bw_api_url',
        'BadWolf API URL',
        'Základní URL BadWolf API, např. https://be.s60dev.cz/api',
        'https://be.s60dev.cz/api'
    ));

    $settings->add(new admin_setting_configpassword(
        'local_s60auth/bw_api_key',
        'BadWolf API Key',
        'API klíč pro server-to-server komunikaci Moodle → BadWolf',
        ''
    ));
}
