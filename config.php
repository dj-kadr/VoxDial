<?php
// Central configuration for VoxDial.
// Keep this file outside public backups and restrict permissions on the server.

date_default_timezone_set('Europe/Kyiv');

$config = [
    'mysql' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => 'MysqlPass',
        'databases' => [
            'dialer' => 'dialer',
            'asterisk' => 'asterisk',
            'cdr' => 'asteriskcdrdb',
        ],
        'charset' => 'utf8',
    ],
    'ami' => [
        'host' => '127.0.0.1',
        'port' => 5038,
        'user' => 'dialer_user',
        'pass' => 'AMI password',
        'timeout' => 10,
    ],
    'services' => [
        'dialer' => 'dialer.service',
    ],
    'asterisk' => [
        'bin' => 'asterisk',
    ],
];

function app_config($key = null) {
    global $config;

    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }

    return $value;
}

function db_pdo($database_key) {
    $mysql = app_config('mysql');
    $database = isset($mysql['databases'][$database_key]) ? $mysql['databases'][$database_key] : $database_key;
    $dsn = "mysql:host={$mysql['host']};dbname={$database};charset={$mysql['charset']}";

    $pdo = new PDO($dsn, $mysql['user'], $mysql['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
