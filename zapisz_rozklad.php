<?php
session_start();
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_trasy = $_SESSION['id_trasy'] ?? null;
    $nr_poc = $_SESSION['nr_poc'] ?? null;
    $id_typu_pociagu = $_SESSION['id_typu_pociagu'] ?? null;
    $nazwa_pociagu = $_SESSION['nazwa_pociagu'] ?? '';
    
    // To są teksty na plakaty:
    $daty_kursowania = $_POST['daty_kursowania'] ?? '';
    $dni_kursowania = $_POST['dni_kursowania'] ?? '';
    $symbole = json_encode($_POST['symbole'] ?? [], JSON_UNESCAPED_UNICODE);

    // Parametry instancji
    $data_od = $_POST['data_od'] ?? date('Y-m-d');
    $data_do = $_POST['data_do'] ?? date('Y-m-d');
    $dni_tygodnia = $_POST['dni_tygodnia'] ?? []; // Tablica 1-7
    $zapis = $_POST['zapis'] ?? [];

    if (!$id_trasy || empty($zapis)) {
        die("Błąd: Brak danych trasy do zapisu.");
    }

    $start_time = strtotime($data_od);
    $end_time = strtotime($data_do);
    $wygenerowano = 0;

    // PĘTLA: Idziemy dzień po dniu od daty startu do daty końca
    for ($t = $start_time; $t <= $end_time; $t += 86400) {
        $dzien_tygodnia = date('N', $t); // 1 = Poniedziałek, 7 = Niedziela
        
        // Generujemy pociąg TYLKO jeśli ten dzień tygodnia był zaznaczony w panelu
        if (in_array($dzien_tygodnia, $dni_tygodnia)) {
            $data_kursowania_db = date('Y-m-d', $t);

            // 1. Zapisujemy główny przejazd dla konkretnej daty
            $stmt = mysqli_prepare($conn, "INSERT INTO przejazdy (id_trasy, numer_pociagu, nazwa_pociagu, id_typu_pociagu, daty_kursowania, dni_kursowania, symbole, data_kursowania) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "ississss", $id_trasy, $nr_poc, $nazwa_pociagu, $id_typu_pociagu, $daty_kursowania, $dni_kursowania, $symbole, $data_kursowania_db);
            mysqli_stmt_execute($stmt);
            
            // Pobieramy ID właśnie wygenerowanego pociągu
            $new_id_przejazdu = mysqli_insert_id($conn);

            // 2. Wrzucamy do niego wszystkie stacje po kolei
            foreach ($zapis as $wiersz) {
                $id_stacji = $wiersz['id_stacji'];
                $kolejnosc = $wiersz['kolejnosc'];
                $przyjazd = !empty($wiersz['przyjazd']) ? $wiersz['przyjazd'] : null;
                $odjazd = !empty($wiersz['odjazd']) ? $wiersz['odjazd'] : null;
                $uwagi = $wiersz['uwagi_postoju'];
                $peron = !empty($wiersz['peron']) ? $wiersz['peron'] : null;
                $tor = !empty($wiersz['tor']) ? $wiersz['tor'] : null;

                // Od razu wpisujemy czas planowy jako rzeczywisty - dzięki temu panel dyżurnego jest czysty
                $stmt_s = mysqli_prepare($conn, "INSERT INTO szczegoly_rozkladu (id_przejazdu, id_stacji, kolejnosc, przyjazd, odjazd, przyjazd_rzecz, odjazd_rzecz, uwagi_postoju, peron, tor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt_s, "iiisssssss", $new_id_przejazdu, $id_stacji, $kolejnosc, $przyjazd, $odjazd, $przyjazd, $odjazd, $uwagi, $peron, $tor);
                mysqli_stmt_execute($stmt_s);
            }
            $wygenerowano++;
        }
    }

    // Czyścimy pamięć po skończonej pracy
    unset($_SESSION['postoje'], $_SESSION['czas_odjazdu'], $_SESSION['nr_poc'], $_SESSION['nazwa_pociagu'], $_SESSION['symbole'], $_SESSION['daty_kursowania'], $_SESSION['dni_kursowania']);

    header("Location: generator_rozkladu.php?status=success&msg=Sukces! Utworzono $wygenerowano niezależnych pociągów we wskazanych datach.");
    exit;
}
?>