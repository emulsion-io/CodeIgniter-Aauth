<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "The mysqli PHP extension is required.\n");
    exit(1);
}

$options = getopt('', array('schema:', 'reset'));
$schemaPath = isset($options['schema']) ? (string) $options['schema'] : '';
$reset = array_key_exists('reset', $options);
$database = (string) getenv('AAUTH_TEST_DB_NAME');

if ($schemaPath === '' || !is_file($schemaPath)) {
    fwrite(STDERR, "SQL schema not found.\n");
    exit(1);
}
if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $database)) {
    fwrite(STDERR, "Invalid database name.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $connection = new mysqli(
        (string) getenv('AAUTH_TEST_DB_HOST'),
        (string) getenv('AAUTH_TEST_DB_USER'),
        (string) getenv('AAUTH_TEST_DB_PASSWORD'),
        '',
        (int) getenv('AAUTH_TEST_DB_PORT')
    );
    $connection->set_charset('utf8mb4');
    $connection->query(
        'CREATE DATABASE IF NOT EXISTS `' . $database
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $connection->select_db($database);

    $existing = $connection->query("SHOW TABLES LIKE 'aauth\\_%'");
    if ($existing->num_rows > 0 && !$reset) {
        throw new RuntimeException(
            'Aauth tables already exist. Re-run with -ResetDatabase to replace this test database.'
        );
    }
    $existing->free();

    $sql = file_get_contents($schemaPath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('The SQL schema is empty or unreadable.');
    }

    $connection->multi_query($sql);
    do {
        $result = $connection->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($connection->more_results() && $connection->next_result());

    echo "Database {$database} deployed successfully.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database deployment failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
