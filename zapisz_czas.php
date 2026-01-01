<?php
session_start();
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "Błąd: Wymagane żądanie POST";
    exit;
}

$id_szczegolu = (int)$_POST['id_szczegolu'];
// Pobieramy dane z formularza
$przyjazd_rzecz_input = !empty($_POST['przyjazd_rzecz']) ? $_POST['przyjazd_rzecz'] . ":00" : null;
$odjazd_rzecz_input = !empty($_POST['odjazd_rzecz']) ? $_POST['odjazd_rzecz'] . ":00" : null;

// 1. Pobieramy dane o aktualnym wierszu
$stmt = mysqli_prepare($conn, "SELECT id_przejazdu, kolejnosc, przyjazd, odjazd FROM szczegoly_rozkladu WHERE id_szczegolu = ?");
mysqli_stmt_bind_param($stmt, "i", $id_szczegolu);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);

if (!$row) {
    echo "Błąd: Nie znaleziono pociągu.";
    exit;
}

$id_przejazdu = $row['id_przejazdu'];
$kolejnosc = $row['kolejnosc'];

// 2. Aktualizujemy bieżącą stację i ustawiamy ZATWIERDZONY = 1
// To sprawi, że wiersz zaświeci się na zielono w panelu dyżurnego
$stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
mysqli_stmt_bind_param($stmt_upd, "ssi", $przyjazd_rzecz_input, $odjazd_rzecz_input, $id_szczegolu);
mysqli_stmt_execute($stmt_upd);

// 3. Obliczamy opóźnienie (Delta) do propagacji na resztę trasy
$opoznienie_sekundy = 0;
$czy_aktualizowac_reszte = false;
$baza_czasu = date('Y-m-d'); 

if ($odjazd_rzecz_input && $row['odjazd']) {
    $plan = strtotime("$baza_czasu " . $row['odjazd']);
    $rzecz = strtotime("$baza_czasu " . $odjazd_rzecz_input);
    
    if ($rzecz - $plan > 43200) $rzecz -= 86400;
    if ($plan - $rzecz > 43200) $rzecz += 86400;
    
    $opoznienie_sekundy = $rzecz - $plan;
    $czy_aktualizowac_reszte = true;
} 
elseif ($przyjazd_rzecz_input && $row['przyjazd']) {
    $plan = strtotime("$baza_czasu " . $row['przyjazd']);
    $rzecz = strtotime("$baza_czasu " . $przyjazd_rzecz_input);
    
    if ($rzecz - $plan > 43200) $rzecz -= 86400;
    if ($plan - $rzecz > 43200) $rzecz += 86400;

    $opoznienie_sekundy = $rzecz - $plan;
    $czy_aktualizowac_reszte = true;
}

// 4. Propagacja (Kaskada) - aktualizuje czasy, ale NIE ustawia zatwierdzenia dla przyszłych stacji
if ($czy_aktualizowac_reszte) {
    
    $znak = ($opoznienie_sekundy < 0) ? "-" : "";
    $sek = abs($opoznienie_sekundy);
    $h = floor($sek / 3600);
    $m = floor(($sek / 60) % 60);
    $s = $sek % 60;
    $time_str = sprintf("%s%02d:%02d:%02d", $znak, $h, $m, $s);

    $sql_kaskada = "UPDATE szczegoly_rozkladu 
                    SET 
                        przyjazd_rzecz = ADDTIME(przyjazd, ?),
                        odjazd_rzecz = ADDTIME(odjazd, ?)
                    WHERE id_przejazdu = ? AND kolejnosc > ?";
    
    $stmt_kaskada = mysqli_prepare($conn, $sql_kaskada);
    mysqli_stmt_bind_param($stmt_kaskada, "ssii", $time_str, $time_str, $id_przejazdu, $kolejnosc);
    mysqli_stmt_execute($stmt_kaskada);
}

echo "OK";
?>