<?php
declare (strict_types = 1);
ini_set('max_execution_time', '600');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

// TODO gérer les cas d'erreur (magasin hors-ligne) et sortir du script sans modifier le CSV

DEFINE('FRENCH', 'fr-FR');
DEFINE('ENGLISH', 'en-US');
DEFINE('ITALIAN', 'it-IT');
DEFINE('SPANISH', 'es-ES');
DEFINE('GERMAN', 'de-DE');

// Used by progress handler.
global $trackCount;
global $progressCount;

$startTime = microtime(true);

$languages = [FRENCH, ENGLISH, ITALIAN, SPANISH, GERMAN];
write("Starting script for " . count($languages) . " languages");

$extraDataJson = downloadExtraDataFromOverlay();
$trackList     = downloadTrackList();
$trackCount    = count($trackList);

foreach ($languages as $lang) {
    createCsvForLanguage($trackList, $lang, $extraDataJson);
}

$elapsedTime = microtime(true) - $startTime;

write("<h3>Job complete!</h3>Total time: " . number_format($elapsedTime, 3) . " s", true, false);

finish();

function createCsvForLanguage(array &$trackList, string $language, $extraDataJson)
{
    write("<strong><u>Language '$language'</u></strong>");

    downloadTrackDetails($trackList, $language);
    if ($extraDataJson != null) {
        addExtraDataFromOverlay($trackList, $extraDataJson);
    }
    writeCsv($trackList, $language);
}

