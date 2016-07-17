<?php
/**
* Functions can be used to generate objects of Subsonic Directories
* and provide links to stream and download content.
*
* @global $subcred object containing address and credentials for access to subsonic server
*/
$subcred = array();
$subcred["access"]["address"] = "";
$subcred["access"]["authuser"] = "";
$subcred["access"]["authpass"] = "";

/**
* Handles all function calls that are needed in the process of
* applying new credentials
*
* @var $newcred object to update server credentials with.
*/
function updateSubsonicAuth($newcred)
{
	global $subcred;
	if (!testSubsonicCredentials($newcred)) {
		return false;
	}
	//Apply Credentials
	$subcred = $newcred;
	return true;
}

/**
* Forms the URL request needed for the subsonic server 
*
* @var $request string to base request on
* @var $id string id for requests that require an id
* @return string url request
*/
function SubsonicRequest($request = "", $id = "")
{
	global $subcred;
	//Initial String
	$str = "http://" . $subcred["access"]["address"] . "/rest/";
	//REST API request
	switch($request)
	{
		case "stream":
			$str .= "stream.view?";
			break;
		case "dir":
			$str .= "getMusicDirectory.view?";
			break;
		case "root":
			$str .= "getIndexes.view?";
			break;
		case "download":
			$str .= "download.view?";
			break;
		case "search":
			$str .= "search.view?";
			break;
		case "info":
			$str .= "getSong.view?";
			break;
		default:
			$str .= "ping.view?";
	}
	// Add Credentials
	$str .= "u=" . $subcred["access"]["authuser"] . "&p=" . $subcred["access"]["authpass"] . "&v=1.14&c=rpimedia";
	if ($id != ""){
		$str .= "&id=" . $id;
	}
	return $str;
}

/**
* Gets the XML request from the rest api of the subsonic server and returns an object
*
* @var $request string to base request on
* @var $id string id for requests that require an id
* @return object parsed from subsonic request
*/
function getSubsonicRequest($request = "", $id = "")
{
	$str = SubsonicRequest($request, $id);
	$retRequest = simplexml_load_file($str) or die("Error: Cannot create object from request " . $str);
	return $retRequest;
}

/**
* Gets the XML request from the rest api of the subsonic server for a search of the given title
* and returns an object.
*
* @var $request string to search for
* @return object parsed from subsonic request
*/
function getSubsonicSearchRequest($search = "")
{
	$str = SubsonicRequest("search");
	$retRequest = simplexml_load_file($str . "&title=" . $search) or die("Error: Cannot create object from request " . $str);
	return $retRequest;
}

/**
* Test connection to Subsonic Server with set credentials.
*
* @return boolean result of sending a ping.view request to the server.
*/
function testSubsonicConnection()
{
	$str = SubsonicRequest("ping");
	if (!urlExists($str)){
		return false;
	}
	$results = simplexml_load_file($str) or die("Error: Cannot create object from request " . $str);
	if ($results["status"] == "failed") {
		return false;
	}
	return true;
}

/**
* Test connection to Subsonic Server with given credentials.
*
* @var $cred array containing access credentials to test against server.
* @return boolean result of sending a ping.view request to the server.
*/
function testSubsonicCredentials($cred)
{
	global $subcred;
	//Test given credentials for indexes
	if (!array_key_exists("access", $cred)) return false;
	if (!array_key_exists("address", $cred["access"])) return false;
	if (!array_key_exists("authuser", $cred["access"])) return false;
	if (!array_key_exists("authpass", $cred["access"])) return false;
	//Test  given credentials against server
	$tmpcred = $subcred;
	$subcred = $cred;
	$results = testSubsonicConnection();
	$subcred = $tmpcred;
	return $results;
}

