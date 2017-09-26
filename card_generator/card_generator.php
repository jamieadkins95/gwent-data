<?php
/**
 * Gwent Card Generator
 * 
 * The script can be called as well, or in command line can be called followed by
 * cards-id to generate specific cards (JSON still needs to be parsed)
 * As the Gwent version isn't a part of the json file, the const VERSION needs to
 * be change manually when needed
 * 
 * Example of command-line call :   php card_generator.php
 *                                  php card_generator.php -cards 152101 152102 152103
 * 
 * Example of http call :           localhost/card_generator/card_generator.php
 *                                  localhost/card_generator/card_generator.php?cards=152101,152102,152103
 * 
 * JsonStreamingParser is required. file_get_contents with json_decode is way too
 * long for the cards.json file to explore
 * @url : https://github.com/salsify/jsonstreamingparser
 *  
 * @author      Meowcate
 * @license     http://www.opensource.org/licenses/mit-license.html  MIT License
 */

/*****************
 * CONFIGURATION *
 ****************/
const DS = '/'; // Change for `\` for Windows if needed
const VERSION = 'v0-9-10'; // Only important as the destination folder
const DEBUG = 1;
const MAX_CARDS = 5; // Maximum generated cards. 0 for no limit



require_once 'vendor/autoload.php';

// The script can takes more than 2 hours to generate all Gwent cards
// If you need to have an idea of the time, generate a few cards and check the
// debug timer to get the idea.
// Note : The JSON parsing will take a while but will be running only once every time
set_time_limit(0);

// Checking progress in real time with output buffering
ob_implicit_flush(true);
ob_start();
$microtime = microtime(true);

// json_decode is too slow getting all the datas in memory
// The streaming listener is faster
$listener = new \JsonStreamingParser\Listener\InMemoryListener();
$stream = fopen('cards.json', 'r');
try {
    $parser = new JsonStreamingParser\Parser($stream, $listener);
    $parser->parse();
    fclose($stream);
} catch (Exception $e) {
    fclose($stream);
    throw $e;
}
$json = current($listener->getJson());
debug("JSON to Array done");


// Let's generate
if ((isset($argv[0]) && $argv[0] == '-cards' && sizeof($argv) > 1)  // command-line version
        || isset($_GET['cards'])) {                                 // http version
    // Generate all cards from passed arguments
    $cardList = [];
    if (isset($argv[0])) {
        // Removing the first parameter
        array_shift($argv);
        $cardList = $argv;
    }
    if (isset($_GET['cards'])) {
        $cardList = explode(',', $_GET['cards']);
    }
    startCardList($cardList, $json);
} else {
    // Generate all cards from JSON, top to MAX_CARDS
    startCardList($cardList, $json);
}
ob_end_flush();


/**
 * Simple debug
 * @global type $microtime
 * @param type $data
 */
function debug($data)
{
    global $microtime;
    if (DEBUG) {
        echo (PHP_SAPI == 'cli') ? '' : '<pre>';
        echo "Time : " . round(microtime(true) - $microtime, 6) . " sec";
        echo (PHP_SAPI == 'cli') ? '\n' : '<br>';
        $microtime = microtime(true);
        var_dump($data);
        echo (PHP_SAPI == 'cli') ? '' : '</pre>';
        ob_flush();
    }
}


/**
 * Start the generation of all cards from the card list
 * It will stops when all cards are generated or MAX_CARDS is got
 * @param array $cardList Array of card IDs
 * @param array $json Content of cards.json
 */
function startCardList($cardList, $json)
{
    $i = 0;
    foreach ($cardList as $cardId) {
        if (isset($json[$cardId]) && $json[$cardId]['released'] == 1) {
            generateCard($cardId, $json[$cardId]);
            debug("Card : " . $cardId);
            $i++;
        } else {
            debug("The card ID `" . $cardId . "` is incorrect, or this card hasn't been released");
        }
        if ($i >= MAX_CARDS) break;
    }
}


/**
 * Card generation
 * @param int $cardId Id of the card
 * @param array $cardDatas Datas of the card extracted from the json
 * @throws Exception
 */
