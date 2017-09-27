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
 *                                  php card_generator.php cards 152101,152102,152103
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
// Let's check whether we can perform the magick.
if (!extension_loaded('imagick')) {
    die('Imagick extension is not loaded.' . PHP_EOL);
}

/* * ***************
 * CONFIGURATION *
 * ************** */
        const DS = DIRECTORY_SEPARATOR,
        VERSION = 'v0-9-10', // Only important as the destination folder
        MAX_CARDS = 5, // Maximum generated cards. 0 for no limit
        JSON_FILENAME = 'cards.json', // Current JSON filename, same folder as the script
        CARDS_FOLDER = 'images',
        ASSETS_FOLDER = 'assets',
        RESIZE_FILTER = Imagick::FILTER_LANCZOS;


require_once 'vendor/autoload.php';

ob_implicit_flush(true);
ob_start();

$Generator = new CardGenerator();
$Generator->start();

/**
 * Gwent Card Generator
 */
class CardGenerator
{

    private $_microtime;
    private $_cardsJson;
    private $_card;
    private $_errors;
    private $_notReleased;

    public function __construct()
    {
        set_time_limit(0);
        $this->_errors = 0;
        $this->_notReleased = 0;
        $this->_microtime = microtime(true);

        // Start with parsing the JSON
        $this->_cardsJsonToArray();
    }

    /**
     * Start point, checking parameters for the right action
     * @global array $argv CLI-passed arguments
     */
    public function start()
    {
        global $argv;

        if (PHP_SAPI == 'cli') { // command-line
            // Check the existence of `cards` and a list of ID
            $cardsParam = array_search('cards', $argv);
            if (($cardsParam !== false) && (!empty($argv[$cardsParam + 1]))) {
                $this->_startCardList(explode(',', $argv[$cardsParam + 1]));
            } else {
                $this->_startCardList();
            }
        } else { // HTTP
            die(var_dump($_GET));
            if (!empty($_GET['cards'])) {
                $this->_startCardList(explode(',', $_GET['cards']));
            } else {
                $this->_startCardList();
            }
        }
    }

    /**
     * Start the generation of all cards from the card list
     * It will stops when all cards are generated or MAX_CARDS is got
     * @param array $cardIdList Array of card IDs. If null, all cards are generated
     */
    private function _startCardList($cardIdList = null)
    {
        $cards = 0;
        if (empty($cardIdList)) {
            // No list ? so the JSON id will be the list
            $cardIdList = array_keys($this->_cardsJson);
        }
        foreach ($cardIdList as $cardId) {
            if ($this->_generateGwentCard($cardId)) {
                $cards++;
            }
            if ($cards >= MAX_CARDS) {
                $this->_echoFlush(" [v] Max numbers of cards achieved (" . MAX_CARDS . ")");
                break;
            }
        }
        $time = round(microtime(true) - $this->_microtime, 2) . " sec";
        $this->_echoFlush($cards . " cards generated in " . $time);
        $this->_echoFlush("Generation errors : " . $this->_errors);
        $this->_echoFlush("Not-released cards : " . $this->_notReleased);
    }

    /**
     * Generate a Gwent card
     * @param int $cardId Game ID of the card to generate
     * @return
     */
    private function _generateGwentCard($cardId)
    {
        if (empty($this->_cardsJson[$cardId])) {
            $this->_echoFlush(" [x] Card ID " . $cardId . " not found");
            $this->_errors++;
            return false;
        }
        if ($this->_cardsJson[$cardId]['released'] != 1) {
            $this->_echoFlush(" [x] Card ID " . $cardId . " has not been released");
            $this->_notReleased++;
            return false;
        }
        $cardDatas = $this->_formatJsonCardDatas($this->_cardsJson[$cardId]);
        try {
            $microtime = microtime(true);
            $this->_generateCardImagick($cardDatas);
            $time = round(microtime(true) - $microtime, 2) . " sec";
            $this->_echoFlush(" [v] Card ID " . $cardId . " generated (" . $time . ")");
        } catch (Exception $ex) {
            $this->_echoFlush(" [x] Card ID " . $cardId . " not generated : " . $ex->getMessage());
            $this->_errors++;
            return false;
        }
        return true;
    }

    /**
     * Display an output message
     * @param type $message
     */
    private function _echoFlush($message)
    {
        echo $message;
        if (PHP_SAPI == 'cli') {
            echo PHP_EOL;
        } else {
            echo '<br>';
        }
        ob_flush();
    }

    /**
     * Parse the JSON file to use a PHP array
     * @throws Exception
     */
    private function _cardsJsonToArray()
    {
        $microtime = microtime(true);
        if (!file_exists(JSON_FILENAME)) {
            throw new Exception("JSON file not found");
        }
        // json_decode is too slow getting all the datas in memory
        // The streaming listener is faster
        $listener = new \JsonStreamingParser\Listener\InMemoryListener();
        $stream = fopen(JSON_FILENAME, 'r');
        try {
            $parser = new JsonStreamingParser\Parser($stream, $listener);
            $parser->parse();
            fclose($stream);
        } catch (Exception $e) {
            fclose($stream);
            throw $e;
        }
        $this->_cardsJson = current($listener->getJson());
        $time = round(microtime(true) - $microtime, 2) . " sec";
        $this->_echoFlush(" [v] JSON parsing done (" . $time . ")");
    }

