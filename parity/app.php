<?php

declare(strict_types=1);

// ZealPHP entry — the Rust-driver path (zealphp_mongodb + zealphp/mongodb),
// running under OpenSwoole coroutines via the real framework.
require __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;
use ZealPHP\MongoDB\Parity\ParityApi;

App::superglobals(false);
$app = App::init('127.0.0.1', (int) (getenv('PARITY_ZEAL_PORT') ?: 8090));

$app->route('/api', methods: ['GET'], handler: function ($request) {
    $op = $request->get['op'] ?? 'crud';
    try {
        $api = new ParityApi(getenv('PARITY_MONGODB_URI') ?: 'mongodb://172.30.0.3:27036/?replicaSet=rs0');

        return $api->handle((string) $op);
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage(), 'where' => $e->getFile() . ':' . $e->getLine()];
    }
});

$app->run(['worker_num' => 2, 'task_worker_num' => 0]);
