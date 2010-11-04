<?php
class Parser {
  protected $lastAction = null;
  protected $openScenario = false;
  protected $lineNumber = 0;
  protected $fileName;
  protected $executor;
  protected $reporter;
  function __construct($executor, $reporter) {
    $this->executor = $executor;
    $this->reporter = $reporter;
  }

  function parse($input, $fileName) {
    $this->lineNumber = 0;
    $this->fileName = $fileName;
    foreach (explode("\n", $input) as $line) {
      $this->lineNumber++;
      $this->accept($line);
    }
    $this->flush();
  }
  /*
    Feature -> onFeature -> printFeature
    Feature + Unknown -> onUnknown -> printText
    Scenario -> onScenario -> printScenario
    Comment -> onUnknown -> printText
    Action -> runAction -> { printPass | printFail | printMissing }
  */
  function accept($line) {
    if (preg_match('/^\s*(feature|scenario):\s*(.+)$/i', $line, $mm)) {
      $this->{"_".$mm[1]}($line);
    } elseif (preg_match('/^\s*(given|and|but|when|then)\s*(.+)$/i', $line, $mm)) {
      $this->{"_".$mm[1]}($line, $mm[2]);
    } else {
      $this->_unknown($line);
    }
  }
  function flush() {
    if ($this->openScenario) {
      $this->openScenario = false;
      $this->executor->_endScenario();
    }
  }
  function _feature($line) {
    $this->flush();
    $this->reporter->printFeature($line, $this->fileName, $this->lineNumber);
  }
  function _scenario($line) {
    $this->flush();
    $this->executor->_beginScenario();
    $this->openScenario = true;
    $this->reporter->printScenario($line, $this->fileName, $this->lineNumber);
  }
  function reportActionResult($line, $result) {
    switch ($result[0]) {
    case 'PASS':
      $this->reporter->printPass($line, $result[1], $this->fileName, $this->lineNumber);
      break;
    case 'FAIL':
      $this->reporter->printFail($line, $result[1], $this->fileName, $this->lineNumber);
      break;
    case 'MISSING':
      $this->reporter->printMissing($line, $result[1], $this->fileName, $this->lineNumber);
      break;
    case 'NOFAIL':
      $this->reporter->printNoFail($line, $result[1], $this->fileName, $this->lineNumber);
      break;
    default:
      throw new Exception("Unexpected status '{$result[0]}'");
    }
  }
  function _given($line, $text) {
    $this->lastAction = '_given';
    $this->reportActionResult($line, $this->executor->_given($text));
  }
  function _when($line, $text) {
    $this->lastAction = '_when';
    $this->reportActionResult($line, $this->executor->_when($text));
  }
  function _then($line, $text) {
    $this->lastAction = '_then';
    $this->reportActionResult($line, $this->executor->_then($text));
  }
  function _and($line, $text) {
    $this->{$this->lastAction}($line, $text);
  }
  function _but($line, $text) {
    $this->{$this->lastAction}($line, $text);
  }
  function _unknown($line) {
    $this->reporter->printText($line);
  }
}

class Asserter {
  protected $passes = array();
  protected $failures = array();
  function pass() {
    $this->passes[] = "Okey-Dokey";
  }
  function fail($message) {
    $this->failures[] = $message;
  }
  function passes() {
    return $this->passes;
  }
  function failures() {
    return $this->failures;
  }
}

class AnnotationExecutor {
  protected $helper;
  function __construct($helper) {
    $this->helper = $helper;
  }
  function _given($text) {
    return $this->dispatch('given', $text);
  }
  function _when($text) {
    return $this->dispatch('when', $text);
  }
  function _then($text) {
    return $this->dispatch('then', $text);
  }
  function _beginScenario() {
    $this->helper->setUp();
  }
  function _endScenario() {
    $this->helper->tearDown();
  }
  function dispatch($keyword, $text) {
    $klass = new ReflectionClass($this->helper);
    foreach ($klass->getMethods() as $m) {
      if (preg_match('/'.$keyword.':\s*([^\n]+)/i', $m->getDocComment(), $mm)) {
        if (preg_match($mm[1], $text, $mmm)) {
          array_shift($mmm);
          $asserter = new Asserter();
          $this->helper->setAsserter($asserter);
          try {
            ob_start();
            call_user_func_array(array($this->helper, $m->getName()), $mmm);
          } catch (Exception $ex) {
            ob_get_clean();
            return array('FAIL', $ex);
          }
          $output = ob_get_clean();
          if ($asserter->failures()) {
            if ($output) {
              $output .= "\n---\n";
            }
            return array('FAIL', $output . implode("\n", $asserter->failures()));
          }
          if (strtolower($keyword) != "then" || $asserter->passes()) {
            return array('PASS', $output);
          }
          return array('NOFAIL', $output);
        }
      }
    }
    return array('MISSING', $keyword);
  }
}

class TestHelper {
  protected $asserter;
  function setAsserter($asserter) {
    $this->asserter = $asserter;
  }
  function pass() {
    $this->asserter->pass();
  }
  function fail($message) {
    $this->asserter->fail($message);
  }
  function setUp() {}
  function tearDown() {}
}

