<?php
require 'db_config.php';

if (isset($_GET['id_przejazdu'])) {
    $id_przejazdu = (int)$_GET['id_przejazdu'];
    
    // 1. POBIERZ OPIS (Z POPRAWNYM JOINEM DO PRZEWOŹNIKA)
    $sql_opis = "
        SELECT 
            p.*, 
            tr.nazwa_trasy, 
            tp.pelna_nazwa as typ_nazwa, 
            tp.skrot as rodzaj_skrot,
            pr.pelna_nazwa as przewoznik_nazwa, 
            pr.pelna_nazwa as przewoznik_skrot,
            (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = tr.id_stacji_poczatkowej) as stacja_pocz,
            (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = tr.id_stacji_koncowej) as stacja_konc
        FROM przejazdy p
        JOIN trasy tr ON p.id_trasy = tr.id_trasy
        LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
        LEFT JOIN przewoznicy pr ON tp.id_przewoznika = pr.id_przewoznika
        WHERE p.id_przejazdu = ?
    ";
    
    $stmt = mysqli_prepare($conn, $sql_opis);
    mysqli_stmt_bind_param($stmt, "i", $id_przejazdu);
    mysqli_stmt_execute($stmt);
    $opis = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // 2. POBIERZ TRASĘ
    $sql_trasa = "
        SELECT 
            s.id_stacji,     
            s.typ_stacji_id,
            s.czy_zapowiadac,
            sr.kolejnosc, s.nazwa_stacji, 
            sr.przyjazd, sr.odjazd, 
            sr.przyjazd_rzecz, sr.odjazd_rzecz, 
            sr.uwagi_postoju, sr.tor, sr.peron,
            sr.czy_odwolany,
            sr.zatwierdzony  -- <--- DODANA KOLUMNA
        FROM szczegoly_rozkladu sr
        JOIN stacje s ON sr.id_stacji = s.id_stacji
        WHERE sr.id_przejazdu = ?
        ORDER BY sr.kolejnosc ASC
    ";
    
    $stmt2 = mysqli_prepare($conn, $sql_trasa);
    mysqli_stmt_bind_param($stmt2, "i", $id_przejazdu);
    mysqli_stmt_execute($stmt2);
    $trasa = mysqli_fetch_all(mysqli_stmt_get_result($stmt2), MYSQLI_ASSOC);

    echo json_encode(['opis' => $opis, 'trasa' => $trasa]);
}
?>