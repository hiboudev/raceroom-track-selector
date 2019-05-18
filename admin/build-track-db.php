<?php
declare (strict_types = 1);
ini_set('max_execution_time', '600');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$startTime = microtime(true);

// TODO gérer les cas d'erreur (magasin hors-ligne) et sortir du script sans modifier le CSV

$trackList = [];
downloadTrackList($trackList);
downloadTrackDetails($trackList);
downloadExtraDataFromOverlay($trackList);
createCsv($trackList);

$elapsedTime = microtime(true) - $startTime;
write("Total time: $elapsedTime s");

finish();

function downloadTrackList(array &$list)
{
    write("Downloading global track list...");

    $json = getShopJSON("http://game.raceroom.com/store/tracks/?json");

    $totalLayoutCount = 0;

    foreach ($json["context"]["c"]["sections"][0]["items"] as $item) {
        $trackId = intval($item['cid']);

        $layoutCount = intval($item['content_info']['number_of_layouts']);
        $totalLayoutCount += $layoutCount;

        $list[$trackId] = new Track(
            $trackId,
            $item['name'],
            $item['content_info']['country']['name'],
            $item['content_info']['track_type'], // Note: will be overwritten on detail parsing to get translation
            $layoutCount,
            $item['description'],
            $item['image']['logo'],
            $item['image']['thumb'],
            $item['image']['big'],
            $item['image']['full'],
            $item['image']['signature'],
            key_exists('free_to_use', $item) ? $item['free_to_use'] : false,
            $item['video']['id'],
            $item['path']
        );
    }
    write("Total of " . count($list) . " tracks, $totalLayoutCount layouts.");
}

function downloadTrackDetails(array &$list)
{
    write("Downloading details for " . count($list) . " tracks...");

    $count = 0;
    $total = count($list);
    foreach ($list as $track) {
        $count++;
        write("[$count/$total] $track->name ($track->layoutCount layout(s))");

        $json      = getShopJSON("$track->url?json");
        $trackItem = $json["context"]["c"]["item"];

        $track->type               = $trackItem['specs_data']['track_type']; // Translated version only in detail page.
        $track->verticalDifference = $trackItem['specs_data']['vertical_difference'];
        $track->location           = $trackItem['specs_data']['location'];

        if (key_exists('screenshots', $trackItem)) {
            $track->screenshot1 = $trackItem['screenshots'][0]['scaled']; // TODO pas pris les 3 formats
            $track->screenshot2 = $trackItem['screenshots'][1]['scaled']; // TODO anticiper qu'il n'y en ai pas 4
            $track->screenshot3 = $trackItem['screenshots'][2]['scaled'];
            $track->screenshot4 = $trackItem['screenshots'][3]['scaled'];
        }

        foreach ($trackItem['related_items'] as $layoutItem) {
            $layoutId = intval($layoutItem['cid']);

            $layout = new Layout(
                $layoutId,
                $track->id,
                $layoutItem['name'],
                $layoutItem['image']['thumb'],
                $layoutItem['image']['big'],
                $layoutItem['image']['full'],
                floatval($layoutItem['content_info']['specs']['length']),
                intval($layoutItem['content_info']['specs']['turns']),
                $layoutItem['content_info']['name']
            );
            $track->layouts[$layoutId] = $layout;
        }
    }
}

function downloadExtraDataFromOverlay(array &$trackList)
{
    write("Downloading extra data from S3S Overlay...");

    $url         = "https://raw.githubusercontent.com/sector3studios/r3e-spectator-overlay/master/r3e-data.json";
    $fileContent = file_get_contents($url);
    $json        = null;

    if ($fileContent !== false) {
        // Removing invalid ';' at end of json.
        $fileContent = preg_replace("/;\s*$/", '', $fileContent);
        $json        = json_decode($fileContent, true);

        if ($json == null) {
            write("Error parsing JSON from URL: $url");
            return;
        }
    } else {
        write("Error getting file from URL: $url");
        return;
    }

    $layoutCount = 0;

    foreach ($json["layouts"] as $layoutItem) {
        $layoutCount++;

        $trackId  = intval($layoutItem['Track']);
        $layoutId = intval($layoutItem['Id']);

        $trackList[$trackId]->layouts[$layoutId]->maxVehicules = intval($layoutItem['MaxNumberOfVehicles']);
    }

    write("Added extra data for $layoutCount layouts.");
}

