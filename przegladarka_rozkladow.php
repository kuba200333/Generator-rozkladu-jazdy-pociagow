<?php
require 'db_config.php';

$id_stacji_wybranej = isset($_GET['id_stacji']) ? (int)$_GET['id_stacji'] : null;
$typ_plakatu = isset($_GET['typ_plakatu']) ? $_GET['typ_plakatu'] : 'odjazdy';

function format_days($days_str) {
    if (empty($days_str)) return '';
    return htmlspecialchars($days_str);
}

// Pobieranie przewoźników i typów pociągów do dynamicznej legendy
$przewoznicy_baza = [];
$typy_baza = [];
if (isset($conn)) {
    $res_przew = mysqli_query($conn, "SELECT skrot, pelna_nazwa FROM przewoznicy");
    while ($row_przew = mysqli_fetch_assoc($res_przew)) {
        $przewoznicy_baza[$row_przew['skrot']] = $row_przew['pelna_nazwa'];
    }
    
    $res_typy = mysqli_query($conn, "SELECT skrot, pelna_nazwa FROM typy_pociagow");
    while ($row_typy = mysqli_fetch_assoc($res_typy)) {
        $typy_baza[$row_typy['skrot']] = $row_typy['pelna_nazwa'];
    }
}

// Definicje symboli
$symbol_definitions = [
    'klasa_1' => ['1️⃣', '1 klasa / First class seats / 1 клас'],
    'klasa_2' => ['2️⃣', '2 klasa / Second class seats / 2 клас'],
    'rezerwacja' => ['R', 'rezerwacja obowiązkowa / seat reservation required / бронювання обов\'язкове'],
    'rower' => ['🚲', 'wagon przystosowany do przewozu rowerów / wagon adopted to the transport of bicycles / вагон призначений для перевезення велосипедів'],
    'kuszetka' => ['⌶', 'wagon z miejscami do leżenia / couchette car / купейний вагон'],
    'sypialny' => ['🛏️', 'wagon sypialny / sleeping car / спальний вагон'],
    'bar' => ['🍸', 'wagon barowy / mini-bar / вагон-бар'],
    'restauracyjny' => ['🍴', 'wagon restauracyjny / dining car / вагон-ресторан'],
    'wozek_rampa' => ['♿', 'wagon z miejscami dla osób na wózkach ze windą/rampą / car with seats for disabled passengers with a lift/ramp / вагон з місцями для інвалідних візків з ліфтом/пандусом'],
    'wozek_bez_rampy' => ['♿🚫', 'wagon z miejscami dla osób na wózkach bez windy/rampy / wagon with places for people moving on wheelchairs without the elevator/platform / вагон з місцями для пасажирів на інвалідних візках без ліфта/пандуса'],
    'klima' => ['❄️', 'klimatyzacja / air conditioning / кондиціонер'],
    'wifi' => ['📶', 'dostęp do WiFi / WiFi access / доступ до WiFi'],
    'przewijak' => ['🚼', 'dostępne miejsce do przewijania dziecka / baby changing space available / доступне місце для сповивання дитини'],
    'duzy_bagaz' => ['🧳', 'miejsce na duży bagaż / space for large luggage / місце для великого багажу'],
    'kalendarz' => ['📅', 'terminy kursowania / days of operation / періодичність руху'],
    'oprocze' => ['⍉', 'oprócz / except / окрім'],
    'oraz' => ['⊕', 'oraz / and also / i'],
    'dzien_1' => ['①', 'w poniedziałki / Mondays / по понеділках'],
    'dzien_2' => ['②', 'we wtorki / Tuesdays / по вівторках'],
    'dzien_3' => ['③', 'w środy / Wednesdays / по середах'],
    'dzien_4' => ['④', 'w czwartki / Thursdays / по четвергах'],
    'dzien_5' => ['⑤', 'w piątki / Fridays / по п\'ятницях'],
    'dzien_6' => ['⑥', 'w soboty / Saturdays / по суботах'],
    'dzien_7' => ['⑦', 'w niedziele / Sundays / по неділях']
];

