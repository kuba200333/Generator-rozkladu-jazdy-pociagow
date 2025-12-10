<?php
// api_pociag.php
require 'db_config.php';
header('Content-Type: application/json');

if (isset($_GET['action']) && $_GET['action'] == 'trasa' && isset($_GET['id_przejazdu'])) {
    $id_przejazdu = (int)$_GET['id_przejazdu'];
    
    $sql = "
    SELECT 
        s.nazwa_stacji, 
        sr.przyjazd, sr.odjazd, 
        sr.przyjazd_rzecz, sr.odjazd_rzecz
    FROM szczegoly_rozkladu sr
    JOIN stacje s ON sr.id_stacji = s.id_stacji
    WHERE sr.id_przejazdu = ?
    ORDER BY sr.kolejnosc ASC
    ";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id_przejazdu);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Oblicz czas postoju jeśli jest przyjazd i odjazd
        $row['postoj'] = '';
        if ($row['przyjazd'] && $row['odjazd']) {
             // prosta różnica, w rzeczywistości można obliczyć minuty
             $row['postoj'] = 'ph'; // placeholder
        }
        $data[] = $row;
    }
    
    echo json_encode($data);
    exit;
}