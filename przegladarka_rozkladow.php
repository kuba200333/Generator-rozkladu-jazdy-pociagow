<?php
require 'db_config.php';
$id_stacji_wybranej = isset($_GET['id_stacji']) ? (int)$_GET['id_stacji'] : null;

// Rozszerzona definicja symboli do legendy
$legend_symbols = [
    'klasa_1' => '1 klasa',
    'klasa_2' => '2 klasa',
    'rezerwacja' => 'Rezerwacja obowiązkowa',
    'rower' => 'wagon przystosowany do przewozu rowerów',
    'kuszetka' => 'kuszetka',
    'sypialny' => 'wagon sypialny',
    'bar' => 'wagon barowy / mini-bar',
    'restauracyjny' => 'wagon restauracyjny',
    'wozek_rampa' => 'wagon z miejscami dla osób na wózkach - z windą/rampa',
    'wozek_bez_rampy' => 'wagon z miejscami dla osób na wózkach - bez windy/rampy',
    'klima' => 'klimatyzacja',
    'wifi' => 'dostęp do WiFi',
    'przewijak' => 'dostępne miejsce do przewijania dziecka',
    'duzy_bagaz' => 'miejsce na duży bagaż'
];

function render_symbols($symbols_str, $map) {
    if (empty($symbols_str)) {
        return '';
    }
    
    $symbols_map_chars = [
        'klasa_1' => '1', 'klasa_2' => '2', 'rezerwacja' => '®', 'rower' => '🚲',
        'kuszetka' => '⌶', 'sypialny' => '🛏️', 'bar' => '🍸', 'restauracyjny' => '🍴',
        'wozek_rampa' => '♿', 'wozek_bez_rampy' => '♿', 'klima' => '❄️', 'wifi' => '📶',
        'przewijak' => '🚼', 'duzy_bagaz' => '🧳'
    ];
    
    $html = "<div class='symbols'>";
    $symbols_arr = explode(',', $symbols_str);
    foreach ($symbols_arr as $symbol_key) {
        if (isset($symbols_map_chars[$symbol_key])) {
            $html .= "<span title='" . htmlspecialchars($map[$symbol_key]) . "'>{$symbols_map_chars[$symbol_key]}</span> ";
        }
    }
    $html .= "</div>";
    return $html;
}

function format_days($days_str) {
    if (empty($days_str)) return '';
    // This function can be expanded based on actual data format for days
    return htmlspecialchars($days_str);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Plakatowy Rozkład Jazdy</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Arial+Narrow:wght@400;700&family=Arial:wght@400;700&display=swap');
        
        body { 
            font-family: 'Arial Narrow', Arial, sans-serif; 
            background-color: #f0f0f0; 
            margin: 0; 
            padding: 0;
        }
        .container { 
            max-width: 1800px; 
            margin: 20px auto; 
        }
        .poster { 
            background-color: #FFFF00; /* Main yellow background */
            color: black; 
            padding: 15px; 
            border: 3px solid black; 
        }
        .poster-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            border-bottom: 3px solid black; 
            padding-bottom: 10px; 
            margin-bottom: 10px;
        }
        .poster-header .title-block { 
            flex-grow: 1; 
        }
        .poster-header .title-block .title { 
            font-size: 2.5em; 
            font-weight: 700; 
            line-height: 1;
            letter-spacing: -1px;
        }
        .poster-header .title-block .station-name { 
            font-size: 6.5em; 
            font-weight: 700; 
            line-height: 1.1; 
            margin-top: 5px;
            text-transform: uppercase;
        }
        .poster-header .qr-code { 
            margin: 0 20px; 
        }
        .poster-header .qr-code img { 
            width: 110px; 
            height: 110px; 
            border: 2px solid black; 
        }
        .poster-header .details { 
            text-align: right; 
            font-size: 1.2em; 
            font-weight: bold;
        }
        .poster-header .details .company {
            font-size: 1.3em;
        }
        .poster-header .details .validity { 
            color: black; 
            font-size: 1.6em; 
            font-weight: 700; 
            border: 4px solid black; 
            padding: 5px 10px; 
            display: inline-block; 
            margin: 10px 0;
            background-color: white;
        }
        .poster-header .details .update-date {
            font-size: 1.1em;
        }
        .main-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 5px; 
        }
        .main-table th { 
            border-bottom: 3px solid black; 
            text-align: left; 
            padding: 8px 5px; 
            font-size: 1.2em;
            font-weight: bold;
            vertical-align: bottom; 
        }
        .main-table td {
            border-bottom: 1px solid #A0A0A0;
            padding: 8px 5px;
            vertical-align: top;
        }
        .main-table tbody tr:last-child td {
             border-bottom: none;
        }

        .col-time { 
            width: 7%; 
            font-size: 2.8em; 
            font-weight: 700; 
            text-align: center; 
        }
        .col-platform { 
            width: 5%; 
            font-size: 1.4em;
            text-align: center; 
            line-height: 1.2;
        }
        .col-platform .platform-label, .col-platform .track-label {
            font-size: 0.9em;
            font-weight: bold;
        }
        .col-platform .platform-num {
            font-size: 2.2em;
            font-weight: 700;
        }
        .col-platform .track-num {
            font-size: 1.8em;
            font-weight: normal;
        }
        .col-train { 
            width: 20%;
            font-size: 1.3em;
            line-height: 1.4;
        }
        .col-train .train-cat-num { 
            font-weight: 700; 
        }
        .col-train .train-name { 
            font-weight: 700; 
        }
        .col-train .symbols { 
            font-size: 1.5em; 
            margin-top: 4px; 
            word-spacing: 3px; 
        }
        .col-train .remarks-details {
            font-size: 0.9em;
            font-weight: bold;
        }
        .col-destination { 
            width: 53%; 
            font-size: 1.25em; 
            line-height: 1.5; 
            word-spacing: 0.1em;
        }
        .col-destination-final { 
            width: 15%; 
            font-size: 1.6em; 
            font-weight: 700; 
            text-align: left; 
            vertical-align: top;
        }
        .col-destination-final .arrival-time {
            font-size: 1.3em;
            margin-top: 5px;
        }
        
        .form-container { 
            background-color: #e9ecef; 
            padding: 15px; 
            border: 1px solid #ccc; 
            margin-bottom: 20px; 
            text-align: center; 
        }
        a { 
            color: #007bff; 
            text-decoration: none; 
        }
        .train-red td, .train-red td div { 
            color: red !important; 
        }
    </style>
