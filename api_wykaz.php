<?php
require 'db_config.php';
header('Content-Type: application/json');

$id_stacji = $_GET['id_stacji'] ?? 29;

$sql = "
    SELECT 
        sr.id_szczegolu, sr.id_przejazdu, sr.przyjazd, sr.odjazd, 
        sr.przyjazd_rzecz, sr.odjazd_rzecz, sr.tor, sr.peron, sr.status_dyzurnego, sr.uwagi_postoju,
        sr.kolejnosc,
        p.numer_pociagu, p.nazwa_pociagu, tp.skrot as rodzaj, tp.kolor_czcionki, sr.czy_odwolany, sr.zatwierdzony,
        
        (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = t.id_stacji_poczatkowej) as stacja_pocz,
        (SELECT s.nazwa_stacji FROM stacje s WHERE s.id_stacji = t.id_stacji_koncowej) as stacja_konc,
        
        pr.pelna_nazwa as przewoznik_skrot,
        
        -- Z KIERUNKU: Tylko Typ 1 (Stacja) lub 3 (Posterunek Odg.)
        (SELECT s2.nazwa_stacji 
         FROM szczegoly_rozkladu sr2 
         JOIN stacje s2 ON sr2.id_stacji = s2.id_stacji
         WHERE sr2.id_przejazdu = sr.id_przejazdu 
         AND CAST(sr2.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
         AND s2.typ_stacji_id IN (1, 3)
         ORDER BY CAST(sr2.kolejnosc AS SIGNED) DESC LIMIT 1) as stacja_prev,

        -- W KIERUNKU: Tylko Typ 1 (Stacja) lub 3 (Posterunek Odg.)
        (SELECT s3.nazwa_stacji 
         FROM szczegoly_rozkladu sr3 
         JOIN stacje s3 ON sr3.id_stacji = s3.id_stacji
         WHERE sr3.id_przejazdu = sr.id_przejazdu 
         AND CAST(sr3.kolejnosc AS SIGNED) > CAST(sr.kolejnosc AS SIGNED)
         AND s3.typ_stacji_id IN (1, 3)
         ORDER BY CAST(sr3.kolejnosc AS SIGNED) ASC LIMIT 1) as stacja_next,

        (SELECT 
            CASE 
                WHEN sr_hist.odjazd_rzecz IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sr_hist.odjazd, sr_hist.odjazd_rzecz)
                WHEN sr_hist.przyjazd_rzecz IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, sr_hist.przyjazd, sr_hist.przyjazd_rzecz)
                ELSE 0
            END
         FROM szczegoly_rozkladu sr_hist
         WHERE sr_hist.id_przejazdu = sr.id_przejazdu 
           AND CAST(sr_hist.kolejnosc AS SIGNED) < CAST(sr.kolejnosc AS SIGNED)
           AND (sr_hist.odjazd_rzecz IS NOT NULL OR sr_hist.przyjazd_rzecz IS NOT NULL)
         ORDER BY CAST(sr_hist.kolejnosc AS SIGNED) DESC
         LIMIT 1
        ) as opoznienie_aktywne

    FROM szczegoly_rozkladu sr
    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu
    JOIN trasy t ON p.id_trasy = t.id_trasy
    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
    LEFT JOIN przewoznicy pr ON tp.id_przewoznika = pr.id_przewoznika
    WHERE sr.id_stacji = ?
    ORDER BY sr.przyjazd ASC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $id_stacji);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_all($res, MYSQLI_ASSOC);

echo json_encode($data);
?>