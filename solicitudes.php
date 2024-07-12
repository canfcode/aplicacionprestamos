<?php
// Configuración de zona horaria
$zonaHorariaLocal = 'America/Bogota';
date_default_timezone_set('UTC');

// Función de logging
function log_message($message, $type = 'INFO') {
    $log_file = '/home/u116500482/domains/jucoing.com/pscript/notificaciones_solicitudes.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp UTC] [$type] $message\n", FILE_APPEND);
}

try {
    log_message("Iniciando script de notificaciones de solicitudes de préstamos");

    // Cargar el autoloader de Composer
    $autoloadPath = '/home/u116500482/domains/jucoing.com/pscript/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("El archivo autoload.php no existe en la ruta especificada.");
    }
    require $autoloadPath;

    // Configuración
    $projectId = 'prestamos-4b56b';
    $serviceAccountFile = '/home/u116500482/domains/jucoing.com/pscript/prestamos-4b56b-firebase-adminsdk-5z2n7-769b5ba492.json';

    if (!file_exists($serviceAccountFile)) {
        throw new Exception("El archivo de cuenta de servicio no existe en la ruta especificada.");
    }

    // Conexión a la base de datos
   $db = new PDO('mysql:host=localhost;dbname=u116500482_bprestamo', 'u116500482_uprestamo', 'Y84eIxgRk]');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Función para enviar notificación
    function enviarNotificacionCurl($token, $titulo, $mensaje, $projectId, $serviceAccountFile) {
        log_message("Enviando notificación: $titulo");
        
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        $credentials = new Google\Auth\Credentials\ServiceAccountCredentials($scope, $serviceAccountFile);
        $auth_token = $credentials->fetchAuthToken()['access_token'];

        $url = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";
        $headers = [
            'Authorization: Bearer ' . $auth_token,
            'Content-Type: application/json'
        ];
        $data = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $titulo,
                    'body' => $mensaje,
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            log_message("Error al enviar notificación: $error", 'ERROR');
            return false;
        } else {
            log_message("Notificación enviada con éxito");
            return true;
        }
    }

    // Obtener token del administrador
    $query_admin = $db->prepare("SELECT tokennoti_user FROM users WHERE rol_user = 'admin' LIMIT 1");
    $query_admin->execute();
    $admin = $query_admin->fetch(PDO::FETCH_ASSOC);
    $token_admin = $admin['tokennoti_user'];

    // Consulta para obtener nuevas solicitudes de préstamo
    $query_nuevas_solicitudes = $db->prepare("
        SELECT p.id_prestamo, p.monto_prestamo, p.estadonotificacion_prestamo, u.id_user, u.nombre_user, u.tokennoti_user
        FROM prestamos p
        JOIN users u ON p.id_user_prestamo = u.id_user
        WHERE p.estadosolicitud_prestamo = 0 AND p.estadonotificacion_prestamo < 3
    ");
    $query_nuevas_solicitudes->execute();
    $nuevas_solicitudes = $query_nuevas_solicitudes->fetchAll(PDO::FETCH_ASSOC);

    // Notificar al administrador sobre nuevas solicitudes
    foreach ($nuevas_solicitudes as $solicitud) {
        $mensaje_admin = "Nueva solicitud de préstamo de {$solicitud['nombre_user']} por un monto de {$solicitud['monto_prestamo']}. ID de préstamo: {$solicitud['id_prestamo']}.";
        $nuevo_estado = $solicitud['estadonotificacion_prestamo'] + 1;
        
        if (enviarNotificacionCurl($token_admin, "Nueva solicitud de préstamo", $mensaje_admin, $projectId, $serviceAccountFile)) {
            $update_query = $db->prepare("UPDATE prestamos SET estadonotificacion_prestamo = :nuevo_estado WHERE id_prestamo = :id_prestamo");
            $update_query->execute(['nuevo_estado' => $nuevo_estado, 'id_prestamo' => $solicitud['id_prestamo']]);
            log_message("Notificación enviada al administrador y estadonotificacion_prestamo actualizado a $nuevo_estado para préstamo ID: {$solicitud['id_prestamo']}");
        }
    }
// Consulta para obtener préstamos aprobados
$query_prestamos_aprobados = $db->prepare("
    SELECT p.id_prestamo, p.monto_prestamo, p.estadosolicitud_prestamo, u.id_user, u.nombre_user, u.tokennoti_user
    FROM prestamos p
    JOIN users u ON p.id_user_prestamo = u.id_user
    WHERE p.estadosolicitud_prestamo = 1
");
$query_prestamos_aprobados->execute();
$prestamos_aprobados = $query_prestamos_aprobados->fetchAll(PDO::FETCH_ASSOC);

log_message("Número de préstamos aprobados encontrados: " . count($prestamos_aprobados));

// Notificar a los usuarios sobre préstamos aprobados
foreach ($prestamos_aprobados as $prestamo) {
    log_message("Procesando préstamo ID: {$prestamo['id_prestamo']}, Estado de solicitud: {$prestamo['estadosolicitud_prestamo']}");
    
    if ($prestamo['estadosolicitud_prestamo'] == 1) {
        $mensaje_usuario = "Tu solicitud de préstamo por {$prestamo['monto_prestamo']} ha sido aprobada y consignada. ID de préstamo: {$prestamo['id_prestamo']}.";
        if (enviarNotificacionCurl($prestamo['tokennoti_user'], "Préstamo aprobado", $mensaje_usuario, $projectId, $serviceAccountFile)) {
            $update_query = $db->prepare("UPDATE prestamos SET estadosolicitud_prestamo = 2 WHERE id_prestamo = :id_prestamo");
            $update_query->execute(['id_prestamo' => $prestamo['id_prestamo']]);
            log_message("Notificación enviada al usuario y estadosolicitud_prestamo actualizado a 2 para préstamo ID: {$prestamo['id_prestamo']}");
        } else {
            log_message("Error al enviar notificación al usuario para préstamo ID: {$prestamo['id_prestamo']}", 'ERROR');
        }
    } else {
        log_message("Préstamo ID: {$prestamo['id_prestamo']} no notificado. Estado de solicitud es {$prestamo['estadosolicitud_prestamo']}.", 'WARNING');
    }
}
    log_message("Script finalizado con éxito");

} catch (Exception $e) {
    log_message("Error crítico: " . $e->getMessage(), 'CRITICAL');
}