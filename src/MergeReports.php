<?php
namespace Codeception\Task;

use Robo\Contract\TaskInterface;
use Robo\Exception\TaskException;
use Robo\Task\BaseTask;

trait MergeReports
{
    protected function taskMergeXmlReports($src = [])
    {
        return $this->task(MergeXmlReportsTask::class, $src);
    }

    protected function taskMergeHTMLReports($src = [])
    {
        return $this->task(MergeHTMLReportsTask::class, $src);
    }
}

interface MergeReportsTaskInterface
{
    public function from($fileName);

    public function into($fileName);
}

class MergeXmlReportsTask extends BaseTask implements TaskInterface, MergeReportsTaskInterface
{
    protected $src = [];
    protected $dst;
    protected $summarizeTime = true;

    protected $mergeRewrite = false;
    /** @var \DOMElement[][] */
    protected $suites = [];

    public function __construct($src = [])
    {
        $this->src = $src;
    }

    public function sumTime()
    {
        $this->summarizeTime = true;
    }

    public function maxTime()
    {
        $this->summarizeTime = false;
    }

    public function mergeRewrite()
    {
        $this->mergeRewrite = true;
        return $this;
    }

    public function from($fileName)
    {
        if (is_array($fileName)) {
            $this->src = array_merge($fileName, $this->src);
        } else {
            $this->src[] = $fileName;
        }
        return $this;
    }

    public function into($fileName)
    {
        $this->dst = $fileName;
        return $this;
    }

    public function run()
    {
        if (!$this->dst) {
            throw new TaskException($this, "No destination file is set. Use `->into()` method to set result xml");
        }
        $this->printTaskInfo("Merging JUnit XML reports into {$this->dst}");
        $dstXml = new \DOMDocument();
        $dstXml->appendChild($dstXml->createElement('testsuites'));

        $this->suites = [];
        foreach ($this->src as $src) {
            $this->printTaskInfo("Processing $src");

            $srcXml = new \DOMDocument();
            if (!file_exists($src)) {
                throw new TaskException($this, "XML file $src does not exist");
            }
            if (empty($src)) {
                throw new TaskException($this, "XML file $src is empty");
            }
            $loaded = $srcXml->load($src);
            if (!$loaded) {
                $this->printTaskInfo("<error>File $src can't be loaded as XML</error>");
                continue;
            }
            $suiteNodes = (new \DOMXPath($srcXml))->query('//testsuites/testsuite');
            $i = 0;
            foreach ($suiteNodes as $suiteNode) {
                $this->suiteDuration[$suiteNode->getAttribute('name')][] = (float) $suiteNode->getAttribute('time');
                $suiteNode = $dstXml->importNode($suiteNode, true);
                /** @var $suiteNode \DOMElement  **/
                $this->loadSuites($suiteNode);
                $i++;
            }
        }
        $this->mergeSuites($dstXml);

        $dstXml->save($this->dst);
        $this->printTaskInfo("File <info>{$this->dst}</info> saved. ".count($this->suites).' suites added');
    }

    protected function loadSuites(\DOMElement $current)
    {
        /** @var \DOMNode $node */
        foreach ($current->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                if ($this->mergeRewrite) {
                    $this->suites[$current->getAttribute('name')][$node->getAttribute('class') . '::' . $node->getAttribute('name')] = $node->cloneNode(true);
                } else {
                    $this->suites[$current->getAttribute('name')][] = $node->cloneNode(true);
                }
            }
        }
    }

    protected function mergeSuites(\DOMDocument $dstXml)
    {
        $i = 0;
        foreach ($this->suites as $suiteName => $tests) {
            $resultNode = $dstXml->createElement("testsuite");
            $resultNode->setAttribute('name', $suiteName);
            $data = [
                'tests' => count($tests),
                'assertions' => 0,
                'failures' => 0,
                'errors' => 0,
                'time' => 0,
            ];

            foreach ($tests as $test) {
                $resultNode->appendChild($test);

                $data['assertions'] += (int)$test->getAttribute('assertions');
                if ($this->summarizeTime) {
                    (float) $test->getAttribute('time') + $data['time'];
                }
                $data['failures'] += $test->getElementsByTagName('failure')->length;
                $data['errors'] += $test->getElementsByTagName('error')->length;
            }
            if (!$this->summarizeTime) {
                $data['time'] = max($this->suiteDuration[$suiteName]);
            }
            
            foreach ($data as $key => $value) {
                $resultNode->setAttribute($key, $value);
            }
            $dstXml->firstChild->appendChild($resultNode);
            $i++;
        }
    }
}

