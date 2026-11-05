<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$values = Dotenv\Dotenv::parse(preg_replace('/\A\xEF\xBB\xBF/', '', file_get_contents(dirname(__DIR__, 2).'/.env')));
$db = new PDO(sprintf('pgsql:host=%s;port=%d;dbname=postgres;connect_timeout=5', $values['DB_HOST'], (int) $values['DB_PORT']), $values['DB_USERNAME'], $values['DB_PASSWORD']);
echo json_encode([
    'max_connections' => (int) $db->query('SHOW max_connections')->fetchColumn(),
    'connections' => $db->query("SELECT CASE WHEN datname IN ('avtoglasskz', 'avtoglasskz_test', 'avtoglasskz_modernization_preview') THEN datname ELSE 'other' END AS category, state, count(*)::int AS count FROM pg_stat_activity WHERE backend_type='client backend' GROUP BY 1,2 ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT), PHP_EOL;
