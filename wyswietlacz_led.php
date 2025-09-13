<?php
require 'db_config.php';
$id_przejazdu_wybranego = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;

$stacje_list = [];
$info_pociagu = '';
$nazwa_pociagu = '';
$kierunek = '';
$formularz_ukryty = false;

if ($id_przejazdu_wybranego) {
    $formularz_ukryty = true;
    // Pobierz dane o przejeździe
    $sql_info = "SELECT p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot 
                 FROM przejazdy p
                 JOIN trasy t ON p.id_trasy = t.id_trasy
                 LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                 WHERE p.id_przejazdu = ?";
    $stmt_info = mysqli_prepare($conn, $sql_info);
    mysqli_stmt_bind_param($stmt_info, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_info);
    $przejazd_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));
    $info_pociagu = $przejazd_info['typ_skrot'] . ' ' . $przejazd_info['numer_pociagu'];
    $nazwa_pociagu = $przejazd_info['nazwa_pociagu'];
    
    // Używamy bezpośrednio pobranej nazwy stacji końcowej
    $sql_koncowa = "SELECT s.nazwa_stacji FROM trasy t JOIN stacje s ON t.id_stacji_koncowej = s.id_stacji WHERE t.id_trasy = (SELECT id_trasy FROM przejazdy WHERE id_przejazdu = ?)";
    $stmt_koncowa = mysqli_prepare($conn, $sql_koncowa);
    mysqli_stmt_bind_param($stmt_koncowa, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_koncowa);
    $koncowa_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_koncowa));
    $kierunek = $koncowa_info['nazwa_stacji'];

    // Pobieramy tylko stację początkową (przyjazd IS NULL), końcową (odjazd IS NULL) i te z postojem 'ph'
    $sql_szczegoly = "SELECT s.nazwa_stacji, sr.przyjazd, sr.odjazd, sr.uwagi_postoju 
                      FROM szczegoly_rozkladu sr 
                      JOIN stacje s ON sr.id_stacji = s.id_stacji 
                      WHERE sr.id_przejazdu = ? AND (sr.uwagi_postoju = 'ph' OR sr.przyjazd IS NULL OR sr.odjazd IS NULL)
                      ORDER BY sr.kolejnosc ASC";
    $stmt_szczegoly = mysqli_prepare($conn, $sql_szczegoly);
    mysqli_stmt_bind_param($stmt_szczegoly, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_szczegoly);
    $result_szczegoly = mysqli_stmt_get_result($stmt_szczegoly);
    $stacje_list = mysqli_fetch_all($result_szczegoly, MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Wyświetlacz LED</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet">
    <link rel="manifest" href="manifest.json">
    <style>
        /* === ZMIENIONE STYLE DLA MINIMALIZACJI APLIKACJI === */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body { 
            background-color: #333; 
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        /* Klasa .container została usunięta z kodu HTML, więc jej definicja nie jest już potrzebna */

        .form-container { 
            background-color: #d4edda; 
            padding: 25px; /* Zwiększony padding dla lepszego wyglądu */
            border-radius: 8px; 
            margin-bottom: 20px;
            width: 600px; /* Stała szerokość dla estetyki */
            box-sizing: border-box; /* Zapobiega problemom z paddingiem */
            text-align: center;
        }
        
        a { 
            color: #fff; 
            margin-bottom: 20px; 
            font-size: 1.2em;
            text-decoration: none; /* Lepszy wygląd linku */
        }
        a:hover {
            text-decoration: underline;
        }
        
        .ukryty {
            display: none;
        }
        
        .display-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            padding: 20px; /* Daje minimalny margines od krawędzi okna */
        }

        .nav-button {
            background: transparent;
            border: none;
            color: #D4452D;
            font-size: 2.5em;
            cursor: pointer;
            padding: 10px;
            opacity: 0.2;
            transition: opacity 0.3s ease, transform 0.2s ease;
        }

        .display-wrapper:hover .nav-button {
            opacity: 1;
        }

        .nav-button:hover {
            transform: scale(1.1);
        }

        .led-display { 
            background-color: #2a1a00; 
            border: 5px solid #4a3a20; 
            border-radius: 10px; 
            padding: 20px; 
            font-family: 'DotGothic16', sans-serif; 
            color: #D4452D;
            text-shadow: 0 0 5px rgba(212, 69, 45, 0.7), 0 0 8px rgba(212, 69, 45, 0.5);
            font-size: 28px; 
            line-height: 1.5; 
            text-transform: uppercase;
            flex-shrink: 0; /* Zapobiega kurczeniu się */
            width: 450px; /* Ustawia stałą szerokość wyświetlacza */
        }
        
        .led-line { min-height: 42px; overflow: hidden; white-space: nowrap; display: flex; align-items: center; justify-content: flex-start; }
        .ticker-wrapper { flex-grow: 1; overflow: hidden; min-width: 0; }
        .scrolling-text { display: inline-block; padding-left: 100%; animation: marquee 15s linear; }
        @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
    </style>
</head>
<body>
    
    <?php if (!$formularz_ukryty): ?>
        <a href="index.php">Powrót do menu</a>
    <?php endif; ?>

    <div class="form-container <?php if ($formularz_ukryty) echo 'ukryty'; ?>">
        <form method="GET" action="">
            <label for="id_przejazdu"><strong>Wybierz zapisany rozkład:</strong></label>
            <select name="id_przejazdu" id="id_przejazdu" onchange="this.form.submit()" style="width: 90%; padding: 5px; margin-top: 5px;">
                <option value="">-- Wybierz pociąg --</option>
                <?php
                $sql_przejazdy = "SELECT p.id_przejazdu, p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot FROM przejazdy p JOIN trasy t ON p.id_trasy = t.id_trasy LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu ORDER BY p.data_utworzenia DESC";
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
        <div class="display-wrapper">
            <button class="nav-button" onclick="navigate('prev')">◀</button>
            <div class="led-display">
                <div id="line1" class="led-line"></div>
                <div id="line2" class="led-line"></div>
            </div>
            <button class="nav-button" onclick="navigate('next')">▶</button>
        </div>
    <?php endif; ?>

    <script>
      // Cały kod JavaScript pozostaje bez zmian
      const schedule = <?php echo json_encode($stacje_list); ?>;
      const trainInfo = "<?php echo $info_pociagu; ?>";
      const trainName = "<?php echo $nazwa_pociagu; ?>";
      const destination = "<?php echo $kierunek; ?>";
      const line1 = document.getElementById('line1');
      const line2 = document.getElementById('line2');
      let currentIndex = 0;
      let displayMode = 0;
      let autoTickerTimeout = null;
      let textDisplayInterval = null;
      let infoLoopTimeout = null;
      function getStationTimes(station) { if (station.przyjazd && station.odjazd && station.przyjazd.substring(0, 5) === station.odjazd.substring(0, 5)) { return 'p.' + station.przyjazd.substring(0, 5); } let times = ''; if (station.przyjazd) times += 'p.' + station.przyjazd.substring(0, 5) + ' '; if (station.odjazd) times += 'o.' + station.odjazd.substring(0, 5); return times.trim(); }
      function displayText(element, text, maxChars = 25) { clearInterval(textDisplayInterval); element.innerHTML = ''; if (text.length <= maxChars) { element.textContent = text; return; } const words = text.split(' '); let pages = []; let currentPage = ''; for (const word of words) { if ((currentPage + word).length > maxChars) { pages.push(currentPage.trim()); currentPage = word + ' '; } else { currentPage += word + ' '; } } pages.push(currentPage.trim()); let pageIndex = 0; element.textContent = pages[pageIndex]; if (pages.length > 1) { textDisplayInterval = setInterval(() => { pageIndex = (pageIndex + 1) % pages.length; element.textContent = pages[pageIndex]; }, 2000); } }
      function clearAllTimers() { clearTimeout(autoTickerTimeout); clearInterval(textDisplayInterval); clearTimeout(infoLoopTimeout); }
      function updateDisplay() { if (!schedule || schedule.length === 0) return; clearAllTimers(); line1.innerHTML = ''; line2.innerHTML = ''; const station = schedule[currentIndex]; const stationName = station.nazwa_stacji.toUpperCase(); const stationTimes = getStationTimes(station); if (displayMode === 0) { displayText(line1, 'NASTĘPNA STACJA:'); displayText(line2, stationName + ' ' + stationTimes); autoTickerTimeout = setTimeout(startInfoTicker, 4000); } else { displayText(line1, 'STACJA:'); displayText(line2, stationName + ' ' + stationTimes); } }
      function startInfoTicker() { let loopState = 0; const weekDays = ['NIEDZIELA', 'PONIEDZIAŁEK', 'WTOREK', 'ŚRODA', 'CZWARTEK', 'PIĄTEK', 'SOBOTA']; const months = ['STYCZNIA', 'LUTEGO', 'MARCA', 'KWIETNIA', 'MAJA', 'CZERWCA', 'LIPCA', 'SIERPNIA', 'WRZEŚNIA', 'PAŹDZIERNIKA', 'LISTOPADA', 'GRUDNIA']; function loopStep() { clearAllTimers(); line1.innerHTML = ''; line2.innerHTML = ''; let nextStepDelay = 5000; switch(loopState) { case 0: const remainingStations = schedule.slice(currentIndex); if (remainingStations.length <= 1) { displayText(line1, 'STACJA DOCELOWA'); displayText(line2, remainingStations[0].nazwa_stacji.toUpperCase()); loopState = 1; nextStepDelay = 5000; } else { displayText(line1, 'TRASA:'); const routeString = remainingStations.map(s => `${s.nazwa_stacji.toUpperCase()} ${getStationTimes(s)}`).join('  -  '); const wrapper = document.createElement('div'); wrapper.className = 'ticker-wrapper'; const scrollingSpan = document.createElement('span'); scrollingSpan.className = 'scrolling-text'; scrollingSpan.textContent = routeString; const duration = routeString.length * 0.25; scrollingSpan.style.animation = `marquee ${duration}s linear`; wrapper.appendChild(scrollingSpan); line2.appendChild(wrapper); nextStepDelay = (duration * 1000) + 2000; loopState = 1; } break; case 1: displayText(line1, 'POCIĄG ' + trainInfo + ' ' + trainName.toUpperCase()); displayText(line2, 'STACJA DOCELOWA: ' + destination.toUpperCase()); loopState = 2; break; case 2: const now = new Date(); const dateStr = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`; const timeStr = now.toTimeString().split(' ')[0]; displayText(line1, dateStr); displayText(line2, `${weekDays[now.getDay()]} ${timeStr}`); loopState = 0; break; } infoLoopTimeout = setTimeout(loopStep, nextStepDelay); } loopStep(); }
      function navigate(direction) { clearAllTimers(); if (direction === 'next') { if (displayMode === 1) { if (currentIndex < schedule.length - 1) { currentIndex++; displayMode = 0; updateDisplay(); } } else { displayMode = 1; updateDisplay(); } } else if (direction === 'prev') { if (displayMode === 0) { if (currentIndex > 0) { currentIndex--; displayMode = 1; } } else { displayMode = 0; } updateDisplay(); } }
      if (schedule.length > 0) { updateDisplay(); }
    </script>
</body>
</html>