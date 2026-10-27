<?php

/**
 * Controlador para solicitar un tablero de power bi
 * para que pueda ser renderizado por el cliente.
 */

require_once __DIR__ . '/../models/PowerBIModel.php';

class PowerBIController {
    private $powerBIModel;
    private $httpClient;

    public function __construct($pdo, $httpClient = null) {
        $this->powerBIModel = new PowerBIModel($pdo);
        $this->httpClient = $httpClient;
    }

    public function getCredentialsEmpresaReporte($id, $user_id, $role) {
        try {
            $credentials = $this->powerBIModel->getCredentialsEmpresaReporte($id, $user_id, $role);

            if ($credentials['success']) {
                // Verificar si hay datos disponibles
                if (isset($credentials['data']) && count($credentials['data']) > 0) {
                    return [
                        'success' => true,
                        'data' => $credentials['data'][0],
                        'message' => 'Credenciales obtenidas exitosamente'
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'No se encontraron credenciales para este ID'
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'message' => $credentials['message']
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    // 1. Obtener el Access Token
    /**
     * Obtiene el Access Token de Power BI usando OAuth 2.0 con client_credentials
     *
     * @param string $tenantId
     * @param string $clientId
     * @param string $clientSecret
     * @return array ['success' => bool, 'access_token' => string|null, 'message' => string]
     *
     */
    public function getAccessToken($tenantId, $clientId, $clientSecret) {
        try {
            $tokenUrl = "https://login.microsoftonline.com/$tenantId/oauth2/token";
            $data = [
                "grant_type" => "client_credentials",
                "client_id" => $clientId,
                "client_secret" => $clientSecret,
                "resource" => "https://analysis.windows.net/powerbi/api"
            ];

            $options = [
                "http" => [
                    "header"  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    "method"  => "POST",
                    "content" => http_build_query($data)
                ]
            ];

            $context = stream_context_create($options);
            $result = $this->httpClient !== null
                ? call_user_func($this->httpClient, $tokenUrl, $context)
                : @file_get_contents($tokenUrl, false, $context);
            if ($result === false) {
                return ['success' => false, 'access_token' => null, 'message' => 'No se pudo obtener el acceso al reporte'];
            }
            $response = json_decode($result, true);

            if (!isset($response["access_token"])) {
                return [
                    'success' => false,
                    'access_token' => null,
                    'message' => 'No se pudo obtener el acceso al reporte'
                ];
            }
            return [
                'success' => true,
                'access_token' => $response["access_token"],
                'message' => 'Access token obtenido exitosamente'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'No se pudo obtener el acceso al reporte',
                'access_token' => null
            ];
        }
    }
}