</head>
<body>
<div class="container">
    <a href="index.php">Powrót do menu</a><br><br>
    <div class="form-container">
        <form method="GET" action="">
            <label for="id_stacji"><strong>Wybierz stację, dla której chcesz wygenerować plakat:</strong></label>
            <select name="id_stacji" id="id_stacji" onchange="this.form.submit()">
                <option value="">-- Wybierz stację --</option>
                <?php
                if (isset($conn)) {
                    $res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
                    while ($row = mysqli_fetch_assoc($res)) {
                        $selected = ($id_stacji_wybranej == $row['id_stacji']) ? "selected" : "";
                        echo "<option value='{$row['id_stacji']}' {$selected}>{$row['nazwa_stacji']}</option>";
                    }
                }
                ?>
            </select>
        </form>
    </div>

    <?php if ($id_stacji_wybranej && isset($conn)): ?>
        <?php
        $stacja_info_res = mysqli_query($conn, "SELECT nazwa_stacji FROM stacje WHERE id_stacji = $id_stacji_wybranej");
        $stacja_info = mysqli_fetch_assoc($stacja_info_res);
        $daty_kursowania_plakatu = "15 VI - 30 VIII 2025";
        $current_date_for_update = date('d.m.Y'); // Format date as seen in PDF
        ?>
        <div class="poster">
            <div class="poster-header">
                <div class="title-block">
                    <div class="title">Odjazdy / Departures / Відправлення</div>
                    <div class="station-name"><?= htmlspecialchars($stacja_info['nazwa_stacji']) ?></div>
                </div>
                <div class="qr-code">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://portalpasazera.pl" alt="QR Code">
                </div>
                <div class="details">
                    <div class="company">PKP POLSKIE LINIE KOLEJOWE S.A.</div>
                    <div class="validity">Ważny: <?= $daty_kursowania_plakatu ?></div>
                    <div class="update-date">Aktualizacja wg stanu na <?= $current_date_for_update ?></div>
                </div>
            </div>
            
            <table class="main-table">
                <thead>
                    <tr>
                        <th style="text-align:center;">godzina<br>odjazdu</th>
                        <th style="text-align:center;">peron<br>tor</th>
                        <th>pociąg</th>
                        <th>godziny przyjazdów do stacji pośrednich</th>
                        <th>godzina przyjazdu do<br>stacji docelowej</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql_odjazdy = "SELECT sr.*, p.*, t.id_stacji_koncowej, st_konc.nazwa_stacji AS stacja_koncowa,
                                    tp.skrot as typ_skrot, tp.pelna_nazwa as typ_nazwa,
                                    przew.skrot as przewoznik_skrot, kolor_czcionki
                                    FROM szczegoly_rozkladu sr 
                                    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu 
                                    JOIN trasy t ON p.id_trasy = t.id_trasy 
                                    JOIN stacje st_konc ON t.id_stacji_koncowej = st_konc.id_stacji
                                    LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                                    LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                                    WHERE sr.id_stacji = ? AND sr.odjazd IS NOT NULL AND (sr.uwagi_postoju = 'ph' OR sr.przyjazd IS NULL)
                                    ORDER BY sr.odjazd ASC";
                    
                    $stmt_odjazdy = mysqli_prepare($conn, $sql_odjazdy);
                    mysqli_stmt_bind_param($stmt_odjazdy, "i", $id_stacji_wybranej);
                    mysqli_stmt_execute($stmt_odjazdy);
                    $result_odjazdy = mysqli_stmt_get_result($stmt_odjazdy);

                    if (mysqli_num_rows($result_odjazdy) > 0) {
                        while ($row = mysqli_fetch_assoc($result_odjazdy)) {
                            $font_class = strtolower($row['kolor_czcionki']) == 'red' ? 'train-red' : '';
                            
                            echo "<tr class='train-entry-main {$font_class}'>";
                            echo "<td class='col-time'>" . date("H:i", strtotime($row['odjazd'])) . "</td>";
                            // MODIFICATION: Show '-' if platform or track is empty
                            echo "<td class='col-platform'>
                                    <div class='platform-label'></div>
                                    <div class='platform-num'>" . (!empty($row['peron']) ? htmlspecialchars($row['peron']) : '') . "</div>
                                    <div class='track-label'></div>
                                    <div class='track-num'>" . (!empty($row['tor']) ? htmlspecialchars($row['tor']) : '') . "</div>
                                  </td>";
                            echo "<td class='col-train'>
                                    <div class='train-cat-num'>{$row['przewoznik_skrot']}-{$row['typ_skrot']} {$row['numer_pociagu']}</div>
                                    <div class='train-name'>{$row['nazwa_pociagu']}</div>"
                                    . render_symbols($row['symbole'], $legend_symbols) .
                                    "<div class='remarks-details'>
                                        {$row['daty_kursowania']} " . format_days($row['dni_kursowania']) . "
                                    </div>
                                  </td>";
                            
                            $id_przejazdu = $row['id_przejazdu'];

                            // Get intermediate stops
                            $current_kolejnosc = $row['kolejnosc']; // Pobieramy 'kolejnosc' z aktualnego wiersza odjazdu
                            $sql_przystanki = "SELECT s.nazwa_stacji, sr.przyjazd, sr.uwagi_postoju 
                                            FROM szczegoly_rozkladu sr 
                                            JOIN stacje s ON sr.id_stacji = s.id_stacji 
                                            WHERE sr.id_przejazdu = ? 
                                                AND sr.kolejnosc > ? 
                                                AND sr.id_stacji != ? 
                                            ORDER BY sr.kolejnosc ASC";
                            $stmt_przystanki = mysqli_prepare($conn, $sql_przystanki);
                            // Zmieniamy parametry, aby pasowały do nowego zapytania
                            mysqli_stmt_bind_param($stmt_przystanki, "iii", $id_przejazdu, $current_kolejnosc, $row['id_stacji_koncowej']);
                            mysqli_stmt_execute($stmt_przystanki);
                            $result_przystanki = mysqli_stmt_get_result($stmt_przystanki);
                            
                            $stacje_posrednie_str = "";
                            while ($przystanek = mysqli_fetch_assoc($result_przystanki)) {
                                if ($przystanek['uwagi_postoju'] == 'ph') {
                                     $stacje_posrednie_str .= htmlspecialchars($przystanek['nazwa_stacji']) . " " . date("H:i", strtotime($przystanek['przyjazd'])) . ", ";
                                }
                            }
                            $stacje_posrednie_str = rtrim($stacje_posrednie_str, ", ");
                            echo "<td class='col-destination'>{$stacje_posrednie_str}</td>";

                            // MODIFICATION: Direct query for final arrival time for reliability
                            $czas_koncowy = "";
                            $sql_czas_koncowy = "SELECT przyjazd FROM szczegoly_rozkladu WHERE id_przejazdu = ? AND id_stacji = ?";
                            $stmt_czas_koncowy = mysqli_prepare($conn, $sql_czas_koncowy);
                            mysqli_stmt_bind_param($stmt_czas_koncowy, "ii", $id_przejazdu, $row['id_stacji_koncowej']);
                            mysqli_stmt_execute($stmt_czas_koncowy);
                            $result_czas_koncowy = mysqli_stmt_get_result($stmt_czas_koncowy);
                            if ($czas_koncowy_data = mysqli_fetch_assoc($result_czas_koncowy)) {
                                if ($czas_koncowy_data['przyjazd']) {
                                    $czas_koncowy = date("H:i", strtotime($czas_koncowy_data['przyjazd']));
                                }
                            }
                            
                            echo "<td class='col-destination-final'>
                                    <div>" . htmlspecialchars($row['stacja_koncowa']) . "</div>
                                    <div class='arrival-time'>" . $czas_koncowy . "</div>
                                  </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' style='text-align:center;'>Brak zaplanowanych odjazdów handlowych dla tej stacji.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>