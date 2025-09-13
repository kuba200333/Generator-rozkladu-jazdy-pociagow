<?php
require 'db_config.php';
$id_trasy = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$nazwa_trasy = '';
$stacje_na_trasie_ids = [];

if ($id_trasy > 0) {
    // Tryb edycji
    $stmt = mysqli_prepare($conn, "SELECT nazwa_trasy FROM trasy WHERE id_trasy = ?");
    mysqli_stmt_bind_param($stmt, "i", $id_trasy);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($trasa = mysqli_fetch_assoc($result)) {
        $nazwa_trasy = $trasa['nazwa_trasy'];
    }

    $stmt = mysqli_prepare($conn, "SELECT id_stacji FROM stacje_na_trasie WHERE id_trasy = ? ORDER BY kolejnosc");
    mysqli_stmt_bind_param($stmt, "i", $id_trasy);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while($row = mysqli_fetch_assoc($result)) {
        $stacje_na_trasie_ids[] = $row['id_stacji'];
    }
}

$wszystkie_stacje_res = mysqli_query($conn, "SELECT id_stacji, nazwa_stacji FROM stacje ORDER BY nazwa_stacji");
$wszystkie_stacje = mysqli_fetch_all($wszystkie_stacje_res, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title><?= $id_trasy ? 'Edytor' : 'Kreator' ?> Tras</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .editor-container { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-top:20px; }
        .station-list { width: 300px; height: 400px; border: 1px solid #ccc; padding: 5px; }
        .controls { display: flex; flex-direction: column; gap: 10px; }
        .controls button { padding: 8px; width: 50px; }
        .form-section { margin-bottom: 20px; }
    </style>
</head>
<body>
    <a href="kreator_tras.php">Powrót do listy tras</a>
    <h1><?= $id_trasy ? 'Edycja Trasy' : 'Tworzenie Nowej Trasy' ?></h1>
    
    <form action="zapisz_trase.php" method="POST" onsubmit="selectAllStations()">
        <input type="hidden" name="id_trasy" value="<?= $id_trasy ?>">
        
        <div class="form-section">
            <label for="nazwa_trasy"><b>Nazwa trasy:</b></label><br>
            <input type="text" id="nazwa_trasy" name="nazwa_trasy" value="<?= htmlspecialchars($nazwa_trasy) ?>" required size="60">
        </div>

        <?php if (!$id_trasy): ?>
        <div class="form-section">
            <label for="szablon_trasy"><b>Użyj jako szablonu (opcjonalne):</b></label><br>
            <select id="szablon_trasy" onchange="loadTemplate()">
                <option value="">-- Wybierz trasę jako szablon --</option>
                <?php
                $szablony_res = mysqli_query($conn, "SELECT id_trasy, nazwa_trasy FROM trasy ORDER BY nazwa_trasy");
                while($szablon = mysqli_fetch_assoc($szablony_res)) {
                    echo "<option value='{$szablon['id_trasy']}'>{$szablon['nazwa_trasy']}</option>";
                }
                ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="editor-container">
            <div>
                <h3>Wszystkie dostępne stacje</h3>
                <select id="stacje_dostepne" multiple class="station-list">
                    <?php foreach($wszystkie_stacje as $stacja): if (!in_array($stacja['id_stacji'], $stacje_na_trasie_ids)): ?>
                        <option value="<?= $stacja['id_stacji'] ?>"><?= htmlspecialchars($stacja['nazwa_stacji']) ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </div>
            <div class="controls">
                <button type="button" onclick="addStations()">&gt;</button>
                <button type="button" onclick="removeStations()">&lt;</button>
            </div>
            <div>
                <h3>Stacje na tej trasie (w kolejności)</h3>
                <select id="stacje_na_trasie" name="stacje[]" multiple class="station-list">
                     <?php foreach($stacje_na_trasie_ids as $id_stacji): ?>
                        <?php foreach($wszystkie_stacje as $stacja): if ($stacja['id_stacji'] == $id_stacji): ?>
                            <option value="<?= $stacja['id_stacji'] ?>"><?= htmlspecialchars($stacja['nazwa_stacji']) ?></option>
                        <?php break; endif; endforeach; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="controls">
                <button type="button" onclick="moveUp()">Góra</button>
                <button type="button" onclick="moveDown()">Dół</button>
            </div>
        </div>

        <br>
        <button type="submit">Zapisz trasę</button>
    </form>

    <script>
        const dostepne = document.getElementById('stacje_dostepne');
        const naTrasie = document.getElementById('stacje_na_trasie');
        const szablon = document.getElementById('szablon_trasy');

        function moveOptions(fromSelect, toSelect) {
            Array.from(fromSelect.selectedOptions).forEach(option => {
                toSelect.appendChild(option);
            });
        }
        function addStations() { moveOptions(dostepne, naTrasie); }
        function removeStations() { moveOptions(naTrasie, dostepne); }

        function move(direction) {
            const selected = Array.from(naTrasie.selectedOptions);
            if (direction === 'up') {
                selected.forEach(option => {
                    if (option.previousElementSibling) {
                        naTrasie.insertBefore(option, option.previousElementSibling);
                    }
                });
            } else if (direction === 'down') {
                selected.reverse().forEach(option => {
                    if (option.nextElementSibling) {
                        naTrasie.insertBefore(option.nextElementSibling, option);
                    }
                });
            }
        }
        function moveUp() { move('up'); }
        function moveDown() { move('down'); }

        function selectAllStations() {
            Array.from(naTrasie.options).forEach(option => option.selected = true);
        }
        
        async function loadTemplate() {
            const id = szablon.value;
            if (!id) return;
            
            const response = await fetch(`api_get_route_stations.php?id=${id}`);
            const data = await response.json();
            
            dostepne.innerHTML = '';
            naTrasie.innerHTML = '';
            
            const stacjeNaTrasieSzablonuIds = data.map(s => parseInt(s.id_stacji));
            const wszystkieStacje = <?php echo json_encode($wszystkie_stacje); ?>;

            wszystkieStacje.forEach(stacja => {
                const option = new Option(stacja.nazwa_stacji, stacja.id_stacji);
                if (!stacjeNaTrasieSzablonuIds.includes(parseInt(stacja.id_stacji))) {
                    dostepne.appendChild(option);
                }
            });

            data.forEach(stacja => {
                 const option = new Option(stacja.nazwa_stacji, stacja.id_stacji);
                 naTrasie.appendChild(option);
            });
        }
    </script>
</body>
</html>