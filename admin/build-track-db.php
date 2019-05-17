<?php
declare (strict_types = 1);
ini_set('max_execution_time', '600');

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

$list = new TrackLayoutList();
downloadTrackList($list);
downloadTrackDetails($list);

function downloadTrackList(TrackLayoutList $list)
{
    $fileContent = file_get_contents("http://game.raceroom.com/store/tracks/?json");
    $json        = json_decode($fileContent, true);

    foreach ($json["context"]["c"]["sections"][0]["items"] as $item) {
        $list->tracks[] = new Track(
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
    write(count($list->tracks) . ' tracks.');
}

function downloadTrackDetails(TrackLayoutList $list)
{
    foreach ($list->tracks as $track) {
        write($track->name);

        $fileContent = file_get_contents($track->url . "?json");
        $json        = json_decode($fileContent, true);
        $trackItem   = $json["context"]["c"]["item"];

        $track->screenshot1 = $trackItem['screenshots'][0]['scaled']; // TODO pas pris les 3
        $track->screenshot2 = $trackItem['screenshots'][1]['scaled'];
        $track->screenshot3 = $trackItem['screenshots'][2]['scaled'];
        $track->screenshot4 = $trackItem['screenshots'][3]['scaled'];

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
            write(var_dump($layout));
        }
    }
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

class TrackLayoutList
{
    public $tracks  = [];
    public $layouts = [];
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
