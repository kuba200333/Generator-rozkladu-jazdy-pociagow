<?php
require 'db_config.php';
$id_przejazdu_wybranego = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Służbowy Rozkład Jazdy</title>
    <style>
        /* ZMIANA: Usunięto marginesy i tło z body */
        body {
            font-family: 'Arial Narrow', Arial, sans-serif;
            margin: 0;
            padding: 0;
        }
        /* ZMIANA: Ustawiono stałą szerokość, usunięto centrowanie i zbędne style */
        .container {
            width: 300px; /* <-- Możesz zmienić tę wartość, jeśli chcesz mieć węższe/szersze okno */
            margin: 0;
            padding: 10px;
            border: none;
            box-shadow: none;
            border-radius: 0;
            background: white;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
            table-layout: fixed; /* Zapobiega rozpychaniu tabeli przez długą treść */
        }
        td, th {
            border: 1px solid black;
            padding: 5px;
            text-align: center;
            vertical-align: middle;
            word-wrap: break-word; /* Umożliwia łamanie długich słów */
        }
        th { background-color: #e9e9e9; }
        .station-cell { text-align: left; }
        .station-cell b { font-size: 1.1em; }
        .time-cell { font-weight: bold; white-space: pre; line-height: 1.2; font-size: 1.1em; }
        .form-container, .info-bar { padding: 10px; text-align: center; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 5px; }
        h1, h2, h3 { text-align: center; color: #333; margin: 5px 0 15px 0;}
        a { color: #007bff; }
        select { width: 100%; box-sizing: border-box; padding: 5px; }
    </style>
</head>
<body>
<div class="container">
    <a href="index.php">Powrót do menu</a>
    <h1>Służbowy Rozkład Jazdy</h1>
    
    <div class="form-container">
        <form method="GET" action="">
            <label for="id_przejazdu"><strong>Wybierz zapisany rozkład:</strong></label>
            <select name="id_przejazdu" id="id_przejazdu" onchange="this.form.submit()">
                <option value="">-- Wybierz pociąg --</option>
                <?php
                $sql_przejazdy = "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, p.data_utworzenia, t.nazwa_trasy, tp.skrot as typ_skrot
                                  FROM przejazdy p
                                  JOIN trasy t ON p.id_trasy = t.id_trasy
                                  LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                                  ORDER BY p.data_utworzenia DESC";
                $res = mysqli_query($conn, $sql_przejazdy);
                while ($row = mysqli_fetch_assoc($res)) {
                    $opis = "Pociąg {$row['typ_skrot']} {$row['numer_pociagu']} ({$row['nazwa_pociagu']}) | {$row['nazwa_trasy']}";
                    $selected = ($id_przejazdu_wybranego == $row['id_przejazdu']) ? "selected" : "";
                    echo "<option value='{$row['id_przejazdu']}' {$selected}>{$opis}</option>";
                }
                ?>
            </select>
        </form>
    </div>

    <?php if ($id_przejazdu_wybranego): ?>
        <?php
        // Pobierz główne informacje o przejeździe
        $sql_info = "SELECT p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot
                     FROM przejazdy p
                     JOIN trasy t ON p.id_trasy = t.id_trasy
                     LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                     WHERE p.id_przejazdu = ?";
        $stmt_info = mysqli_prepare($conn, $sql_info);
        mysqli_stmt_bind_param($stmt_info, "i", $id_przejazdu_wybranego);
        mysqli_stmt_execute($stmt_info);
        $przejazd_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));

        // Pobierz szczegóły trasy
        $sql_szczegoly = "SELECT sr.przyjazd, sr.odjazd, sr.uwagi_postoju, s.id_stacji, s.nazwa_stacji, ts.skrot_typu_stacji, s.uwagi
                          FROM szczegoly_rozkladu sr
                          JOIN stacje s ON sr.id_stacji = s.id_stacji
                          JOIN typy_stacji ts ON s.typ_stacji_id = ts.id_typu_stacji
                          WHERE sr.id_przejazdu = ?
                          ORDER BY sr.kolejnosc ASC";
        $stmt_szczegoly = mysqli_prepare($conn, $sql_szczegoly);
        mysqli_stmt_bind_param($stmt_szczegoly, "i", $id_przejazdu_wybranego);
        mysqli_stmt_execute($stmt_szczegoly);
        $result_szczegoly = mysqli_stmt_get_result($stmt_szczegoly);
        $stacje_list = mysqli_fetch_all($result_szczegoly, MYSQLI_ASSOC);
        ?>

        <div class="info-bar">
            <h2><?= "{$przejazd_info['typ_skrot']} {$przejazd_info['numer_pociagu']} {$przejazd_info['nazwa_pociagu']}" ?></h2>
            <h3><?= $przejazd_info['nazwa_trasy'] ?></h3>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 55%;">Stacja</th>
                    <th style="width: 20%;">Vmax</th>
                    <th style="width: 25%;">Godzina</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stacje_list as $index => $stacja): ?>
                    <?php
                    $vmax = '-';
                    if ($index < count($stacje_list) - 1) {
                        $id_stacji_biezacej = $stacja['id_stacji'];
                        $id_stacji_nastepnej = $stacje_list[$index + 1]['id_stacji'];

                        $sql_vmax = "SELECT predkosc_max FROM odcinki 
                                     WHERE (id_stacji_A = ? AND id_stacji_B = ?) OR (id_stacji_A = ? AND id_stacji_B = ?)";
                        $stmt_vmax = mysqli_prepare($conn, $sql_vmax);
                        mysqli_stmt_bind_param($stmt_vmax, "iiii", $id_stacji_biezacej, $id_stacji_nastepnej, $id_stacji_nastepnej, $id_stacji_biezacej);
                        mysqli_stmt_execute($stmt_vmax);
                        $res_vmax = mysqli_stmt_get_result($stmt_vmax);
                        if ($vmax_row = mysqli_fetch_assoc($res_vmax)) {
                            $vmax = $vmax_row['predkosc_max'];
                        }
                    }
                    
                    $godzina_formatted = '';
                    if ($stacja['przyjazd'] && $stacja['odjazd'] && $stacja['przyjazd'] == $stacja['odjazd']) {
                        $godzina_formatted = "|\n" . date("H:i", strtotime($stacja['przyjazd']));
                    } elseif ($stacja['przyjazd'] && $stacja['odjazd']) {
                        $godzina_formatted = date("H:i", strtotime($stacja['przyjazd'])) . "\n" . date("H:i", strtotime($stacja['odjazd']));
                    } elseif ($stacja['odjazd']) {
                        $godzina_formatted = "|\n" . date("H:i", strtotime($stacja['odjazd']));
                    } elseif ($stacja['przyjazd']) {
                        $godzina_formatted = date("H:i", strtotime($stacja['przyjazd'])) . "\n|";
                    }
                    ?>
                    <tr>
                        <td class="station-cell">
                            <b><?= "{$stacja['nazwa_stacji']} {$stacja['skrot_typu_stacji']}" ?></b> 
                            <?php if(!empty($stacja['uwagi_postoju'])) echo "<b>{$stacja['uwagi_postoju']}</b>"; ?>
                            <br>
                            <?= $stacja['uwagi'] ?>
                        </td>
                        <td><?= $vmax ?></td>
                        <td class="time-cell"><?= $godzina_formatted ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="warnings-section" style="margin-top: 30px;">
            <h2>Wykaz ostrzeżeń</h2>
            <table style="font-size: 12px;">
                <thead>
                    <tr>
                        <th>Lp.</th>
                        <th>Miejsce</th>
                        <th>km</th>
                        <th>Treść ostrzeżenia</th>
                        <th>Obowiązuje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $nazwy_stacji_na_trasie = array_column($stacje_list, 'nazwa_stacji');
                    $today = date('Y-m-d');
                    $sql_wszystkie_ostrzezenia = "SELECT * FROM ostrzezenia WHERE data_waznosci_od <= ? AND (do_odwolania = 1 OR data_waznosci_do >= ?) ORDER BY id_ostrzezenia";
                    $stmt_wszystkie_ostrzezenia = mysqli_prepare($conn, $sql_wszystkie_ostrzezenia);
                    mysqli_stmt_bind_param($stmt_wszystkie_ostrzezenia, "ss", $today, $today);
                    mysqli_stmt_execute($stmt_wszystkie_ostrzezenia);
                    $result_wszystkie_ostrzezenia = mysqli_stmt_get_result($stmt_wszystkie_ostrzezenia);
                    
                    $istotne_ostrzezenia = [];
                    while($ostrz = mysqli_fetch_assoc($result_wszystkie_ostrzezenia)) {
                        $is_relevant = false;
                        foreach ($nazwy_stacji_na_trasie as $nazwa_stacji) {
                            if (mb_stripos($ostrz['miejsce_opis'], $nazwa_stacji) !== false) {
                                $is_relevant = true;
                                break;
                            }
                        }
                        if ($is_relevant) {
                            $istotne_ostrzezenia[] = $ostrz;
                        }
                    }

                    if (count($istotne_ostrzezenia) > 0):
                        $lp = 1;
                        foreach($istotne_ostrzezenia as $ostrz):
                    ?>
                    <tr>
                        <td style="width:5%"><?= $lp++ ?></td>
                        <td style="text-align:left; width:25%;"><?= htmlspecialchars($ostrz['miejsce_opis']) . " tor " . htmlspecialchars($ostrz['nr_toru']) ?></td>
                        <td style="width:10%"><?= htmlspecialchars($ostrz['km_poczatek']) ?></td>
                        <td style="text-align:left;"><?= "Vmax " . $ostrz['predkosc_max'] . " km/h. Powód: " . htmlspecialchars($ostrz['powod']) ?></td>
                        <td style="width:20%"><?= $ostrz['do_odwolania'] ? 'do odwołania' : 'do ' . $ostrz['data_waznosci_do'] ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    else:
                        echo "<tr><td colspan='5'>Brak aktywnych ostrzeżeń dla tej trasy.</td></tr>";
                    endif; 
                    ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>
</div>
</body>
</html>