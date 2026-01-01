<?php
// Ten plik jest includowany w zapisz_czas_auto.php

// 1. Pobierz obecną stację (gdzie właśnie dojechałeś - STOP)
$sql = "SELECT sr.id_przejazdu, sr.kolejnosc, sr.przyjazd, sr.przyjazd_rzecz, s.nazwa_stacji 
        FROM szczegoly_rozkladu sr 
        JOIN stacje s ON sr.id_stacji = s.id_stacji 
        WHERE sr.id_szczegolu = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_szczegolu);
mysqli_stmt_execute($stmt);
$obecna = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Wykonujemy tylko jeśli mamy RZECZYWISTY czas przyjazdu (właśnie kliknięty)
if ($obecna && $obecna['przyjazd_rzecz']) {

    // 2. Znajdź OSTATNIĄ stację, z której RUSZYŁEŚ (START)
    // Szukamy wstecz (kolejnosc < obecna) pierwszego wpisu, który ma wypełniony odjazd_rzecz
    $sql_prev = "SELECT sr.odjazd, sr.odjazd_rzecz, s.nazwa_stacji 
                 FROM szczegoly_rozkladu sr 
                 JOIN stacje s ON sr.id_stacji = s.id_stacji 
                 WHERE sr.id_przejazdu = ? 
                 AND sr.kolejnosc < ? 
                 AND sr.odjazd_rzecz IS NOT NULL AND sr.odjazd_rzecz != ''
                 ORDER BY sr.kolejnosc DESC LIMIT 1";

    $stmt_prev = mysqli_prepare($conn, $sql_prev);
    mysqli_stmt_bind_param($stmt_prev, "ii", $obecna['id_przejazdu'], $obecna['kolejnosc']);
    mysqli_stmt_execute($stmt_prev);
    $poprzednia = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_prev));

    if ($poprzednia) {
        // Mamy parę: START (poprzednia) -> STOP (obecna)
        
        // --- A. OBLICZAMY CZAS RZECZYWISTY (STOPER) ---
        // Używamy daty dzisiejszej + godziny z bazy, żeby stworzyć timestampy
        $today = date('Y-m-d');
        $start_r_ts = strtotime("$today " . $poprzednia['odjazd_rzecz']);
        $stop_r_ts  = strtotime("$today " . $obecna['przyjazd_rzecz']);
        
        // Obsługa północy (jeśli odjazd był 23:59 a przyjazd 00:05)
        if ($stop_r_ts < $start_r_ts) $stop_r_ts += 86400;
        
        $czas_rzecz_sekundy = $stop_r_ts - $start_r_ts;
        
        
        // --- B. OBLICZAMY CZAS PLANOWY (Tylko interwał) ---
        $start_p_ts = strtotime("$today " . $poprzednia['odjazd']);
        $stop_p_ts  = strtotime("$today " . $obecna['przyjazd']);
        
        if ($stop_p_ts < $start_p_ts) $stop_p_ts += 86400;
        
        $czas_plan_sekundy = $stop_p_ts - $start_p_ts;
        
        
        // --- C. RÓŻNICA ---
        $roznica = $czas_rzecz_sekundy - $czas_plan_sekundy;

        // --- D. ZAPIS BEZ DUPLIKATÓW ---
        // Najpierw usuwamy stary wpis dla tego konkretnego odcinka w tym przejeździe
        $delete = mysqli_prepare($conn, "DELETE FROM logi_przejazdu WHERE id_przejazdu = ? AND stacja_start = ? AND stacja_koniec = ?");
        mysqli_stmt_bind_param($delete, "iss", $obecna['id_przejazdu'], $poprzednia['nazwa_stacji'], $obecna['nazwa_stacji']);
        mysqli_stmt_execute($delete);

        // Teraz wstawiamy nowy, świeży pomiar
        $insert = mysqli_prepare($conn, "INSERT INTO logi_przejazdu (id_przejazdu, stacja_start, stacja_koniec, czas_start_rzecz, czas_stop_rzecz, czas_podrozy_sekundy, planowany_czas_sekundy, roznica_sekundy) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($insert, "issssiii", 
            $obecna['id_przejazdu'],
            $poprzednia['nazwa_stacji'],
            $obecna['nazwa_stacji'],
            $poprzednia['odjazd_rzecz'],
            $obecna['przyjazd_rzecz'],
            $czas_rzecz_sekundy,
            $czas_plan_sekundy,
            $roznica
        );
        mysqli_stmt_execute($insert);
    }
}
?>