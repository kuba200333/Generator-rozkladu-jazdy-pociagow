<?php
session_start();
require 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_trasy = (int)$_POST['id_trasy'];
    $nazwa_trasy = $_POST['nazwa_trasy'];
    $stacje_ids = $_POST['stacje'] ?? [];

    if (empty($nazwa_trasy) || count($stacje_ids) < 2) {
        die("Błąd: Nazwa trasy jest wymagana, a trasa musi zawierać co najmniej 2 stacje.");
    }
    
    $id_pocz = $stacje_ids[0];
    $id_konc = end($stacje_ids);

    mysqli_begin_transaction($conn);

    try {
        if ($id_trasy > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE trasy SET nazwa_trasy = ?, id_stacji_poczatkowej = ?, id_stacji_koncowej = ? WHERE id_trasy = ?");
            mysqli_stmt_bind_param($stmt, "siii", $nazwa_trasy, $id_pocz, $id_konc, $id_trasy);
            mysqli_stmt_execute($stmt);
            
            $stmt_del = mysqli_prepare($conn, "DELETE FROM stacje_na_trasie WHERE id_trasy = ?");
            mysqli_stmt_bind_param($stmt_del, "i", $id_trasy);
            mysqli_stmt_execute($stmt_del);
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO trasy (nazwa_trasy, id_stacji_poczatkowej, id_stacji_koncowej) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sii", $nazwa_trasy, $id_pocz, $id_konc);
            mysqli_stmt_execute($stmt);
            $id_trasy = mysqli_insert_id($conn);
        }

        $stmt_ins = mysqli_prepare($conn, "INSERT INTO stacje_na_trasie (id_trasy, id_stacji, kolejnosc) VALUES (?, ?, ?)");
        foreach ($stacje_ids as $index => $id_stacji) {
            $kolejnosc = $index + 1;
            mysqli_stmt_bind_param($stmt_ins, "iii", $id_trasy, $id_stacji, $kolejnosc);
            mysqli_stmt_execute($stmt_ins);
        }
        
        $nowe_odcinki_komunikaty = [];
        $stacje_map = [];
        $stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje");
        while($s = mysqli_fetch_assoc($stacje_res)) {
            $stacje_map[$s['id_stacji']] = $s['nazwa_stacji'];
        }

        for ($i = 0; $i < count($stacje_ids) - 1; $i++) {
            $stacja_A_id = $stacje_ids[$i];
            $stacja_B_id = $stacje_ids[$i + 1];

            $check_sql = "SELECT id_odcinka FROM odcinki WHERE (id_stacji_A = ? AND id_stacji_B = ?) OR (id_stacji_A = ? AND id_stacji_B = ?)";
            $stmt_check = mysqli_prepare($conn, $check_sql);
            mysqli_stmt_bind_param($stmt_check, "iiii", $stacja_A_id, $stacja_B_id, $stacja_B_id, $stacja_A_id);
            mysqli_stmt_execute($stmt_check);
            $result_check = mysqli_stmt_get_result($stmt_check);

            if (mysqli_num_rows($result_check) == 0) {
                $insert_sql = "INSERT IGNORE INTO odcinki (id_stacji_A, id_stacji_B, czas_przejazdu, predkosc_max) VALUES (?, ?, '00:00:00', '0')";
                
                $stmt_add = mysqli_prepare($conn, $insert_sql);
                mysqli_stmt_bind_param($stmt_add, "ii", $stacja_A_id, $stacja_B_id);
                mysqli_stmt_execute($stmt_add);
                
                mysqli_stmt_bind_param($stmt_add, "ii", $stacja_B_id, $stacja_A_id);
                mysqli_stmt_execute($stmt_add);

                $nazwa_A = $stacje_map[$stacja_A_id] ?? 'Nieznana';
                $nazwa_B = $stacje_map[$stacja_B_id] ?? 'Nieznana';
                $nowe_odcinki_komunikaty[] = "Stworzono nowy odcinek: <b>" . htmlspecialchars($nazwa_A) . " – " . htmlspecialchars($nazwa_B) . "</b>. Uzupełnij czas przejazdu w module 'Zarządzaj Odcinkami'.";
            }
        }
        
        mysqli_commit($conn);
        $message = "Trasa została pomyślnie zapisana.";
        $_SESSION['nowe_odcinki_info'] = $nowe_odcinki_komunikaty;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $message = "Błąd podczas zapisu: " . $e->getMessage();
    }
    
    header("Location: kreator_tras.php?msg=" . urlencode($message));
    exit();
}
?>