class ConsoleReporter {
  protected $showAdvise = false;
  function showAdvise() {
    $this->showAdvise = true;
  }
  function _red($text) {
    return "\x1B[1;31m" . $text . "\x1B[00m";
  }
  function _green($text) {
    return "\x1B[1;32m" . $text . "\x1B[00m";
  }
  function _yellow($text) {
    return "\x1B[1;33m" . $text . "\x1B[00m";
  }
  function _blue($text) {
    return "\x1B[1;34m" . $text . "\x1B[00m";
  }
  function _purple($text) {
    return "\x1B[0;35m" . $text . "\x1B[00m";
  }
  function _bold($text) {
    return "\x1B[1m" . $text . "\x1B[22m";
  }
  function _comment($text) {
    return "\x1B[1;30m" . $text . "\x1B[00m";
  }
  function printFeature($line, $fileName, $lineNumber) {
    echo $this->_bold(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
  }
  function printScenario($line, $fileName, $lineNumber) {
    echo $this->_bold(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
  }
  function printText($line) {
    echo $this->_bold($line), "\n";
  }
  function printPass($line, $message, $fileName, $lineNumber) {
    echo $this->_green(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
    if ($message) {
      echo $message, "\n";
    }
  }
  function printNoFail($line, $message, $fileName, $lineNumber) {
    echo $this->_yellow(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
    if ($message) {
      echo $message, "\n";
    }
  }
  function printFail($line, $message, $fileName, $lineNumber) {
    echo $this->_red(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
    echo $message, "\n";
  }
  function printMissing($line, $keyword, $fileName, $lineNumber) {
    echo $this->_blue(str_pad($line, 60)), $this->_comment(" # $fileName : $lineNumber"), "\n";
  }
  function printAdvise($message) {
    if ($this->showAdvise) {
      echo $this->_purple($this->_bold("[Advise]\n") . $message), "\n";
    }
  }
}

class LogAdviseProxyReporter {
  protected $reporter;
  public $missing = array();
  public $noFail = array();
  public $scenarioCount = 0;
  function __construct($reporter) {
    $this->reporter = $reporter;
  }
  function __call($method, $args) {
    if ($method == 'printMissing') {
      $this->missing[] = array(
        'line' => $args[0],
        'keyword' => $args[1],
        'lineNumber' => $args[3]);
    }
    if ($method == 'printNoFail') {
      $this->noFail[] = array(
        'line' => $args[0],
        'lineNumber' => $args[3]);
    }
    if ($method == 'printScenario') {
      $this->scenarioCount++;
    }
    return call_user_func_array(array($this->reporter, $method), $args);
  }
}

class FeatureFileRunner {
  protected $featureFileName;
  protected $advise = array();
  function __construct($featureFileName) {
    $this->featureFileName = $featureFileName;
  }
  function run($reporter) {
    $helper = $this->findHelper($this->featureFileName);
    $log = new LogAdviseProxyReporter($reporter);
    $p = new Parser(new AnnotationExecutor($helper), $log);
    $p->parse(file_get_contents($this->featureFileName), $this->featureFileName);
    if ($log->scenarioCount == 0) {
      $this->advise[] = "You don't have any scenarios in your feature. You can use the following template:"
        . "\n" . "
  Scenario: ...
    Given ...
    When ...
    Then ...
";
    }
    foreach ($log->missing as $missing) {
      if (preg_match('/^\s*(given|when|then|but|and)\s*(.+)$/i', $missing['line'], $mm)) {
        $keyword = ucfirst(strtolower($missing['keyword']));
        $body = $mm[2];
        $mehod_name = strtolower($keyword) . '_' . trim(strtolower(preg_replace('/[^a-zA-Z]+/', '_', trim($mm[2]))), '_');
        $this->advise[] = "You have an unrecognised step in line " . $missing['lineNumber'] . "."
          . " You can use the following template:"
          . "\n\n" . "  /**
   * " . $keyword . ": /".preg_quote($body, '/')."/i
   */
  function " . $mehod_name . "() {
  }
";
      }
    }
    foreach ($log->noFail as $noPass) {
      if (preg_match('/^\s*(then|and|but)\s*(.+)$/i', $noPass['line'], $mm)) {
        $this->advise[] = "Your test on line " . $noPass['lineNumber']. " does not make any assertions.";
      }
    }
    //    if ($this->advise) {
    //      $reporter->printAdvise($this->advise[0], $this->featureFileName);
    //    }
    foreach ($this->advise as $advise) {
      $reporter->printAdvise($advise, $this->featureFileName);
    }
  }
  function findHelper($featureFileName) {
    if (!is_dir('features/helpers')) {
      $this->advise[] = "Directory features/helpers/ not found. Create it with:\n    mkdir features/helpers";
    }
    if (preg_match('~^features/(.+)\.feature$~', $featureFileName, $mm)) {
      $name = $mm[1];
      $helperFileName = 'features/helpers/' . $name . '_helper.php';
      if (is_file($helperFileName)) {
        require_once($helperFileName);
        $className = implode(array_map('ucfirst', array_map('strtolower', explode('_', $name)))) . 'Helper';
        if (class_exists($className)) {
          return new $className;
        } else {
          $this->advise[] = "Class $className not found in $helperFileName. You can use the following template:"
            . "\n\n" . "class $className extends TestHelper {
}
";
        }
      } else {
        $this->advise[] = "No helper found for $featureFileName. Create the file $helperFileName";
      }
    } else {
      throw new Exception("Feature filename doesn't match expected pattern");
    }
    return new TestHelper();
  }
}

class FeatureRunner {
  function run($reporter) {
    $found = false;
    if (is_dir('features')) {
      foreach (scandir('features') as $node) {
        if (preg_match('~\.feature$~', $node)) {
          $found = true;
          $runner = new FeatureFileRunner('features/' . $node);
          $runner->run($reporter);
        }
      }
      if (!$found) {
        $reporter->printAdvise(
          "No feature specs found. Start by creating one in features/my_feature.feature. You can use the following template:"
          . "\n" . "Feature: ...
  In order ...
  As a ...
  I want ...
");
      }
    } else {
      $reporter->printAdvise("Directory features/ not found. Create it with:\n    mkdir features");
    }
  }
}
