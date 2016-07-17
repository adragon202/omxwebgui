<?php
/**
* Simple Web GUI for Omxplayer on a Raspberry Pi
*
* @link https://github.com/brainfoolong/omxwebgui
* @author BrainFooLong
* @license GPL v3
*/

error_reporting(E_ALL);
ini_set("display_errors", 1);
set_time_limit(15);
if(version_compare(PHP_VERSION, "5.4", "<")) die("You need PHP 5.4 or higher");
header("X-UA-Compatible: IE=edge");
header("Expires: 0");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require(__DIR__."/lib/functions.php");
require(__DIR__."/lib/Translations.class.php");
require(__DIR__."/lib/OMX.class.php");
require(__DIR__."/lib/subsonic.php");

try{

    //Get Subsonic Settings
    $subsonicFile = __DIR__."/subsonic.json";
    $subsonic = file_exists($subsonicFile) ? json_decode(file_get_contents($subsonicFile), true) : array();
    updateSubsonicAuth($subsonic);
    //Get Options
    $optionsFile = __DIR__."/options.json";
    $options = file_exists($optionsFile) ? json_decode(file_get_contents($optionsFile), true) : array();
    $folders = isset($options["folders"]) ? $options["folders"] : array();
    Translations::$language = isset($options["language"]) ? $options["language"] : "en";

    # json requests
    if(isset($_GET["json"])){
        $data = NULL;
        switch($_GET["action"]){
            case "get-status":
                $data = array("status" => "stopped");
                $output = $return = "";
                exec('sh '.escapeshellcmd(__DIR__."/omx-status.sh"), $output, $return);
                if($return){
                    $data["status"] = "playing";
                    if(file_exists(OMX::$fifoStatusFile)){
                        $json = json_decode(file_get_contents(OMX::$fifoStatusFile), true);
                        $data["path"] = $json["path"];
                        # view cache
                        $hash = getPathHash($data["path"]);
                        if(!isset($options["viewed"][$hash])) {
                            $options["viewed"][$hash] = true;
                            file_put_contents($optionsFile, json_encode($options));
                        }
                        # Check  Subsonic
                        if(strpos($data["path"], '&id=') !== false) {
                            if(!isset($data["name"])) {
                                $tmpid = explode("&",$data["path"])[4];
                                $data["id"] = str_replace("id=","",$tmpid);
                                $info = getSubsonicRequest("info",$data["id"]);
                                foreach($info->song as $songs){
                                    $data["name"] = "Subsonic:".$songs["path"];
                                }
                            }
                        }
                    }
                }else {
                    # start gui
                    $output = $return = "";
                    exec('bash '.escapeshellcmd(__DIR__."/gui-start.sh"), $output, $return);
                }
                break;
        }
        echo json_encode($data);
        exit;
    }

    # ajax requests
    if(isset($_POST["action"])){
        switch($_POST["action"]){
            # getting the filelist
            case "get-filelist":
                # Resolve Local Files
                $files = array();
                foreach($folders as $folder){
                    $files = array_merge($files, isStreamUrl($folder) ? array($folder) : getVideoFiles($folder));
                }
                foreach($files as $file){
                    $classes = array("file");
                    if(isset($options["viewed"][getPathHash($file)])) $classes[] = "viewed";
                    echo '<div class="'.implode(" ", $classes).'" data-folder="0" data-path="'.$file.'" data-subsonicid="0"><span class="eye"></span> <span class="path">'.$file.'</span></div>';
                }
                # Check for Subsonic Connection
                if (!testSubsonicConnection()) {
                    exit;
                    break;
                }
                # Resolve Subsonic Files
                $remotefiles = getSubsonicFiles();
                # Draw Subsonic Files
                foreach($remotefiles as $remotefile) {
                    $classes = array("file");
                    if(isset($options["viewed"][getPathHash($remotefile["name"])])) $classes[] = "viewed";
                    echo '<div class="'.implode(" ", $classes).' directory  subsonic" data-folder="'.$remotefile["directory"].'" data-path="'.$remotefile["name"].'" data-subsonicid="'.$remotefile["id"].'"><span class="path">Subsonic:'.$remotefile["name"].'</span></div>';
                    if($remotefile["directory"]) echo '<div class="list" id="'.$remotefile["id"].'" style="display:none;">'.t("Loading").'...</div>';
                }
                # Draw Subsonic Search region
                echo '<div id="subsonicsearch"></div>';
                exit;
                break;
            # save options
            case "save-options":
                # Save Basic Options
                $folders = explode("\n", trim($_POST["folders"]));
                $error = false;
                foreach($folders as $key => $folder){
                    $folder = trim(str_replace("\\", "/", $folder));
                    $folders[$key] = $folder;
                    if(isStreamUrl($folder)) continue;
                    if(!is_dir($folder) || !is_readable($folder) || !$folder || $folder == "/"){
                        $error = true;
                        unset($folders[$key]);
                    }
                }
                $options["folders"] = $folders;
                $options = array_merge($options, $_POST["option"]);
                file_put_contents($optionsFile, json_encode($options));
                echo t("saved");
                # Save Subsonic Options
                $subsonic["access"]["address"] = trim($_POST["subsonicAddress"]);
                $subsonic["access"]["authuser"] = trim($_POST["subsonicAuthUser"]);
                $subsonic["access"]["authpass"] = trim($_POST["subsonicAuthPass"]);
                file_put_contents($subsonicFile, json_encode($subsonic));
                echo "<br/>".t("saved subsonic");
                if($error) echo t("error.folders");
                echo "<br/>".t("reload.page");
                break;
            # key/mouse click commands
            case "shortcut":
                $startCmd = escapeshellarg($_POST["path"])." ".(isset($options["speedfix"]) && $options["speedfix"] ? "1" : "0");
                switch($_POST["shortcut"]){
                    case "start":
                        $data = array("path" => $_POST["path"]);
                        if(strpos($_POST["path"], '&id=') !== false) {
                            $tmpid = explode("&",$_POST["path"])[4];
                            $data["id"] = str_replace("id=","",$tmpid);
                            $info = getSubsonicRequest("info",$data["id"]);
                            foreach($info->song as $songs){
                                $data["name"] = "Subsonic:".$songs["path"];
                            }
                        }
                        file_put_contents(OMX::$fifoStatusFile, json_encode($data));
                        OMX::sendCommand($startCmd, "start");
                        break;
                    case "p":
                        if(!file_exists(OMX::$fifoFile)){
                            OMX::sendCommand($startCmd, "start");
                        }else{
                            OMX::sendCommand("p", "pipe");
                        }
                        break;
                    default:
                        $key = OMX::$hotkeys[$_POST["shortcut"]];
                        OMX::sendCommand(isset($key["shortcut"]) ? $key["shortcut"] : $_POST["shortcut"], "pipe");
                }
                break;
            # expand/hide folder folder
            case "openfolder":
                # also has name, id, loaded, and expanded
                $remotefiles = getSubsonicFiles($_POST["id"]);
                foreach($remotefiles as $remotefile) {
                    $classes = array("file");
                    if(isset($options["viewed"][getPathHash($remotefile["name"])])) $classes[] = "viewed";
                    if($remotefile["directory"] == "1" || $remotefile["directory"] == "true"){
                    echo '<div class="'.implode(" ", $classes).' directory subsonic" data-folder="'.$remotefile["directory"].'" data-path="'.$remotefile["name"].'" data-subsonicid="'.$remotefile["id"].'"><span class="path">Subsonic:'.$remotefile["parent"].'/'.$remotefile["name"].'</span></div>';
                        echo '<div class="list" id="'.$remotefile["id"].'" style="display:none;">'.t("Loading").'...</div>';
                    }else{
                        echo '<div class="'.implode(" ", $classes).' subsonic" data-folder="'.$remotefile["directory"].'" data-path="'.$remotefile["path"].'" data-subsonicid="'.$remotefile["id"].'"><span class="eye"></span> <span class="path">Subsonic:'.$remotefile["name"].'</span></div>';
                    }
                }
                exit;
                break;
            # Request Subsonic for Search Results
            case "subsonicsearch":
                if (!testSubsonicConnection()) {
                    exit;
                    break;
                }
                $remotefiles = getSubsonicSearch($_POST["search"]);
                foreach($remotefiles as $remotefile) {
                    $classes = array("file");
                    if(isset($options["viewed"][getPathHash($remotefile["name"])])) $classes[] = "viewed";
                    if($remotefile["directory"] == "1" || $remotefile["directory"] == "true"){
                        echo '<div class="'.implode(" ", $classes).' directory subsonic" data-folder="'.$remotefile["directory"].'" data-path="'.$remotefile["name"].'" data-subsonicid="'.$remotefile["id"].'"><span class="path">Subsonic:'.$remotefile["parent"].'/'.$remotefile["name"].'</span></div>';
                        echo '<div class="list" id="'.$remotefile["id"].'" style="display:none;">'.t("Loading").'...</div>';
                    }else{
                        echo '<div class="'.implode(" ", $classes).' subsonic" data-folder="'.$remotefile["directory"].'" data-path="'.$remotefile["path"].'" data-subsonicid="'.$remotefile["id"].'"><span class="eye"></span> <span class="path">Subsonic:'.$remotefile["name"].'</span></div>';
                    }
                }
            exit;
            break;
        }
        die();
    }

    ?>
    <!DOCTYPE html>
    <html>
        <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <meta name="viewport" content="width=670, initial-scale=0.8">
            <link rel="stylesheet" type="text/css" href="css/site.css">
            <meta http-equiv="X-UA-Compatible" content="IE=edge">
            <script type="text/javascript" src="js/jquery.js"></script>
            <script type="text/javascript">
                var omxHotkeys = <?=json_encode(OMX::$hotkeys)?>;
                var options = <?=json_encode($options)?>;
                var subsonic = <?=json_encode($subsonic)?>;
                var language = <?=json_encode(Translations::$language)?>;
                var translations = <?=json_encode(Translations::$translations)?>;
            </script>
            <script type="text/javascript" src="js/scripts.js"></script>
            <title>OMX Web GUI - By BrainFooLong</title>
        </head>
        <body>
            <div class="page">
                <div class="ajax-error"><div></div></div>
                <div class="ajax-success"><div></div></div>
                <div class="header">
                    <a href="https://github.com/brainfoolong/omxwebgui" target="_blank"><img src="images/logo.png" alt="" class="logo"/></a>
                    <div class="box">
                        <form name="opt" method="post" action="">
                            <input type="hidden" name="action" value="save-options"/>
                            <table style="width:100%;"><tbody>
                                <tr><td colspan="2">
                                    <p><?=nl2br(t("folders.desc"))?></p>
                                    <textarea cols="45" rows="3" name="folders"><?=htmlentities(implode("\n", $folders))?></textarea><br/>
                                </td></tr>
                                <tr>
                                    <td>
                                        <?=t("ui.language")?>: <select name="option[language]">
                                        <?php foreach(Translations::$languages as $lang => $label){
                                            echo '<option value="'.$lang.'"';
                                            if(isset($options["language"]) && $options["language"] == $lang) echo ' selected="selected"';
                                            echo '>'.$label.'</option>';
                                        }?>
                                        </select><br/>
                                        <?php displayYesNoOption("speedfix", $options) ?><br/>
                                        <?php displayYesNoOption("autoplay-next", $options) ?><br/>
                                        <br/>
                                        <input type="button" class="action button" data-action="save-options" value="<?=t("save")?>"/>
                                    </td>
                                    <td>
                                        Subsonic Credentials<br>
                                        <input type="text" name="subsonicAddress" placeholder="Server" value="<?=$subcred["access"]["address"]?>"><br>
                                        <input type="text" name="subsonicAuthUser" placeholder="UserName" value="<?=$subcred["access"]["authuser"]?>"><br>
                                        <input type="password" name="subsonicAuthPass" placeholder="Password" value="<?=$subcred["access"]["authpass"]?>">
                                    </td>
                                </tr>
                            </tbody></table>
                        </form>
                    </div>
                </div>
                <div class="files">
                    <div class="status-line"><b><?=t("status")?>:</b> <span id="status"><?=t("loading")?>...</span></div>
                    <div class="results">
                        <input type="text" class="search" value="<?=t("search.input")?>" autocomplete="off"/>
                        <div id="filelist"><?=t("loading")?>...</div>
                    </div>
                </div>
                <div class="omx-buttons">
                    <?php foreach(OMX::$hotkeys as $key => $value){
                        $keyValue = $key;
                        switch($key){
                            case "left": $keyValue = "&#x2190"; break;
                            case "right": $keyValue = "&#x2192"; break;
                            case "up": $keyValue = "&#x2191"; break;
                            case "down": $keyValue = "&#x2193"; break;
                        }
                        echo '<div class="button" data-action="shortcut" data-shortcut="'.$key.'"><span class="shortcut">'.$keyValue.'</span><span class="label">'.nl2br(t("shortcut-$key")).'</span></div>';
                    }?>
                    <div class="clear"></div>
                </div>
                <div class="footer">
                    Powered by BrainFooLong - Contribute on GitHub <a href="https://github.com/brainfoolong/omxwebgui" target="_blank">omxwebgui</a>
                </div>
            </div>
        </body>
    </html>
    <?php
}catch(Exception $e){
    header("HTTP/1.0 500 Internal Server Error");
    echo $e->getMessage()."\n\n";
    echo $e->getTraceAsString();
}

