<?php
// Configuración de zona horaria
$zonaHorariaLocal = 'America/Bogota'; // Ajusta esto a tu zona horaria si es diferente
date_default_timezone_set('UTC'); // Establecemos UTC como predeterminado para los cálculos

// Función de logging
function log_message($message, $type = 'INFO') {
    $log_file = '/home/u116500482/domains/jucoing.com/pscript/pagos_cobros.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp UTC] [$type] $message\n", FILE_APPEND);
}

try {
    log_message("Iniciando script de notificaciones de pagos y cobros");

    // Cargar el autoloader de Composer
    $autoloadPath = '/home/u116500482/domains/jucoing.com/pscript/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("El archivo autoload.php no existe en la ruta especificada.");
    }
    require $autoloadPath;

    // Configuración
    $projectId = 'prestamos-4b56b'; // Reemplaza con tu Project ID real
    $serviceAccountFile = '/home/u116500482/domains/jucoing.com/pscript/prestamos-4b56b-firebase-adminsdk-5z2n7-769b5ba492.json';

    // Verificar que el archivo de cuenta de servicio existe
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

    // Calcular las fechas relevantes
    $fechaHoraLocal = new DateTime('now', new DateTimeZone($zonaHorariaLocal));
    $fecha_actual = $fechaHoraLocal->format('Y-m-d');
    $fechaHoraLocal->modify('+1 day');
    $fecha_manana = $fechaHoraLocal->format('Y-m-d');

    log_message("Buscando pagos pendientes para hoy ({$fecha_actual}) y mañana ({$fecha_manana})");

    // Consulta SQL para obtener pagos pendientes
    $query = $db->prepare("
        SELECT p.id_prestamo, p.monto_prestamo, pg.monto_pago, pg.numerocuota_pago, 
               u.id_user, u.nombre_user, u.tokennoti_user, pg.fecha_pago
        FROM prestamos p
        JOIN pagos pg ON p.id_prestamo = pg.id_prestamo_pago
        JOIN users u ON p.id_user_prestamo = u.id_user
        WHERE (pg.fecha_pago = :fecha_actual OR pg.fecha_pago = :fecha_manana)
          AND pg.estado_pago = 'no'
    ");
    $query->execute(['fecha_actual' => $fecha_actual, 'fecha_manana' => $fecha_manana]);
    $pagos_pendientes = $query->fetchAll(PDO::FETCH_ASSOC);

    // Obtener token del administrador
    $query_admin = $db->prepare("SELECT tokennoti_user FROM users WHERE rol_user = 'admin' LIMIT 1");
    $query_admin->execute();
    $admin = $query_admin->fetch(PDO::FETCH_ASSOC);
    $token_admin = $admin['tokennoti_user'];

    $notificaciones_enviadas = 0;

    // Procesar cada pago pendiente
    foreach ($pagos_pendientes as $pago) {
        $es_hoy = $pago['fecha_pago'] === $fecha_actual;
        
        // Notificar al usuario
        if ($pago['tokennoti_user']) {
            $mensaje_usuario = $es_hoy 
                ? "Hoy es tu fecha de pago. Tienes un pago de {$pago['monto_pago']} para tu préstamo. Cuota {$pago['numerocuota_pago']}."
                : "Mañana es tu fecha de pago. Recuerda que tienes un pago de {$pago['monto_pago']} para tu préstamo. Cuota {$pago['numerocuota_pago']}.";
            
            if (enviarNotificacionCurl($pago['tokennoti_user'], "Recordatorio de pago", $mensaje_usuario, $projectId, $serviceAccountFile)) {
                $notificaciones_enviadas++;
            }
        }

        // Notificar al administrador
        if ($token_admin) {
            $mensaje_admin = $es_hoy
                ? "Hoy hay un cobro programado de {$pago['monto_pago']} para el préstamo #{$pago['id_prestamo']} de {$pago['nombre_user']}."
                : "Mañana hay un cobro programado de {$pago['monto_pago']} para el préstamo #{$pago['id_prestamo']} de {$pago['nombre_user']}.";
            
            if (enviarNotificacionCurl($token_admin, "Recordatorio de cobro", $mensaje_admin, $projectId, $serviceAccountFile)) {
                $notificaciones_enviadas++;
            }
        }
    }

    log_message("Notificaciones enviadas: $notificaciones_enviadas");
    log_message("Script finalizado con éxito");

} catch (Exception $e) {
    log_message("Error crítico: " . $e->getMessage(), 'CRITICAL');
}