/**
 * Generate common HTML report
 * Class MergeHTMLReportsTask
 * @author Kerimov Asif
 */
class MergeHTMLReportsTask extends BaseTask implements TaskInterface, MergeReportsTaskInterface
{
    protected $src = [];
    protected $dst;
    protected $countSuccess = 0;
    protected $countFailed = 0;
    protected $countSkipped = 0;
    protected $countIncomplete = 0;
    protected $previousLibXmlUseErrors;
    protected $insertNodeBeforeText;

    public function __construct($src = [])
    {
        $this->src = $src;
    }

    public function from($fileName)
    {
        if (is_array($fileName)) {
            $this->src = array_merge($fileName, $this->src);
        } else {
            $this->src[] = $fileName;
        }
        return $this;
    }

    public function into($fileName)
    {
        $this->dst = $fileName;
        return $this;
    }

    public function run()
    {
        //save initial statament and switch on use_internal_errors mode
        $this->previousLibXmlUseErrors = libxml_use_internal_errors(true);

        if (!$this->dst) {
            libxml_use_internal_errors($this->previousLibXmlUseErrors);
            throw new TaskException($this, "No destination file is set. Use `->into()` method to set result HTML");
        }

        $this->printTaskInfo("Merging HTML reports into {$this->dst}");

        //read template source file as main
        $dstHTML = new \DOMDocument();
        $dstHTML->loadHTMLFile('tests/_data/template_parallel_report.html',LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        //main node for all table rows
        $table = (new \DOMXPath($dstHTML))->query("//table")->item(0);

        //prepare reference nodes for envs
        $refnodes = (new \DOMXPath($dstHTML))->query("//div[@class='layout']/table/tr[not(@class)]");
        
        $this->prepareHtmlFiles($refnodes);

        for($k=0;$k<count($this->src);$k++){
            $this->printTaskInfo("Processing " . $this->src[$k]);
            $srcHTML = new \DOMDocument();
            $srcHTML->loadHTMLFile($this->src[$k]);
            $srcDURATION[$k] = $this->getDurationFile($srcHTML);
            $suiteNodes = (new \DOMXPath($srcHTML))->query("//div[@class='layout']/table/tr");
            
            $j=0;
            foreach($suiteNodes as $suiteNode){
                if($suiteNode->getAttribute('class') == ''){ 
                    //move to next reference node
                    $j++;
                    if($j > $refnodes->length-1) break;
                    continue;
                }
                //insert nodes before current reference node
                $suiteNode = $dstHTML->importNode($suiteNode, true);
                $table->insertBefore($suiteNode, $refnodes->item($j));
            }
        }
        
        /**
         * The next 6 functions correct our almost finished final report
         */
        $this->updateTitleReport($dstHTML, $srcDURATION);
        $this->countSummary($dstHTML);
        $this->moveSummaryTable($dstHTML,$table);
        $this->updateSummaryTable($dstHTML);
        $this->updateToolbarTable($dstHTML);
        $this->updateButtons($dstHTML);

        //save final report
        file_put_contents($this->dst,$dstHTML->saveHTML());

        //return to initial statement
        libxml_use_internal_errors($this->previousLibXmlUseErrors);
    }

    private function prepareHtmlFiles($refnodes) {

        //in template, we have always the four suites + summary (we want to check if all suites names are present)
        foreach ($refnodes as $refnode) {
            $arrayRefNodes[] = trim($refnode->textContent);
        }

        for ($i=0; $i<count($this->src); $i++){
            if (!file_exists($this->src[$i])) {
                throw new TaskException($this, "HTML file $this->src[$i] does not exist");
            }
            if (empty($this->src[$i])) {
                throw new TaskException($this, "HTML file $this->src[$i] is empty");
            }
            $srcHTML = new \DOMDocument();
            $src = $this->src[$i];
            $srcHTML->loadHTMLFile($src);
            $srcTable = (new \DOMXPath($srcHTML))->query("//table")->item(0);
            $srcRefNodes = (new \DOMXPath($srcHTML))->query("//div[@class='layout']/table/tr[not(@class)]");

            foreach ($srcRefNodes as $srcRefNode) {
                $arraySrcRefNodes[$i][] = trim($srcRefNode->textContent);
            }
            
            if (array_key_exists($i, $arraySrcRefNodes)) {
                $diffRefNodes = array_diff($arrayRefNodes, $arraySrcRefNodes[$i]);

                if (empty($diffRefNodes)) {
                    return;
                }
                $this->insertNodeBeforeText = null;
                foreach ($diffRefNodes as $key => $node) {

                    if (strpos($node, 'Summary') === false) {
                        $newTR = $this->createRefNodeMissing($srcHTML, $node);
                        $insertBeforeNode = $this->getBeforeNode($srcHTML, $arrayRefNodes, $key);
                        $srcTable->insertBefore($newTR, $insertBeforeNode);
                        $newHtml = $srcHTML->saveHTML();
                        $doc = new \DOMDocument();
                        $doc->formatOutput = true;
                        $doc->loadHTML($newHtml);
                        $doc->saveHTMLFile($src);
                    }
                }
            }
        }
    }

    private function createRefNodeMissing($src, $node) {
        $newTR = $src->createElement("tr");
        $newTD = $src->createElement("td");
        $newH3 = $src->createElement("h3");
        $newH3Text = $src->createTextNode($node);
        $newH3->appendChild($newH3Text);
        $newTD->appendChild($newH3);
        $newTR->appendChild($newTD);
        return $src->importNode($newTR, true);
    }

    private function getBeforeNode($src, $array, $key) {
        $this->insertNodeBeforeText = $array[$key+1];
        if (strpos($this->insertNodeBeforeText, 'Summary') !== false) {
            $this->insertNodeBeforeText = 'Summary';
        }
        $insertBeforeNode = (new \DOMXPath($src))->query("//div[@class='layout']/table/tr[not(@class)][contains(.,'" . $this->insertNodeBeforeText . "')]")->item(0);
        
        if (!$insertBeforeNode) {
            $this->insertNodeBeforeText = $array[$key+2];
            if (strpos($this->insertNodeBeforeText, 'Summary') !== false) {
                $this->insertNodeBeforeText = 'Summary';
            }
            $insertBeforeNode = (new \DOMXPath($src))->query("//div[@class='layout']/table/tr[not(@class)][contains(.,'" . $this->insertNodeBeforeText . "')]")->item(0);
        }
        return $insertBeforeNode;
    }

    /**
     * This function return all durations for each files 
     * @param $srcFile \DOMDocument - src file
     */
    private function getDurationFile($srcFile){
        if (!(new \DOMXPath($srcFile))->query("//div[@class='layout']/h1/small")->item(0)) {
            return;
        }
        $regexDuration = '/(\d{1,}[\.]{0,1}\d{0,})/';
        preg_match($regexDuration, (new \DOMXPath($srcFile))->query("//div[@class='layout']/h1/small")->item(0)->textContent, $matches);
        return $matches[0];
    }

    /**
     * This function counts all types of tests' scenarios and writes in class members
     * @param $dstFile \DOMDocument - destination file
     */
    private function countSummary($dstFile){
        $tests = (new \DOMXPath($dstFile))->query("//table/tr[contains(@class,'scenarioRow')]");
        foreach($tests as $test){
            $class = str_replace('scenarioRow ', '', $test->getAttribute('class'));
            switch($class){
                case 'scenarioSuccess':
                    $this->countSuccess += 0.5;
                    break;
                case 'scenarioFailed':
                    $this->countFailed += 0.5;
                    break;
                case 'scenarioSkipped':
                    $this->countSkipped += 0.5;
                    break;
                case 'scenarioIncomplete':
                    $this->countIncomplete += 0.5;
                    break;
            }
        }
    }

    /**
     * This function updates values in Summary block for each type of scenarios
     * @param $dstFile \DOMDocument - destination file
     */
    private function updateSummaryTable($dstFile){
        $dstFile = new \DOMXPath($dstFile);
        $pathFor = function ($type) { return "//div[@id='stepContainerSummary']//td[@class='$type']";};
        $dstFile->query($pathFor('scenarioSuccessValue'))->item(0)->nodeValue = $this->countSuccess;
        $dstFile->query($pathFor('scenarioFailedValue'))->item(0)->nodeValue = $this->countFailed;
        $dstFile->query($pathFor('scenarioSkippedValue'))->item(0)->nodeValue = $this->countSkipped;
        $dstFile->query($pathFor('scenarioIncompleteValue'))->item(0)->nodeValue = $this->countIncomplete;
    }

    /**
     * This function moves Summary block in the bottom of result report
     * @param $dstFile \DOMDocument - destination file
     * @param $node \DOMNode - parent node of Summary table
     */
    private function moveSummaryTable($dstFile,$node){
        $summaryTable = (new \DOMXPath($dstFile))->query("//div[@id='stepContainerSummary']")
            ->item(0)->parentNode->parentNode;
        $node->appendChild($dstFile->importNode($summaryTable,true));
    }

    /**
     * This function updates values in Toolbar block for each type of scenarios (blue block on the left side of the report)
     * @param $dstFile \DOMDocument - destination file
     */
    private function updateToolbarTable($dstFile){
        $dstFile = new \DOMXPath($dstFile);
        $pathFor = function ($type) {return "//ul[@id='toolbar-filter']//a[@title='$type']";};
        $dstFile->query($pathFor('Successful'))->item(0)->nodeValue = '✔ '.$this->countSuccess;
        $dstFile->query($pathFor('Failed'))->item(0)->nodeValue = '✗ '.$this->countFailed;
        $dstFile->query($pathFor('Skipped'))->item(0)->nodeValue = 'S '.$this->countSkipped;
        $dstFile->query($pathFor('Incomplete'))->item(0)->nodeValue = 'I '.$this->countIncomplete;
    }

    /**
     * This function updates "+" and "-" button for viewing test steps in final report
     * @param $dstFile \DOMDocument - destination file
     */
    private function updateButtons($dstFile){
        $nodes = (new \DOMXPath($dstFile))->query("//div[@class='layout']/table/tr[contains(@class, 'scenarioRow')]");
        
        for($i=2;$i<$nodes->length;$i+=2){
            $n = $i/2 + 1;
            $tdP = $nodes->item($i)->childNodes->item(1);
            if ($tdP->nodeType !== 1) {
                foreach ($nodes->item($i)->childNodes as $childNode) {
                    if ($childNode->nodeType === 1 && $childNode->tagName === 'td') {
                        $tdP = $childNode;
                        break;
                    }
                }

            }
            $tdTable = $nodes->item($i+1)->childNodes->item(1);
            if ($tdTable->nodeType !== 1) {
                foreach ($nodes->item($i+1)->childNodes as $childNode) {
                    if ($childNode->nodeType === 1 && $childNode->tagName === 'td') {
                        $tdTable = $childNode;
                        break;
                    }
                }
            }
            $p = $tdP->childNodes->item(1);
            $table = $tdTable->childNodes->item(1);
            $p->setAttribute('onclick',"showHide('$n', this)");
            $table->setAttribute('id',"stepContainer".$n);
        }
    }

    /**
     * This function updates h1 in final report
     * @param $dstFile \DOMDocument - destination file
     * @param $titleHTML array
     */
    private function updateTitleReport($dstFile, $titleHTML){
        $title = (new \DOMXPath($dstFile))->query("//div[@class='layout']/h1/small")->item(0);
        $statusHTML = (new \DOMXPath($dstFile))->query("//div[@class='layout']/h1/small/span")->item(0);
        $duration = max($titleHTML);
        $statusHTML->nodeValue = ($this->countFailed > 0) ? 'FAILED' : 'OK';
        $statusHTML->setAttribute('style', ($this->countFailed === 0) ? 'color: green' : 'color: #e74c3c');
        $durationText = $dstFile->createTextNode(' (' . $duration . 's)');
        $title->appendChild($durationText);
    }

}
