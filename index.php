<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Panel Zarządzania Rozkładami Jazdy</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1, h2 { text-align: center; color: #333; }
        .menu { list-style-type: none; padding: 0; }
        .menu li { margin: 15px 0; }
        .menu a { display: block; background-color: #007bff; color: white; padding: 15px; text-decoration: none; border-radius: 5px; text-align: center; font-size: 1.2em; transition: background-color 0.3s; }
        .menu a:hover { background-color: #0056b3; }
        .menu a.admin-link { background-color: #6c757d; }
        .menu a.admin-link:hover { background-color: #5a6268; }
        hr { margin: 20px 0; border: 0; border-top: 1px solid #ccc; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Panel Zarządzania Rozkładami Jazdy</h1>
        <ul class="menu">
            <li><a href="panel_dyzurnego.php">🖥️ Panel Dyżurnego (SWDR)</a></li>
            <li><a href="generator_rozkladu.php">➡️ Generator Rozkładu Jazdy</a></li>
            <li><a href="zarzadzanie_trasa.php">Edycja trasy pociągu</a></li>
            <li><a href="podglad_maszynisty.php">📄 Podgląd dla Maszynisty</a></li>
            <li><a href="przegladarka_rozkladow.php">📋 Przeglądarka Rozkładów (Plakaty)</a></li>
            <li><a href="wyswietlacz_led.php">🟧 Wyświetlacz LED w Pociągu</a></li>
            <hr>
            <li><a href="zarzadzaj_stacjami.php" class="admin-link">🚉 Zarządzaj Stacjami</a></li>
            <li><a href="kreator_tras.php" class="admin-link">🗺️ Zarządzaj Trasami</a></li>
            <li><a href="zarzadzaj_odcinkami.php" class="admin-link">📏 Zarządzaj Odcinkami</a></li>
            <li><a href="zarzadzaj_ostrzezeniami.php" class="admin-link">⚠️ Zarządzaj Ostrzeżeniami (Rozkazy O)</a></li>
        </ul>
    </div>
</body>
</html>