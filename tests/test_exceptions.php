<?php
/**
 * Exception Hierarchy Tests
 *
 * Tests the full exception hierarchy, interface implementations,
 * special methods (getResultDocument, getWriteResult, hasErrorLabel),
 * and catches a real MongoDB error.
 */

// Preload ExceptionInterface (defined in Exception.php, not its own file)
require_once __DIR__ . '/../php/src/Exception/Exception.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'ZealPHP\\MongoDB\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../php/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) require_once $file;
});

use ZealPHP\MongoDB\Exception\ExceptionInterface;
use ZealPHP\MongoDB\Exception\Exception;
use ZealPHP\MongoDB\Exception\RuntimeException;
use ZealPHP\MongoDB\Exception\ConnectionException;
use ZealPHP\MongoDB\Exception\AuthenticationException;
use ZealPHP\MongoDB\Exception\ConnectionTimeoutException;
use ZealPHP\MongoDB\Exception\ServerException;
use ZealPHP\MongoDB\Exception\CommandException;
use ZealPHP\MongoDB\Exception\BulkWriteException;
use ZealPHP\MongoDB\Exception\ExecutionTimeoutException;
use ZealPHP\MongoDB\Exception\InvalidArgumentException;
use ZealPHP\MongoDB\Exception\LogicException;
use ZealPHP\MongoDB\Exception\UnexpectedValueException;

$pass = 0; $fail = 0; $errors = [];
function check($label, $cond) {
    global $pass, $fail, $errors;
    if ($cond) { $pass++; }
    else { $fail++; $errors[] = $label; echo "FAIL $label\n"; }
}

// ============================================================
echo "=== Exception hierarchy ===\n";
// ============================================================

// AuthenticationException -> ConnectionException -> RuntimeException
$auth = new AuthenticationException('auth failed');
check('AuthenticationException instanceof ConnectionException', $auth instanceof ConnectionException);
check('AuthenticationException instanceof RuntimeException', $auth instanceof RuntimeException);
check('AuthenticationException instanceof ExceptionInterface', $auth instanceof ExceptionInterface);
check('AuthenticationException instanceof \Throwable', $auth instanceof \Throwable);

// ConnectionException -> RuntimeException
$conn = new ConnectionException('conn failed');
check('ConnectionException instanceof RuntimeException', $conn instanceof RuntimeException);
check('ConnectionException instanceof ExceptionInterface', $conn instanceof ExceptionInterface);

// ConnectionTimeoutException -> ConnectionException -> RuntimeException
$timeout = new ConnectionTimeoutException('timed out');
check('ConnectionTimeoutException instanceof ConnectionException', $timeout instanceof ConnectionException);
check('ConnectionTimeoutException instanceof RuntimeException', $timeout instanceof RuntimeException);
check('ConnectionTimeoutException instanceof ExceptionInterface', $timeout instanceof ExceptionInterface);

// ServerException -> RuntimeException
$server = new ServerException('server error');
check('ServerException instanceof RuntimeException', $server instanceof RuntimeException);
check('ServerException instanceof ExceptionInterface', $server instanceof ExceptionInterface);

// CommandException -> ServerException -> RuntimeException
$cmd = new CommandException('cmd error', 42, null, (object)['errmsg' => 'test']);
check('CommandException instanceof ServerException', $cmd instanceof ServerException);
check('CommandException instanceof RuntimeException', $cmd instanceof RuntimeException);
check('CommandException instanceof ExceptionInterface', $cmd instanceof ExceptionInterface);

// BulkWriteException -> ServerException -> RuntimeException
$bulk = new BulkWriteException('bulk error', 0, null, (object)['nInserted' => 0]);
check('BulkWriteException instanceof ServerException', $bulk instanceof ServerException);
check('BulkWriteException instanceof RuntimeException', $bulk instanceof RuntimeException);
check('BulkWriteException instanceof ExceptionInterface', $bulk instanceof ExceptionInterface);

// ExecutionTimeoutException -> ServerException -> RuntimeException
$execTimeout = new ExecutionTimeoutException('exec timeout');
check('ExecutionTimeoutException instanceof ServerException', $execTimeout instanceof ServerException);
check('ExecutionTimeoutException instanceof RuntimeException', $execTimeout instanceof RuntimeException);
check('ExecutionTimeoutException instanceof ExceptionInterface', $execTimeout instanceof ExceptionInterface);

// ============================================================
echo "\n=== Non-Runtime exception classes ===\n";
// ============================================================

