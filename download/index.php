<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <!--link rel="icon" type="image/png" href="images/favicon.png" /-->

    <title>Raceroom tracks and layouts</title>
    <meta name="description" content="Raceroom tracks and layouts" />

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        body {
            font-family: sans-serif
        }
        th, td {
            padding: 5px 20px;
            border-bottom: 1px solid #ddd;
        }
    </style>
</head>

<body>
    <h3>Download Raceroom track and layout list as CSV</h3>
    <p>
        <table><thead><tr><th>File</th><th>Last updated (GMT)</th></tr></thead><tbody>
<?php
$files = glob("../files/r3e-tracks_??-??.csv");
$count = 0;
foreach ($files as $file) {
    echo "<tr>";
    if (!is_file($file)) {
        continue;
    }
    $count++;
    $fileName    = basename($file);
    $lastModDate = gmdate("d/m/y H:i", filemtime($file));

    echo "<td><a href=\"$file\">" . $fileName . "</a></td><td><small>$lastModDate</small></td>";
    echo "</tr>";
}
echo "</tbody></table>";
if ($count == 0) {
    echo "No file found.";
}

?>
    </p>
    <p><small><em>Separator: comma ","<br/>Encoding: UTF-8 with BOM</em></small></p>

</body>
</html>