    /**
     * Format the card datas to simplify the code and stay consistant if the
     * structure comes to change
     * Also provide a data template to extend the script to card datas from others
     * sources than Gwent-data
     * @param array $cardDatas Array from the JSON
     * @return array Datas formated to the essential
     */
    private function _formatJsonCardDatas($cardDatas)
    {
        switch ($cardDatas['faction']) {
            case "Northen Realms":
                $faction = 'northernrealms';
                break;
            case "Monster":
                $faction = 'monsters';
                break;
            case "Skellige":
                $faction = 'skellige';
                break;
            case "Scoiatael":
                $faction = 'scoiatael';
                break;
            case "Nilfgaard":
                $faction = 'nilfgaard';
                break;
            default:
                $faction = 'neutral';
        }

        $spy = (in_array('Disloyal', $cardDatas['loyalties']) && !in_array('Loyal', $cardDatas['loyalties'])) ? true : false;
        if (in_array('Event', $cardDatas['positions'])) {
            $position = false;
        } else if (count($cardDatas['positions']) == 3) {
            $position = 'multiple';
        } else {
            $position = strtolower(current($cardDatas['positions']));
        }

        $countMatch = null;
        if (preg_match('/Counter : ([0-9]+)/i', $cardDatas['info']['en-US'], $countMatch)) {
            $count = $countMatch[1];
        } else {
            $count = false;
        }

        return [
            'id' => $cardDatas['ingameId'],
            'faction' => $faction,
            'type' => strtolower($cardDatas['type']),
            'rarity' => strtolower($cardDatas['variations'][$cardDatas['ingameId'] . '00']['rarity']),
            'strength' => $cardDatas['strength'],
            'position' => $position,
            'spy' => $spy,
            'count' => $count,
            'banner' => ($cardDatas['type'] === "Gold") ? $faction . "-plus" : $faction,
        ];
    }

    /**
     * Add a composite image on the current generated image
     * @param string $stepName Name of the current step
     * @param string $stepFile Path to the file to the current step
     * @throws Exception
     */
    private function _composeImage($stepName, $stepFile)
    {
        $compose = new Imagick();
        if ($compose->readImage($stepFile) !== true) {
            throw new Exception($stepName . " not found");
        }
        $this->_card->compositeImage($compose, Imagick::COMPOSITE_DEFAULT, 0, 0);
    }

