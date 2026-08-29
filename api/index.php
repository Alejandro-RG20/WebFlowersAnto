<?php
/**
 * La carpeta api/ no tiene índice navegable: sus endpoints solo aceptan POST.
 */
declare(strict_types=1);
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'No hay nada aquí.'], JSON_UNESCAPED_UNICODE);
