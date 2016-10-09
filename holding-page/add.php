<?php

include("config.php");

// Create connection
$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// prepare and bind
$stmt = $conn->prepare("INSERT INTO websites (url, `datetime`) VALUES (?, NOW())");

$url = $_POST['url'];
$stmt->bind_param("s", $url);

$stmt->execute();

echo mysqli_error($conn);

$stmt->close();
$conn->close();
?>