function downloadTrackList()
{
    write("Downloading unlocalized global track list");

    // No need to localize
    $json = getShopJSON("http://game.raceroom.com/store/tracks/?json", ENGLISH);

    $totalLayoutCount = 0;

    $list = [];

    foreach ($json["context"]["c"]["sections"][0]["items"] as $item) {
        $trackId = intval($item['cid']);

        $layoutCount = intval($item['content_info']['number_of_layouts']);
        $totalLayoutCount += $layoutCount;

        $list[$trackId] = new Track(
            $trackId,
            $item['name'],
            $layoutCount,
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
    write("Total of <b>" . count($list) . " tracks</b>, <b>$totalLayoutCount layouts</b>");

    return $list;
}

function downloadTrackDetails(array &$trackList, string $language)
{
    write("Downloading details for " . count($trackList) . " tracks", false);

    $count = 0;
    $total = count($trackList);

    $curlList  = prepareCurlList($trackList, $language);
    $multiCurl = getMultiCurl($curlList);
    global $progressCount;
    $progressCount = 0;

    do {
        $status = curl_multi_exec($multiCurl, $active);
        if ($active) {
            curl_multi_select($multiCurl);
        }
    } while ($active && $status == CURLM_OK);

    releaseMultiCurl($multiCurl, $curlList);

    write("Parsing details for " . count($trackList) . " tracks");

    foreach ($curlList as $curl) {
        $response = curl_multi_getcontent($curl);
        $json     = json_decode($response, true);

        $trackItem = $json["context"]["c"]["item"];

        $trackId = intval($trackItem['cid']);
        $track   = $trackList[$trackId];

        $vDiff = preg_replace("/(\d+)\s?m.*/", "$1", $trackItem['specs_data']['vertical_difference']);

        $track->type             = $trackItem['specs_data']['track_type']; // Translated version only in detail page.
        $track->heightDifference = floatval($vDiff);
        $track->location         = $trackItem['specs_data']['location'];
        $track->description      = trim($trackItem['description']);
        $track->country          = $trackItem['content_info']['country']['name'];
        $track->trackType        = $trackItem['content_info']['track_type'];

        if (key_exists('screenshots', $trackItem)) {
            // TODO anticiper qu'il n'y en ai pas 4
            $track->screenshot1Thumb  = $trackItem['screenshots'][0]['thumb'];
            $track->screenshot1Scaled = $trackItem['screenshots'][0]['scaled'];
            $track->screenshot1Full   = $trackItem['screenshots'][0]['full'];

            $track->screenshot2Thumb  = $trackItem['screenshots'][1]['thumb'];
            $track->screenshot2Scaled = $trackItem['screenshots'][1]['scaled'];
            $track->screenshot2Full   = $trackItem['screenshots'][1]['full'];

            $track->screenshot3Thumb  = $trackItem['screenshots'][2]['thumb'];
            $track->screenshot3Scaled = $trackItem['screenshots'][2]['scaled'];
            $track->screenshot3Full   = $trackItem['screenshots'][2]['full'];

            $track->screenshot4Thumb  = $trackItem['screenshots'][3]['thumb'];
            $track->screenshot4Scaled = $trackItem['screenshots'][3]['scaled'];
            $track->screenshot4Full   = $trackItem['screenshots'][3]['full'];
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

function prepareCurlList(array &$trackList, string $language)
{
    $curlList = [];
    foreach ($trackList as $track) {
        $request = curl_init();
        curl_setopt($request, CURLOPT_URL, "$track->url?json");
        //return the transfer as a string
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($request, CURLOPT_HTTPHEADER, getRequestHeader($language));

        $curlList[] = $request;
    }

    return $curlList;
}

function getMultiCurl(array &$curlList)
{
    // TODO gérer magasin offline
    $multiCurl = curl_multi_init();
    foreach ($curlList as $curl) {
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION, "onCurlProgress");
        curl_setopt($curl, CURLOPT_NOPROGRESS, false);
        curl_multi_add_handle($multiCurl, $curl);
        // break;
    }
    return $multiCurl;
}

function releaseMultiCurl($multiCurl, array &$curlList)
{
    foreach ($curlList as $curl) {
        curl_multi_remove_handle($multiCurl, $curl);
    }
    curl_multi_close($multiCurl);
}

function onCurlProgress($resource, $download_size, $downloaded, $upload_size, $uploaded)
{
    global $trackCount;
    global $progressCount;

    // write("$download_size, $downloaded, $upload_size, $uploaded");
    if ($download_size > 0 && $downloaded == $download_size) {
        $progressCount++;
        // write("[$progressCount/ " . $trackCount . "] " . curl_getinfo($resource, CURLINFO_EFFECTIVE_URL), false);
        write("<small>.</small>", $trackCount == $progressCount, false);
        curl_setopt($resource, CURLOPT_NOPROGRESS, true);
    }
}

function downloadExtraDataFromOverlay(): ?array
{
    write("Downloading unlocalized extra data from S3S Overlay");

    $url         = "https://raw.githubusercontent.com/sector3studios/r3e-spectator-overlay/master/r3e-data.json";
    $fileContent = file_get_contents($url);
    $json        = null;

    if ($fileContent !== false) {
        // Removing invalid ';' at end of json.
        $fileContent = preg_replace("/;\s*$/", '', $fileContent);
        $json        = json_decode($fileContent, true);

        if ($json == null) {
            write("Error parsing JSON from URL: $url");
            return null;
        }
    } else {
        write("Error getting file from URL: $url");
        return null;
    }

    return $json["layouts"];
}

function addExtraDataFromOverlay(array &$trackList, array &$extraJsonLayouts)
{
    write("Adding extra data to " . count($extraJsonLayouts) . " layouts");

    foreach ($extraJsonLayouts as $layoutItem) {
        $trackId  = intval($layoutItem['Track']);
        $layoutId = intval($layoutItem['Id']);

        $trackList[$trackId]->layouts[$layoutId]->maxVehicules = intval($layoutItem['MaxNumberOfVehicles']);
    }
}

function writeCsv(array &$list, string $language)
{
    $fileName = "r3e-tracks_$language.csv";
    write("Creating CSV file <i>$fileName</i>");
    // TODO Escape "," and """ from all fields

    // UTF8 header
    $csvContent = "\xEF\xBB\xBF";

    $csvContent .= "TrackId,LayoutId,TrackName,LayoutName,TrackType,MaxVehicules,Length (km),HeightDifference (m),Turns,Country,Location,TotalLayout,IsFree,TrackUrl,TrackScreenshot1Thumb,TrackScreenshot1Scaled,TrackScreenshot1Full,TrackScreenshot2Thumb,TrackScreenshot2Scaled,TrackScreenshot2Full,TrackScreenshot3Thumb,TrackScreenshot3Scaled,TrackScreenshot3Full,TrackScreenshot4Thumb,TrackScreenshot4Scaled,TrackScreenshot4Full,TrackImgLogo,TrackImgThumb,TrackImgBig,TrackImgFull,TrackImgSignature,TrackVideo,LayoutImgThumb,LayoutImgBig,LayoutImgFull,Description\r\n";

    foreach ($list as $track) {
        foreach ($track->layouts as $layout) {
            $description = "\"" . str_replace("\"", "\"\"", $track->description) . "\"";
            // Excel seems to dislike line breaks.
            $description = preg_replace("/\s+/", " ", $description);
            // write("[$description]");
            $isFree     = intval($track->isFree);
            $length     = localizeNumber($layout->length, $language);
            $heightDiff = localizeNumber($track->heightDifference, $language);

            $csvContent .= "$track->id,$layout->id,$track->name,$layout->name,$track->type,$layout->maxVehicules,\"$length\",\"$heightDiff\",$layout->turnCount,$track->country,\"$track->location\",$track->layoutCount,$isFree,$track->url,$track->screenshot1Thumb,$track->screenshot1Scaled,$track->screenshot1Full,$track->screenshot2Thumb,$track->screenshot2Scaled,$track->screenshot2Full,$track->screenshot3Thumb,$track->screenshot3Scaled,$track->screenshot3Full,$track->screenshot4Thumb,$track->screenshot4Scaled,$track->screenshot4Full,$track->imgLogo,$track->imgThumb,$track->imgBig,$track->imgFull,$track->imgSignature,$track->video,$layout->imgThumb,$layout->imgBig,$layout->imgFull,$description\r\n";
        }
    }

    if (file_put_contents("../files/$fileName", $csvContent) === false) {
        write("ERROR writing csv file!");
    }
}

function localizeNumber(float $number, string $language): string
{
    switch ($language) {
        case FRENCH:
        case GERMAN:
            return number_format($number, 3, ",", "");
        case ITALIAN:
        case SPANISH:
            return number_format($number, 3, ",", ".");
        default:
            return number_format($number, 3, ".", ",");
    }
}

function getShopJSON(string $url, string $language)
{
    $request = getCurl($url, $language);

    // $output contains the output string
    $output = curl_exec($request);
    // close curl resource to free up system resources
    curl_close($request);

    if (!$output) {
        write("Error accessing S3S shop, is it offline?");
        exit(1);
    }

    $json = json_decode($output, true);

    if ($json === null) {
        write("Error downloading/parsing JSON at $url");
        finish();
        exit(1);
    }

    return $json;
}

function getCurl($url, string $language)
{
    $request = curl_init();
    curl_setopt($request, CURLOPT_URL, $url);
    //return the transfer as a string
    curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($request, CURLOPT_HTTPHEADER, getRequestHeader($language));

    return $request;
}

function getRequestHeader(string $language): array
{
    // Request header
    $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
    $header[] = "Cache-Control: max-age=0";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: $language,en;q=0.5";
    $header[] = "Pragma: ";

    return $header;
}

function write($text, bool $addLineBreak = true, bool $addTime = true)
{
    $time = date("H:i:s");
    echo "data:{\"message\":\"" . ($addTime ? "<small>[$time]</small> " : "") . $text . ($addLineBreak ? "<br />" : "") . "\"}\n\n";
    ob_flush();
    flush();
}

function finish()
{
    echo "data:{\"message\":\"COMPLETE\"}" . "\n\n";
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
    public $heightDifference;
    public $location;
    public $screenshot1Thumb;
    public $screenshot1Scaled;
    public $screenshot1Full;
    public $screenshot2Thumb;
    public $screenshot2Scaled;
    public $screenshot2Full;
    public $screenshot3Thumb;
    public $screenshot3Scaled;
    public $screenshot3Full;
    public $screenshot4Thumb;
    public $screenshot4Scaled;
    public $screenshot4Full;

    public $layouts = [];

    public function __construct(int $id, string $name, int $layoutCount,
        string $imgLogo, string $imgThumb, string $imgBig, string $imgFull, string $imgSignature, bool $isFree,
        ?string $video, string $url) {

        $this->id           = $id;
        $this->name         = $name;
        $this->layoutCount  = $layoutCount;
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
