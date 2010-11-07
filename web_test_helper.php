<?php
require_once 'simpletest/browser.php';

class WebTestHelper extends TestHelper {
  protected $paths = array();
  protected $hostname = "http://localhost";
  function __construct() {
    $this->browser = new SimpleBrowser();
  }
  function namedPath($page) {
    if (!isset($this->paths[$page])) {
      throw new Exception("Unknown page $page. Define it in `\$paths`");
    }
    return $this->hostname . $this->paths[$page];
  }
  /**
   * Given: /I visit "(.+)"/i
   * When: /I visit "(.+)"/i
   */
  function given_i_visit($page) {
    $this->browser->get($this->namedPath($page));
  }

  /**
   * When: /I enter "(.+)" as (.+)/i
   */
  function when_i_enter($value, $field) {
    $this->browser->setField($field, $value);
  }

  /**
   * When: /(press|click) submit/i
   */
  function when_press_submit() {
    $this->browser->clickSubmit();
  }

  /**
   * Then: /I should see "(.+)"/i
   */
  function then_i_should_see($text) {
    $this->assert(preg_match('/'.preg_quote($text).'/', $this->browser->getContentAsText()), "'$text' not found in:\n" . $this->browser->getContent());
  }

}
