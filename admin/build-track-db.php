<?php
declare (strict_types = 1);
ini_set('max_execution_time', '600');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$startTime = microtime(true);

$trackList = [];
downloadTrackList($trackList);
downloadTrackDetails($trackList);
createCsv($trackList);

$elapsedTime = microtime(true) - $startTime;
write("Total time: $elapsedTime s");

finish();

function downloadTrackList(array &$list)
{
    // $fileContent = file_get_contents("http://game.raceroom.com/store/tracks/?json");
    // $json        = json_decode($fileContent, true);
    $json = getShopJSON("http://game.raceroom.com/store/tracks/?json");

    foreach ($json["context"]["c"]["sections"][0]["items"] as $item) {
        $list[] = new Track(
            intval($item['cid']),
            $item['name'],
            $item['content_info']['country']['name'],
            $item['content_info']['track_type'],
            intval($item['content_info']['number_of_layouts']),
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
    write(count($list) . ' tracks.');
}

function downloadTrackDetails(array &$list)
{
    $count = 0;
    $total = count($list);
    foreach ($list as $track) {
        $count++;
        write("[$count/$total] $track->name ($track->layoutCount layout(s))");

        // $fileContent = file_get_contents($track->url . "?json");
        // $json        = json_decode($fileContent, true);
        $json      = getShopJSON("$track->url?json");
        $trackItem = $json["context"]["c"]["item"];

        if (key_exists('screenshots', $trackItem)) {
            $track->screenshot1 = $trackItem['screenshots'][0]['scaled']; // TODO pas pris les 3 formats
            $track->screenshot2 = $trackItem['screenshots'][1]['scaled']; // TODO anticiper qu'il n'y en ai pas 4
            $track->screenshot3 = $trackItem['screenshots'][2]['scaled'];
            $track->screenshot4 = $trackItem['screenshots'][3]['scaled'];
        }

        foreach ($trackItem['related_items'] as $layoutItem) {
            $layout = new Layout(
                intval($layoutItem['cid']),
                $track->id,
                $layoutItem['name'],
                $layoutItem['image']['thumb'],
                $layoutItem['image']['big'],
                $layoutItem['image']['full'],
                floatval($layoutItem['content_info']['specs']['length']),
                intval($layoutItem['content_info']['specs']['turns']),
                $layoutItem['content_info']['name']
            );
            $track->layouts[] = $layout;
        }
        break;
    }
}

function createCsv(array &$list)
{
    write("Creating Csv file...");

    // UTF8 header
    $csvContent = "\xEF\xBB\xBF";

    $csvContent .= "TrackId,LayoutId,TrackName,LayoutName,TrackType,Length (km),Turns,Country,TotalLayout,isFree,trackUrl,trackScreenshot1,trackScreenshot2,trackScreenshot3,trackScreenshot4,trackImgLogo,trackImgThumb,trackImgBig,trackImgFull,trackImgSignature,trackVideo,layoutImgThumb,layoutImgBig,layoutImgFull,Description\r\n";

    foreach ($list as $track) {
        foreach ($track->layouts as $layout) {
            $description = "\"" . str_replace("\"", "\"\"", $track->description) . "\"";
            $csvContent .= "$track->id,$layout->id,$track->name,$layout->name,$track->type,$layout->length,$layout->turnCount,$track->country,$track->layoutCount,$track->isFree,$track->url,$track->screenshot1,$track->screenshot2,$track->screenshot3,$track->screenshot4,$track->imgLogo,$track->imgThumb,$track->imgBig,$track->imgFull,$track->imgSignature,$track->video,$layout->imgThumb,$layout->imgBig,$layout->imgFull,$description\r\n";
        }
    }

    if (file_put_contents("../tracks.csv", $csvContent) === false) {
        write("ERROR writing csv file!");
    }

    write("CSV file created successfully!");
}

function getShopJSON(string $url)
{
    $ch = curl_init();

    // set url
    curl_setopt($ch, CURLOPT_URL, $url);

    //return the transfer as a string
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    //set the header params
    $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
    $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
    $header[] = "Cache-Control: max-age=0";
    $header[] = "Connection: keep-alive";
    $header[] = "Keep-Alive: 300";
    $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
    $header[] = "Accept-Language: fr-fr,en;q=0.5";
    $header[] = "Pragma: ";

    //assign to the curl request.
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

    // $output contains the output string
    $output = curl_exec($ch);

    // close curl resource to free up system resources
    curl_close($ch);

    $json = json_decode($output, true);

    if ($json === null) {
        write("Error downloading/parsing JSON at $url");
        finish();
        exit(1);
    }

    return $json;
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
    public $screenshot1;
    public $screenshot2;
    public $screenshot3;
    public $screenshot4;

    public $layouts = [];

    public function __construct(int $id, string $name, string $country, string $type, int $layoutCount, string $description,
        string $imgLogo, string $imgThumb, string $imgBig, string $imgFull, string $imgSignature, bool $isFree, ?string $video, string $url,
        ?string $screenshot1 = null, ?string $screenshot2 = null, ?string $screenshot3 = null, ?string $screenshot4 = null) {

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
        $this->screenshot1  = $screenshot1;
        $this->screenshot2  = $screenshot2;
        $this->screenshot3  = $screenshot3;
        $this->screenshot4  = $screenshot4;

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
