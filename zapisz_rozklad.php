<?php
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['zapis'])) {
    $id_trasy = $_POST['id_trasy'];
    $nr_poc = $_POST['nr_poc'];
    $id_typu_pociagu = $_POST['id_typu_pociagu']; // Odczytujemy ID rodzaju pociągu
    $nazwa_pociagu = $_POST['nazwa_pociagu'];
    $daty_kursowania = $_POST['daty_kursowania'];
    $dni_kursowania = $_POST['dni_kursowania'];
    
    // Konwertujemy tablicę symboli na tekst do zapisu w bazie
    $symbole = isset($_POST['symbole']) ? implode(',', $_POST['symbole']) : null;
    
    $dane_do_zapisu = $_POST['zapis'];

    // Weryfikacja, czy kluczowe dane nie są puste
    if (empty($id_trasy) || empty($nr_poc) || empty($id_typu_pociagu)) {
        $error_msg = "Błąd zapisu: Trasa, rodzaj pociągu i numer pociągu są wymagane. Uzupełnij dane i spróbuj ponownie.";
        header("Location: generator_rozkladu.php?status=error&msg=" . urlencode($error_msg));
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        // 1. Stworzenie wpisu w tabeli `przejazdy` z nowymi danymi
        $stmt1 = mysqli_prepare($conn, "INSERT INTO przejazdy (id_trasy, numer_pociagu, id_typu_pociagu, nazwa_pociagu, daty_kursowania, dni_kursowania, symbole) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        // Bindowanie parametrów (7 zmiennych, typy "iisssss")
        mysqli_stmt_bind_param($stmt1, "issssss", $id_trasy, $nr_poc, $id_typu_pociagu, $nazwa_pociagu, $daty_kursowania, $dni_kursowania, $symbole);
        
        mysqli_stmt_execute($stmt1);
        
        if (mysqli_stmt_errno($stmt1)) {
            throw new Exception("Błąd SQL przy tworzeniu przejazdu: " . mysqli_stmt_error($stmt1));
        }

        $id_przejazdu = mysqli_insert_id($conn);
        if ($id_przejazdu == 0) {
            throw new Exception("Nie udało się utworzyć nowego przejazdu w bazie danych (brak zwróconego ID).");
        }

        // 2. Wstawienie wszystkich szczegółów do `szczegoly_rozkladu` z nowymi danymi
        // ZMIANA: Dodano kolumny przyjazd_rzecz i odjazd_rzecz do zapytania
        $stmt2 = mysqli_prepare($conn, "INSERT INTO szczegoly_rozkladu (id_przejazdu, id_stacji, kolejnosc, przyjazd, odjazd, przyjazd_rzecz, odjazd_rzecz, uwagi_postoju, peron, tor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($dane_do_zapisu as $wpis) {
            $przyjazd = empty($wpis['przyjazd']) ? null : $wpis['przyjazd'];
            $odjazd = empty($wpis['odjazd']) ? null : $wpis['odjazd'];
            $peron = empty($wpis['peron']) ? null : $wpis['peron'];
            $tor = empty($wpis['tor']) ? null : $wpis['tor'];
            
            // ZMIANA: Przepisujemy godziny planowe do rzeczywistych na start
            $przyjazd_rzecz = $przyjazd;
            $odjazd_rzecz = $odjazd;
            
            // ZMIANA: Zaktualizowano typy zmiennych (doszły 2 stringi) i listę zmiennych w bind_param
            mysqli_stmt_bind_param($stmt2, "iiisssssss", 
                $id_przejazdu, 
                $wpis['id_stacji'], 
                $wpis['kolejnosc'], 
                $przyjazd, 
                $odjazd, 
                $przyjazd_rzecz, // Wstawiamy to samo co w planie
                $odjazd_rzecz,   // Wstawiamy to samo co w planie
                $wpis['uwagi_postoju'],
                $peron,
                $tor
            );
            mysqli_stmt_execute($stmt2);

            if (mysqli_stmt_errno($stmt2)) {
                 throw new Exception("Błąd SQL przy zapisie szczegółów dla stacji ID {$wpis['id_stacji']}: " . mysqli_stmt_error($stmt2));
            }
        }

        mysqli_commit($conn);
        $status = "success";
        $message = "Rozkład dla pociągu nr {$nr_poc} został pomyślnie zapisany!";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $status = "error";
        $message = "Wystąpił błąd podczas zapisu: " . $e->getMessage();
    }

    header("Location: generator_rozkladu.php?status={$status}&msg=" . urlencode($message));
    exit();

} else {
    header("Location: generator_rozkladu.php");
    exit();
}
?>