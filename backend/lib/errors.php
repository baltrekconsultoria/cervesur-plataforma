<?php
/**
 * Mensajes de error públicos.
 * Las respuestas HTTP nunca incluyen SQLSTATE, SQL ni el mensaje de la excepción: el detalle técnico
 * solo se registra en el log interno del servidor (error_log).
 */
if (!function_exists('domain_error_message')) {
    /** Mensaje de una DomainException: texto de regla de negocio escrito por la aplicación (nunca de la base de datos). */
    function domain_error_message(DomainException $e)
    {
        return $e->getMessage();
    }
}

if (!function_exists('public_error_message')) {
    function public_error_message($publicMessage, $e)
    {
        $detail = ($e instanceof Throwable)
            ? get_class($e) . ' [' . $e->getCode() . '] ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
            : (string)$e;
        error_log('[cervesur] ' . $publicMessage . ' :: ' . $detail);
        return $publicMessage;
    }
}
