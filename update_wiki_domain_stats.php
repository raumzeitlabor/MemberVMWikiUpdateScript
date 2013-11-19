<?php
// Script, welches Arbeitsspeicher und HDD-Belegung von libvirt zieht und sie ins RaumZeitLabor-Wiki packt.
// Fix zusammengehackt von Felicitus am 2013-11-17


$conn = libvirt_connect('null', false);
$doms = libvirt_list_domain_resources($conn);

$totalMemory = 0;
$totalStorage = 0;

$content[] = "== VMs ==\n";

$content[] = "{| class=\"wikitable\"  valign = \"top\"\n";
$content[] = "! VM\n";
$content[] = "! Arbeitsspeicher\n";
$content[] = "! Plattenplatz\n";
$content[] = "|-\n";

foreach ($doms as $dom) {

    if (libvirt_domain_get_id($dom) !== -1) {
        $content[] = "| [[Mate (Server)/" . libvirt_domain_get_name($dom) . "]] \n";

        $memory = round(libvirt_domain_memory_stats($dom)[6] / 1024);
        $totalMemory += $memory;
        $content[] = "|" . $memory . " MB \n";

        $storage = 0;

        $devices = libvirt_domain_get_disk_devices($dom);
        unset($devices["num"]);
        foreach ($devices as $disk) {
            $storageInfo = @libvirt_domain_get_block_info($dom, $disk);

            $storage += $storageInfo["capacity"];
        }

        $storage = round($storage / 1024 / 1024 / 1024, 1);
        $content[] = "|" . $storage . " GB\n";

        $totalStorage += $storage;

        $content[] = "|-\n";
    }
}

$totalHostMemory = 12001;
$totalHostStorage = 5400;

$content[] = "| '''Gesamt'''\n";
$content[] = "| '''" . $totalMemory . " MB / " . $totalHostMemory . " MB (" . round(
        ($totalMemory / $totalHostMemory) * 100,
        2
    ) . "% belegt)'''\n";
$content[] = "| '''" . round($totalStorage / 1024, 1) . " TB / " . round($totalHostStorage / 1024, 1) . " TB (" . round(
        ($totalStorage / $totalHostStorage) * 100,
        2
    ) . "% belegt)'''\n";
$content[] = "|}\n";

$content[] = "\nAutomatisch geupdatet von [[Benutzer:Matebot]] am: " . date("Y-m-d H:i:s") . "\n";
// load Zend classes
require_once 'Zend/Loader.php';
Zend_Loader::loadClass('Zend_Rest_Client');
Zend_Loader::loadClass('Zend_Http_Client');
Zend_Loader::loadClass('Zend_Http_CookieJar');
try {
    // initialize REST client
    $client = new Zend_Http_Client();
    $cookieJar = new Zend_Http_CookieJar();
    $client->setCookieJar($cookieJar);
    $wikipedia = new Zend_Rest_Client('https://wiki.raumzeitlabor.de/api.php');
    $wikipedia->setHttpClient($client);

    $wikipedia->action('login');
    $wikipedia->lgname('Matebot');
    $wikipedia->lgpassword('hermes88');
    $wikipedia->format('xml');
    $result = $wikipedia->post();

    $token = $result->login->attributes()->token[0]->__toString();
    $wikipedia->action('login');
    $wikipedia->lgname('Matebot');
    $wikipedia->lgpassword('hermes88');
    $wikipedia->format('xml');
    $wikipedia->lgtoken($token);
    $result = $wikipedia->post();

    $wikipedia->action('query');
    $wikipedia->prop('info');
    $wikipedia->intoken('edit');
    $wikipedia->titles('RaumZeitLabor:Sandbox');
    $wikipedia->format('xml');
    $result = $wikipedia->get();

    $edittoken = $result->query->pages->page->attributes()->edittoken[0]->__toString();

    $wikipedia->action('parse');
    $wikipedia->page("Mate (Server)");
    $wikipedia->prop("sections");
    $wikipedia->format("xml");

    $result = $wikipedia->get();

    $foundSection = false;
    $sectionCount = 1;
    foreach ($result->parse->sections->s as $key => $section) {
        if ($section->attributes()->line == "VMs") {
            $foundSection = $sectionCount;
            break;
        }
        $sectionCount++;
    }

    if ($foundSection === false) {
        echo "Could not find section named VMs, exit.";
        exit;
    }

    $wikipedia->action('edit');
    $wikipedia->title('Mate (Server)');
    $wikipedia->section($foundSection);
    $wikipedia->summary('Updated VM list');
    $wikipedia->text(implode("", $content));
    $wikipedia->token($edittoken);

    $result = $wikipedia->post();

    print_r($result);
} catch (Exception $e) {
    print_r($e);
}
