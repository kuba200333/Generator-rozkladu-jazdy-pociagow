<?php
require 'db_config.php';

$wybrana_stacja = isset($_GET['id_stacji']) ? (int)$_GET['id_stacji'] : null;
$wybrana_data = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');
$id_przejazdu_szczegoly = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;

// Słownik symboli (z generatora)
$available_symbols = [
    'klasa_1' => '1 klasa', 'klasa_2' => '2 klasa', 'rower' => 'Przewóz rowerów', 'rezerwacja' => 'Rezerwacja obowiązkowa',
    'wozek_rampa' => 'Wagon z miejscami dla osób na wózkach - z windą/rampą', 'wozek_bez_rampy' => 'Miejsce na wózek (bez windy)', 'kuszetka' => 'Kuszetka',
    'sypialny' => 'Wagon sypialny', 'bar' => 'Wagon barowy', 'restauracyjny' => 'Wagon restauracyjny',
    'automat' => 'Automat z przekąskami', 'wifi' => 'Dostęp do WiFi', 'klima' => 'Klimatyzacja',
    'przewijak' => 'Dostępne miejsce do przewijania dziecka', 'duzy_bagaz' => 'Miejsce na duży bagaż'
];

$PLK_KODY = [
    "11" => "Wypadek / Kolizja",
    "13" => "Wypadek z człowiekiem / Samobójstwo",
    "34" => "Zbyt późne zgłoszenie gotowości",
    "40" => "Opóźnienie wtórne (krzyżowanie / wyprzedzanie)",
    "61" => "Usterka taboru",
    "63" => "Usterka wagonów",
    "64" => "Brak sprawnych hamulców / Oględziny",
    "82" => "Usterka urządzeń SRK",
    "83" => "Usterka sieci trakcyjnej",
    "86" => "Usterka toru / Pęknięcie szyny",
    "90" => "Brak maszynisty / drużyny",
    "94" => "Oczekiwanie na skomunikowanie"
];

function calcDelayMin($plan, $rzecz) {
    if (!$plan || !$rzecz) return 0;
    $p = strtotime(substr($plan, 0, 5));
    $r = strtotime(substr($rzecz, 0, 5));
    $diff = round(($r - $p) / 60);
    if ($diff < -720) $diff += 1440;
    if ($diff > 720) $diff -= 1440;
    return $diff;
}

function formatTime($t) {
    return $t ? substr($t, 0, 5) : '';
}

