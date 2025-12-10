<?php
require 'db_config.php';
$id_przejazdu_wybranego = isset($_GET['id_przejazdu']) ? (int)$_GET['id_przejazdu'] : null;
// Pobieramy start_index, jeśli został wysłany
$start_index = isset($_GET['start_index']) ? (int)$_GET['start_index'] : null;

$stacje_list = [];
$info_pociagu = '';
$nazwa_pociagu = '';
$kierunek = '';

// Formularz ukrywamy TYLKO wtedy, gdy wybrano pociąg ORAZ wybrano stację startową
$formularz_ukryty = ($id_przejazdu_wybranego && $start_index !== null);

if ($id_przejazdu_wybranego) {
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
    
    $sql_koncowa = "SELECT s.nazwa_stacji FROM trasy t JOIN stacje s ON t.id_stacji_koncowej = s.id_stacji WHERE t.id_trasy = (SELECT id_trasy FROM przejazdy WHERE id_przejazdu = ?)";
    $stmt_koncowa = mysqli_prepare($conn, $sql_koncowa);
    mysqli_stmt_bind_param($stmt_koncowa, "i", $id_przejazdu_wybranego);
    mysqli_stmt_execute($stmt_koncowa);
    $koncowa_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_koncowa));
    $kierunek = $koncowa_info['nazwa_stacji'];

    $sql_szczegoly = "SELECT sr.id_szczegolu, s.nazwa_stacji, sr.przyjazd, sr.odjazd, sr.uwagi_postoju 
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
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body { background-color: #333; overflow: hidden; display: flex; justify-content: center; align-items: center; flex-direction: column; }
        .form-container { background-color: #d4edda; padding: 25px; border-radius: 8px; margin-bottom: 20px; width: 600px; box-sizing: border-box; text-align: center; }
        .ukryty { display: none; }
        .display-wrapper { display: flex; align-items: center; justify-content: center; gap: 15px; padding: 20px; }
        .nav-button { background: transparent; border: none; color: #D4452D; font-size: 2.5em; cursor: pointer; padding: 10px; opacity: 0.2; transition: opacity 0.3s ease, transform 0.2s ease; }
        .display-wrapper:hover .nav-button { opacity: 1; }
        .nav-button:hover { transform: scale(1.1); }
        .led-display { background-color: #2a1a00; border: 5px solid #4a3a20; border-radius: 10px; padding: 20px; font-family: 'DotGothic16', sans-serif; color: #D4452D; text-shadow: 0 0 5px rgba(212, 69, 45, 0.7), 0 0 8px rgba(212, 69, 45, 0.5); font-size: 28px; line-height: 1.5; text-transform: uppercase; flex-shrink: 0; width: 450px; }
        .led-line-green { color: #fae92eff !important; text-shadow: 0 0 5px rgba(253, 175, 116, 0.8), 0 0 10px rgba(253, 175, 116, 0.6); }
        .led-line { min-height: 42px; overflow: hidden; white-space: nowrap; display: flex; align-items: center; justify-content: flex-start; }
        .ticker-wrapper { flex-grow: 1; overflow: hidden; min-width: 0; }
        .scrolling-text { display: inline-block; padding-left: 100%; animation: marquee 15s linear infinite; } 
        @keyframes marquee { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
    </style>
</head>
<body>
    
    <div class="form-container <?php if ($formularz_ukryty) echo 'ukryty'; ?>">
        <form method="GET" action="">
            <label for="id_przejazdu"><strong>1. Wybierz pociąg:</strong></label>
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

        <?php if ($id_przejazdu_wybranego && !empty($stacje_list)): ?>
        <form method="GET" action="" style="margin-top: 15px; border-top: 1px solid #ccc; padding-top: 15px;">
            <input type="hidden" name="id_przejazdu" value="<?= $id_przejazdu_wybranego ?>">
            <label for="start_index"><strong>2. Rozpocznij od stacji:</strong></label><br>
            <select name="start_index" id="start_index">
                <?php foreach ($stacje_list as $index => $stacja): ?>
                    <option value="<?= $index ?>"><?= ($index + 1) . ". " . $stacja['nazwa_stacji'] ?></option>
                <?php endforeach; ?>
            </select>
            <br>
            <button type="submit" class="start-btn">URUCHOM WYŚWIETLACZ</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($id_przejazdu_wybranego): ?>
        <div class="display-wrapper">
            <button class="nav-button" onclick="navigate('prev')">◀</button>
            <div class="led-display">
                <div id="line1" class="led-line"></div>
                <div id="line2" class="led-line led-line-green"></div>
            </div>
            <button class="nav-button" onclick="navigate('next')">▶</button>
        </div>
        <audio id="announcement-audio" preload="auto"></audio>
    <?php endif; ?>

<script>
      function saveAutoTime(id, type) {
          const formData = new FormData();
          formData.append('id_szczegolu', id);
          formData.append('typ', type);
          fetch('zapisz_czas_auto.php', { method: 'POST', body: formData });
      }
      const schedule = <?php echo json_encode($stacje_list); ?>;
      const trainInfo = "<?php echo $info_pociagu; ?>";
      const trainName = "<?php echo $nazwa_pociagu; ?>";
      const destination = "<?php echo $kierunek; ?>";
      const line1 = document.getElementById('line1');
      const line2 = document.getElementById('line2');
      const audioPlayer = document.getElementById('announcement-audio');
      
      let currentIndex = <?php echo $start_index !== null ? $start_index : 0; ?>;
      let displayMode = 0;
      let infoLoopTimeout = null;
      let textDisplayInterval = null;
      let audioPlaylist = [];
      let currentAudioIndex = 0;
      let playDestinationInLoop = false; 
      let calculatedDelayMinutes = 0; 
      let playDelayAnnouncement = false; 
      let lastDepartureTime = null; 
      let isMaster = false; // Czy ta karta steruje?

      // === SYNCHRONIZACJA ===
      const syncChannel = new BroadcastChannel('train_display_sync');

      syncChannel.onmessage = (event) => {
        const data = event.data;
        if (data.type === 'update_state') {
            // Jesteśmy Slave
            isMaster = false;
            currentIndex = data.index;
            displayMode = data.mode;
            lastDepartureTime = data.lastDepartureTime;

            playDelayAnnouncement = false; 
            playDestinationInLoop = false; 
            
            updateDisplay(); // Odśwież, ale czekaj na trigger
        }
        else if (data.type === 'trigger_loop') {
            startInfoTicker();
        }
      };

      function sendSyncUpdate() {
        syncChannel.postMessage({
            type: 'update_state',
            index: currentIndex,
            mode: displayMode,
            lastDepartureTime: lastDepartureTime
        });
      }

      function getFileName(stationName) {
        let name = stationName.replace(/ł/g, 'l').replace(/Ł/g, 'L')
                                .replace(/[ąćęłńóśźż]/g, c => ({'ą':'a','ć':'c','ę':'e','ł':'l','ń':'n','ó':'o','ś':'s','ź':'z','ż':'z'}[c]))
                                .replace(/[\. ]/g, '_');
        return name;
      }
      
      function playSequential(files, onFinishedCallback = null) {
          audioPlayer.pause();
          audioPlayer.currentTime = 0;
          audioPlayer.onended = null;
          audioPlayer.muted = false;
          audioPlaylist = files;
          currentAudioIndex = 0;

          if (audioPlaylist.length === 0) {
              if (onFinishedCallback) onFinishedCallback();
              return;
          }

          const normalOnEnd = () => {
              currentAudioIndex++;
              if (currentAudioIndex < audioPlaylist.length) {
                  audioPlayer.src = audioPlaylist[currentAudioIndex];
                  audioPlayer.play().catch(e => console.log("Błąd odtwarzania:", e));
              } else {
                  audioPlayer.onended = null;
                  audioPlaylist = [];
                  if (onFinishedCallback) onFinishedCallback();
              }
          };

          const firstFile = audioPlaylist[currentAudioIndex];
          audioPlayer.src = firstFile;
          audioPlayer.muted = false;
          audioPlayer.onended = normalOnEnd;
          audioPlayer.play().catch(e => console.log("Play error:", e));
      }
      
      function playAnnouncement(stationName, mode) {
          const prefix = mode === 0 ? 'n_' : 's_';
          const fileName = getFileName(stationName);
          const fullPath = `dzwiek/${prefix}${fileName}.mp3`;
          
          const callback = (mode === 0) ? () => {
              startInfoTicker();
              syncChannel.postMessage({ type: 'trigger_loop' });
          } : null;
          
          playSequential([fullPath], callback);
      }
      
      function playDestinationAnnouncement() {
          const destinationFileName = getFileName(destination);
          const filesToPlay = ['dzwiek/stacja_koncowa1.mp3', `dzwiek/s_${destinationFileName}.mp3`];
          playSequential(filesToPlay);
      }
      
      function getStationTimes(station) { 
        if (station.przyjazd && station.odjazd && station.przyjazd.substring(0, 5) === station.odjazd.substring(0, 5)) { 
          return 'p.' + station.przyjazd.substring(0, 5); 
        } 
        let times = ''; 
        if (station.przyjazd) times += 'p.' + station.przyjazd.substring(0, 5) + ' '; 
        if (station.odjazd) times += 'o.' + station.odjazd.substring(0, 5); 
        return times.trim(); 
      }
      
      function displayText(element, text, maxChars = 25) { 
        clearInterval(textDisplayInterval); 
        element.innerHTML = ''; 
        if (text.length <= maxChars) { 
          element.textContent = text; 
          return; 
        } 
        const wrapper = document.createElement('div'); wrapper.className = 'ticker-wrapper'; 
        const scrollingSpan = document.createElement('span'); scrollingSpan.className = 'scrolling-text';
        scrollingSpan.textContent = text; 
        const duration = Math.max(8, text.length * 0.2); 
        scrollingSpan.style.animation = `marquee ${duration}s linear infinite`; 
        wrapper.appendChild(scrollingSpan); element.appendChild(wrapper);
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
      
      function updateDisplay() { 
        if (!schedule || schedule.length === 0) return; 
        
        clearTimeout(infoLoopTimeout);
        clearInterval(textDisplayInterval);
        
        line1.innerHTML = ''; 
        line2.innerHTML = ''; 
        
        const station = schedule[currentIndex]; 
        const stationName = station.nazwa_stacji.toUpperCase(); 
        const stationTimes = getStationTimes(station); 
        
        if (displayMode === 0) { 
          calculatedDelayMinutes = calculateDelay();
          displayText(line1, 'NASTĘPNA STACJA:'); 
          displayText(line2, stationName + ' ' + stationTimes); 
          
          if (isMaster) {
             playAnnouncement(station.nazwa_stacji, 0);
             // Trigger wyśle playAnnouncement po zakończeniu
          } else {
             // Slave: czeka na trigger
          }

        } else { 
          calculatedDelayMinutes = 0;
          displayText(line1, 'STACJA:'); 
          displayText(line2, stationName + ' ' + stationTimes); 
          
          if (isMaster) playAnnouncement(station.nazwa_stacji, 1);
          clearTimeout(infoLoopTimeout); 
          audioPlayer.onended = null;
        } 
      }
      
      function startInfoTicker() { 
        audioPlayer.pause(); audioPlayer.currentTime = 0; audioPlayer.onended = null;
        clearTimeout(infoLoopTimeout); clearInterval(textDisplayInterval); 
        
        let loopState = 0; 
        const weekDays = ['NIEDZIELA', 'PONIEDZIAŁEK', 'WTOREK', 'ŚRODA', 'CZWARTEK', 'PIĄTEK', 'SOBOTA']; 
        const months = ['STYCZNIA', 'LUTEGO', 'MARCA', 'KWIETNIA', 'MAJA', 'CZERWCA', 'LIPCA', 'SIERPNIA', 'WRZEŚNIA', 'PAŹDZIERNIKA', 'LISTOPADA', 'GRUDNIA']; 
        
        function loopStep() { 
          clearTimeout(infoLoopTimeout); clearInterval(textDisplayInterval); 
          line1.innerHTML = ''; line2.innerHTML = ''; 
          let nextStepDelay = 7500; 
          
          switch(loopState) { 
            case 0: 
              const lastStation = schedule[schedule.length - 1];
              const arrivalTime = lastStation && lastStation.przyjazd ? lastStation.przyjazd.substring(0, 5) : '??:??';
              displayText(line1, 'POCIĄG ' + trainInfo + ' ' + trainName.toUpperCase()); 
              displayText(line2, 'STACJA KOŃCOWA: ' + destination.toUpperCase() + ' p.' + arrivalTime); 
              if (playDestinationInLoop && isMaster) {
                  playDestinationAnnouncement(); playDestinationInLoop = false; nextStepDelay = 5000;
              } else { nextStepDelay = 3750; }
              loopState = 1; 
              break; 
            case 1:
              if (calculatedDelayMinutes > 4) {
                  displayText(line1, 'OPÓŹNIENIE POCIĄGU:'); displayText(line2, `${calculatedDelayMinutes} MINUT.`);
                  if (playDelayAnnouncement && isMaster) { 
                      playSequential(['dzwiek/opoznienie_pociagu.mp3', `dzwiek/${calculatedDelayMinutes}.mp3`]); playDelayAnnouncement = false;
                  }
                  nextStepDelay = 5000; loopState = 2; 
              } else { loopState = 2; nextStepDelay = 10; }
              break;
            case 2: 
              const remainingStations = schedule.slice(currentIndex); 
              if (remainingStations.length <= 1) { loopState = 3; nextStepDelay = 10;
              } else { 
                displayText(line1, 'TRASA:'); 
                const routeString = remainingStations.map(s => {
                    let stStr = `${s.nazwa_stacji.toUpperCase()} ${getStationTimes(s)}`;
                    const initialDelay = calculatedDelayMinutes;
                    let correctedDelay = initialDelay;
                    const passed = remainingStations.indexOf(s); 
                    if (passed > 0) correctedDelay = Math.max(0, initialDelay - (passed * 0.25));
                    if (Math.ceil(correctedDelay) > 0) stStr += ` (+${Math.ceil(correctedDelay)})`;
                    return stStr;
                }).join('  -  ');
                const wrapper = document.createElement('div'); wrapper.className = 'ticker-wrapper'; 
                const scrollingSpan = document.createElement('span'); scrollingSpan.className = 'scrolling-text'; 
                const duration = Math.max(15, routeString.length * 0.25);
                scrollingSpan.textContent = routeString; scrollingSpan.style.animation = `marquee ${duration}s linear infinite`; 
                wrapper.appendChild(scrollingSpan); line2.appendChild(wrapper); 
                nextStepDelay = (duration * 1000) + 2000; loopState = 3; 
              } 
              break; 
            case 3: 
              const now = new Date(); 
              const dateStr = `${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()}`; 
              const timeStr = now.toTimeString().split(' ')[0].substring(0, 8); 
              displayText(line1, dateStr); displayText(line2, `${weekDays[now.getDay()]} ${timeStr}`); 
              loopState = 0; 
              break; 
          } 
          infoLoopTimeout = setTimeout(loopStep, nextStepDelay); 
        } 
        loopStep();
      }
      
      function navigate(direction) { 
        isMaster = true; // Kliknięcie = stajemy się Masterem

        audioPlayer.pause(); audioPlayer.currentTime = 0; audioPlayer.onended = null;
        
        if (direction === 'next') { 
          if (displayMode === 1) { 
            saveAutoTime(schedule[currentIndex].id_szczegolu, 'odjazd');
            if (currentIndex < schedule.length - 1) { 
              lastDepartureTime = schedule[currentIndex].odjazd;
              currentIndex++; displayMode = 0; 
              playDelayAnnouncement = true; playDestinationInLoop = true;
            }
          } else { 
            displayMode = 1; 
            saveAutoTime(schedule[currentIndex].id_szczegolu, 'przyjazd');
          } 
        } else if (direction === 'prev') { 
          if (displayMode === 0) { 
            if (currentIndex > 0) { 
              lastDepartureTime = schedule[currentIndex - 1].odjazd; 
              currentIndex--; displayMode = 1; 
              playDelayAnnouncement = true; playDestinationInLoop = true; 
            }
          } else { displayMode = 0; } 
        }
        
        if (currentIndex === 0) lastDepartureTime = null;
        
        sendSyncUpdate();
        updateDisplay(); 
      }
      
      if (schedule.length > 0) { 
        isMaster = true; // Start jako Master, dopóki nie dostaniemy sync
        if (displayMode === 0) {
             if (currentIndex > 0) lastDepartureTime = schedule[currentIndex - 1].odjazd;
             playDestinationInLoop = true; playDelayAnnouncement = true; 
        }
        updateDisplay(); 
      }
    </script>
</body>
</html>