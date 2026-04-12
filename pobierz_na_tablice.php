<?php
// pobierz_na_tablice.php
require 'db_config.php';
header('Content-Type: application/json');

$peron = $_GET['peron'] ?? '1';
$tor = $_GET['tor'] ?? '1'; // Dodane, by wyszukiwać precyzyjnie

// 1. Zmienione zapytanie - dodano wyliczanie opóźnienia na podstawie wcześniejszych stacji!
$sql = "
    SELECT 
        sr.*, 
        p.numer_pociagu, p.nazwa_pociagu, tp.pelna_nazwa as rodzaj,
        (SELECT s.nazwa_stacji FROM stacje s JOIN trasy t ON s.id_stacji = t.id_stacji_koncowej WHERE t.id_trasy = p.id_trasy) as stacja_konc,
        (SELECT s.nazwa_stacji FROM stacje s JOIN trasy t ON s.id_stacji = t.id_stacji_poczatkowej WHERE t.id_trasy = p.id_trasy) as stacja_pocz,
        (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = sr.id_stacji) as aktualna_stacja,
        
        (SELECT CASE 
            WHEN sr_hist.odjazd_rzecz IS NOT NULL AND sr_hist.odjazd_rzecz != sr_hist.odjazd THEN TIMESTAMPDIFF(MINUTE, sr_hist.odjazd, sr_hist.odjazd_rzecz)
            WHEN sr_hist.przyjazd_rzecz IS NOT NULL AND sr_hist.przyjazd_rzecz != sr_hist.przyjazd THEN TIMESTAMPDIFF(MINUTE, sr_hist.przyjazd, sr_hist.przyjazd_rzecz)
            ELSE 0 END
         FROM szczegoly_rozkladu sr_hist
         WHERE sr_hist.id_przejazdu = sr.id_przejazdu 
           AND CAST(sr_hist.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
           AND (
                (sr_hist.odjazd_rzecz IS NOT NULL AND sr_hist.odjazd_rzecz != sr_hist.odjazd) 
                OR 
                (sr_hist.przyjazd_rzecz IS NOT NULL AND sr_hist.przyjazd_rzecz != sr_hist.przyjazd)
               )
         ORDER BY CAST(sr_hist.kolejnosc AS SIGNED) DESC LIMIT 1
        ) as opoznienie_aktywne
        
    FROM szczegoly_rozkladu sr 
    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu
    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
    WHERE sr.peron = ? AND sr.tor = ? AND sr.status_wyswietlacza = 1 
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['error' => 'Błąd SQL (główne): ' . mysqli_error($conn)]);
    exit;
}

// Bind dla peronu I toru
mysqli_stmt_bind_param($stmt, "ss", $peron, $tor);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($res);