function formatDelayTime($plan, $rzecz, $isDelay) {
    if (!$plan) return '';
    $p = substr($plan, 0, 5);
    if ($rzecz && substr($rzecz, 0, 5) !== $p && $isDelay > 0) {
        $r = substr($rzecz, 0, 5);
        return "<div class='time-block'><span class='time-crossed'>$p</span><span class='time-actual'>$r</span></div>";
    }
    return "<div class='time-block'><span>$p</span></div>";
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Pasażera</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; background-color: #f4f6f9; color: #333; }
        .header { background-color: #004080; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .header h1 { margin: 0; font-size: 22px; }
        .header a { color: white; text-decoration: none; background: rgba(255,255,255,0.2); padding: 8px 15px; border-radius: 4px; font-weight: bold; font-size: 14px; }
        .header a:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1000px; margin: 30px auto; padding: 0 15px; }
        
        .search-box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .search-box .form-group { display: flex; flex-direction: column; flex-grow: 1; min-width: 200px; }
        .search-box label { font-size: 13px; color: #555; font-weight: bold; margin-bottom: 5px; }
        .search-box select, .search-box input { padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; }
        .search-box button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; height: 40px; }
        .search-box button:hover { background: #0056b3; }

        .results-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .results-table th, .results-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .results-table th { background: #f8f9fa; font-weight: bold; color: #555; }
        .results-table tr:hover td { background-color: #f1f7fd; cursor: pointer; }
        .delay-red { color: #dc3545; font-weight: bold; }
        .delay-green { color: #28a745; font-weight: bold; }
        .btn-szczegoly { background: #004080; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }

        /* WIDOK SZCZEGÓŁÓW POCIĄGU */
        .train-header { background: #004080; color: white; padding: 25px; border-radius: 8px 8px 0 0; text-align: center; }
        .train-header .relacja { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .train-header .info { font-size: 14px; color: #cbd5e1; }
        
        .tabs { display: flex; background: #003366; border-radius: 0 0 8px 8px; margin-bottom: 20px; }
        .tab { flex: 1; text-align: center; padding: 12px; color: #cbd5e1; cursor: pointer; font-weight: bold; border-bottom: 3px solid transparent; transition: 0.2s; }
        .tab:hover { background: rgba(255,255,255,0.1); }
        .tab.active { color: white; border-bottom: 3px solid #007bff; background: rgba(255,255,255,0.05); }

        .tab-content { display: none; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .tab-content.active { display: block; }

        /* Oś czasu - Trasa POPRAWIONA */
        .timeline { position: relative; padding-left: 0; margin-top: 10px; }
        /* Zielona linia bazowa - idealnie przez środek kropek */
        .timeline::before { content: ''; position: absolute; left: 92px; top: 15px; bottom: 15px; width: 4px; background: #28a745; border-radius: 2px; z-index: 1; }
        .timeline-item { display: flex; position: relative; margin-bottom: 30px; align-items: flex-start; }
        /* Sekcja z godzinami */
        .timeline-time { width: 70px; font-size: 15px; font-weight: bold; text-align: right; padding-right: 25px; z-index: 2; }
        .time-block { display: flex; flex-direction: column; align-items: flex-end; line-height: 1.2; }
        .time-crossed { text-decoration: line-through; color: #888; font-weight: normal; font-size: 0.9em; }
        .time-actual { color: #dc3545; font-weight: bold; }
        
        /* Kropka na osi */
        .timeline-dot { width: 16px; height: 16px; background: #28a745; border: 4px solid white; border-radius: 50%; position: absolute; left: 82px; top: 0; z-index: 2; box-shadow: 0 0 0 1px #28a745; }
        /* Zawartość stacji */
        .timeline-content { margin-left: 40px; flex-grow: 1; padding-top: 0; }
        .timeline-content .st-name { font-size: 18px; font-weight: bold; color: #004080; }
        .timeline-content .st-peron { font-size: 12px; color: #666; margin-top: 3px; }
        .timeline-content .st-delay { font-size: 13px; margin-top: 3px; cursor: help; }
        
        /* Opcja dla opóźnień na osi */
        .has-delay .timeline-dot { background: #dc3545; box-shadow: 0 0 0 1px #dc3545; }

        /* Mapa */
        #map { height: 500px; width: 100%; border-radius: 4px; border: 1px solid #ccc; }

        /* Dane połączenia (Styl PKP) */
        .conn-summary { display: flex; background: #fff; border: 1px solid #eee; border-radius: 6px; margin-bottom: 20px; overflow: hidden; }
        .conn-summary .col { flex: 1; padding: 15px; border-right: 1px solid #eee; }
        .conn-summary .col:last-child { border-right: none; }
        .conn-summary .label { font-size: 12px; color: #666; text-transform: uppercase; margin-bottom: 5px; }
        .conn-summary .value { font-size: 16px; font-weight: bold; color: #004080; }

        .conn-block { background: #f8f9fa; border: 1px solid #e2e8f0; border-left: 4px solid #004080; padding: 20px; border-radius: 4px; margin-bottom: 15px; display: grid; grid-template-columns: 1fr 3fr; gap: 20px; }
        .conn-block .block-title { font-size: 18px; font-weight: bold; color: #004080; }
        .conn-block .block-content { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .conn-block.train-info { border-left-color: #28a745; }
        
        .info-field { display: flex; flex-direction: column; }
        .info-field .lbl { font-size: 12px; color: #666; margin-bottom: 3px; }
        .info-field .val { font-size: 15px; font-weight: bold; color: #333; }
        
        .symbols-list { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 15px; grid-column: span 2; }
        .symbol-item { display: flex; align-items: center; gap: 5px; font-size: 13px; color: #444; }
    </style>
</head>
<body>

<div class="header">
    <h1>🚆 Portal Pasażera</h1>
    <div>
        <a href="index.php">Wróć do Systemu</a>
    </div>
</div>

<div class="container">
    
    <?php if (!$id_przejazdu_szczegoly): ?>
        <form method="GET" action="" class="search-box">
            <div class="form-group">
                <label>Stacja</label>
                <select name="id_stacji" required>
                    <option value="">Wybierz stację...</option>
                    <?php
                    $res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
                    while ($s = mysqli_fetch_assoc($res)) {
                        $sel = ($s['id_stacji'] == $wybrana_stacja) ? 'selected' : '';
                        echo "<option value='{$s['id_stacji']}' $sel>{$s['nazwa_stacji']}</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Data</label>
                <input type="date" name="data" value="<?= $wybrana_data ?>" required>
            </div>
            <button type="submit">Szukaj połączeń</button>
        </form>

        <?php if ($wybrana_stacja): ?>
            <?php
            $sql = "SELECT sr.id_przejazdu, sr.przyjazd, sr.odjazd, sr.przyjazd_rzecz, sr.odjazd_rzecz, 
                           p.numer_pociagu, p.nazwa_pociagu, tp.skrot as rodzaj, przew.pelna_nazwa as przewoznik,
                           (SELECT nazwa_stacji FROM stacje WHERE id_stacji = t.id_stacji_koncowej) as stacja_docelowa
                    FROM szczegoly_rozkladu sr
                    JOIN przejazdy p ON sr.id_przejazdu = p.id_przejazdu
                    JOIN trasy t ON p.id_trasy = t.id_trasy
                    JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                    LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                    WHERE sr.id_stacji = ? AND p.data_kursowania = ? AND sr.czy_odwolany = 0
                    ORDER BY COALESCE(sr.odjazd, sr.przyjazd)";
            
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "is", $wybrana_stacja, $wybrana_data);
            mysqli_stmt_execute($stmt);
            $wyniki = mysqli_stmt_get_result($stmt);
            ?>
            
            <?php if (mysqli_num_rows($wyniki) > 0): ?>
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Godzina</th>
                            <th>Pociąg</th>
                            <th>Kierunek</th>
                            <th>Opóźnienie</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = mysqli_fetch_assoc($wyniki)): 
                            $czas_plan = $r['odjazd'] ? formatTime($r['odjazd']) : formatTime($r['przyjazd']);
                            $czas_rzecz = $r['odjazd_rzecz'] ? formatTime($r['odjazd_rzecz']) : ($r['przyjazd_rzecz'] ? formatTime($r['przyjazd_rzecz']) : '');
                            
                            $delay = 0;
                            if ($r['odjazd_rzecz'] && $r['odjazd']) $delay = calcDelayMin($r['odjazd'], $r['odjazd_rzecz']);
                            elseif ($r['przyjazd_rzecz'] && $r['przyjazd']) $delay = calcDelayMin($r['przyjazd'], $r['przyjazd_rzecz']);
                            
                            $delay_text = "";
                            if ($delay > 0) $delay_text = "<span class='delay-red'>+{$delay} min</span>";
                            elseif ($delay < 0) $delay_text = "<span class='delay-green'>{$delay} min</span>";
                        ?>
                            <tr onclick="window.location.href='?id_przejazdu=<?= $r['id_przejazdu'] ?>&id_stacji=<?= $wybrana_stacja ?>&data=<?= $wybrana_data ?>'">
                                <td style="font-size: 18px; font-weight: bold;"><?= $czas_plan ?></td>
                                <td>
                                    <strong><?= $r['rodzaj'] ?> <?= $r['numer_pociagu'] ?></strong><br>
                                    <span style="font-size: 12px; color: #666;"><?= $r['przewoznik'] ?></span>
                                </td>
                                <td><?= htmlspecialchars($r['stacja_docelowa']) ?></td>
                                <td><?= $delay_text ?></td>
                                <td style="text-align: right;"><button class="btn-szczegoly">Szczegóły</button></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="background: white; padding: 40px; text-align: center; border-radius: 8px; color: #666;">
                    Brak pociągów dla wybranej stacji w tym dniu.
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php else: ?>
        <?php
        // Pobieranie danych o pociągu
        $q_info = mysqli_prepare($conn, "SELECT p.*, tp.skrot as rodzaj, tp.pelna_nazwa as kat_pelna, przew.pelna_nazwa as przewoznik, t.nazwa_trasy,
                                         (SELECT nazwa_stacji FROM stacje WHERE id_stacji = t.id_stacji_poczatkowej) as st_pocz,
                                         (SELECT nazwa_stacji FROM stacje WHERE id_stacji = t.id_stacji_koncowej) as st_konc
                                         FROM przejazdy p 
                                         JOIN trasy t ON p.id_trasy = t.id_trasy
                                         JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                                         LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                                         WHERE p.id_przejazdu = ?");
        mysqli_stmt_bind_param($q_info, "i", $id_przejazdu_szczegoly);
        mysqli_stmt_execute($q_info);
        $info = mysqli_fetch_assoc(mysqli_stmt_get_result($q_info));

        // Pobieranie pełnej trasy
        $q_trasa = mysqli_prepare($conn, "SELECT sr.*, s.nazwa_stacji, s.lat, s.lng 
                                          FROM szczegoly_rozkladu sr 
                                          JOIN stacje s ON sr.id_stacji = s.id_stacji 
                                          WHERE sr.id_przejazdu = ? AND sr.czy_odwolany = 0
                                          ORDER BY sr.kolejnosc");
        mysqli_stmt_bind_param($q_trasa, "i", $id_przejazdu_szczegoly);
        mysqli_stmt_execute($q_trasa);
        $trasa_res = mysqli_stmt_get_result($q_trasa);
        
        $trasa = [];
        while ($t = mysqli_fetch_assoc($trasa_res)) {
            $trasa[] = $t;
        }

        // ODCINKI DO RYSOWANIA DOKŁADNEJ MAPY
        $segments_data_map = [];
        for ($i = 0; $i < count($trasa) - 1; $i++) {
            $idA = $trasa[$i]['id_stacji'];
            $idB = $trasa[$i+1]['id_stacji'];
            $q_odc = mysqli_query($conn, "SELECT sciezka FROM odcinki WHERE (id_stacji_A = $idA AND id_stacji_B = $idB) OR (id_stacji_A = $idB AND id_stacji_B = $idA) LIMIT 1");
            $sciezka = [];
            if ($r_odc = mysqli_fetch_assoc($q_odc)) {
                if (!empty($r_odc['sciezka']) && $r_odc['sciezka'] !== 'null') {
                    $sciezka = json_decode($r_odc['sciezka'], true);
                    if (!empty($sciezka)) {
                        // Odwracanie punktów jeśli potrzeba
                        $distA = pow($sciezka[0][0] - $trasa[$i]['lat'], 2) + pow($sciezka[0][1] - $trasa[$i]['lng'], 2);
                        $distB = pow(end($sciezka)[0] - $trasa[$i]['lat'], 2) + pow(end($sciezka)[1] - $trasa[$i]['lng'], 2);
                        if ($distB < $distA) {
                            $sciezka = array_reverse($sciezka);
                        }
                    }
                }
            }
            if (empty($sciezka)) {
                $sciezka = [ [$trasa[$i]['lat'], $trasa[$i]['lng']], [$trasa[$i+1]['lat'], $trasa[$i+1]['lng']] ];
            }
            $segments_data_map[] = $sciezka;
        }

        // Zmienne do mapy (punkty)
        $map_data = [];
        foreach ($trasa as $t) {
            $map_data[] = [
                'lat' => (float)$t['lat'], 
                'lng' => (float)$t['lng'], 
                'nazwa' => $t['nazwa_stacji']
            ];
        }

        // Obliczanie czasu podróży
        $start_node = $trasa[0];
        $end_node = $trasa[count($trasa)-1];
        
        $start_time = strtotime(formatTime($start_node['odjazd']));
        $end_time = strtotime(formatTime($end_node['przyjazd']));
        if ($end_time < $start_time) $end_time += 86400; // Następny dzień
        $total_minutes = round(($end_time - $start_time) / 60);
        $travel_hours = floor($total_minutes / 60);
        $travel_mins = $total_minutes % 60;
        $travel_str = "{$travel_hours}h:" . str_pad($travel_mins, 2, '0', STR_PAD_LEFT) . "min";

        // Przygotowanie symboli
        $symbole_array = [];
        if (!empty($info['symbole'])) {
            $symbole_db = json_decode($info['symbole'], true);
            if (!is_array($symbole_db)) $symbole_db = explode(',', $info['symbole']);
            foreach ($symbole_db as $s) {
                $s = trim(str_replace(['"', '[', ']', '\\'], '', $s));
                if (isset($available_symbols[$s])) {
                    $symbole_array[] = $available_symbols[$s];
                }
            }
        }
        ?>

        <div style="margin-bottom: 15px;">
            <a href="?id_stacji=<?= $wybrana_stacja ?>&data=<?= $wybrana_data ?>" style="color: #004080; text-decoration: none; font-weight: bold;">⬅ Wróć do wyników wyszukiwania</a>
        </div>

        <div class="train-header">
            <div class="relacja"><?= $info['st_pocz'] ?> <span style="font-weight:normal; margin: 0 10px;">➔</span> <?= $info['st_konc'] ?></div>
            <div class="info">
                <?= $info['rodzaj'] ?> <?= $info['numer_pociagu'] ?> <?= $info['nazwa_pociagu'] ? '"'.$info['nazwa_pociagu'].'"' : '' ?> <br>
                Data kursowania: <?= date('d.m.Y', strtotime($info['data_kursowania'])) ?>
            </div>
        </div>
        
        <div class="tabs">
            <div class="tab active" onclick="switchTab('trasa')">Trasa stacja po stacji</div>
            <div class="tab" onclick="switchTab('mapa')">Trasa na mapie</div>
            <div class="tab" onclick="switchTab('dane')">Dane połączenia</div>
        </div>

        <div id="tab-trasa" class="tab-content active">
            <div class="timeline">
                <?php foreach ($trasa as $index => $t): 
                    // Sprawdzanie czy to stacja handlowa (ph) lub początkowa/końcowa
                    if ($t['uwagi_postoju'] !== 'ph' && $index !== 0 && $index !== count($trasa)-1) continue;

                    // Logika czasów (Wyjazd ma tylko Odjazd, Przyjazd ma tylko Przyjazd, Pośrednie mają oba)
                    $arr_plan = formatTime($t['przyjazd']);
                    $arr_rzecz = formatTime($t['przyjazd_rzecz']);
                    $dep_plan = formatTime($t['odjazd']);
                    $dep_rzecz = formatTime($t['odjazd_rzecz']);

                    $delay = 0;
                    if ($dep_rzecz && $dep_plan) $delay = calcDelayMin($dep_plan, $dep_rzecz);
                    elseif ($arr_rzecz && $arr_plan) $delay = calcDelayMin($arr_plan, $arr_rzecz);
                    
                    $delay_class = ($delay > 0) ? 'has-delay' : '';
                    $delay_reason = isset($PLK_KODY[$t['kod_opoznienia']]) ? $PLK_KODY[$t['kod_opoznienia']] : 'Opóźnienie pociągu';
                ?>
                    <div class="timeline-item <?= $delay_class ?>">
                        <div class="timeline-time">
                            <?php if ($index === 0): ?>
                                <?= formatDelayTime($dep_plan, $dep_rzecz, $delay) ?>
                            <?php elseif ($index === count($trasa)-1): ?>
                                <?= formatDelayTime($arr_plan, $arr_rzecz, $delay) ?>
                            <?php else: ?>
                                <?= formatDelayTime($arr_plan, $arr_rzecz, $delay) ?>
                                <div style="height: 5px;"></div>
                                <?= formatDelayTime($dep_plan, $dep_rzecz, $delay) ?>
                            <?php endif; ?>
                        </div>
                        <div class="timeline-dot"></div>
                        <div class="timeline-content">
                            <div class="st-name"><?= htmlspecialchars($t['nazwa_stacji']) ?></div>
                            <div class="st-peron">Peron <?= $t['peron'] ?: '-' ?> Tor <?= $t['tor'] ?: '-' ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="tab-mapa" class="tab-content">
            <div id="map"></div>
        </div>

        <div id="tab-dane" class="tab-content" style="background: transparent; box-shadow: none; padding: 0;">
            <div class="conn-summary">
                <div class="col">
                    <div class="label">Tryb połączenia</div>
                    <div class="value">Bezpośrednie</div>
                </div>
                <div class="col">
                    <div class="label">Łączny czas podróży</div>
                    <div class="value"><?= $travel_str ?></div>
                </div>
            </div>

            <div class="conn-block">
                <div class="block-title">Wyjazd</div>
                <div class="block-content">
                    <div class="info-field">
                        <div class="lbl">Stacja</div>
                        <div class="val"><?= htmlspecialchars($info['st_pocz']) ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Peron / Tor</div>
                        <div class="val">Peron <?= $start_node['peron'] ?: '-' ?> Tor <?= $start_node['tor'] ?: '-' ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Godzina odjazdu</div>
                        <div class="val"><?= formatTime($start_node['odjazd']) ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Dzień odjazdu</div>
                        <div class="val"><?= date('d.m.Y', strtotime($info['data_kursowania'])) ?></div>
                    </div>
                </div>
            </div>

            <div class="conn-block train-info">
                <div class="block-title">Pociąg</div>
                <div class="block-content">
                    <div class="info-field">
                        <div class="lbl">Przewoźnik</div>
                        <div class="val"><?= htmlspecialchars($info['przewoznik']) ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Nazwa pociągu</div>
                        <div class="val"><?= htmlspecialchars($info['nazwa_pociagu']) ?: '-' ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Numer pociągu</div>
                        <div class="val"><?= $info['numer_pociagu'] ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Kategoria handlowa</div>
                        <div class="val"><?= $info['kat_pelna'] ?: $info['rodzaj'] ?></div>
                    </div>
                    
                    <div class="info-field" style="grid-column: span 2;">
                        <div class="lbl">Relacja</div>
                        <div class="val"><?= htmlspecialchars($info['st_pocz']) ?> - <?= htmlspecialchars($info['st_konc']) ?></div>
                    </div>

                    <div style="grid-column: span 2; margin-top: 10px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                        <div class="lbl" style="font-weight: bold; color: #004080; margin-bottom: 10px;">Informacje o pociągu</div>
                        <div class="symbols-list">
                            <?php if (empty($symbole_array)): ?>
                                <div class="symbol-item">Brak dodatkowych informacji</div>
                            <?php else: ?>
                                <?php foreach ($symbole_array as $sym): ?>
                                    <div class="symbol-item">› <?= $sym ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="conn-block">
                <div class="block-title">Przyjazd</div>
                <div class="block-content">
                    <div class="info-field">
                        <div class="lbl">Stacja</div>
                        <div class="val"><?= htmlspecialchars($info['st_konc']) ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Peron / Tor</div>
                        <div class="val">Peron <?= $end_node['peron'] ?: '-' ?> Tor <?= $end_node['tor'] ?: '-' ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Godzina przyjazdu</div>
                        <div class="val"><?= formatTime($end_node['przyjazd']) ?></div>
                    </div>
                    <div class="info-field">
                        <div class="lbl">Dzień przyjazdu</div>
                        <div class="val"><?= date('d.m.Y', strtotime($info['data_kursowania'])) ?></div>
                    </div>
                </div>
            </div>

        </div>

        <script>
            let latlngs = []; // Globalna tablica z koordynatami

            function switchTab(tabId) {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                event.target.classList.add('active');
                document.getElementById('tab-' + tabId).classList.add('active');
                
                if (tabId === 'mapa' && map) {
                    setTimeout(() => {
                        map.invalidateSize(); // Odświeża rozmiar po pokazaniu diva
                        if (latlngs.length > 0) {
                            map.fitBounds(L.polyline(latlngs).getBounds(), {padding: [40, 40]}); // Idealnie łapie całą trasę!
                        }
                    }, 100);
                }
            }

            const mapData = <?= json_encode($map_data) ?>;
            const segmentsData = <?= json_encode($segments_data_map) ?>;
            let map;

            if (mapData.length > 0) {
                map = L.map('map').setView([mapData[0].lat, mapData[0].lng], 10);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 18,
                    attribution: '&copy; OpenStreetMap'
                }).addTo(map);

                // Rysowanie idealnej trasy z odcinków
                segmentsData.forEach(path => {
                    L.polyline(path, {color: '#004080', weight: 4, opacity: 0.7}).addTo(map);
                });

                // Kropki stacji
                let latlngs = [];
                mapData.forEach(st => {
                    latlngs.push([st.lat, st.lng]);
                    L.circleMarker([st.lat, st.lng], {
                        radius: 6, color: '#004080', fillColor: '#fff', fillOpacity: 1, weight: 2
                    }).addTo(map).bindTooltip(st.nazwa, {permanent: false});
                });

            }
        </script>

    <?php endif; ?>
</div>

</body>
</html>