function render_symbols($symbols_str, $definitions) {
    if (empty($symbols_str)) return '';
    $html = "<div class='symbols' style='margin-top: 4px;'>";
    $symbols_arr = explode(',', $symbols_str);
    foreach ($symbols_arr as $symbol_key) {
        $symbol_key = trim($symbol_key);
        if (isset($definitions[$symbol_key])) {
            $icon = $definitions[$symbol_key][0];
            $html .= "<span style='margin-right: 4px; font-size: 1.05em; color: #111;'>{$icon}</span>";
        }
    }
    $html .= "</div>";
    return $html;
}

if ($typ_plakatu === 'przyjazdy') {
    $title_main = 'Przyjazdy <span style="font-weight: normal; font-size: 0.6em; font-style: italic;">/ Arrivals / Прибуття</span>';
    $col_1_title = "godzina<br>przyjazdu<br><span style='font-weight:normal; font-size:0.7em; font-style: italic;'>arrival time</span>";
    $col_4_title = "godziny odjazdów ze stacji pośrednich<br><br><span style='font-weight:normal; font-size:0.7em; font-style: italic;'>departures from intermediate stops</span>";
} else {
    $title_main = 'Odjazdy <span style="font-weight: normal; font-size: 0.6em; font-style: italic;">/ Departures / Відправлення</span>';
    $col_1_title = "godzina<br>odjazdu<br><span style='font-weight:normal; font-size:0.7em; font-style: italic;'>departure time</span>";
    $col_4_title = "godziny przyjazdów do stacji pośrednich<br><br><span style='font-weight:normal; font-size:0.7em; font-style: italic;'>arrivals at intermediate stops</span>";
    $col_5_title = "godzina przyjazdu do<br>stacji docelowej<br><span style='font-weight:normal; font-size:0.7em; font-style: italic;'>arrival at destination (station)</span>";
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Rozkład Jazdy</title>
    <style>
        body { font-family: 'Arial', sans-serif; background-color: #e0e0e0; margin: 0; padding: 0; }
        
        body.is-odjazdy { background-color: #FFF200; color: black; }
        body.is-przyjazdy { background-color: #FFFFFF; color: black; }

        .container { max-width: 1400px; margin: 20px auto; background: inherit; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .poster { padding: 20px 30px; }

        .poster-header { display: flex; justify-content: space-between; align-items: stretch; border-bottom: 4px solid black; padding-bottom: 15px; margin-bottom: 5px; }
        .title-block { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }
        .title-block .top-row { display: flex; align-items: center; gap: 15px; }
        .title-block .icon { background: black; border-radius: 4px; padding: 5px; display: inline-flex; }
        .title-block .title { font-size: 2.5em; font-weight: 900; margin: 0; letter-spacing: -1px; }
        .title-block .station-name { font-size: 4.5em; font-weight: 900; line-height: 1; margin-top: 5px; }
        
        .header-right { display: flex; align-items: center; gap: 20px; text-align: right; }
        .qr-code img { width: 90px; height: 90px; border: 2px solid black; }
        .details { display: flex; flex-direction: column; justify-content: space-between; height: 100%; }
        .details .pkp-logo { font-size: 0.85em; font-weight: bold; color: #111; letter-spacing: 0.5px; }
        .details .validity { color: #d00; font-size: 2.8em; font-weight: 900; letter-spacing: -1px; margin: auto 0; line-height: 1; }
        .details .update { font-size: 0.8em; font-weight: bold; }

        /* Tabela Główna */
        .main-table { width: 100%; border-collapse: collapse; table-layout: fixed; border: 2px solid black; }
        
        .main-table th { 
            border: 1px solid black; 
            border-top: 2px solid black; 
            border-bottom: 2px solid black; 
            text-align: center; 
            padding: 5px 3px; 
            font-size: 0.85em; 
            font-weight: bold; 
            line-height: 1.1; 
            vertical-align: bottom; 
        }

        .main-table td { 
            border-top: 1px solid black;
            border-bottom: 1px solid black; 
            /* ZERO pionowych linii w danych */
            border-left: none !important; 
            border-right: none !important; 
            padding: 6px 5px; 
            vertical-align: top; 
        }

        body.is-przyjazdy .main-table tr:nth-child(even) td { background-color: #f6f6f6; }

        /* Zdecydowane wyszarzenie tylko dla godziny i peronu/toru (w tym nagłówków) */
        .main-table th.th-time,
        .main-table td.td-time,
        .main-table th.th-platform,
        .main-table td.td-platform { 
            background-color: rgba(0, 0, 0, 0.09) !important; 
        }

        /* Szerokości kolumn w procentach */
        .th-time { width: 9%; }
        .th-platform { width: 6%; }
        .th-train { width: 14%; }
        .th-dest { width: 56%; }
        .th-final { width: 15%; }

        /* Komórki z danymi */
        .td-time { font-size: 2.3em; font-weight: 900; text-align: center; letter-spacing: -1px; }
        .td-platform { text-align: center; }
        .td-train { font-size: 0.85em; text-align: center; line-height: 1.2; }
        .td-destination { font-size: 0.9em; line-height: 1.35; padding: 6px 12px !important; text-align: left; }
        .td-final { font-size: 1.05em; text-align: right; font-weight: bold; padding-right: 10px !important; }

        .td-platform .platform-val { font-size: 1.5em; font-weight: 900; line-height: 1; }
        .td-platform .track-val { font-size: 1.1em; line-height: 1; margin-top: 4px; }
        .td-train .train-cat { font-weight: bold; font-size: 1.15em; }
        .td-train .train-name { font-style: italic; font-weight: bold; margin-top: 2px; }
        .td-final .final-time { font-size: 1.4em; margin-top: 4px; }

        .dates-row { font-size: 0.9em; margin-top: 8px; display: flex; align-items: flex-start; gap: 5px; }

        .legend-container { margin-top: 20px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; font-size: 0.85em; border-top: 3px solid black; padding-top: 15px; }
        .legend-column h4 { background-color: black; color: white; padding: 4px 5px; font-size: 1.1em; margin: 0 0 10px 0; text-align: center; }
        .legend-item { display: flex; align-items: flex-start; margin-bottom: 6px; line-height: 1.2; word-wrap: break-word; }
        .legend-item .symbol { font-weight: bold; width: 30px; flex-shrink: 0; text-align: center; margin-right: 5px; }

        .ui-bar { background: #343a40; color: white; padding: 15px; display: flex; justify-content: center; gap: 20px; align-items: center; }
        .ui-bar select, .ui-bar button { padding: 8px 12px; font-size: 1em; cursor: pointer; border-radius: 4px; border: 1px solid #ccc; }
        .ui-bar button { background: #28a745; color: white; border: none; font-weight: bold; }
        .ui-bar a { color: #17a2b8; text-decoration: none; font-weight: bold; }

        @media print {
            @page { size: A4 portrait; margin: 0; }
            html, body { width: 100%; min-height: 100%; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; font-size: 11px !important; }
            .ui-bar { display: none !important; }
            .container { box-shadow: none; width: 100%; max-width: 100%; margin: 0; padding: 0; }
            .poster { padding: 10mm 15mm !important; box-sizing: border-box; min-height: 100vh; border: none !important; }
            
            .td-time { font-size: 2.2em !important; }
            .td-platform .platform-val { font-size: 1.4em !important; }
            .td-destination { font-size: 0.85em !important; padding: 6px 8px !important; }
            
            .main-table { border: 2px solid black !important; }
            .main-table th { border: 1px solid black !important; border-top: 2px solid black !important; border-bottom: 2px solid black !important; font-size: 0.75em !important; padding: 3px 1px !important; word-wrap: break-word; }
            .main-table td { border-top: 1px solid black !important; border-bottom: 1px solid black !important; border-left: none !important; border-right: none !important; }
            
            .legend-column h4 { background-color: black !important; color: white !important; }
            .legend-container { break-before: page; page-break-before: always; margin-top: 0; padding-top: 15mm; border-top: none; }
        }
    </style>
</head>
<body class="<?= $typ_plakatu === 'odjazdy' ? 'is-odjazdy' : 'is-przyjazdy' ?>">

<div class="ui-bar">
    <a href="index.php">⬅ Powrót</a>
    <form method="GET" action="" id="control-form" style="margin: 0; display: flex; gap: 15px; align-items: center;">
        <label for="id_stacji">Stacja:</label>
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
        
        <label for="typ_plakatu">Rozkład:</label>
        <select name="typ_plakatu" id="typ_plakatu" onchange="this.form.submit()">
            <option value="odjazdy" <?= $typ_plakatu === 'odjazdy' ? 'selected' : '' ?>>Odjazdy</option>
            <option value="przyjazdy" <?= $typ_plakatu === 'przyjazdy' ? 'selected' : '' ?>>Przyjazdy</option>
        </select>
    </form>
    <button type="button" onclick="window.print()">🖨️ Drukuj Plakat</button>
</div>

<div class="container">
    <?php if ($id_stacji_wybranej && isset($conn)): ?>
        <?php
        $stacja_info_res = mysqli_query($conn, "SELECT nazwa_stacji FROM stacje WHERE id_stacji = $id_stacji_wybranej");
        $stacja_info = mysqli_fetch_assoc($stacja_info_res);
        $daty_kursowania_plakatu = "8 III – 13 VI 2026";
        $current_date_for_update = date('d.m.Y');

        $uzyci_przewoznicy = [];
        $uzyte_typy_pociagow = [];
        $uzyte_symbole = [];
        ?>
        
        <div class="poster">
            <div class="poster-header">
                <div class="title-block">
                    <div class="top-row">
                        <div class="icon">
                            <svg width="45" height="45" viewBox="0 0 24 24" fill="white"><path d="M12 2C8 2 4 2.5 4 6v9.5C4 17.43 5.57 19 7.5 19L6 20.5v.5h2.23l2-2H14l2 2h2v-.5L16.5 19c1.93 0 3.5-1.57 3.5-3.5V6c0-3.5-4-4-8-4zm0 2c3.51 0 6 .43 6 2v5H6V6c0-1.57 2.49-2 6-2zm-2.5 13c-.83 0-1.5-.67-1.5-1.5S8.67 14 9.5 14s1.5.67 1.5 1.5S10.33 17 9.5 17zm5 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                        </div>
                        <div class="title"><?= $title_main ?></div>
                    </div>
                    <div class="station-name"><?= htmlspecialchars($stacja_info['nazwa_stacji']) ?></div>
                </div>
                
                <div class="header-right">
                    <div class="qr-code">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://portalpasazera.pl" alt="QR">
                    </div>
                    <div class="details">
                        <div class="pkp-logo">PKP POLSKIE LINIE KOLEJOWE S.A.</div>
                        <div class="validity"><?= $daty_kursowania_plakatu ?></div>
                        <div class="update">Aktualizacja wg stanu na <?= $current_date_for_update ?></div>
                    </div>
                </div>
            </div>
            
            <table class="main-table">
                <thead>
                    <tr>
                        <th class="th-time"><?= $col_1_title ?></th>
                        <th class="th-platform"><?= $col_1_title == 'godzina<br>odjazdu' ? 'peron<br>tor' : 'peron<br>tor' ?><br><span style='font-weight:normal; font-size:0.7em;'>platform<br>track</span></th>
                        <th class="th-train">pociąg<br><br><span style='font-weight:normal; font-size:0.7em;'>train</span></th>
                        <th class="th-dest"><?= $col_4_title ?></th>
                        <?php if ($typ_plakatu === 'odjazdy'): ?>
                            <th class="th-final"><?= $col_5_title ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($typ_plakatu === 'odjazdy') {
                        $sql = "SELECT sr.*, p.*, t.id_stacji_koncowej AS stacja_docelowa_id, st_konc.nazwa_stacji AS stacja_docelowa_nazwa,
                                tp.skrot as typ_skrot, przew.skrot as przewoznik_skrot, kolor_czcionki
                                FROM szczegoly_rozkladu sr 
                                JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu 
                                JOIN trasy t ON p.id_trasy = t.id_trasy 
                                JOIN stacje st_konc ON t.id_stacji_koncowej = st_konc.id_stacji
                                LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                                LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                                WHERE sr.id_stacji = ? AND sr.odjazd IS NOT NULL AND (sr.uwagi_postoju = 'ph' OR sr.przyjazd IS NULL)
                                ORDER BY sr.odjazd ASC";
                    } else {
                        $sql = "SELECT sr.*, p.*, t.id_stacji_poczatkowej AS stacja_docelowa_id, st_pocz.nazwa_stacji AS stacja_docelowa_nazwa,
                                tp.skrot as typ_skrot, przew.skrot as przewoznik_skrot, kolor_czcionki
                                FROM szczegoly_rozkladu sr 
                                JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu 
                                JOIN trasy t ON p.id_trasy = t.id_trasy 
                                JOIN stacje st_pocz ON t.id_stacji_poczatkowej = st_pocz.id_stacji
                                LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                                LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                                WHERE sr.id_stacji = ? AND sr.przyjazd IS NOT NULL AND (sr.uwagi_postoju = 'ph' OR sr.odjazd IS NULL)
                                ORDER BY sr.przyjazd ASC";
                    }
                    
                    $stmt = mysqli_prepare($conn, $sql);
                    mysqli_stmt_bind_param($stmt, "i", $id_stacji_wybranej);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($result) > 0) {
                        while ($row = mysqli_fetch_assoc($result)) {
                            if (!empty($row['przewoznik_skrot'])) {
                                $uzyci_przewoznicy[$row['przewoznik_skrot']] = $przewoznicy_baza[$row['przewoznik_skrot']] ?? '';
                            }
                            if (!empty($row['typ_skrot'])) {
                                $uzyte_typy_pociagow[$row['typ_skrot']] = $typy_baza[$row['typ_skrot']] ?? '';
                            }
                            
                            if (!empty($row['symbole'])) {
                                foreach(explode(',', $row['symbole']) as $sym) {
                                    $uzyte_symbole[trim($sym)] = true;
                                }
                            }

                            $dni_kurs = format_days($row['dni_kursowania']);
                            if (strpos($dni_kurs, '①') !== false) $uzyte_symbole['dzien_1'] = true;
                            if (strpos($dni_kurs, '②') !== false) $uzyte_symbole['dzien_2'] = true;
                            if (strpos($dni_kurs, '③') !== false) $uzyte_symbole['dzien_3'] = true;
                            if (strpos($dni_kurs, '④') !== false) $uzyte_symbole['dzien_4'] = true;
                            if (strpos($dni_kurs, '⑤') !== false) $uzyte_symbole['dzien_5'] = true;
                            if (strpos($dni_kurs, '⑥') !== false) $uzyte_symbole['dzien_6'] = true;
                            if (strpos($dni_kurs, '⑦') !== false) $uzyte_symbole['dzien_7'] = true;
                            if (strpos($dni_kurs, '⍉') !== false) $uzyte_symbole['oprocze'] = true;
                            if (strpos($dni_kurs, '⊕') !== false) $uzyte_symbole['oraz'] = true;

                            $color_style = (strtolower($row['kolor_czcionki']) == 'red') ? "color: #d00;" : "";
                            $czas_glowy = ($typ_plakatu === 'odjazdy') ? $row['odjazd'] : $row['przyjazd'];
                            $peron = htmlspecialchars($row['peron'] ?? '');
                            $tor = htmlspecialchars($row['tor'] ?? '');
                            
                            echo "<tr style='{$color_style}'>";
                            echo "<td class='td-time'>" . date("H:i", strtotime($czas_glowy)) . "</td>";
                            echo "<td class='td-platform'>
                                    <div class='platform-val'>{$peron}</div>
                                    <div class='track-val'>{$tor}</div>
                                </td>";
                            
                            $train_num = "{$row['przewoznik_skrot']} - {$row['typ_skrot']}<br><span style='font-weight:normal;'>{$row['numer_pociagu']}</span>";
                            echo "<td class='td-train'>
                                <div class='train-cat'>{$train_num}</div>
                                <div class='train-name'>" . mb_strtoupper($row['nazwa_pociagu']) . "</div>"
                                . render_symbols($row['symbole'], $symbol_definitions) .
                            "</td>";
                            
                            $id_przejazdu = $row['id_przejazdu'];
                            $current_kolejnosc = $row['kolejnosc'];
                            
                            if ($typ_plakatu === 'odjazdy') {
                                $sql_przystanki = "SELECT s.nazwa_stacji, sr.przyjazd AS czas, s.wytluszczony_plakat, sr.uwagi_postoju 
                                                FROM szczegoly_rozkladu sr 
                                                JOIN stacje s ON sr.id_stacji = s.id_stacji 
                                                WHERE sr.id_przejazdu = ? AND sr.kolejnosc > ? 
                                                ORDER BY sr.kolejnosc ASC";
                            } else {
                                $sql_przystanki = "SELECT s.nazwa_stacji, sr.odjazd AS czas, s.wytluszczony_plakat, sr.uwagi_postoju 
                                                FROM szczegoly_rozkladu sr 
                                                JOIN stacje s ON sr.id_stacji = s.id_stacji 
                                                WHERE sr.id_przejazdu = ? AND sr.kolejnosc < ? 
                                                ORDER BY sr.kolejnosc ASC";
                            }
                            
                            $stmt_przystanki = mysqli_prepare($conn, $sql_przystanki);
                            mysqli_stmt_bind_param($stmt_przystanki, "ii", $id_przejazdu, $current_kolejnosc);
                            mysqli_stmt_execute($stmt_przystanki);
                            $result_przystanki = mysqli_stmt_get_result($stmt_przystanki);
                            
                            $stacje_html = "";
                            $czas_koncowy = "";
                            $nazwa_stacji_koncowej = "";

                            $wszystkie_przystanki = [];
                            while ($przystanek = mysqli_fetch_assoc($result_przystanki)) {
                                $wszystkie_przystanki[] = $przystanek;
                            }
                            $ilosc = count($wszystkie_przystanki);

                            if ($typ_plakatu === 'przyjazdy') {
                                foreach ($wszystkie_przystanki as $index => $przystanek) {
                                    $czas = $przystanek['czas'] ? date("H:i", strtotime($przystanek['czas'])) : '';
                                    $nazwa = htmlspecialchars($przystanek['nazwa_stacji']);
                                    if ($przystanek['wytluszczony_plakat']) {
                                        $nazwa = "<strong>{$nazwa}</strong>";
                                    }
                                    
                                    if ($index === 0) {
                                        $stacje_html .= "<span style='font-size: 1.1em; font-weight: bold;'>{$nazwa} {$czas}</span>, ";
                                    } else {
                                        if ($przystanek['uwagi_postoju'] === 'ph') {
                                            $stacje_html .= "{$nazwa} {$czas}, ";
                                        }
                                    }
                                }
                            } else {
                                foreach ($wszystkie_przystanki as $index => $przystanek) {
                                    $czas = $przystanek['czas'] ? date("H:i", strtotime($przystanek['czas'])) : '';
                                    $nazwa = htmlspecialchars($przystanek['nazwa_stacji']);
                                    
                                    if ($index === $ilosc - 1) {
                                        $czas_koncowy = $czas;
                                        $nazwa_stacji_koncowej = $przystanek['wytluszczony_plakat'] ? "<strong>{$nazwa}</strong>" : $nazwa;
                                    } else {
                                        if ($przystanek['uwagi_postoju'] === 'ph') {
                                            if ($przystanek['wytluszczony_plakat']) {
                                                $nazwa = "<strong>{$nazwa}</strong>";
                                            }
                                            $stacje_html .= "{$nazwa} {$czas}, ";
                                        }
                                    }
                                }
                            }
                            
                            $stacje_html = rtrim($stacje_html, ", ");
                            $daty_kurs = trim(htmlspecialchars($row['daty_kursowania']));
                            
                            if (!empty($daty_kurs) || !empty(trim($dni_kurs))) {
                                $stacje_html .= "<div class='dates-row'>
                                                    <span style='margin-right: 5px; color: #111;'>📅</span> 
                                                    <span><strong>{$daty_kurs}</strong> {$dni_kurs}</span>
                                                 </div>";
                            }

                            echo "<td class='td-destination'>{$stacje_html}</td>";

                            if ($typ_plakatu === 'odjazdy') {
                                echo "<td class='td-final'>
                                        <div style='font-weight: bold;'>{$nazwa_stacji_koncowej}</div>
                                        <div class='final-time'>{$czas_koncowy}</div>
                                      </td>";
                            }
                            
                            echo "</tr>";
                        }
                    } else {
                        $colspan = ($typ_plakatu === 'odjazdy') ? 5 : 4;
                        echo "<tr><td colspan='{$colspan}' style='text-align:center; padding: 20px; font-weight: bold;'>Brak zaplanowanych pociągów dla tej stacji.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>

            <div class="legend-container">
                <div class="legend-column">
                    <h4>objaśnienia skrótów / abbreviations</h4>
                    <?php 
                    if (!empty($uzyci_przewoznicy)) {
                        foreach ($uzyci_przewoznicy as $skrot => $pelna) {
                            echo "<div class='legend-item'><strong>{$skrot} - {$pelna}</strong></div>";
                        }
                        foreach ($uzyte_typy_pociagow as $skrot => $pelna) {
                            echo "<div class='legend-item' style='padding-left: 20px;'>{$skrot} - {$pelna}</div>";
                        }
                    } else {
                        echo "<div class='legend-item'><span>Brak skrótów</span></div>";
                    }
                    ?>
                </div>

                <div class="legend-column">
                    <h4>objaśnienia znaków / symbols</h4>
                    <?php
                    $wypisane = 0;
                    $uzyte_symbole['kalendarz'] = true;
                    $polowa = ceil(count($uzyte_symbole) / 2);

                    foreach ($symbol_definitions as $klucz => $dane) {
                        if (isset($uzyte_symbole[$klucz])) {
                            echo "<div class='legend-item'>
                                    <span class='symbol'>{$dane[0]}</span>
                                    <span>- {$dane[1]}</span>
                                  </div>";
                            $wypisane++;
                            
                            if ($wypisane == $polowa && count($uzyte_symbole) > 1) {
                                echo "</div><div class='legend-column'><h4>objaśnienia znaków / symbols</h4>";
                            }
                        }
                    }
                    ?>
                </div>
            </div>

            <div class="poster-footer" style="margin-top: 25px; font-size: 0.85em; line-height: 1.3; text-align: left; padding-bottom: 10px;">
                Sprzedaż biletów krajowych w każdym pociągu, na zasadach określonych przez danego przewoźnika. / Tickets for domestic routes can be purchased on each train, consistent with the terms specified by the carrier.<br>
                Za dane handlowe pociągów odpowiada przewoźnik. / Carriers are responsible for commercial data of trains.<br>
                Numery telefonicznej informacji poszczególnych przewoźników: / Infoline numbers to individual carriers:<br>
                <strong>PR - 22 474 00 44 (6:00-22:00) (koszt według taryfy operatora)</strong> / 22 474 00 44 (6:00-22:00) (connection cost according to operator rates)
            </div>

        </div>
    <?php endif; ?>
</div>

</body>
</html>