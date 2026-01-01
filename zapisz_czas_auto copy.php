<?php
require 'db_config.php';

if (!isset($_POST['id_szczegolu'])) die("Błąd: brak ID");

$id_szczegolu = (int)$_POST['id_szczegolu'];
$typ_raw = $_POST['typ'] ?? 'p'; 

// --- POPRAWKA 1: Normalizacja typu ---
if ($typ_raw === 'przyjazd') $typ = 'p';
elseif ($typ_raw === 'odjazd') $typ = 'o';
else $typ = $typ_raw; 

// --- NOWA LOGIKA: KOREKTA CZASU (FIZYKA POCIĄGU) ---
$timestamp = time(); // Pobieramy aktualny czas serwera

// Konfiguracja (ile sekund korygujemy)
$czas_hamowania = 10; // Ile dodać przy przyjeździe
$czas_rozruchu = 10;  // Ile odjąć przy odjeździe

if ($typ == 'p') {
    // PRZYJAZD: Dodajemy czas, bo pociąg jeszcze hamuje po kliknięciu
    $timestamp += $czas_hamowania;
} elseif ($typ == 'o') {
    // ODJAZD: Odejmujemy czas, uwzględniając moment ruszenia/rozruchu
    $timestamp -= $czas_rozruchu;
}

// Tworzymy sformatowaną godzinę z uwzględnioną korektą
$now = date('H:i:s', $timestamp);
// ----------------------------------------------------


// 1. Pobieramy plan bieżącej stacji
$stmt = mysqli_prepare($conn, "SELECT id_przejazdu, kolejnosc, przyjazd, odjazd FROM szczegoly_rozkladu WHERE id_szczegolu = ?");
mysqli_stmt_bind_param($stmt, "i", $id_szczegolu);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$row) die("Błąd DB");

$id_przejazdu = $row['id_przejazdu'];
$kolejnosc = $row['kolejnosc'];

// 2. Wpisujemy skorygowany "TERAZ" ($now) i ZATWIERDZAMY
if ($typ == 'p') {
    $stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET przyjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
} else {
    $stmt_upd = mysqli_prepare($conn, "UPDATE szczegoly_rozkladu SET odjazd_rzecz = ?, zatwierdzony = 1 WHERE id_szczegolu = ?");
}
mysqli_stmt_bind_param($stmt_upd, "si", $now, $id_szczegolu);
mysqli_stmt_execute($stmt_upd);

// 3. Obliczamy opóźnienie (na podstawie skorygowanego czasu)
$opoznienie_sekundy = 0;
$czy_liczyc = false;
$today = date('Y-m-d');

if ($typ == 'p' && $row['przyjazd']) {
    $plan = strtotime("$today " . $row['przyjazd']);
    $rzecz = strtotime("$today " . $now); // Tu używamy czasu po korekcie
    
    if ($rzecz - $plan > 43200) $rzecz -= 86400;
    if ($plan - $rzecz > 43200) $rzecz += 86400;
    
    $opoznienie_sekundy = $rzecz - $plan;
    $czy_liczyc = true;
} 
elseif ($typ == 'o' && $row['odjazd']) {
    $plan = strtotime("$today " . $row['odjazd']);
    $rzecz = strtotime("$today " . $now); // Tu używamy czasu po korekcie
    
    if ($rzecz - $plan > 43200) $rzecz -= 86400;
    if ($plan - $rzecz > 43200) $rzecz += 86400;

    $opoznienie_sekundy = $rzecz - $plan;
    $czy_liczyc = true;
}

// 4. Aktualizujemy resztę trasy w dół
if ($czy_liczyc) {
    $znak = ($opoznienie_sekundy < 0) ? "-" : "";
    $sek = abs($opoznienie_sekundy);
    $h = floor($sek / 3600);
    $m = floor(($sek / 60) % 60);
    $s = $sek % 60;
    $time_str = sprintf("%s%02d:%02d:%02d", $znak, $h, $m, $s);

    // --- POPRAWKA 2: Bezpieczne zapytanie SQL (IF) ---
    $sql_prop = "UPDATE szczegoly_rozkladu 
                 SET 
                    przyjazd_rzecz = IF(przyjazd IS NOT NULL AND przyjazd != '', ADDTIME(przyjazd, ?), przyjazd_rzecz),
                    odjazd_rzecz = IF(odjazd IS NOT NULL AND odjazd != '', ADDTIME(odjazd, ?), odjazd_rzecz)
                 WHERE id_przejazdu = ? AND kolejnosc > ?";
    
    $stmt_prop = mysqli_prepare($conn, $sql_prop);
    mysqli_stmt_bind_param($stmt_prop, "ssii", $time_str, $time_str, $id_przejazdu, $kolejnosc);
    mysqli_stmt_execute($stmt_prop);
}

echo "OK";
?>