/**
* Parses the root directory of the subsonic server into an object including names and indexes
*
* @return object parsed from subsonic request for root directory, along with a directory flag.
*/
function getSubsonicRoot()
{
	//Get Root Folders to navigate
	$rootdirxml = getSubsonicRequest("root");
	$rootfolders = array();
	$i = 0;
	foreach ($rootdirxml->indexes as $indexes) {
		foreach ($indexes->index as $letter) {
			foreach ($letter->artist as $folder) {
				$rootfolders[$i] = $folder;
				$rootfolders[$i]["directory"] = true;
				$i++;
			}
		}
	}
	//Draw folders for initial navigation
	return $rootfolders;
}

/**
* Gets the contents of the directory in the subsonic server with the given id.
*
* @var $id string id of directory to get contents of
* @return object containing id, parent name, parent id, directory flag, and name of each file and directory.
*/
function getSubsonicFiles($id="")
{
	if ($id == "") return getSubsonicRoot();
	$subsonicfiles = getSubsonicRequest("dir", $id);
	//Parse Subsonic request into proper object to return.
	$files = array();
	foreach ($subsonicfiles->directory as $subsonicdirectory){
		foreach ($subsonicdirectory->child as $subsonicfile){
			$tmpfile = array();
			$tmpfile["id"] = $subsonicfile["id"];
			$tmpfile["parent"] = $subsonicdirectory["name"];
			$tmpfile["parentid"] = $subsonicfile["parent"];
			$tmpfile["directory"] = $subsonicfile["isDir"];
			$tmpfile["name"] = $subsonicfile["title"];
			$tmpfile["path"] = $subsonicfile["title"];
			if ($tmpfile["directory"] == "0" || $tmpfile["directory"] == "false") $tmpfile["name"] = $subsonicfile["path"];
			if ($tmpfile["directory"] == "0" || $tmpfile["directory"] == "false") $tmpfile["path"] = SubsonicRequest("stream", $tmpfile["id"]);
			array_push($files, $tmpfile);
		}
	}
	//Sort contents by name.
	usort($files, 'sortSubsonicByName');
	return $files;
}

/**
* Gets the results of a Subsonic search.
*
* @var $search string to search for on the Subsonic server
* @return object containing id, parent name, parent id, directory flag, and name of each file and directory.
*/
function getSubsonicSearch($search = "")
{
	$subsonicfiles = getSubsonicSearchRequest($search);
	//Parse Subsonic request into proper object to return.
	$files = array();
	foreach ($subsonicfiles->searchResult as $subsonicResult) {
		foreach ($subsonicResult->match as $subsonicMatch) {
			$tmpfile = array();
			$tmpfile["id"] = $subsonicMatch["id"];
			$tmpfile["parent"] = $subsonicMatch["album"];
			$tmpfile["parentid"] = $subsonicMatch["parent"];
			$tmpfile["directory"] = $subsonicMatch["isDir"];
			$tmpfile["name"] = $subsonicMatch["title"];
			$tmpfile["path"] = $subsonicMatch["title"];
			if ($tmpfile["directory"] == "0" || $tmpfile["directory"] == "false") $tmpfile["name"] = $subsonicMatch["path"];
			if ($tmpfile["directory"] == "0" || $tmpfile["directory"] == "false") $tmpfile["path"] = SubsonicRequest("stream", $tmpfile["id"]);
			array_push($files, $tmpfile);
		}
	}
	return $files;
}

/**
* Used by usort function.
* Returns the positive, 0 or negative based on object comparison
*
* @var $a object containing name attribute
* @var $b object containing name attribute
*/
function sortSubsonicByName($a, $b){
	return strcasecmp($a['name'], $b['name']);
}

/**
* Checks connection to given url as though pinging it.
* @source http://www.wrichards.com/blog/2009/05/php-check-if-a-url-exists-with-curl/
*
* @var $url string to check as valid url
*/
function urlExists($url=NULL)  
{  
	if($url == NULL) return false;
	if (!$ch = curl_init($url)) return false;
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$data = curl_exec($ch);
	$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if($httpcode>=200 && $httpcode<300){
		return true;
	} else {
		return false;
	}
}

?>
