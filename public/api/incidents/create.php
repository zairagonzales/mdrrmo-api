<?php
declare(strict_types=1);

/**
* MDRRMO Emergency Reporting API
* POST /api/incidents/create.php
*
* Inserts an emergency report into public.emergency_incidents.
* Creates the required PostGIS geom value from longitude/latitude.
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://mdrrmo-emergency-uyf0.hatchable.site');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

function respond(int $status, array $data): never
{
   http_response_code($status);
   echo json_encode(
       $data,
       JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
   );
   exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
   http_response_code(204);
   exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
   header('Allow: POST, OPTIONS');
   respond(405, [
       'success' => false,
       'message' => 'Use POST to submit an emergency report.'
   ]);
}

try {
   // db_connection.php is in the repository's src directory.
   require_once _DIR_ . '/../../../src/db_connection.php';

   if (!function_exists('getDatabaseConnection')) {
       throw new RuntimeException(
           'Database connection helper was not loaded.'
       );
   }

   // The existing frontend submits FormData.
   $emergencyType = trim((string)($_POST['emergency_type'] ?? ''));
   $description = trim((string)($_POST['description'] ?? ''));
   $personsRaw = $_POST['persons_affected'] ?? null;
   $latitudeRaw = $_POST['latitude'] ?? null;
   $longitudeRaw = $_POST['longitude'] ?? null;
   $accuracyRaw = $_POST['gps_accuracy'] ?? null;

   $allowedTypes = [
       'Flood',
       'Fire',
       'Landslide',
       'Road Accident',
       'Medical Emergency',
       'Storm/Typhoon',
       'Earthquake',
       'Other'
   ];

   if (!in_array($emergencyType, $allowedTypes, true)) {
       respond(422, [
           'success' => false,
           'message' => 'Please select a valid emergency type.'
       ]);
   }

   if ($description === '') {
       respond(422, [
           'success' => false,
           'message' => 'Please describe the emergency.'
       ]);
   }

   if (strlen($description) > 5000) {
       respond(422, [
           'success' => false,
           'message' => 'The description must not exceed 5000 characters.'
       ]);
   }

   if (
       $personsRaw === null ||
       filter_var($personsRaw, FILTER_VALIDATE_INT) === false ||
       (int)$personsRaw < 0
   ) {
       respond(422, [
           'success' => false,
           'message' => 'Enter a valid number of persons affected.'
       ]);
   }

   if (
       !is_numeric($latitudeRaw) ||
       !is_numeric($longitudeRaw)
   ) {
       respond(422, [
           'success' => false,
           'message' => 'Valid GPS latitude and longitude are required.'
       ]);
   }

   $latitude = (float)$latitudeRaw;
   $longitude = (float)$longitudeRaw;
   $personsAffected = (int)$personsRaw;

   if (
       $latitude < -90 || $latitude > 90 ||
       $longitude < -180 || $longitude > 180
   ) {
       respond(422, [
           'success' => false,
           'message' => 'GPS coordinates are outside the valid range.'
       ]);
   }

   $accuracy = null;

   if ($accuracyRaw !== null && $accuracyRaw !== '') {
       if (!is_numeric($accuracyRaw) || (float)$accuracyRaw < 0) {
           respond(422, [
               'success' => false,
               'message' => 'GPS accuracy must be a non-negative number.'
           ]);
       }

       $accuracy = (float)$accuracyRaw;
   }

   /*
    * Optional photo:
    * Validate the uploaded file. This code does not store the photo.
    * Photo storage needs a separate column or file-storage implementation.
    */
   if (isset($_FILES['photo']) &&
       $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {

       if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
           respond(422, [
               'success' => false,
               'message' => 'The photo upload failed. Please try again.'
           ]);
       }

       if ((int)$_FILES['photo']['size'] > 5 * 1024 * 1024) {
           respond(422, [
               'success' => false,
               'message' => 'The photo must not exceed 5 MB.'
           ]);
       }

       $finfo = new finfo(FILEINFO_MIME_TYPE);
       $mime = $finfo->file($_FILES['photo']['tmp_name']);

       $allowedMimes = [
           'image/jpeg',
           'image/png',
           'image/webp',
           'image/gif'
       ];

       if (!in_array($mime, $allowedMimes, true)) {
           respond(422, [
               'success' => false,
               'message' => 'Upload a JPEG, PNG, WEBP, or GIF image.'
           ]);
       }
   }

   $pdo = getDatabaseConnection();

   /*
    * IMPORTANT:
    * geom is NOT NULL in your database. Build it from longitude
    * first, then latitude, using the WGS84 coordinate reference system.
    *
    * This INSERT assumes the existing columns are:
    * emergency_type, latitude, longitude, gps_accuracy, geom,
    * description, persons_affected, status, reported_at, updated_at.
    */
   $sql = "
       INSERT INTO public.emergency_incidents (
           emergency_type,
           latitude,
           longitude,
           gps_accuracy,
           geom,
           description,
           persons_affected,
           status,
           reported_at,
           updated_at
       )
       VALUES (
           :emergency_type,
           :latitude,
           :longitude,
           :gps_accuracy,
           ST_SetSRID(
               ST_MakePoint(:geom_longitude, :geom_latitude),
               4326
           ),
           :description,
           :persons_affected,
           'reported',
           CURRENT_TIMESTAMP,
           CURRENT_TIMESTAMP
       )
       RETURNING incident_id, reported_at
   ";

   $stmt = $pdo->prepare($sql);

   $stmt->execute([
       ':emergency_type' => $emergencyType,
       ':latitude' => $latitude,
       ':longitude' => $longitude,
       ':gps_accuracy' => $accuracy,
       ':geom_longitude' => $longitude,
       ':geom_latitude' => $latitude,
       ':description' => $description,
       ':persons_affected' => $personsAffected
   ]);

   $incident = $stmt->fetch(PDO::FETCH_ASSOC);

   if (!$incident) {
       throw new RuntimeException(
           'The database did not return the newly created incident.'
       );
   }

   respond(201, [
       'success' => true,
       'message' => 'Emergency report submitted successfully.',
       'incident_id' => (int)$incident['incident_id'],
       'reported_at' => $incident['reported_at']
   ]);

} catch (Throwable $e) {
   // Keep detailed errors in Render logs, not in the public response.
   error_log(
       'MDRRMO create incident API error: ' .
       get_class($e) . ': ' . $e->getMessage()
   );

   respond(500, [
       'success' => false,
       'message' => 'Unable to save the emergency report. Check the Render server logs.'
   ]);
}