function createCsv(array &$list)
{
    write("Creating Csv file...");
    // TODO Escape "," and """ from all fields

    // UTF8 header
    $csvContent = "\xEF\xBB\xBF";

    $csvContent .= "TrackId,LayoutId,TrackName,LayoutName,TrackType,MaxVehicules,Length (km),VerticalDifference,Turns,Country,Location,TotalLayout,isFree,trackUrl,trackScreenshot1,trackScreenshot2,trackScreenshot3,trackScreenshot4,trackImgLogo,trackImgThumb,trackImgBig,trackImgFull,trackImgSignature,trackVideo,layoutImgThumb,layoutImgBig,layoutImgFull,Description\r\n";

    foreach ($list as $track) {
        foreach ($track->layouts as $layout) {
            $description = "\"" . str_replace("\"", "\"\"", $track->description) . "\"";
            $isFree      = intval($track->isFree);
            $csvContent .= "$track->id,$layout->id,$track->name,$layout->name,$track->type,$layout->maxVehicules,$layout->length,$track->verticalDifference,$layout->turnCount,$track->country,\"$track->location\",$track->layoutCount,$isFree,$track->url,$track->screenshot1,$track->screenshot2,$track->screenshot3,$track->screenshot4,$track->imgLogo,$track->imgThumb,$track->imgBig,$track->imgFull,$track->imgSignature,$track->video,$layout->imgThumb,$layout->imgBig,$layout->imgFull,$description\r\n";
        }
    }

    if (file_put_contents("../tracks.csv", $csvContent) === false) {
        write("ERROR writing csv file!");
    } else {
        write("CSV file created successfully!");
    }

}

function getShopJSON(string $url)
{
    $request = getCurl($url);

    // $output contains the output string
    $output = curl_exec($request);
    // close curl resource to free up system resources
    curl_close($request);

    $json = json_decode($output, true);

    if ($json === null) {
        write("Error downloading/parsing JSON at $url");
        finish();
        exit(1);
    }

    return $json;
}

function getCurl($url)
{
    // Request header
    $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
    $header[] = "Cache-Control: max-age=0";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: fr-fr,en;q=0.5";
    $header[] = "Pragma: ";

    $request = curl_init();
    curl_setopt($request, CURLOPT_URL, $url);
    //return the transfer as a string
    curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($request, CURLOPT_HTTPHEADER, $header);

    return $request;
}

function write($text)
{
    $time = date("H:i:s");
    echo "data: [$time] $text\n\n";
    ob_flush();
    flush();
}

function finish()
{
    echo "data: COMPLETE\n\n";
    ob_flush();
    flush();
}

class Track
{
    public $id;
    public $name;
    public $country;
    public $type;
    public $layoutCount;
    public $description;
    public $imgLogo;
    public $imgThumb;
    public $imgBig;
    public $imgFull;
    public $imgSignature;
    public $isFree;
    public $video;
    public $url;
    public $verticalDifference;
    public $location;
    public $screenshot1;
    public $screenshot2;
    public $screenshot3;
    public $screenshot4;

    public $layouts = [];

    public function __construct(int $id, string $name, string $country, string $type, int $layoutCount, string $description,
        string $imgLogo, string $imgThumb, string $imgBig, string $imgFull, string $imgSignature, bool $isFree, ?string $video, string $url) {

        $this->id           = $id;
        $this->name         = $name;
        $this->country      = $country;
        $this->type         = $type;
        $this->layoutCount  = $layoutCount;
        $this->description  = $description;
        $this->imgLogo      = $imgLogo;
        $this->imgThumb     = $imgThumb;
        $this->imgBig       = $imgBig;
        $this->imgFull      = $imgFull;
        $this->imgSignature = $imgSignature;
        $this->isFree       = $isFree;
        $this->video        = $video;
        $this->url          = $url;
    }
}

class Layout
{
    public $id;
    public $trackId;
    public $name;
    public $imgThumb;
    public $imgBig;
    public $imgFull;
    public $length;
    public $turnCount;
    public $maxVehicules;

    public function __construct(int $id, int $trackId, string $name, string $imgThumb, string $imgBig, string $imgFull, float $length, int $turnCount)
    {
        $this->id        = $id;
        $this->trackId   = $trackId;
        $this->name      = $name;
        $this->imgThumb  = $imgThumb;
        $this->imgBig    = $imgBig;
        $this->imgFull   = $imgFull;
        $this->length    = $length;
        $this->turnCount = $turnCount;
    }
}
