<?php
echo "Test endpoint working\n";
var_dump($_SERVER['REQUEST_URI'] ?? 'No REQUEST_URI');
echo json_encode(['status' => 'test ok'], JSON_PRETTY_PRINT);
