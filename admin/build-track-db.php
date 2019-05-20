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

$startTime = microtime(true);

$languages = [FRENCH, ENGLISH, ITALIAN, SPANISH, GERMAN];
write("Starting script for " . count($languages) . " languages...");
foreach ($languages as $lang) {
    createCsvForLanguage($lang);
}

$elapsedTime = microtime(true) - $startTime;
write("Total time: $elapsedTime s");

finish();

// Used by progress handler.
global $trackCount;
global $progressCount;

function createCsvForLanguage(string $language)
{
    write("## Computing data for language '$language'...");
    global $trackCount;

    $trackList = [];
    downloadTrackList($trackList, $language);
    $trackCount = count($trackList);
    downloadTrackDetails($trackList, $language);
    downloadExtraDataFromOverlay($trackList); // TODO pas besoin de télécharger pour chaque langue
    writeCsv($trackList, $language);
}

function downloadTrackList(array &$list, string $language)
{
    write("Downloading global track list...");

    $json = getShopJSON("http://game.raceroom.com/store/tracks/?json", $language);

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
            // Excel seems to dislike line breaks.
            trim($item['description']),
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

function downloadTrackDetails(array &$trackList, string $language)
{
    write("Downloading details for " . count($trackList) . " tracks...");

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

    write("Parsing details for " . count($trackList) . " tracks...");

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
        write("[$progressCount/ " . $trackCount . "] " . curl_getinfo($resource, CURLINFO_EFFECTIVE_URL));
        curl_setopt($resource, CURLOPT_NOPROGRESS, true);
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

function writeCsv(array &$list, string $language)
{
    write("Creating Csv file...");
    // TODO Escape "," and """ from all fields

    // UTF8 header
    $csvContent = "\xEF\xBB\xBF";

    $csvContent .= "TrackId,LayoutId,TrackName,LayoutName,TrackType,MaxVehicules,Length (km),HeightDifference (m),Turns,Country,Location,TotalLayout,IsFree,TrackUrl,TrackScreenshot1,TrackScreenshot2,TrackScreenshot3,TrackScreenshot4,TrackImgLogo,TrackImgThumb,TrackImgBig,TrackImgFull,TrackImgSignature,TrackVideo,LayoutImgThumb,LayoutImgBig,LayoutImgFull,Description\r\n";

    foreach ($list as $track) {
        foreach ($track->layouts as $layout) {
            $description = "\"" . str_replace("\"", "\"\"", $track->description) . "\"";
            // Excel seems to dislike line breaks.
            $description = preg_replace("/\s+/", " ", $description);
            // write("[$description]");
            $isFree     = intval($track->isFree);
            $length     = localizeNumber($layout->length, $language);
            $heightDiff = localizeNumber($track->heightDifference, $language);

            $csvContent .= "$track->id,$layout->id,$track->name,$layout->name,$track->type,$layout->maxVehicules,\"$length\",\"$heightDiff\",$layout->turnCount,$track->country,\"$track->location\",$track->layoutCount,$isFree,$track->url,$track->screenshot1,$track->screenshot2,$track->screenshot3,$track->screenshot4,$track->imgLogo,$track->imgThumb,$track->imgBig,$track->imgFull,$track->imgSignature,$track->video,$layout->imgThumb,$layout->imgBig,$layout->imgFull,$description\r\n";
        }
    }

    if (file_put_contents("../tracks_$language.csv", $csvContent) === false) {
        write("ERROR writing csv file!");
    } else {
        write("CSV file created successfully!");
    }
}

function localizeNumber(float $number, string $language): string
{
    switch ($language) {
        case FRENCH:
        case GERMAN:
            return number_format($number, 3, ",", "");
        case ITALIAN:
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
    public $heightDifference;
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
