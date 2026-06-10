<?php

declare(strict_types=1);

// Apache + mod_php entry — the C-driver path (ext-mongodb + mongodb/mongodb).
require __DIR__ . '/../vendor/autoload.php';

use ZealPHP\MongoDB\Parity\ParityApi;

header('Content-Type: application/json');

try {
    $api = new ParityApi(getenv('PARITY_MONGODB_URI') ?: 'mongodb://172.30.0.3:27036/?replicaSet=rs0');
    echo json_encode($api->handle($_GET['op'] ?? 'crud'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'where' => $e->getFile() . ':' . $e->getLine()]);
}
