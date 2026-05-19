<?php

declare(strict_types=1);

$pool = zealphp_mongodb_connect('mongodb://db.selfmade.ninja:27017');
echo "Connected pool=$pool\n";

// Insert documents
$result = zealphp_mongodb_insert_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Alice', 'age' => 30, 'id' => 1]);
echo 'Insert Alice: inserted_id=' . $result['inserted_id'] . ' acknowledged=' . ($result['acknowledged'] ? 'true' : 'false') . ' count=' . $result['inserted_count'] . "\n";

$result = zealphp_mongodb_insert_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Bob', 'age' => 25, 'id' => 2]);
echo 'Insert Bob: inserted_id=' . $result['inserted_id'] . "\n";

$result = zealphp_mongodb_insert_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Charlie', 'age' => 35, 'id' => 3]);
echo 'Insert Charlie: inserted_id=' . $result['inserted_id'] . "\n";

// Count
$count = zealphp_mongodb_count_documents($pool, 'test_zealphp', 'test_crud', []);
echo "Count: $count\n";

// FindOne
$doc = zealphp_mongodb_find_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Alice']);
echo 'FindOne Alice: name=' . $doc['name'] . ' age=' . $doc['age'] . "\n";

// FindOne miss
$doc = zealphp_mongodb_find_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Nobody']);
echo 'FindOne Nobody: ' . ($doc === null ? 'null (correct)' : 'UNEXPECTED') . "\n";

// Find with cursor
$cursor = zealphp_mongodb_find($pool, 'test_zealphp', 'test_crud', []);
$names = [];
while ($row = zealphp_mongodb_cursor_next($cursor)) {
    $names[] = $row['name'];
}

zealphp_mongodb_cursor_close($cursor);
echo 'Find all: ' . implode(', ', $names) . "\n";

// Find with filter
$cursor = zealphp_mongodb_find($pool, 'test_zealphp', 'test_crud', ['age' => ['$gte' => 30]]);
$names = [];
while ($row = zealphp_mongodb_cursor_next($cursor)) {
    $names[] = $row['name'];
}

zealphp_mongodb_cursor_close($cursor);
echo 'Find age>=30: ' . implode(', ', $names) . "\n";

// Update
$upd = zealphp_mongodb_update_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Alice'], ['$set' => ['age' => 31]]);
echo 'Update Alice: matched=' . $upd['matched_count'] . ' modified=' . $upd['modified_count'] . ' ack=' . ($upd['acknowledged'] ? 'true' : 'false') . "\n";

// Verify update
$doc = zealphp_mongodb_find_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Alice']);
echo 'Alice age after update: ' . $doc['age'] . "\n";

// Delete
$del = zealphp_mongodb_delete_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Charlie']);
echo 'Delete Charlie: deleted=' . $del['deleted_count'] . ' ack=' . ($del['acknowledged'] ? 'true' : 'false') . "\n";

$count = zealphp_mongodb_count_documents($pool, 'test_zealphp', 'test_crud', []);
echo "Count after delete: $count\n";

// Aggregate pipeline
$cursor = zealphp_mongodb_aggregate($pool, 'test_zealphp', 'test_crud', [
    ['$match' => ['age' => ['$gte' => 25]]],
    ['$sort' => ['name' => 1]],
]);
echo "Aggregate results:\n";
while ($doc = zealphp_mongodb_cursor_next($cursor)) {
    echo '  ' . $doc['name'] . ' (age: ' . $doc['age'] . ")\n";
}

zealphp_mongodb_cursor_close($cursor);

// Cleanup
zealphp_mongodb_delete_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Alice']);
zealphp_mongodb_delete_one($pool, 'test_zealphp', 'test_crud', ['name' => 'Bob']);

$count = zealphp_mongodb_count_documents($pool, 'test_zealphp', 'test_crud', []);
echo "Count after cleanup: $count\n";

zealphp_mongodb_close($pool);
echo "Done\n";