    /**
     * Card generation
     * @param array $cardDatas Datas of the card
     * @throws Exception
     */
    private function _generateCardImagick($cardDatas)
    {
        $this->_card = new Imagick();
        if ($this->_card->readImage(ASSETS_FOLDER . DS . 'image_layout.png') !== true) {
            throw new Exception("Layout not found");
        }

        // adding the artwork
        $artwork = new Imagick();
        if ($artwork->readImage(ASSETS_FOLDER . DS . 'artworks' . DS . $cardDatas['id'] . '00.png') !== true) {
            throw new Exception("Artwork not found");
        }
        // Cropping the artwork to remove the transparent excess
        $artwork->cropimage(497, 713, 0, 0);
        $artwork->resizeimage(950, 1360, RESIZE_FILTER, 1);
        $this->_card->compositeImage($artwork, Imagick::COMPOSITE_DEFAULT, 301, 227);

        // Adding the constant-position elements
        $this->_composeImage('Black border', ASSETS_FOLDER . DS . 'black_border.png');
        $this->_composeImage('Rank', ASSETS_FOLDER . DS . 'rank' . DS . $cardDatas['type'] . '.png');
        $this->_composeImage('Inner-faction', ASSETS_FOLDER . DS . 'inner-faction' . DS . $cardDatas['faction'] . '.png');
        $this->_composeImage('Rarity', ASSETS_FOLDER . DS . 'rarity' . DS . $cardDatas['rarity'] . '.png');
        $this->_composeImage('Banner', ASSETS_FOLDER . DS . 'banner' . DS . $cardDatas['banner'] . '.png');

        // Adding strength
        /**
         * 3 cases exist here :
         * - Strength is one digit
         * - Strength is two digits and there is a 1
         * - Strength is two digits and there is no 1
         * When there is a 1 with another digit, each needs to be closer to the other (1 is thin)
         */
        if (is_int($cardDatas['strength']) && $cardDatas['strength'] > 0) { // Events are 0 or absent
            if ($cardDatas['strength'] < 10) {
                $strength = new Imagick();
                if ($strength->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'strength' . DS . 'number' . $cardDatas['strength'] . '.png') !== true) {
                    throw new Exception("Strength not found");
                }
                $strength->resizeimage(140, 211, RESIZE_FILTER, 1);
                $this->_card->compositeImage($strength, Imagick::COMPOSITE_DEFAULT, 206, 191);
            } else {
                // Two digits case
                $ten = intval($cardDatas['strength'] / 10, 10);
                $unit = $cardDatas['strength'] % 10;
                $strengthTen = new Imagick();

                if ($strengthTen->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'strength' . DS . 'number' . $ten . '.png') !== true) {
                    throw new Exception("Strength not found");
                }
                $strengthTen->resizeimage(140, 211, RESIZE_FILTER, 1);
                if ($ten === 1 || $unit == 1) {
                    $this->_card->compositeImage($strengthTen, Imagick::COMPOSITE_DEFAULT, 143, 191);
                } else {
                    $this->_card->compositeImage($strengthTen, Imagick::COMPOSITE_DEFAULT, 160, 191);
                }
                $strengthUnit = new Imagick();
                if ($strengthUnit->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'strength' . DS . 'number' . $unit . '.png') !== true) {
                    throw new Exception("Strength not found");
                }
                $strengthUnit->resizeimage(140, 211, RESIZE_FILTER, 1);
                if ($ten === 1 || $unit == 1) {
                    $this->_card->compositeImage($strengthUnit, Imagick::COMPOSITE_DEFAULT, 230, 191);
                } else {
                    $this->_card->compositeImage($strengthUnit, Imagick::COMPOSITE_DEFAULT, 258, 191);
                }
            }
        }

        // Adding position
        if ($cardDatas['position']) {
            $position = new Imagick();
            if ($position->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'position' . DS . $cardDatas['position'] . '.png') !== true) {
                throw new Exception("Position not found");
            }
            $position->resizeimage(236, 236, RESIZE_FILTER, 1);
            $this->_card->compositeImage($position, Imagick::COMPOSITE_DEFAULT, 150, 438);
        }

        // Adding spy token
        if ($cardDatas['spy']) {
            $spy = new Imagick();
            if ($spy->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'position' . DS . 'spy.png') !== true) {
                throw new Exception("Loyalty not found");
            }
            $spy->resizeimage(236, 236, RESIZE_FILTER, 1);
            $this->_card->compositeImage($spy, Imagick::COMPOSITE_DEFAULT, 150, 438);
        }

        // Adding counter
        if ($cardDatas['count']) {
            // adding the hourglass
            $count = new Imagick();
            if (FALSE === $count->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'effects' . DS . 'countdown.png')) {
                throw new Exception("Countdown not found");
            }
            $count->resizeimage(192, 192, RESIZE_FILTER, 1);
            $this->_card->compositeImage($count, Imagick::COMPOSITE_DEFAULT, 132, 811);

            // adding the number
            $turns = new Imagick();
            if (FALSE === $turns->readImage(ASSETS_FOLDER . DS . 'symbols' . DS . 'strength' . DS . 'number' . $cardDatas['count'] . '.png')) {
                throw new Exception("Turn number not found");
            }
            $turns->resizeimage(121, 183, RESIZE_FILTER, 1);
            $this->_card->compositeImage($turns, Imagick::COMPOSITE_DEFAULT, 272, 806);
        }

        // API version
        $cardsDestination = CARDS_FOLDER . DS . VERSION . DS . $cardDatas['id'] . DS . $cardDatas['id'] . '00' . DS;
        if (!is_dir($cardsDestination)) {
            mkdir($cardsDestination, 0755, true);
        }
        $this->_card->resizeimage(1850, 2321, RESIZE_FILTER, 1);

        $newApiCard = new Imagick();
        // The original version doesn't use the generateCardFile() function
        // It needs to be placed on a bigger layout to add the same transparent
        // margins as the official source
        $newApiCard->newImage(2186, 2924, new ImagickPixel("rgba(250,15,150,0)"));
        $newApiCard->compositeImage($this->_card, Imagick::COMPOSITE_DEFAULT, 164, 330);
        $newApiCard->setImageFileName($cardsDestination . 'original.png');
        if (FALSE == $newApiCard->writeImage()) {
            throw new Exception("Original copy error");
        }

        $this->_generateCardFile($newApiCard, $cardsDestination, 'high', 1093, 1462);
        $this->_generateCardFile($newApiCard, $cardsDestination, 'medium', 547, 731);
        $this->_generateCardFile($newApiCard, $cardsDestination, 'low', 274, 366);
        $this->_generateCardFile($newApiCard, $cardsDestination, 'thumbnail', 137, 183);
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
    private function _generateCardFile($image, $imagePath, $size, $width, $height)
    {
        $image->resizeimage($width, $height, RESIZE_FILTER, 1);
        $image->setImageFileName($imagePath . $size . '.png');
        if (FALSE == $image->writeImage()) {
            throw new Exception(ucfirst($size) . " copy error");
        }
    }

}
