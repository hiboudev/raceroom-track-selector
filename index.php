<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <link rel="icon" type="image/png" href="images/favicon.png" />

    <title>Raceroom tracks and layouts</title>
    <meta name="description" content="Raceroom tracks and layouts" />

    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>

<body>

    <p>Download Raceroom track and layout list as CSV (separator is comma ",").
<?php
$files = glob("files/r3e-tracks_??-??.csv");
$count = 0;
echo "<ul>";
foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }
    $count++;
    echo "<li><a href=\"$file\">" . basename($file) . "</a></li>";
}
echo "</ul>";
if ($count == 0) {
    echo "No file found.";
}

?>
    </p>

</body>
</html>
