<?php

header('Content-Type: application/json');

require_once _DIR_ . '/../../../src/database.php';

try {

   if (!isset($_GET['id'])) {

       http_response_code(400);

       echo json_encode([
           'success' => false,
           'message' => 'Incident ID is required.'
       ]);

       exit;
   }

   $id = (int) $_GET['id'];

   $pdo = getDatabaseConnection();

   $sql = "
       SELECT
           id,
           incident_type,
           description,
           latitude,
           longitude,
           status,
           created_at
       FROM incidents
       WHERE id = :id
   ";

   $stmt = $pdo->prepare($sql);

   $stmt->execute([
       ':id' => $id
   ]);

   $incident = $stmt->fetch();

   if (!$incident) {

       http_response_code(404);

       echo json_encode([
           'success' => false,
           'message' => 'Incident not found.'
       ]);

       exit;
   }

   echo json_encode([
       'success' => true,
       'data' => $incident
   ]);

} catch (Throwable $e) {

   http_response_code(500);

   echo json_encode([
       'success' => false,
       'message' => $e->getMessage()
   ]);
}
