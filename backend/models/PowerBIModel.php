<?php

/**
 * Modelo para la gestión de tableros de Power BI
 * Recupera los campos necesarios de Empresas y Reportes para prepararlos antes de generar el token de acceso a Power BI
 */
class PowerBIModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtener token o claves de acceso de Empresa y Reporte
     * @param int $id ID del reporte asignado a la empresa
     * @return array Datos de los tokens o claves de acceso
     */
    public function getCredentialsEmpresaReporte($id, $user_id, $role) {
        // Validación básica del parámetro de entrada
        if (!is_numeric($id) || (int)$id <= 0) {
            return [
                'success' => false,
                'message' => 'Parámetro id inválido.'
            ];
        }

        try {
            $sql = "SELECT e.powerbi_client, e.powerbi_clientsecret, e.powerbi_tenant, r.grupo_id, r.reporte_id, er.notas
                FROM empresa_reporte er
                INNER JOIN empresa e ON e.id = er.empresa_id
                INNER JOIN reporte r ON r.id = er.reporte_id
                WHERE er.id = :id AND er.activo = 1 AND e.activo = 1 AND r.activo = 1";
            $params = [':id' => $id];
            if (strtolower((string)$role) !== 'admin') {
                $sql .= " AND EXISTS (SELECT 1 FROM user_empresa_reporte uer WHERE uer.empresa_reporte_id = er.id AND uer.user_id = :user_id AND uer.activo = 1)
                          AND EXISTS (SELECT 1 FROM empresa_usuario eu WHERE eu.empresa_id = er.empresa_id AND eu.user_id = :empresa_user_id AND eu.activo = 1)";
                $params[':user_id'] = $user_id;
                $params[':empresa_user_id'] = $user_id;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                return [
                    'success' => true,
                    'data' => [$row],
                    'count' => 1
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'No existe un reporte para esta empresa.'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en PowerBIModel::getTokenEmpresaReporte: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener el reporte para esta empresa.'
            ];
        }
    }
}
