<?php
/**
 * Test batch workflow
 */
require_once '/www/holisticpeoplecom_349/public/wp-load.php';

echo "=== Testing batchAnalyze (no filter) ===\n";
$result = HP_GMC\Abilities\ProductAbilities::batchAnalyze([
    'limit' => 20
]);
echo json_encode($result, JSON_PRETTY_PRINT);
