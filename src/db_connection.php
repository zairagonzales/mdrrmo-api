<?php
declare(strict_types=1);

/**
* MDRRMO Response Emergency System
* PostgreSQL connection helper.
*
* Requires DATABASE_URL in Render environment variables.
*/

if (!function_exists('getDatabaseConnection')) {
   function getDatabaseConnection(): PDO
   {
       static $pdo = null;

       if ($pdo instanceof PDO) {
           return $pdo;
       }

       $databaseUrl = getenv('DATABASE_URL');

       if (!$databaseUrl) {
           error_log('MDRRMO: DATABASE_URL is not configured.');
           throw new RuntimeException(
               'Database configuration is unavailable.'
           );
       }

       $db = parse_url($databaseUrl);

       if (
           $db === false ||
           empty($db['host']) ||
           empty($db['user']) ||
           !isset($db['pass']) ||
           empty($db['path']) ||
           $db['path'] === '/'
       ) {
           error_log('MDRRMO: Invalid DATABASE_URL.');
           throw new RuntimeException(
               'Database configuration is invalid.'
           );
       }

       $host = $db['host'];
       $port = (int) ($db['port'] ?? 5432);
       $username = rawurldecode($db['user']);
       $password = rawurldecode($db['pass']);
       $database = rawurldecode(ltrim($db['path'], '/'));

       $query = [];
       parse_str($db['query'] ?? '', $query);

       $sslmode = $query['sslmode']
           ?? getenv('PGSSLMODE')
           ?: 'require';

       $dsn = sprintf(
           'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
           $host,
           $port,
           $database,
           $sslmode
       );

       try {
           $pdo = new PDO($dsn, $username, $password, [
               PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
               PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
               PDO::ATTR_EMULATE_PREPARES => false,
               PDO::ATTR_TIMEOUT => 10
           ]);

           return $pdo;

       } catch (PDOException $e) {
           error_log(
               'MDRRMO PostgreSQL connection failed: ' .
               $e->getMessage()
           );

           throw new RuntimeException(
               'Database connection failed.',
               0,
               $e
           );
       }
   }
}

