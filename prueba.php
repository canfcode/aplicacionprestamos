<?php
header('Content-Type: application/json');

// Función de logging mejorada
function log_message($message, $type = 'INFO') {
    $log_file = '/home/u116500482/domains/jucoing.com/pscript/notification_test.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] [$type] $message\n", FILE_APPEND);
}

try {
    log_message("Iniciando script de prueba de notificación");

    // Verificar que el autoload existe
    $autoloadPath = '/home/u116500482/domains/jucoing.com/pscript/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("El archivo autoload.php no existe en la ruta especificada.");
    }
    require $autoloadPath;

    log_message("Autoload cargado correctamente");

    $projectId = 'prestamos-4b56b'; // Reemplaza con tu Project ID real
    $serviceAccountFile = '/home/u116500482/domains/jucoing.com/pscript/prestamos-4b56b-firebase-adminsdk-5z2n7-769b5ba492.json';

    // Verificar que el archivo de cuenta de servicio existe
    if (!file_exists($serviceAccountFile)) {
        throw new Exception("El archivo de cuenta de servicio no existe en la ruta especificada.");
    }

    log_message("Archivo de cuenta de servicio verificado");

    function enviarNotificacion($token, $titulo, $mensaje, $projectId, $serviceAccountFile) {
        log_message("Iniciando envío de notificación");
        
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        try {
            $credentials = new Google\Auth\Credentials\ServiceAccountCredentials($scope, $serviceAccountFile);
            log_message("Credenciales cargadas correctamente");
        } catch (Exception $e) {
            log_message("Error al cargar credenciales: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => 'Error de credenciales: ' . $e->getMessage()];
        }

        $client = new GuzzleHttp\Client([
            'handler' => GuzzleHttp\HandlerStack::create(),
            'base_uri' => "https://fcm.googleapis.com/v1/projects/$projectId/",
            'auth' => 'google_auth',
            'timeout' => 10.0, // Timeout de 10 segundos
        ]);

        try {
            log_message("Intentando enviar notificación a FCM...");
            $response = $client->post('messages:send', [
                'auth' => $credentials,
                'json' => [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $titulo,
                            'body' => $mensaje,
                        ],
                    ],
                ],
            ]);
            $responseBody = $response->getBody()->getContents();
            log_message("Respuesta de FCM: " . $responseBody);
            return ['success' => true, 'status' => $response->getStatusCode(), 'body' => $responseBody];
        } catch (GuzzleHttp\Exception\RequestException $e) {
            log_message("Error de Guzzle: " . $e->getMessage(), 'ERROR');
            if ($e->hasResponse()) {
                log_message("Respuesta de error: " . $e->getResponse()->getBody()->getContents(), 'ERROR');
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            log_message("Error general: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        log_message("Recibida solicitud POST");
        
        $token = $_POST['token'] ?? '';
        $titulo = $_POST['titulo'] ?? '';
        $mensaje = $_POST['mensaje'] ?? '';

        if (empty($token) || empty($titulo) || empty($mensaje)) {
            log_message("Campos incompletos en la solicitud", 'WARNING');
            $response = ['success' => false, 'message' => 'Todos los campos son requeridos.'];
        } else {
            log_message("Iniciando envío de notificación con token: $token, título: $titulo");
            $resultado = enviarNotificacion($token, $titulo, $mensaje, $projectId, $serviceAccountFile);
            if ($resultado['success']) {
                log_message("Notificación enviada con éxito");
                $response = ['success' => true, 'message' => 'Notificación enviada con éxito.', 'details' => $resultado];
            } else {
                log_message("Error al enviar la notificación: " . $resultado['error'], 'ERROR');
                $response = ['success' => false, 'message' => 'Error al enviar la notificación', 'error' => $resultado['error']];
            }
        }

        echo json_encode($response);
    } else {
        log_message("Método de solicitud no válido", 'WARNING');
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }

} catch (Exception $e) {
    log_message("Error crítico: " . $e->getMessage(), 'CRITICAL');
    echo json_encode(['success' => false, 'message' => 'Error crítico en el servidor', 'error' => $e->getMessage()]);
}

log_message("Script finalizado");