if ($data) {
    $data['is_last_station'] = ($data['stacja_konc'] == $data['aktualna_stacja'] || $data['status_dyzurnego'] == 'konczy');
    $data['stacje_posrednie'] = ''; 
    
    // Zabezpieczenie przed błędem z NULL
    $opoz_min = isset($data['opoznienie_aktywne']) ? intval($data['opoznienie_aktywne']) : 0;
    $data['opoznienie_minuty'] = $opoz_min;

    // Funkcja dodająca minuty z poziomu PHP (by na wyświetlacz szedł od razu dobry czas)
    function addMins($time, $mins) {
        if (!$time) return '';
        return date('H:i:s', strtotime($time . " +$mins minutes"));
    }

    // 2. Aplikowanie opóźnienia do czasu, który ląduje na tablicy
    // if ($opoz_min != 0) {
    //     if ($data['przyjazd']) {
    //         $data['przyjazd'] = addMins($data['przyjazd'], $opoz_min);
    //     }
    //     if ($data['odjazd']) {
    //         $data['odjazd'] = addMins($data['odjazd'], $opoz_min);
    //     }
    // }

    if (!$data['is_last_station']) {
        $sql_via = "
            SELECT s.nazwa_stacji, s.typ_stacji_id, s.czy_zapowiadac, sr_via.*
            FROM szczegoly_rozkladu sr_via
            JOIN stacje s ON sr_via.id_stacji = s.id_stacji
            WHERE sr_via.id_przejazdu = ? 
              AND CAST(sr_via.kolejnosc AS SIGNED) > CAST(? AS SIGNED)
              AND s.nazwa_stacji != ?
              AND sr_via.uwagi_postoju = 'ph'
            ORDER BY CAST(sr_via.kolejnosc AS SIGNED) ASC
        ";
        
        $stmt_via = mysqli_prepare($conn, $sql_via);
        if (!$stmt_via) {
            echo json_encode(['error' => 'Błąd SQL (stacje via): ' . mysqli_error($conn)]);
            exit;
        }
        
        mysqli_stmt_bind_param($stmt_via, "iss", $data['id_przejazdu'], $data['kolejnosc'], $data['stacja_konc']);
        mysqli_stmt_execute($stmt_via);
        $res_via = mysqli_stmt_get_result($stmt_via);
        
        $all_future_stops = [];
        while ($row = mysqli_fetch_assoc($res_via)) {
            $all_future_stops[] = $row;
        }

        $selected_stops = [];
        $added_kolejnosc = []; 

        // --- TWÓJ PRZEŁĄCZNIK W KODZIE (DLA TABLIC) ---
        // true  = dobiera małe przystanki, by na siłę dobić do 5 wyświetlanych stacji
        // false = ignoruje przystanki, pokazuje tylko ważne stacje (nawet jeśli będą tylko 2)
        $DOBIERAJ_MALE_PRZYSTANKI = false;

        // KROK 1: Najpierw te z wymuszonym zapowiadaniem (czy_zapowiadac = 1) - BEZ LIMITU!
        // KROK 1: Najpierw te z wymuszonym zapowiadaniem (czy_zapowiadac = 1) - BEZ LIMITU!
        foreach ($all_future_stops as $s) {
            $czy = isset($s['czy_zapowiadac']) ? $s['czy_zapowiadac'] : 0;
            if ($czy == 1 && !in_array($s['kolejnosc'], $added_kolejnosc)) {
                $selected_stops[] = $s;
                $added_kolejnosc[] = $s['kolejnosc'];
            }
        }

        // KROK 2: Potem stacje węzłowe (typ_stacji_id = 1) - dopełniamy do 5
        foreach ($all_future_stops as $s) {
            if (count($selected_stops) >= 5) break;
            if ($s['typ_stacji_id'] == 1 && !in_array($s['kolejnosc'], $added_kolejnosc)) {
                $selected_stops[] = $s;
                $added_kolejnosc[] = $s['kolejnosc'];
            }
        }

        // KROK 3: Inne stacje, mijanki itp. (wszystko co NIE JEST typem 2) - dopełniamy do 5
        foreach ($all_future_stops as $s) {
            if (count($selected_stops) >= 5) break;
            if ($s['typ_stacji_id'] != 2 && !in_array($s['kolejnosc'], $added_kolejnosc)) {
                $selected_stops[] = $s;
                $added_kolejnosc[] = $s['kolejnosc'];
            }
        }

        // KROK 4: Małe przystanki osobowe (typ_stacji_id = 2) - TYLKO JEŚLI PRZEŁĄCZNIK JEST NA TRUE
        if ($DOBIERAJ_MALE_PRZYSTANKI) {
            foreach ($all_future_stops as $s) {
                if (count($selected_stops) >= 5) break;
                if ($s['typ_stacji_id'] == 2 && !in_array($s['kolejnosc'], $added_kolejnosc)) {
                    $selected_stops[] = $s;
                    $added_kolejnosc[] = $s['kolejnosc'];
                }
            }
        }
        
        // Na koniec sortujemy je chronologicznie według kolejności na trasie
        usort($selected_stops, function($a, $b) {
            return (int)$a['kolejnosc'] - (int)$b['kolejnosc'];
        });

        $via_names = array_column($selected_stops, 'nazwa_stacji');
        if (count($via_names) > 0) {
            $data['stacje_posrednie'] = implode(', ', $via_names);
        }
    }
    
    echo json_encode($data);
} else {
    echo json_encode(['error' => 'Brak pociągu']);
}
?>