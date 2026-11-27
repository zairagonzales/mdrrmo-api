
<?php
declare(strict_types=1);

/**
* MDRRMO Response Emergency System
* Command Center - Incident List API
*
* Endpoint:
* GET /api/incidents/list.php
*
* Optional query parameters:
* ?status=reported
* ?type=Flood
* ?limit=100
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
* Send a JSON response without conflicting with other API files.
*/
function mdrrmoIncidentListResponse(
   int $statusCode,
   array $payload
): void {
   http_response_code($statusCode);

   echo json_encode(
       $payload,
       JSON_UNESCAPED_SLASHES |
       JSON_INVALID_UTF8_SUBSTITUTE
   );

   exit;
}

/**
* Handle CORS preflight requests.
*/
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
   http_response_code(204);
   exit;
}

/**
* Only GET requests are allowed.
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
   header('Allow: GET, OPTIONS');

   mdrrmoIncidentListResponse(405, [
       'success' => false,
       'message' => 'Method not allowed. Use GET to retrieve incidents.'
   ]);
}

try {
   /**
    * Load the PostgreSQL connection helper.
    *
    * Assumes the deployed structure places src/ at /var/www/src/.
    * Confirm this path against your Dockerfile.
    */
   $databaseFile = dirname(_DIR_, 3)
       . '/src/db_connection.php';

   if (
       !is_file($databaseFile) ||
       !is_readable($databaseFile)
   ) {
       throw new RuntimeException(
           'Database connection helper not found or unreadable: '
           . $databaseFile
       );
   }

   require_once $databaseFile;

   if (!function_exists('getDatabaseConnection')) {
       throw new RuntimeException(
           'getDatabaseConnection() is not defined in db_connection.php.'
       );
   }

   $pdo = getDatabaseConnection();

   if (!$pdo instanceof PDO) {
       throw new RuntimeException(
           'Database helper did not return a valid PDO connection.'
       );
   }

   /**
    * Build the incident query.
    */
   $sql = '
       SELECT
           incident_id,
           emergency_type,
           description,
           persons_affected,
           latitude,
           longitude,
           gps_accuracy,
           status,
           reported_at
       FROM public.emergency_incidents
       WHERE 1 = 1
   ';

   $params = [];

   /**
    * Optional status filter.
    * Example: ?status=reported
    */
   if (
       isset($_GET['status']) &&
       trim((string) $_GET['status']) !== ''
   ) {
       $sql .= ' AND LOWER(status) = LOWER(:status)';
       $params['status'] = trim((string) $_GET['status']);
   }

   /**
    * Optional emergency type filter.
    * Example: ?type=Flood
    */
   if (
       isset($_GET['type']) &&
       trim((string) $_GET['type']) !== ''
   ) {
       $sql .= ' AND LOWER(emergency_type) = LOWER(:type)';
       $params['type'] = trim((string) $_GET['type']);
   }

   /**
    * Validate the requested limit.
    * Default: 100; minimum: 1; maximum: 500.
    */
   $requestedLimit = filter_input(
       INPUT_GET,
       'limit',
       FILTER_VALIDATE_INT
   );

   if ($requestedLimit === false || $requestedLimit === null) {
       $requestedLimit = 100;
   }

   $limit = max(1, min(500, $requestedLimit));

   $sql .= '
       ORDER BY reported_at DESC NULLS LAST, incident_id DESC
       LIMIT ' . $limit;

   /**
    * Execute the query.
    */
   $stmt = $pdo->prepare($sql);
   $stmt->execute($params);

   $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

   /**
    * Convert numeric database values to JSON numbers
    * for the Command Center map and dashboard.
    */
   foreach ($incidents as &$incident) {
       if (isset($incident['incident_id'])) {
           $incident['incident_id'] =
               (int) $incident['incident_id'];
       }

       if (
           isset($incident['persons_affected']) &&
           is_numeric($incident['persons_affected'])
       ) {
           $incident['persons_affected'] =
               (int) $incident['persons_affected'];
       }

       foreach (
           ['latitude', 'longitude', 'gps_accuracy'] as $field
       ) {
           if (
               isset($incident[$field]) &&
               is_numeric($incident[$field])
           ) {
               $incident[$field] =
                   (float) $incident[$field];
           }
       }
   }

   unset($incident);

   /**
    * Return the incident data.
    */
   mdrrmoIncidentListResponse(200, [
       'success' => true,
       'message' => 'Emergency reports retrieved successfully.',
       'count' => count($incidents),
       'data' => $incidents
   ]);

} catch (Throwable $e) {
   /**
    * Log technical details on the server only.
    */
   error_log(
       'MDRRMO incident list API error: ' .
       $e->getMessage()
   );

   mdrrmoIncidentListResponse(500, [
       'success' => false,
       'message' => 'Unable to retrieve incident reports. Check the Render server logs.'
   ]);
}