function generateCard($cardId, $cardDatas)
{
    switch ($cardDatas['faction']) {
        case "Northen Realms":
            $cardFaction = 'northernrealms';
            break;
        case "Monster":
            $cardFaction = 'monsters';
            break;
        case "Skellige":
            $cardFaction = 'skellige';
            break;
        case "Scoiatael":
            $cardFaction = 'scoiatael';
            break;
        case "Nilfgaard":
            $cardFaction = 'nilfgaard';
            break;
        default:
            $cardFaction = 'neutral';
    }

    try {
// Let's check whether we can perform the magick.
        if (TRUE !== extension_loaded('imagick')) {
            throw new Exception('Imagick extension is not loaded.');
        }

        $cardCreator = 'assets' . DS;
        $artFile = 'assets' . DS . 'artworks' . DS . $cardId . '00.png';
        $cardsFolder = 'images' . DS;

        $newCard = new Imagick();
        if (FALSE === $newCard->readImage($cardCreator . 'image_layout.png')) {
            throw new Exception("Layout not found");
        }
        
// adding the artwork
        $artwork = new Imagick();
        if (FALSE === $artwork->readImage($artFile)) {
            throw new Exception("Artwork not found");
        }
        // Cropping the artwork to remove the transparent excess
        $artwork->cropimage(497, 713, 0, 0);
        $artwork->resizeimage(950, 1360, Imagick::FILTER_LANCZOS, 1);
        $newCard->compositeImage($artwork, Imagick::COMPOSITE_DEFAULT, 301, 227);
        
// adding inside/outside black border
        $blackBorder = new Imagick();
        if (FALSE === $blackBorder->readImage($cardCreator . 'black_border.png')) {
            throw new Exception("Black border not found");
        }
        $newCard->compositeImage($blackBorder, Imagick::COMPOSITE_DEFAULT, 0, 0);
        
// Adding rank
        $rank = new Imagick();
        if (FALSE === $rank->readImage($cardCreator . 'rank' . DS . strtolower($cardDatas['type']) . '.png')) {
            throw new Exception("Rank not found");
        }
        $newCard->compositeImage($rank, Imagick::COMPOSITE_DEFAULT, 0, 0);
        
// Adding the faction inner border
        $innerFaction = new Imagick();
        if (FALSE === $innerFaction->readImage($cardCreator . 'inner-faction' . DS . $cardFaction . '.png')) {
            throw new Exception("Faction inner border not found");
        }
        $newCard->compositeImage($innerFaction, Imagick::COMPOSITE_DEFAULT, 0, 0);
        
// Adding rarity
        $rarity = new Imagick();
        if (FALSE === $rarity->readImage($cardCreator . 'rarity' . DS . strtolower($cardDatas['variations'][$cardId . '00']['rarity']) . '.png')) {
            throw new Exception("Rarity not found");
        }
        $newCard->compositeImage($rarity, Imagick::COMPOSITE_DEFAULT, 0, 0);
        
// Adding banner
        $cardBanner = ($cardDatas['type'] === "Gold") ? $cardFaction . "-plus" : $cardFaction;
        $banner = new Imagick();
        if (FALSE === $banner->readImage($cardCreator . 'banner' . DS . $cardBanner . '.png')) {
            throw new Exception("Baner not found");
        }
        $newCard->compositeImage($banner, Imagick::COMPOSITE_DEFAULT, 0, 0);
        
// Adding strength
        $strengthValue = $cardDatas['strength'];

        /**
         * 3 cases exist here :
         * - Strength is one digit
         * - Strength is two digits and there is a 1
         * - Strength is two digits and there is no 1
         * When there is a 1 with another digit, each needs to be closer to the other (1 is thin)
         */
        if (is_int($strengthValue) && $strengthValue > 0) { // Events are 0 or absent
            if ($strengthValue < 10) {
                $strength = new Imagick();
                if (FALSE === $strength->readImage($cardCreator . 'symbols' . DS . 'strength' . DS . 'number' . $strengthValue . '.png')) {
                    throw new Exception("Strength not found");
                }
                $strength->resizeimage(140, 211, Imagick::FILTER_LANCZOS, 1);
                $newCard->compositeImage($strength, Imagick::COMPOSITE_DEFAULT, 206, 191);
            } else {
// Two digits case
                $ten = intval($strengthValue / 10, 10);
                $unit = $strengthValue % 10;
                $strengthTen = new Imagick();

                if (FALSE === $strengthTen->readImage($cardCreator . 'symbols' . DS . 'strength' . DS . 'number' . $ten . '.png')) {
                    throw new Exception("Strength not found");
                }
                $strengthTen->resizeimage(140, 211, Imagick::FILTER_LANCZOS, 1);
                if ($ten === 1 || $unit == 1) {
                    $newCard->compositeImage($strengthTen, Imagick::COMPOSITE_DEFAULT, 143, 191);
                } else {
                    $newCard->compositeImage($strengthTen, Imagick::COMPOSITE_DEFAULT, 160, 191);
                }
                $strengthUnit = new Imagick();
                if (FALSE === $strengthUnit->readImage($cardCreator . 'symbols' . DS . 'strength' . DS . 'number' . $unit . '.png')) {
                    throw new Exception("Strength not found");
                }
                $strengthUnit->resizeimage(140, 211, Imagick::FILTER_LANCZOS, 1);
                if ($ten === 1 || $unit == 1) {
                    $newCard->compositeImage($strengthUnit, Imagick::COMPOSITE_DEFAULT, 230, 191);
                } else {
                    $newCard->compositeImage($strengthUnit, Imagick::COMPOSITE_DEFAULT, 258, 191);
                }
            }
        }
        
// Adding position
        if (!in_array('Event', $cardDatas['positions'])) {
            $position = new Imagick();
            $cardPosition = (count($cardDatas['positions']) == 3) ? 'multiple' : strtolower($cardDatas['positions']);
            if (FALSE === $position->readImage($cardCreator . 'symbols' . DS . 'position' . DS . $cardPosition . '.png')) {
                throw new Exception("Position not found");
            }
            $position->resizeimage(236, 236, Imagick::FILTER_LANCZOS, 1);
            $newCard->compositeImage($position, Imagick::COMPOSITE_DEFAULT, 150, 438);
        }
        
// Adding spy token
        // I guess it's better checking loyalties like that that just checking 'Agent' in categories
        if (in_array('Disloyal', $cardDatas['loyalties']) && !in_array('Loyal', $cardDatas['loyalties'])) {
            $spy = new Imagick();
            if (FALSE === $spy->readImage($cardCreator . 'symbols' . DS . 'position' . DS . 'spy.png')) {
                throw new Exception("Loyauté absente");
            }
            $spy->resizeimage(236, 236, Imagick::FILTER_LANCZOS, 1);
            $newCard->compositeImage($spy, Imagick::COMPOSITE_DEFAULT, 150, 438);
        }
        
// Adding counter
        $countMatch = null;
        if (preg_match('/Counter : ([0-9]+)/i', $cardDatas['info']['en-US'], $countMatch)) {
            // adding the hourglass
            $count = new Imagick();
            if (FALSE === $count->readImage($cardCreator . 'symbols' . DS . 'effects' . DS . 'countdown.png')) {
                throw new Exception("Countdown not found");
            }
            $count->resizeimage(192, 192, Imagick::FILTER_LANCZOS, 1);
            $newCard->compositeImage($count, Imagick::COMPOSITE_DEFAULT, 132, 811);
            
            // adding the number
            $turns = new Imagick();
            if (FALSE === $turns->readImage($cardCreator . 'symbols' . DS . 'strength' . DS . 'number' . $countMatch[1] . '.png')) {
                throw new Exception("Turn not found");
            }
            $turns->resizeimage(121, 183, Imagick::FILTER_LANCZOS, 1);
            $newCard->compositeImage($turns, Imagick::COMPOSITE_DEFAULT, 272, 806);
        }
        
// API version
        $cardsDestination = $cardsFolder . DS . VERSION . DS . $cardId . DS . $cardId . '00' . DS;
        if (!is_dir($cardsDestination)) {
            mkdir($cardsDestination, 0755, true);
        }
        
        $newCard->resizeimage(1850, 2321, Imagick::FILTER_LANCZOS, 1);
        $newApiCard = new Imagick();
        // The original version doesn't use the generateCardFile() function
        // It needs to be placed on a bigger layout to add the same transparent
        // margins as the official source
        $newApiCard->newImage(2186, 2924, new ImagickPixel("rgba(250,15,150,0)"));
        $newApiCard->compositeImage($newCard, Imagick::COMPOSITE_DEFAULT, 164, 330);
        $newApiCard->setImageFileName($cardsDestination . 'original.png');
        if (FALSE == $newApiCard->writeImage()) {
            throw new Exception("Original copy error");
        }
        
        generateCardFile($newApiCard, $cardsDestination, 'high', 1093, 1462);
        generateCardFile($newApiCard, $cardsDestination, 'medium', 547, 731);
        generateCardFile($newApiCard, $cardsDestination, 'low', 274, 366);
        generateCardFile($newApiCard, $cardsDestination, 'thumbnail', 137, 183);
    } catch (Exception $e) {
        echo 'Caught exception: ' . $e->getMessage() . "\n";
    }
}


/**
 * Generate a resized card file
 * @param Imagick $image Card object
 * @param string $imagePath Current destination path
 * @param string $size Size name of the file
 * @param int $width New width of the card
 * @param int $height New height of the card
 * @throws Exception
 */
function generateCardFile($image, $imagePath, $size, $width, $height)
{
    try {
        $image->resizeimage($width, $height, Imagick::FILTER_LANCZOS, 1);
        $image->setImageFileName($imagePath . $size . '.png');
        if (FALSE == $image->writeImage()) {
            throw new Exception(ucfirst($size) . " copy error");
        }
    } catch (Exception $e) {
        echo 'Caught exception: ' . $e->getMessage() . "\n";
    }
}