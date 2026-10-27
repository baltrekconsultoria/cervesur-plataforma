<?php
/**
 * Controlador para estadísticas del sistema
 * Maneja la obtención de totales y métricas generales
 */

require_once __DIR__ . '/../models/ReporteModel.php';
require_once __DIR__ . '/../models/EmpresaModel.php';

class StatsController {
    private $pdo;
    private $reporteModel;
    private $empresaModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->reporteModel = new ReporteModel($pdo);
        $this->empresaModel = new EmpresaModel($pdo);
    }

    /**
     * Obtiene las estadísticas generales del sistema
     * @return array Estadísticas con totales de reportes y empresas
     */
    public function getStats() {
        try {
            // Obtener totales desde los modelos
            $totalReportes = $this->reporteModel->getTotalCount();
            $totalEmpresas = $this->empresaModel->getTotalCount();

            return [
                'success' => true,
                'data' => [
                    'total_reportes' => $totalReportes,
                    'total_empresas' => $totalEmpresas
                ]
            ];
        } catch (Exception $e) {
            error_log("Error en StatsController::getStats: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener las estadísticas'
            ];
        }
    }
}
