<?php
session_start();
require 'db_config.php';

// Upewniamy się, że tabele istnieją
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS nazwy_pociagow (id INT AUTO_INCREMENT PRIMARY KEY, nazwa VARCHAR(100) NOT NULL UNIQUE)");
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS szablony_postojow (id INT AUTO_INCREMENT PRIMARY KEY, id_trasy INT NOT NULL, nazwa_szablonu VARCHAR(100) NOT NULL, typy TEXT, czasy TEXT)");

// Obsługa dodawania nazwy
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['dodaj_nazwe'])) {
    $nazwa = mysqli_real_escape_string($conn, trim($_POST['nowa_nazwa']));
    if (!empty($nazwa)) {
        mysqli_query($conn, "INSERT IGNORE INTO nazwy_pociagow (nazwa) VALUES ('$nazwa')");
    }
    header("Location: zarzadzanie_szablonami.php");
    exit;
}

// Obsługa usuwania nazwy
if (isset($_GET['usun_nazwe'])) {
    $id = (int)$_GET['usun_nazwe'];
    mysqli_query($conn, "DELETE FROM nazwy_pociagow WHERE id = $id");
    header("Location: zarzadzanie_szablonami.php");
    exit;
}

// Obsługa usuwania szablonu postojów
if (isset($_GET['usun_szablon'])) {
    $id = (int)$_GET['usun_szablon'];
    mysqli_query($conn, "DELETE FROM szablony_postojow WHERE id = $id");
    header("Location: zarzadzanie_szablonami.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Zarządzanie Słownikami i Szablonami</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background-color: #e9ecef; margin: 0; padding: 20px; color: #333; }
        .header { background: #004080; color: white; padding: 15px 20px; border-radius: 5px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;}
        .header a { color: white; text-decoration: none; font-weight: bold; background: rgba(255,255,255,0.2); padding: 5px 10px; border-radius: 4px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #004080; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; }
        .btn { padding: 5px 10px; border: none; cursor: pointer; border-radius: 3px; font-weight: bold; text-decoration: none; color: white; }
        .btn-add { background: #28a745; }
        .btn-del { background: #dc3545; }
        input[type="text"] { padding: 6px; border: 1px solid #ccc; border-radius: 3px; width: 250px; }
    </style>
</head>
<body>

<div class="header">
    <h1 style="margin:0; font-size: 20px;">🏷️ Zarządzanie Słownikami i Szablonami</h1>
    <a href="index.php">Powrót do menu</a>
</div>

<div class="grid">
    <div class="card">
        <h2>Baza Nazw Pociągów (Podpowiedzi)</h2>
        <form method="POST" style="margin-bottom: 15px;">
            <input type="text" name="nowa_nazwa" placeholder="np. ORKAN, CHROBRY, USTRONIE..." required>
            <button type="submit" name="dodaj_nazwe" class="btn btn-add">➕ Dodaj do listy</button>
        </form>
        
        <table>
            <tr><th>ID</th><th>Nazwa Pociągu</th><th style="width: 60px;">Akcja</th></tr>
            <?php
            $res = mysqli_query($conn, "SELECT * FROM nazwy_pociagow ORDER BY nazwa");
            while($r = mysqli_fetch_assoc($res)): ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td><b><?= htmlspecialchars($r['nazwa']) ?></b></td>
                <td><a href="?usun_nazwe=<?= $r['id'] ?>" class="btn btn-del" onclick="return confirm('Usunąć tę nazwę?')">Usuń</a></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>

    <div class="card">
        <h2>Zapisane Szablony Postojów (z Generatora)</h2>
        <p style="font-size: 12px; color: #666;">Szablony tworzysz bezpośrednio w oknie Generatora Rozkładu. Tutaj możesz je tylko podglądać i usuwać.</p>
        <table>
            <tr><th>Trasa</th><th>Nazwa Szablonu</th><th style="width: 60px;">Akcja</th></tr>
            <?php
            $res2 = mysqli_query($conn, "SELECT s.id, s.nazwa_szablonu, t.nazwa_trasy FROM szablony_postojow s JOIN trasy t ON s.id_trasy = t.id_trasy ORDER BY t.nazwa_trasy, s.nazwa_szablonu");
            while($r2 = mysqli_fetch_assoc($res2)): ?>
            <tr>
                <td><?= htmlspecialchars($r2['nazwa_trasy']) ?></td>
                <td><b><?= htmlspecialchars($r2['nazwa_szablonu']) ?></b></td>
                <td><a href="?usun_szablon=<?= $r2['id'] ?>" class="btn btn-del" onclick="return confirm('Na pewno usunąć szablon?')">Usuń</a></td>
            </tr>
            <?php endwhile; ?>
        </table>
    </div>
</div>

</body>
</html>