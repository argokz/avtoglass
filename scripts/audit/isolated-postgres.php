<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$out = $root.'/output/playwright/audit-2026-10-09';
$values = Dotenv\Dotenv::parse(preg_replace('/\A\xEF\xBB\xBF/', '', file_get_contents($root.'/.env.preview')));
if (($values['DB_DATABASE'] ?? '') !== 'avtoglasskz_modernization_preview' || ! in_array($values['DB_HOST'], ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Only the synthetic local preview may be copied');
}
$bin = 'C:/Program Files/PostgreSQL/16/bin';
$cluster = $out.'/tooling/postgres';
$port = 5441;
function command(array $args, string $log, array $environment = []): void
{
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, array_merge(getenv(), $environment));
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start isolated PostgreSQL utility');
    }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Isolated PostgreSQL utility failed; inspect safe utility log');
    }
}
if (! is_dir($cluster)) {
    command([$bin.'/initdb.exe', '-D', $cluster, '-U', 'audit', '--auth=trust', '--encoding=UTF8', '--locale=C'], $out.'/postgres-setup.log');
    file_put_contents($cluster.'/postgresql.conf', "\nlisten_addresses = '127.0.0.1'\nport = $port\nmax_connections = 100\n", FILE_APPEND);
}
command([$bin.'/pg_ctl.exe', '-D', $cluster, '-l', $out.'/postgres-server.log', '-w', 'start'], $out.'/postgres-setup.log');
command([$bin.'/createdb.exe', '-h', '127.0.0.1', '-p', (string) $port, '-U', 'audit', 'avtoglasskz_modernization_preview'], $out.'/postgres-setup.log');
command([$bin.'/pg_dump.exe', '-h', $values['DB_HOST'], '-p', $values['DB_PORT'], '-U', $values['DB_USERNAME'], '-Fc', '--no-owner', '--no-acl', '-f', $out.'/preview-synthetic.dump', 'avtoglasskz_modernization_preview'], $out.'/postgres-setup.log', ['PGPASSWORD' => $values['DB_PASSWORD']]);
command([$bin.'/pg_restore.exe', '-h', '127.0.0.1', '-p', (string) $port, '-U', 'audit', '--no-owner', '--no-acl', '-d', 'avtoglasskz_modernization_preview', $out.'/preview-synthetic.dump'], $out.'/postgres-setup.log');
echo 'Synthetic preview copied into isolated PostgreSQL on 127.0.0.1:5441', PHP_EOL;
