<?php
// Script, welches Arbeitsspeicher und HDD-Belegung von libvirt zieht und sie ins RaumZeitLabor-Wiki packt.
// Fix zusammengehackt von Felicitus am 2013-11-17

require_once 'Zend/Loader.php';
include("config.php");

$domainResultSet = getDomains();

$content[] = "== VMs ==";

$content[] = '{| class="wikitable"  valign="top"';
$content[] = "! VM";
$content[] = "! Arbeitsspeicher";
$content[] = "! Plattenplatz";
$content[] = "|-";

ksort($domainResultSet["domains"]);

$percentMemory = round(($domainResultSet["totalMemory"] / $totalHostMemory) * 100, 2);
$percentStorage = round(($domainResultSet["totalStorage"] / $totalHostStorage) * 100, 2);

foreach ($domainResultSet["domains"] as $name => $data) {
    $content[] = "| [[Mate (Server)/" . $name . "]]";
    $content[] = "| " . $data["memory"] . " MB";
    $content[] = "| " . $data["storage"] . " GB";
    $content[] = "|-";
}

$content[] = "| '''Gesamt'''";
$content[] = "| '''" . $domainResultSet["totalMemory"] . " MB / " . $totalHostMemory . " MB (" . $percentMemory . "% belegt)'''";
$content[] = "| '''" . round($domainResultSet["totalStorage"] / 1024, 1) . " TB / " . round(
        $totalHostStorage / 1024,
        1
    ) . " TB (" . $percentStorage . "% belegt)'''";
$content[] = "|}";
$content[] = "";
$content[] = "Automatisch geupdatet von [[Benutzer:Matebot]] am: " . date("Y-m-d H:i:s");
$content[] = "";

$hashLockFile = 'last_page_hash.txt';

$pagContent = implode("\n", $content);
$hashLock     = md5($pageContent);

if(!file_exists($hashLockFile) || file_get_contents($hashLockFile) != $hashLock) {
    publishToWiki($wikiURI, $wikiUsername, $wikiPassword, $wikiPage, $wikiSection, $pageContent);
    file_put_contents($hashLockFile, $hashLock);
}

/**
 * Returns an associative array with all running domains, their memory- and storage consumption.
 *
 * memory is in MB, storage in GB.
 * Example return:
 *
 * array(
 *  "totalMemory" => 1024,
 *  "totalStorage" => 30,
 *  "domains" => array(
 *     "infra.rzl" => array("memory" => 1024, "storage" => 30)
 *   )
 * )
 *
 * @return array
 */
function getDomains () {
    $totalMemory = 0;
    $totalStorage = 0;

    $conn = libvirt_connect('null', false);
    $doms = libvirt_list_domain_resources($conn);

    $domains = array();


    foreach ($doms as $dom) {
        $domain = array();
        if (libvirt_domain_get_id($dom) !== -1) {

            $content[] = "| [[Mate (Server)/" . libvirt_domain_get_name($dom) . "]] \n";

            $memory = round(libvirt_domain_memory_stats($dom)[6] / 1024);
            $totalMemory += $memory;
            $domain["memory"] = $memory;

            $storage = 0;

            $devices = libvirt_domain_get_disk_devices($dom);
            unset($devices["num"]);
            foreach ($devices as $disk) {
                // The following call triggers a warning for some domains; we'll ignore that as we can't work around
                $storageInfo = @libvirt_domain_get_block_info($dom, $disk);

                $storage += $storageInfo["capacity"];
            }

            $storage = round($storage / 1024 / 1024 / 1024, 1);
            $totalStorage += $storage;
            $domain["storage"] = $storage;

            $domains[libvirt_domain_get_name($dom)] = $domain;
        }
    }

    return array("domains" => $domains, "totalMemory" => $totalMemory, "totalStorage" => $totalStorage);
}

/**
 * Publishes content to a specific wiki page and section.
 *
 * @param $apiURI   The API URI endpoint for mediawiki
 * @param $username The username for the bot
 * @param $password The password for the bot
 * @param $page     The page to edit
 * @param $section  The section name to edit
 * @param $content  The content to publish
 */
function publishToWiki ($apiURI, $username, $password, $page, $section, $content) {

    Zend_Loader::loadClass('Zend_Rest_Client');
    Zend_Loader::loadClass('Zend_Http_Client');
    Zend_Loader::loadClass('Zend_Http_CookieJar');

    try {
        // initialize REST client
        $client = new Zend_Http_Client();
        $cookieJar = new Zend_Http_CookieJar();
        $client->setCookieJar($cookieJar);
        $wikipedia = new Zend_Rest_Client($apiURI);
        $wikipedia->setHttpClient($client);

        // Attempt login to get a login token
        $wikipedia->action('login');
        $wikipedia->lgname($username);
        $wikipedia->lgpassword($password);
        $wikipedia->format('xml');
        $result = $wikipedia->post();

        $token = $result->login->attributes()->token[0]->__toString();

        // Login with the received token
        $wikipedia->action('login');
        $wikipedia->lgname($username);
        $wikipedia->lgpassword($password);
        $wikipedia->format('xml');
        $wikipedia->lgtoken($token);
        $result = $wikipedia->post();

        // Retrieve an edit token
        $wikipedia->action('query');
        $wikipedia->prop('info');
        $wikipedia->intoken('edit');
        $wikipedia->titles($page);
        $wikipedia->format('xml');
        $result = $wikipedia->get();

        $edittoken = $result->query->pages->page->attributes()->edittoken[0]->__toString();

        // Retrieve the sections for the target page
        $wikipedia->action('parse');
        $wikipedia->page($page);
        $wikipedia->prop("sections");
        $wikipedia->format("xml");

        $result = $wikipedia->get();

        $foundSection = false;
        $sectionCount = 1;
        foreach ($result->parse->sections->s as $key => $oSection) {
            if ($oSection->attributes()->line == $section) {
                $foundSection = $sectionCount;
                break;
            }
            $sectionCount++;
        }

        if ($foundSection === false) {
            echo "Could not find section named ".$section.", exit.";
            exit;
        }

        $wikipedia->action('edit');
        $wikipedia->title($page);
        $wikipedia->section($foundSection);
        $wikipedia->summary('Updated VM list');
        $wikipedia->text($content);
        $wikipedia->token($edittoken);

        $result = $wikipedia->post();

    } catch (Exception $e) {
        print_r($e);
    }
}
