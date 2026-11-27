<?php
declare(strict_types=1);

/**
* MDRRMO Response Emergency System
* API: Retrieve emergency reports for the Command Center
*
* Endpoint:
* GET /api/incidents/list.php
*
* Optional filters:
* ?status=reported
* ?type=Flood
* ?limit=100
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jsonResponse(int $statusCode, array $payload): void
{
   http_response_code($statusCode);

   echo json_encode(
       $payload,
       JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
   );

   exit;
}

// Handle browser CORS preflight requests.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
   http_response_code(204);
   exit;
}

// This endpoint only accepts GET requests.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
   header('Allow: GET, OPTIONS');

   jsonResponse(405, [
       'success' => false,
       'message' => 'Method not allowed. Use GET to retrieve incidents.'
   ]);
}

// Load the PostgreSQL connection helper.
require_once _DIR_ . '/db_connection.php';

try {
   $pdo = getDatabaseConnection();

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

   // Optional status filter.
   if (isset($_GET['status']) && trim((string) $_GET['status']) !== '') {
       $sql .= ' AND LOWER(status) = LOWER(:status)';
       $params['status'] = trim((string) $_GET['status']);
   }

   // Optional emergency type filter.
   if (isset($_GET['type']) && trim((string) $_GET['type']) !== '') {
       $sql .= ' AND LOWER(emergency_type) = LOWER(:type)';
       $params['type'] = trim((string) $_GET['type']);
   }

   $sql .= ' ORDER BY reported_at DESC NULLS LAST, incident_id DESC';

   // Default to 100; maximum 500.
   $requestedLimit = filter_input(
       INPUT_GET,
       'limit',
       FILTER_VALIDATE_INT
   );

   if ($requestedLimit === false || $requestedLimit === null) {
       $requestedLimit = 100;
   }

   $limit = max(1, min(500, $requestedLimit));
   $sql .= ' LIMIT ' . $limit;

   $stmt = $pdo->prepare($sql);
   $stmt->execute($params);

   $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

   // Return numeric fields as JSON numbers.
   foreach ($incidents as &$incident) {
       $incident['incident_id'] = (int) $incident['incident_id'];

       foreach ([
           'persons_affected',
           'latitude',
           'longitude',
           'gps_accuracy'
       ] as $field) {
           if (
               array_key_exists($field, $incident) &&
               $incident[$field] !== null &&
               $incident[$field] !== '' &&
               is_numeric($incident[$field])
           ) {
               $incident[$field] = (float) $incident[$field];
           }
       }
   }

   unset($incident);

   jsonResponse(200, [
       'success' => true,
       'message' => 'Emergency reports retrieved successfully.',
       'count' => count($incidents),
       'data' => $incidents
   ]);

} catch (Throwable $e) {
   // Keep database and technical details in Render logs.
   error_log(
       'MDRRMO incident list API error: ' . $e->getMessage()
   );

   jsonResponse(500, [
       'success' => false,
       'message' => 'Unable to retrieve incident reports. Check the Render server logs.'
   ]);
}
