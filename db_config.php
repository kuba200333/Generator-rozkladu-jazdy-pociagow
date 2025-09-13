<?php
// db_config.php

$servername = "localhost";
$username = "root"; // Twój użytkownik bazy danych
$password = "";     // Twoje hasło bazy danych
$dbname = "rozklad"; // Nazwa bazy danych

// Utworzenie połączenia
$conn = mysqli_connect($servername, $username, $password, $dbname);

// Sprawdzenie połączenia
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Ustawienie kodowania, aby polskie znaki działały poprawnie
mysqli_set_charset($conn, "utf8");

?>