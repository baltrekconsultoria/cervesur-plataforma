<?php

/**
 * Diagnósticos AGREGADOS de solo lectura (modo «diagnostico»).
 *
 * Solo devuelve NÚMEROS y booleanos: recuentos y conciliación con el inventario real. Nunca registros individuales, nombres, correos, enlaces de Power BI,
 * identificadores de informes ni hashes. Solo ejecuta las sentencias de lib/diagnostico_catalogo.php (catálogo cerrado; la conexión rechaza cualquier otra).
 *
 * Protección frente a grupos pequeños (APP_DIAG_MIN_CELL):
 *   · un recuento de 1 a APP_DIAG_MIN_CELL-1 nunca se publica;
 *   · las medidas declaradas «solo_coincidencia» (roles, anomalías…) solo se informan como coincide / no coincide con el inventario;
 *   · si la diferencia entre un conjunto y su subconjunto es un grupo pequeño, se reservan ambos números (no se puede deducir restando).
 * Limitación declarada: la protección cubre las parejas y familias del catálogo, no cualquier combinación imaginable de cifras externas.
 */
require_once __DIR__ . '/../lib/mode.php';
require_once __DIR__ . '/../lib/diagnostico_catalogo.php';

class DiagnosticoController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /** @return array<string,int> valores EXACTOS (solo para uso interno: nunca se devuelven sin filtrar) */
    public function medir() {
        $result = [];
        foreach (diagnostico_catalogo_medidas() as $name => $measure) {
            $result[$name] = (int)$this->pdo->query($measure['sql'])->fetchColumn();
        }
        return $result;
    }

    /** @return string[] nombres de medidas cuyo valor NO puede publicarse */
    public static function reservadas(array $values) {
        $hidden = [];
        foreach (diagnostico_catalogo_medidas() as $name => $measure) {
            if ($measure['visibilidad'] !== 'publica' || self::esCeldaPequena($values[$name] ?? 0)) {
                $hidden[$name] = true;
            }
        }
        foreach (diagnostico_catalogo_subconjuntos() as [$set, $subset]) {
            if (isset($values[$set], $values[$subset]) && self::esCeldaPequena($values[$set] - $values[$subset])) {
                $hidden[$set] = true;
                $hidden[$subset] = true;
            }
        }
        $names = array_keys($hidden);
        sort($names);
        return $names;
    }

    private static function esCeldaPequena($n) {
        return $n > 0 && $n < APP_DIAG_MIN_CELL;
    }

    /** Controles de ausencia de escritura (booleanos; no revelan nombres de cuenta ni privilegios). */
    public function controles() {
        $queries = diagnostico_catalogo_controles();
        $session = (int)$this->pdo->query($queries['sesion_solo_lectura'])->fetchColumn() === 1;
        $grants = $this->pdo->query($queries['privilegios_de_la_cuenta'])->fetchAll(PDO::FETCH_COLUMN, 0);
        return [
            'modo' => app_mode(),
            'sesion_de_base_de_datos_en_solo_lectura' => $session,
            'cuenta_de_base_de_datos_sin_privilegios_de_escritura' => app_grants_violations($grants) === [],
        ];
    }

    public function resumen() {
        $values = $this->medir();
        $hidden = self::reservadas($values);
        return [
            'success' => true,
            'controles' => $this->controles(),
            'medidas' => array_diff_key($values, array_flip($hidden)),
            'reservadas_por_proteccion_de_grupos_pequenos' => $hidden,
        ];
    }

    /** Compara lo medido con el inventario real. Solo coincide/no coincide; el valor solo se muestra si es publicable. */
    public function conciliacion() {
        $expected = require __DIR__ . '/../lib/inventario_esperado.php';
        $observed = $this->medir();
        $hidden = array_flip(self::reservadas($observed));
        $rows = [];
        $deviations = 0;
        foreach ($expected as $name => $value) {
            $ok = ($observed[$name] ?? null) === $value;
            $deviations += $ok ? 0 : 1;
            $row = ['metrica' => $name, 'ok' => $ok];
            if (!isset($hidden[$name])) {
                $row['esperado'] = $value;
                $row['observado'] = $observed[$name] ?? null;
            }
            $rows[] = $row;
        }
        return ['success' => true, 'coincide' => $deviations === 0, 'desviaciones' => $deviations, 'controles' => $this->controles(), 'detalle' => $rows];
    }
}