// Exception (base)
$base = new Exception('base');
check('Exception implements ExceptionInterface', $base instanceof ExceptionInterface);
check('Exception extends \Exception', $base instanceof \Exception);

// InvalidArgumentException
$inv = new InvalidArgumentException('invalid');
check('InvalidArgumentException implements ExceptionInterface', $inv instanceof ExceptionInterface);
check('InvalidArgumentException extends PHP InvalidArgumentException', $inv instanceof \InvalidArgumentException);

// LogicException
$logic = new LogicException('logic');
check('LogicException implements ExceptionInterface', $logic instanceof ExceptionInterface);
check('LogicException extends PHP LogicException', $logic instanceof \LogicException);

// UnexpectedValueException
$unexp = new UnexpectedValueException('unexpected');
check('UnexpectedValueException implements ExceptionInterface', $unexp instanceof ExceptionInterface);
check('UnexpectedValueException extends PHP UnexpectedValueException', $unexp instanceof \UnexpectedValueException);

// ============================================================
echo "\n=== CommandException getResultDocument ===\n";
// ============================================================

$resultDoc = (object)['errmsg' => 'not found', 'code' => 26];
$cmdEx = new CommandException('command failed', 26, null, $resultDoc);
check('getResultDocument returns object', is_object($cmdEx->getResultDocument()));
check('getResultDocument has errmsg', $cmdEx->getResultDocument()->errmsg === 'not found');
check('getResultDocument has code', $cmdEx->getResultDocument()->code === 26);
check('CommandException message correct', $cmdEx->getMessage() === 'command failed');
check('CommandException code correct', $cmdEx->getCode() === 26);

$cmdNoDoc = new CommandException('no doc');
check('getResultDocument returns null when not provided', $cmdNoDoc->getResultDocument() === null);

// ============================================================
echo "\n=== BulkWriteException getWriteResult ===\n";
// ============================================================

$writeResult = (object)['nInserted' => 2, 'nModified' => 1];
$bulkEx = new BulkWriteException('bulk failed', 0, null, $writeResult);
check('getWriteResult returns object', is_object($bulkEx->getWriteResult()));
check('getWriteResult has nInserted', $bulkEx->getWriteResult()->nInserted === 2);
check('getWriteResult has nModified', $bulkEx->getWriteResult()->nModified === 1);

$bulkNoResult = new BulkWriteException('no result');
check('getWriteResult returns null when not provided', $bulkNoResult->getWriteResult() === null);

// ============================================================
echo "\n=== RuntimeException hasErrorLabel ===\n";
// ============================================================

$rt = new RuntimeException('runtime error');
check('hasErrorLabel returns false by default', $rt->hasErrorLabel('TransientTransactionError') === false);
check('hasErrorLabel returns false for any label', $rt->hasErrorLabel('UnknownLabel') === false);

// ============================================================
echo "\n=== All implement ExceptionInterface ===\n";
// ============================================================

$allExceptions = [
    'Exception' => new Exception('e'),
    'RuntimeException' => new RuntimeException('e'),
    'ConnectionException' => new ConnectionException('e'),
    'AuthenticationException' => new AuthenticationException('e'),
    'ConnectionTimeoutException' => new ConnectionTimeoutException('e'),
    'ServerException' => new ServerException('e'),
    'CommandException' => new CommandException('e'),
    'BulkWriteException' => new BulkWriteException('e'),
    'ExecutionTimeoutException' => new ExecutionTimeoutException('e'),
    'InvalidArgumentException' => new InvalidArgumentException('e'),
    'LogicException' => new LogicException('e'),
    'UnexpectedValueException' => new UnexpectedValueException('e'),
];

foreach ($allExceptions as $name => $ex) {
    check("$name implements ExceptionInterface", $ex instanceof ExceptionInterface);
}

// ============================================================
echo "\n=== Catch real MongoDB error ===\n";
// ============================================================

// Try to trigger a real error by running an invalid command
$client = new \ZealPHP\MongoDB\Client('mongodb://db.selfmade.ninja:27017');
$db = $client->selectDatabase('zealphp_test');

$caught = false;
$errorMsg = '';
try {
    // Run a command that MongoDB does not recognize
    $db->command(['invalidCommandThatDoesNotExist' => 1]);
} catch (\Throwable $e) {
    $caught = true;
    $errorMsg = $e->getMessage();
}
check('invalid command throws an exception', $caught);
check('error message is not empty', strlen($errorMsg) > 0);

echo "\n========================================\n";
echo "Results: $pass passed, $fail failed\n";
echo "========================================\n";
if (count($errors) > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
exit($fail > 0 ? 1 : 0);
