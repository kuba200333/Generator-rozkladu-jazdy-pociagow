<?php
require 'db_config.php';

// Pobieramy ID tych konkretnych stacji po ich nazwach
$q = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje WHERE nazwa_stacji IN ('Szczecin Port Centralny SPB', 'Szczecin Port Centralny SPC', 'Szczecin Port Centralny SPA')");
$ids = [];
while($r = mysqli_fetch_assoc($q)) {
    $ids[$r['nazwa_stacji']] = $r['id_stacji'];
}

$id_spb = $ids['Szczecin Port Centralny SPB'] ?? 0;
$id_spc = $ids['Szczecin Port Centralny SPC'] ?? 0;
$id_spa = $ids['Szczecin Port Centralny SPA'] ?? 0;

if (!$id_spb || !$id_spc || !$id_spa) {
    die("Błąd: Nie znaleziono tych stacji w bazie danych. Upewnij się, że nazwy są identyczne.");
}

$res = mysqli_query($conn, "SELECT DISTINCT id_trasy FROM stacje_na_trasie");
$naprawione = 0;

while ($r = mysqli_fetch_assoc($res)) {
    $id_t = $r['id_trasy'];
    
    // Pobieramy stacje dla trasy
    $q_st = mysqli_query($conn, "SELECT id_stacji FROM stacje_na_trasie WHERE id_trasy = $id_t ORDER BY CAST(kolejnosc AS SIGNED) ASC");
    
    $stacje = [];
    while ($st = mysqli_fetch_assoc($q_st)) {
        $stacje[] = $st['id_stacji'];
    }

    $czy_byl_blad = false;
    $nowa_lista = [];

    // Przeszukujemy trasę i wywalamy SPC jeśli jest wciśnięte między SPB a SPA
    for ($i = 0; $i < count($stacje); $i++) {
        if (
            $stacje[$i] == $id_spc && 
            isset($stacje[$i-1]) && $stacje[$i-1] == $id_spb &&
            isset($stacje[$i+1]) && $stacje[$i+1] == $id_spa
        ) {
            $czy_byl_blad = true;
            $naprawione++;
            // Celowo nie dodajemy tej stacji do nowej listy (czyli ją usuwamy)
        } else {
            $nowa_lista[] = $stacje[$i];
        }
    }

    // Jeśli znaleźliśmy i wywaliliśmy błąd, nadpisujemy trasę z poprawną numeracją
    if ($czy_byl_blad) {
        mysqli_query($conn, "DELETE FROM stacje_na_trasie WHERE id_trasy = $id_t");
        
        $kol = 1;
        foreach ($nowa_lista as $id_s) {
            mysqli_query($conn, "INSERT INTO stacje_na_trasie (id_trasy, id_stacji, kolejnosc) VALUES ($id_t, $id_s, $kol)");
            $kol++;
        }
    }
}

echo "<h1>Gotowe! Miotła posprzątała.</h1>";
echo "Usunięto błędnych stacji SPC: <b>" . $naprawione . "</b>.<br>";
echo "Trasy są znów idealnie połączone i przenumerowane.";
?>