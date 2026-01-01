<?php
require 'db_config.php';
header('Content-Type: application/json');

if (!isset($_POST['id_szczegolu'])) {
    echo json_encode(['status'=>'error', 'msg'=>'Brak ID']);
    exit;
}

$id_szczegolu = (int)$_POST['id_szczegolu'];
$typ_raw = $_POST['typ'] ?? 'p'; 

// --- Normalizacja typu ---
$typ = $typ_raw;
if ($typ_raw === 'przyjazd') $typ = 'p';
elseif ($typ_raw === 'odjazd') $typ = 'o';

// 1. CZAS KLIKNIĘCIA
$now = date('H:i:s'); 

// Pobieramy dane stacji
$stmt = mysqli_prepare($conn, "SELECT id_przejazdu, kolejnosc, przyjazd, odjazd FROM szczegoly_rozkladu WHERE id_szczegolu = ?");
mysqli_stmt_bind_param($stmt, "i", $id_szczegolu);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$row) {
    echo json_encode(['status'=>'error', 'msg'=>'Błąd DB']);
    exit;
}

// 2. ZAPIS DO BAZY (Logika zależna od typu)
if ($typ == 'przelot') {
    // === NOWOŚĆ: PRZELOT ===
    // Zapisujemy TEN SAM czas jako przyjazd i odjazd (bo pociąg tylko mija stację)
    $stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
    mysqli_stmt_bind_param($stmt_upd, "ssi", $now, $now, $id_szczegolu);
    mysqli_stmt_execute($stmt_upd);
    
} elseif ($typ == 'p') {
    // Zwykły postój - Przyjazd
    $stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
    mysqli_stmt_bind_param($stmt_upd, "si", $now, $id_szczegolu);
    mysqli_stmt_execute($stmt_upd);
    
} else {
    // Zwykły postój - Odjazd (typ 'o')
    $stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
    mysqli_stmt_bind_param($stmt_upd, "si", $now, $id_szczegolu);
    mysqli_stmt_execute($stmt_upd);
}


// 3. OBLICZANIE CZASU POSTOJU (Dla licznika)
$czas_postoju = 0;
if ($typ == 'p' && $row['przyjazd'] && $row['odjazd']) {
    $plan_p = strtotime("1970-01-01 " . $row['przyjazd']);
    $plan_o = strtotime("1970-01-01 " . $row['odjazd']);
    $czas_postoju = $plan_o - $plan_p;
    if ($czas_postoju < 0) $czas_postoju = 0; 
}


// 4. LOGOWANIE STATYSTYK
// Uruchamiamy statystyki jeśli to PRZYJAZD ('p') LUB PRZELOT ('przelot')
// Bo w obu przypadkach kończymy jazdę na danym odcinku.
if ($typ == 'p' || $typ == 'przelot') {
    $_POST['id_szczegolu'] = $id_szczegolu; 
    include 'zapisz_statystyki.php';
}

echo json_encode([
    'status' => 'OK',
    'godzina' => $now,
    'postoj_sekundy' => $czas_postoju,
    'typ_zwrotny' => $typ // Odsyłamy typ, żeby JS wiedział co zaktualizować
]);
?>