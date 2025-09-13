<?php
header('Content-Type: application/json; charset=utf-8');
require 'db_config.php';

$id_trasy = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$data = [];

if ($id_trasy > 0) {
    $stmt = mysqli_prepare($conn, "SELECT s.id_stacji, s.nazwa_stacji 
                                  FROM stacje_na_trasie snt 
                                  JOIN stacje s ON snt.id_stacji = s.id_stacji
                                  WHERE snt.id_trasy = ? ORDER BY snt.kolejnosc");
    mysqli_stmt_bind_param($stmt, "i", $id_trasy);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
}

echo json_encode($data);
?>