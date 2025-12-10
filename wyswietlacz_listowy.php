<?php
require 'db_config.php';
$id_przejazdu_wybranego = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;
$start_index = isset($_GET['start_index']) ? (int)$_GET['start_index'] : null;

$theme = $_COOKIE['theme'] ?? 'dark'; 

$stacje_list = [];
$info_pociagu = '';
$nazwa_pociagu = '';
$kierunek = '';

// Formularz ukrywamy tylko gdy wybrano pociąg ORAZ stację startową
$formularz_ukryty = ($id_przejazdu_wybranego && $start_index !== null);

if ($id_przejazdu_wybranego) {
    $sql_info = "SELECT p.numer_pociagu, p.nazwa_pociagu, t.nazwa_trasy, tp.skrot as typ_skrot, przew.skrot as przewoznik 
                 FROM przejazdy p
                 JOIN trasy t ON p.id_trasy = t.id_trasy
                 LEFT JOIN typy_pociagow tp ON p.id_typu_pociagu = tp.id_typu
                 LEFT JOIN przewoznicy przew ON tp.id_przewoznika = przew.id_przewoznika
                 WHERE p.id_przejazdu = ?";
    $stmt_info = mysqli_prepare($conn, $sql_info);
    mysqli_stmt_bind_param($stmt_info, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_info);
    $przejazd_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));
    
    if ($przejazd_info) {
        $info_pociagu = ($przejazd_info['przewoznik'] ? $przejazd_info['przewoznik'] . ' ' : '') . $przejazd_info['typ_skrot'] . ' ' . $przejazd_info['numer_pociagu'];
        $nazwa_pociagu = $przejazd_info['nazwa_pociagu'];
        
        $sql_koncowa = "SELECT s.nazwa_stacji FROM trasy t JOIN stacje s ON t.id_stacji_koncowej = s.id_stacji WHERE t.id_trasy = (SELECT id_trasy FROM przejazdy WHERE id_przejazdu = ?)";
        $stmt_koncowa = mysqli_prepare($conn, $sql_koncowa);
        mysqli_stmt_bind_param($stmt_koncowa, "i", $id_przejazdu_wybranego);
        mysqli_stmt_execute($stmt_koncowa);
        $koncowa_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_koncowa));
        $kierunek = $koncowa_info['nazwa_stacji'] ?? 'Brak danych';
    }

    $sql_wyswietlane = "SELECT sr.id_szczegolu, s.nazwa_stacji, sr.przyjazd, sr.odjazd, sr.uwagi_postoju 
                        FROM szczegoly_rozkladu sr 
                        JOIN stacje s ON sr.id_stacji = s.id_stacji 
                        WHERE sr.id_przejazdu = ? AND (sr.uwagi_postoju = 'ph' OR sr.przyjazd IS NULL OR sr.odjazd IS NULL)
                        ORDER BY sr.kolejnosc ASC";
    $stmt_wyswietlane = mysqli_prepare($conn, $sql_wyswietlane);
    mysqli_stmt_bind_param($stmt_wyswietlane, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_wyswietlane);
    $result_wyswietlane = mysqli_stmt_get_result($stmt_wyswietlane);
    $stacje_wyswietlane = mysqli_fetch_all($result_wyswietlane, MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Wyświetlacz Pociągu</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DotGothic16&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; margin: 0; padding: 0; overflow: hidden; }
        .screen-container { overflow: hidden; position: relative; display: flex; flex-direction: column; }
        .content-area { display: flex; height: calc(100% - 90px); }
        .left-panel { width: 60%; position: relative; }
        .left-panel video { width: 100%; height: 100%; object-fit: cover; }
        .right-panel { width: 40%; overflow: hidden; position: relative; display: flex; flex-direction: column; box-sizing: border-box; }
        .header-info { font-size: 0.9em; font-weight: bold; margin-bottom: 5px; padding-bottom: 5px; flex-shrink: 0; padding-left: 30px; }
        .sticky-station-display, .terminal-station-display { padding: 9px 0 9px 10px; display: flex; justify-content: space-between; align-items: center; font-size: 1.1em; font-weight: 700; position: relative; flex-shrink: 0; padding-left: 30px; }
        .terminal-station-display { margin-top: auto; }
        .station-list-container { overflow-y: hidden; flex-grow: 1; position: relative; padding-left: 30px; }
        .sticky-station-display::before, .station-list-container::before, .terminal-station-display::before { content: ''; position: absolute; left: 34px; width: 2px; opacity: 0.5; z-index: 0; }
        .sticky-station-display::before { top: 50%; height: 50%; left: 34px; }
        .station-list-container::before { top: 0; bottom: 0; left: 34px; }
        .terminal-station-display::before { top: 0; height: 50%; left: 34px; }
        .station-list { list-style: none; padding: 0; margin: 0; position: absolute; width: calc(100% - 30px); }
        .station-item { padding: 9px 0; display: flex; justify-content: space-between; align-items: center; font-size: 1em; position: relative; transition: color 0.3s, background-color 0.3s; }
        .station-name { font-weight: 400; flex-grow: 1; padding-left: 20px; text-transform: uppercase; }
        .sticky-station-display .station-name, .terminal-station-display .station-name { font-weight: 700; }
        .station-time { font-weight: 700; font-size: 1.1em; width: 140px; text-align: right; padding-right: 5px; }
        .indicator { width: 10px; height: 10px; border-radius: 50%; position: absolute; left: 0; top: 50%; transform: translateY(-50%); box-sizing: border-box; z-index: 1; }
        .bottom-bar { height: 90px; display: flex; width: 100%; flex-shrink: 0; background-color: #222; }
        .bottom-left { width: 70%; padding: 2px 15px; box-sizing: border-box; background-color: #2a1a00; font-family: 'DotGothic16', sans-serif; color: #D4452D; text-shadow: 0 0 5px rgba(212, 69, 45, 0.7), 0 0 8px rgba(212, 69, 45, 0.5); font-size: 1.6em; line-height: 1.1; text-transform: uppercase; display: flex; flex-direction: column; justify-content: center; overflow: hidden; border-top: 2px solid #4a3a20; border-right: 2px solid #444; }
        .led-line { min-height: 40px; height: 40px; overflow: hidden; white-space: nowrap; display: flex; align-items: center; justify-content: flex-start; }
        .led-line-green { color: #fae92eff !important; text-shadow: 0 0 5px rgba(253, 175, 116, 0.8), 0 0 10px rgba(253, 175, 116, 0.6); }
        .ticker-wrapper { flex-grow: 1; overflow: hidden; min-width: 0; }
        .scrolling-text { display: inline-block; padding-left: 100%; animation: marquee 15s linear infinite; } 
        @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
        .bottom-right { width: 30%; padding: 0 15px; box-sizing: border-box; display: flex; justify-content: space-between; align-items: center; font-size: 1.2em; }
        #bottom-time { font-weight: 700; font-size: 1.5em; font-family: 'Consolas', 'Courier New', monospace; }
        #bottom-date { font-size: 1em; font-family: 'Consolas', 'Courier New', monospace; }
        .nav-buttons { position: static; height: auto; z-index: 1; }
        .nav-buttons button { border-radius: 5px; font-size: 1em; cursor: pointer; margin-left: 5px; width: 35px; height: 35px; line-height: 30px; text-align: center; }
        .form-container { position: absolute; top: 0; left: 0; width: 100%; z-index: 10; background-color: rgba(255, 255, 255, 0.95); color: #000; padding: 20px; box-shadow: 0 5px 10px rgba(0,0,0,0.2); text-align: center; border-radius: 0 0 5px 5px; }
        .ukryty { display: none !important; }
        .controls { margin-top: 15px; }
        
        body.light-theme { background-color: #333; color: #333; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        body.light-theme .screen-container { width: 1000px; height: 600px; background: #fff; box-shadow: 0 0 50px rgba(0,0,0,0.5); border-radius: 5px; }
        body.light-theme .left-panel { background-color: #f0f0f0; border-right: 2px solid #ddd; }
        body.light-theme .right-panel { background-color: #f0f0f0; color: #333; }
        body.light-theme .header-info { color: #333; border-bottom: 1px solid #ccc; }
        body.light-theme .sticky-station-display, body.light-theme .terminal-station-display { color: #000; border-color: #ccc; }
        body.light-theme .sticky-station-display::before, body.light-theme .station-list-container::before, body.light-theme .terminal-station-display::before { background-color: #6a0dad; }
        body.light-theme .station-item { color: #333; }
        body.light-theme .indicator { background: #aaa; border: 2px solid #f0f0f0; }
        body.light-theme .status-past { color: #aaa; }
        body.light-theme .indicator.status-past { background-color: #aaa; }
        body.light-theme .station-item.status-current, body.light-theme .sticky-station-display.status-current, body.light-theme .terminal-station-display.status-current { color: #6a0dad; font-weight: 700; background-color: transparent; }
        body.light-theme .indicator.status-current { background-color: #6a0dad; width: 12px; height: 12px; left: -1px; }
        body.light-theme .status-future { color: #333; }
        body.light-theme .indicator.status-future { background-color: #6a0dad; }
        body.light-theme .bottom-bar { background-color: #f0f0f0; border-top: 2px solid #ddd; color: #000; }
        body.light-theme .bottom-right { color: #000; }
        body.light-theme #bottom-date { color: #555; }

        body.dark-theme { background-color: #000; color: #fff; }
        body.dark-theme .screen-container { width: 100vw; height: 100vh; background: #000; box-shadow: none; border-radius: 0; }
        body.dark-theme .left-panel { background-color: #000; border-right: 2px solid #333; }
        body.dark-theme .right-panel { background-color: #00204A; color: #fff; }
        body.dark-theme .header-info { color: #fff; border-bottom: 1px solid #004a80; }
        body.dark-theme .sticky-station-display, body.dark-theme .terminal-station-display { color: #fff; border-color: #003366; }
        body.dark-theme .sticky-station-display::before, body.dark-theme .station-list-container::before, body.dark-theme .terminal-station-display::before { background-color: #FFD700; }
        body.dark-theme .station-item { color: #fff; }
        body.dark-theme .indicator { background: #88aacc; border: 2px solid #00204A; }
        body.dark-theme .status-past { color: #88aacc; }
        body.dark-theme .indicator.status-past { background-color: #88aacc; }
        body.dark-theme .station-item.status-current, body.dark-theme .sticky-station-display.status-current, body.dark-theme .terminal-station-display.status-current { color: #FFD700; font-weight: 700; background-color: rgba(255, 215, 0, 0.15); }
        body.dark-theme .indicator.status-current { background-color: #FFD700; width: 12px; height: 12px; left: -1px; border-color: #00204A; }
        body.dark-theme .status-future { color: #fff; }
        body.dark-theme .indicator.status-future { background-color: #fff; }
        body.dark-theme .bottom-bar { background-color: #222; border-top: 2px solid #444; color: #fff; }
        body.dark-theme .bottom-right { color: #fff; }
        body.dark-theme #bottom-date { color: #ccc; }
    </style>
</head>
<body class="<?php echo $theme; ?>-theme">

<div class="form-container <?php if ($formularz_ukryty) echo 'ukryty'; ?>">
        <form method="GET" action="">
            <label for="id_przejazdu"><strong>1. Wybierz pociąg:</strong></label><br>
            <select name="id_przejazdu" id="id_przejazdu" onchange="this.form.submit()">
                <option value="">-- Wybierz z listy --</option>
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

        <?php if ($id_przejazdu_wybranego && !empty($stacje_wyswietlane)): ?>
        <form method="GET" action="" style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 15px;">
            <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu_wybranego ?>">
            <label for="start_index"><strong>2. Rozpocznij od stacji:</strong></label><br>
            <select name="start_index" id="start_index">
                <?php foreach ($stacje_wyswietlane as $index => $stacja): ?>
                    <option value="<?= $index ?>"><?= ($index + 1) . ". " . $stacja['nazwa_stacji'] ?></option>
                <?php endforeach; ?>
            </select>
            <br><br>
            <button type="submit" class="start-btn">URUCHOM TABLICĘ</button>
        </form>
        <?php endif; ?>

        <div class="controls">
            <button type="button" onclick="toggleTheme()">Zmień motyw</button>
            <button onclick="document.querySelector('.form-container').classList.add('ukryty')">Ukryj panel sterowania</button>
        </div>
    </div>
    
    <?php if ($id_przejazdu_wybranego && !empty($stacje_wyswietlane)): ?>
    <div class="screen-container">
        <div class="content-area">
            <div class="left-panel"><video src="polregio.mp4" autoplay loop muted></video></div>
            <div class="right-panel">
                <div class="header-info"><?= htmlspecialchars($info_pociagu) ?> / Kierunek: <?= htmlspecialchars($kierunek) ?></div>
                <div id="sticky-station-view" class="sticky-station-display"></div>
                <div class="station-list-container"><ul id="station-list-view" class="station-list"></ul></div>
                <div id="sticky-terminal-view" class="terminal-station-display"></div>
            </div>
        </div>
        <div class="bottom-bar">
            <div class="bottom-left" id="bottom-left-content">
                 <div id="line1" class="led-line"></div>
                 <div id="line2" class="led-line led-line-green"></div>
            </div>
            <div class="bottom-right">
                <span id="bottom-time">--:--</span>
                <span id="bottom-date">--.--.----</span>
                <div class="nav-buttons">
                    <button id="btn-prev" onclick="navigateList('prev')">◀</button>
                    <button id="btn-next" onclick="navigateList('next')">▶</button>
                </div>
            </div>
        </div>
    </div>
    <audio id="announcement-audio" preload="auto"></audio>
    <?php elseif ($id_przejazdu_wybranego): ?>
        <div style="text-align: center; background: #000; padding: 20px; border-radius: 5px;">
            <h2>Brak danych o stacjach</h2>
            <a href="?" style="color: #fff;">Wróć do wyboru</a>
        </div>
    <?php endif; ?>

    <script>
        function saveAutoTime(id, type) {
            const formData = new FormData();
            formData.append('id_szczegolu', id);
            formData.append('typ', type);
            
            fetch('zapisz_czas_auto.php', { method: 'POST', body: formData })
                .then(r => r.text())
                .then(console.log)
                .catch(console.error);
        }
        function toggleTheme() {
            const body = document.body;
            let newTheme = 'dark';
            if (body.classList.contains('dark-theme')) {
                body.classList.remove('dark-theme'); body.classList.add('light-theme'); newTheme = 'light';
            } else {
                body.classList.remove('light-theme'); body.classList.add('dark-theme'); newTheme = 'dark';
            }
            document.cookie = "theme=" + newTheme + ";path=/;max-age=31536000";
        }

    if (typeof <?php echo json_encode($stacje_wyswietlane); ?> !== 'undefined' && <?php echo json_encode($stacje_wyswietlane); ?>.length > 0) {
        
        const visibleSchedule = <?php echo json_encode($stacje_wyswietlane); ?>;
        const destinationName = "<?php echo htmlspecialchars($kierunek); ?>";
        const trainInfoFull = "<?php echo htmlspecialchars($info_pociagu); ?>";
        const trainName = "<?php echo htmlspecialchars($nazwa_pociagu); ?>";
        const info_pociagu_led = "<?php echo $przejazd_info['typ_skrot'] . ' ' . $przejazd_info['numer_pociagu']; ?>"; 
        
        const totalStations = visibleSchedule.length;
        let currentVisibleIndex = <?php echo $start_index !== null ? $start_index : 0; ?>;
        let displayMode = 0; 
        let lastDepartureTime = null;
        
        // Flagi audio i synchronizacji
        let playDestinationInLoop = false; 
        let playDelayAnnouncement = false; 
        let calculatedDelayMinutes = 0;
        let isMaster = false; // Czy ta karta steruje?
        
        const stickyStation = document.getElementById('sticky-station-view');
        const stickyTerminal = document.getElementById('sticky-terminal-view'); 
        const stationList = document.getElementById('station-list-view');
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');
        const bottomTime = document.getElementById('bottom-time');
        const bottomDate = document.getElementById('bottom-date');
        const audioPlayer = document.getElementById('announcement-audio');
        const line1 = document.getElementById('line1');
        const line2 = document.getElementById('line2');

        const stationItemHeight = 34;
        let STATIONS_TO_SHOW_IN_LIST = 10;
        const listContainer = document.querySelector('.station-list-container');
        if (listContainer && listContainer.clientHeight > 0) {
             STATIONS_TO_SHOW_IN_LIST = Math.floor(listContainer.clientHeight / stationItemHeight) + 2;
        }
        const CURRENT_STATION_LIST_POSITION = 2; 

        // === SYNCHRONIZACJA ===
        const syncChannel = new BroadcastChannel('train_display_sync');

        syncChannel.onmessage = (event) => {
            const data = event.data;
            
            if (data.type === 'update_state') {
                // Jesteśmy "Slave" (odbiorcą)
                isMaster = false;
                currentVisibleIndex = data.index;
                displayMode = data.mode;
                lastDepartureTime = data.lastDepartureTime;
                
                playDelayAnnouncement = false;
                playDestinationInLoop = false;
                
                renderScreen(); // Odśwież, ale czekaj na trigger pętli
            }
            else if (data.type === 'trigger_loop') {
                // Sygnał do uruchomienia pętli (od Mastera)
                startInfoTicker();
            }
        };

        function sendSyncUpdate() {
            syncChannel.postMessage({
                type: 'update_state',
                index: currentVisibleIndex,
                mode: displayMode,
                lastDepartureTime: lastDepartureTime
            });
        }

        // --- Funkcje pomocnicze ---
        function formatTime(time) { return time ? time.substring(0, 5) : '--:--'; }
        
        function getStationTimes(station, separate = true) { 
            if (!separate) { 
                 if (station.przyjazd && station.odjazd && station.przyjazd.substring(0, 5) === station.odjazd.substring(0, 5)) { 
                    return 'p.' + station.przyjazd.substring(0, 5); 
                 }
                 let times = ''; 
                 if (station.przyjazd) times += 'p.' + station.przyjazd.substring(0, 5) + ' '; 
                 if (station.odjazd) times += 'o.' + station.odjazd.substring(0, 5); 
                 return times.trim();
            } else { 
                if (station.przyjazd && station.odjazd && station.przyjazd.substring(0, 5) === station.odjazd.substring(0, 5)) { 
                    return 'p.' + station.przyjazd.substring(0, 5); 
                } 
                let times = ''; 
                if (station.przyjazd) times += 'p.' + station.przyjazd.substring(0, 5) + '<br>'; 
                if (station.odjazd) times += 'o.' + station.odjazd.substring(0, 5); 
                if (!station.przyjazd && station.odjazd) times = 'o.' + station.odjazd.substring(0, 5);
                if (station.przyjazd && !station.odjazd) times = 'p.' + station.przyjazd.substring(0, 5);
                return times.trim(); 
            }
        }

        function calculateDelay() {
            if (!lastDepartureTime) return 0;
            const now = new Date();
            const [hours, minutes] = lastDepartureTime.substring(0, 5).split(':').map(Number);
            let scheduledDeparture = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hours, minutes, 0);
            const differenceMs = now.getTime() - scheduledDeparture.getTime();
            const delayMinutes = Math.floor(differenceMs / (1000 * 60));
            return Math.max(0, delayMinutes); 
        }

        function updateClock() {
            const now = new Date();
            const weekDays = ['nd.', 'pn.', 'wt.', 'śr.', 'cz.', 'pt.', 'sb.'];
            const timeStr = now.toTimeString().split(' ')[0].substring(0, 5);
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear().toString().substring(2);
            const dateStr = `${weekDays[now.getDay()]} ${day}.${month}.${year}`;
            if (bottomTime) bottomTime.textContent = timeStr;
            if (bottomDate) bottomDate.textContent = dateStr;
        }

        // --- Audio ---
        let audioPlaylist = [];
        let currentAudioIndex = 0;
        
        function getFileName(stationName) {
            let name = stationName.replace(/ł/g, 'l').replace(/Ł/g, 'L')
                                    .replace(/[ąćęłńóśźż]/g, c => ({'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z'}[c]))
                                    .replace(/[\. ]/g, '_');
            return name;
        }
        
        function playSequential(files, callback = null) {
            audioPlayer.pause();
            audioPlayer.currentTime = 0;
            audioPlayer.onended = null;
            audioPlaylist = files;
            currentAudioIndex = 0;
            if (audioPlaylist.length === 0) { if(callback) callback(); return; }

            audioPlayer.onended = () => {
                currentAudioIndex++;
                if (currentAudioIndex < audioPlaylist.length) {
                    audioPlayer.src = audioPlaylist[currentAudioIndex];
                    audioPlayer.play().catch(e => console.log("Audio err:", e));
                } else {
                    audioPlayer.onended = null;
                    audioPlaylist = [];
                    if (callback) callback();
                }
            };
            audioPlayer.src = audioPlaylist[0];
            audioPlayer.play().catch(e => console.log("Audio start err:", e));
        }

        function playAnnouncement(stationName, mode) {
            const prefix = mode === 0 ? 'n_' : 's_';
            const fileName = getFileName(stationName);
            const fullPath = `dzwiek/${prefix}${fileName}.mp3`;
            
            // Jeśli to "następna stacja" (mode 0), to po zakończeniu odpal pętlę I wyślij sygnał
            const callback = (mode === 0) ? () => {
                startInfoTicker();
                syncChannel.postMessage({ type: 'trigger_loop' });
            } : null;
            
            playSequential([fullPath], callback);
        }
        
        function playDestinationAnnouncement() {
            const destinationFileName = getFileName(destinationName); 
            playSequential(['dzwiek/stacja_koncowa1.mp3', `dzwiek/s_${destinationFileName}.mp3`]);
        }

        // --- LOGIKA PASKA DOLNEGO ---
        let infoLoopTimeout = null;

        function displayText(element, text, maxChars = 25) { 
            element.innerHTML = ''; 
            if (text.length <= maxChars) { 
                element.textContent = text; 
                return; 
            } 
            const wrapper = document.createElement('div');
            wrapper.className = 'ticker-wrapper'; 
            const scrollingSpan = document.createElement('span');
            scrollingSpan.className = 'scrolling-text';
            scrollingSpan.textContent = text; 
            const duration = Math.max(8, text.length * 0.2); 
            scrollingSpan.style.animation = `marquee ${duration}s linear infinite`; 
            wrapper.appendChild(scrollingSpan);
            element.appendChild(wrapper);
        }

        function startInfoTicker() {
            clearTimeout(infoLoopTimeout);
            
            let loopState = 0;
            const weekDaysFull = ['NIEDZIELA', 'PONIEDZIAŁEK', 'WTOREK', 'ŚRODA', 'CZWARTEK', 'PIĄTEK', 'SOBOTA']; 
            const months = ['STYCZNIA', 'LUTEGO', 'MARCA', 'KWIETNIA', 'MAJA', 'CZERWCA', 'LIPCA', 'SIERPNIA', 'WRZEŚNIA', 'PAŹDZIERNIKA', 'LISTOPADA', 'GRUDNIA']; 
            
            function loopStep() {
                clearTimeout(infoLoopTimeout);
                if (displayMode !== 0) return; 
                
                line1.innerHTML = ''; 
                line2.innerHTML = ''; 
                let nextStepDelay = 7500;
                
                switch(loopState) {
                    case 0: // Pociąg i Koniec
                         const lastStation = visibleSchedule[totalStations - 1];
                         const arrivalTime = lastStation && lastStation.przyjazd ? lastStation.przyjazd.substring(0, 5) : '??:??';
                         displayText(line1, 'POCIĄG ' + info_pociagu_led + ' ' + trainName.toUpperCase()); 
                         displayText(line2, 'STACJA KOŃCOWA: ' + destinationName.toUpperCase() + ' p.' + arrivalTime); 
                         if (playDestinationInLoop && isMaster) { // Graj tylko jeśli master
                             playDestinationAnnouncement();
                             playDestinationInLoop = false;
                             nextStepDelay = 5000;
                         } else {
                             nextStepDelay = 3750;
                         }
                         loopState = 1;
                         break;
                    case 1: // Opóźnienie
                        if (calculatedDelayMinutes > 4) {
                            displayText(line1, 'OPÓŹNIENIE POCIĄGU:'); 
                            displayText(line2, `${calculatedDelayMinutes} MINUT.`);
                            if (playDelayAnnouncement && isMaster) {
                                playSequential(['dzwiek/opoznienie_pociagu.mp3', `dzwiek/${calculatedDelayMinutes}.mp3`]);
                                playDelayAnnouncement = false;
                            }
                            nextStepDelay = 5000;
                            loopState = 2;
                        } else {
                            loopState = 2;
                            nextStepDelay = 10;
                        }
                        break;
                    case 2: // Trasa
                        const remainingStations = visibleSchedule.slice(currentVisibleIndex);
                        if (remainingStations.length <= 1) {
                            loopState = 3; nextStepDelay = 10;
                        } else {
                            displayText(line1, 'TRASA:');
                            const routeString = remainingStations.map(s => {
                                let stStr = `${s.nazwa_stacji.toUpperCase()} ${getStationTimes(s, false)}`;
                                const initialDelay = calculatedDelayMinutes;
                                let correctedDelay = initialDelay;
                                const passed = remainingStations.indexOf(s);
                                if (passed > 0) correctedDelay = Math.max(0, initialDelay - (passed * 0.25));
                                if (Math.ceil(correctedDelay) > 0) stStr += ` (+${Math.ceil(correctedDelay)})`;
                                return stStr;
                            }).join('  -  ');

                            const wrapper = document.createElement('div'); wrapper.className = 'ticker-wrapper'; 
                            const span = document.createElement('span'); span.className = 'scrolling-text'; 
                            const dur = Math.max(15, routeString.length * 0.25);
                            span.textContent = routeString; span.style.animation = `marquee ${dur}s linear infinite`; 
                            wrapper.appendChild(span); line2.appendChild(wrapper); 
                            nextStepDelay = (dur * 1000) + 2000;
                            loopState = 3;
                        }
                        break;
                    case 3: // Data
                        const now = new Date();
                        const dateStr = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`; 
                        const timeStr = now.toTimeString().split(' ')[0].substring(0, 8); 
                        displayText(line1, dateStr); displayText(line2, `${weekDaysFull[now.getDay()]} ${timeStr}`);
                        loopState = 0;
                        break;
                }
                infoLoopTimeout = setTimeout(loopStep, nextStepDelay);
            }
            loopStep();
        }

        function updateBottomBar() {
            clearTimeout(infoLoopTimeout);
            line1.innerHTML = ''; 
            line2.innerHTML = '';
            
            const station = visibleSchedule[currentVisibleIndex];
            const stationName = station.nazwa_stacji.toUpperCase();
            const stationTimes = getStationTimes(station, false);
            
            if (displayMode === 0) { // Następna stacja
                calculatedDelayMinutes = calculateDelay();
                displayText(line1, 'NASTĘPNA STACJA:');
                displayText(line2, stationName + ' ' + stationTimes);
                
                if (isMaster) {
                    // Jeśli sterujemy: graj audio. Po audio uruchom pętlę i wyślij trigger.
                    playAnnouncement(station.nazwa_stacji, 0);
                } else {
                    // Jeśli odbieramy: czekamy na trigger.
                    // (Opcjonalnie: fallback timer, gdyby trigger nie dotarł)
                }
            } else { // Stacja
                calculatedDelayMinutes = 0;
                displayText(line1, 'STACJA:');
                displayText(line2, stationName + ' ' + stationTimes);
                if (isMaster) playAnnouncement(station.nazwa_stacji, 1);
            }
        }

        // === RENDERUJ EKRAN ===
        function renderScreen() {
            const currentIdx = currentVisibleIndex;
            const mode = displayMode;
            
            if (!visibleSchedule[currentIdx]) return;

            // 1. Sticky Stations
            const firstStationData = visibleSchedule[0];
            const firstStatusClass = (currentIdx === 0) ? 'status-current' : 'status-past';
            stickyStation.className = `sticky-station-display ${firstStatusClass}`;
            stickyStation.innerHTML = `<span class="indicator ${firstStatusClass}"></span><span class="station-name">${firstStationData.nazwa_stacji.toUpperCase()}</span><span class="station-time">${getStationTimes(firstStationData)}</span>`;

            const lastStationData = visibleSchedule[totalStations - 1];
            const lastStatus = (currentIdx === totalStations - 1) ? 'current' : (currentIdx > totalStations - 1 ? 'past' : 'future');
            stickyTerminal.className = `terminal-station-display status-${lastStatus}`;
            stickyTerminal.innerHTML = `<span class="indicator status-${lastStatus}"></span><span class="station-name">${lastStationData.nazwa_stacji.toUpperCase()}</span><span class="station-time">${getStationTimes(lastStationData)}</span>`;

            // 2. Lista
            stationList.innerHTML = '';
            let startDisplayIndex = Math.max(1, currentIdx - 1); 
            let endDisplayIndex = totalStations - 1; 
            const numVisible = STATIONS_TO_SHOW_IN_LIST - 4;
            
            if (currentIdx > CURRENT_STATION_LIST_POSITION) {
                 startDisplayIndex = Math.max(1, currentIdx - CURRENT_STATION_LIST_POSITION);
                 endDisplayIndex = Math.min(totalStations - 1, startDisplayIndex + numVisible);
            } else {
                startDisplayIndex = 1;
                endDisplayIndex = Math.min(totalStations - 1, startDisplayIndex + numVisible);
            }
            if (endDisplayIndex - startDisplayIndex < numVisible && totalStations > 2) {
                startDisplayIndex = Math.max(1, endDisplayIndex - numVisible);
            }

            for (let i = startDisplayIndex; i < endDisplayIndex; i++) {
                const station = visibleSchedule[i];
                const item = document.createElement('li');
                let statusClass = (i < currentIdx) ? 'status-past' : ((i === currentIdx) ? 'status-current' : 'status-future');
                item.className = `station-item ${statusClass}`;
                item.innerHTML = `<span class="indicator ${statusClass}"></span><span class="station-name">${station.nazwa_stacji.toUpperCase()}</span><span class="station-time">${getStationTimes(station)}</span>`;
                stationList.appendChild(item);
            }

            // 3. Dolny Pasek
            updateBottomBar();

            // 4. Przyciski
            btnPrev.disabled = (currentIdx === 0 && mode === 0);
            btnNext.disabled = (currentIdx === totalStations - 1 && mode === 1);
        }
        
        function navigateList(direction) {
            clearTimeout(infoLoopTimeout); 
            isMaster = true; // Kliknęliśmy, więc jesteśmy Masterem
            
            if (direction === 'next') {
                if (displayMode === 1) { 
                    saveAutoTime(visibleSchedule[currentVisibleIndex].id_szczegolu, 'odjazd');
                    if (currentVisibleIndex < totalStations - 1) {
                        lastDepartureTime = visibleSchedule[currentVisibleIndex].odjazd; 
                        currentVisibleIndex++; 
                        displayMode = 0; 
                        playDelayAnnouncement = true;
                        playDestinationInLoop = true;
                    }
                } else { 
                    displayMode = 1; 
                    saveAutoTime(visibleSchedule[currentVisibleIndex].id_szczegolu, 'przyjazd');
                }
            } else if (direction === 'prev') {
                if (displayMode === 0) { 
                    if (currentVisibleIndex > 0) {
                        currentVisibleIndex--; 
                        lastDepartureTime = (currentVisibleIndex > 0) ? visibleSchedule[currentVisibleIndex - 1].odjazd : null;
                        displayMode = 1; 
                        playDelayAnnouncement = true; 
                        playDestinationInLoop = true;
                    }
                } else { 
                    displayMode = 0; 
                }
            }
            sendSyncUpdate();
            renderScreen();
        }
        
        if (totalStations > 0) {
            lastDepartureTime = (currentVisibleIndex > 0) ? visibleSchedule[currentVisibleIndex - 1].odjazd : null;
            if (displayMode === 0) {
                playDestinationInLoop = true;
                playDelayAnnouncement = true; 
            }
            // Na starcie zakładamy, że ta karta to Master (żeby coś grało po odświeżeniu)
            // Chyba że przyjdzie sync.
            isMaster = true; 
            renderScreen(); 
            setInterval(updateClock, 1000);
            updateClock();
        }
    }
    </script>
</body>
</html>