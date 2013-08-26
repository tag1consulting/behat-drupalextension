<?php
/**
 * @file
 * Override DrupalContext with Tag1 extensions.
 *
 * When writing new methods, please consider:
 * - Given can do things
 * - Then should just check things
 *
 * And when formualting the regex language
 * - Given should have an action verb
 * - Then should be an affirmative statement
 *
 * The word "should" is even good in an affirmative statement
 * But offer multiple options, as in "I should see the link"
 * and "I see the link".
 */

namespace Drupal\DrupalExtension\Context;

use Drupal\DrupalExtension\Context\DrupalContext;
use Drupal\Component\Utility\Random;
use Behat\Behat\Context\Step\Given;
use Behat\Behat\Context\Step\When;
use Behat\Behat\Context\Step\Then;
use Behat\Mink\Element\NodeElement;

/**
 * Features context.
 */
class Tag1Context extends DrupalContext {
  private $tag1Parameters;
  private $mailCreds;
  private $mailMessages;
  private $currentField;
  private $vars;

  /**
   * @defgroup initialization
   * @{
   */

  /**
   * Initializes context.
   *
   * Every Scenario gets its own context object.
   *
   * @param array $parameters.
   *   Context parameters (set them up through behat.yml or behat.local.yml).
   */
  public function __construct(array $parameters) {
    $this->tag1Parameters = $parameters;
    $this->tag1Parameters += array(
      'user_account' => 'My Account',
    );
    $this->mailCreds = array();
    $this->mailMessages = array();
  }

  /**
   * Before running the suite, clear the cache once.
   *
   * Ideally this should be done in a BeforeSuite hook.
   * But since BeforeSuite and BeforeFeature hooks use
   * static classes, we don't have access to $this,
   * which is needed for Drush.
   *
   * To get around this restriction, run this before
   * every scenario, but then make sure that the cache
   * is cleared only once.
   *
   * @BeforeScenario
   */
  public function beforeScenario($event) {
    static $done = FALSE;
    if (!$done) {
      print "  Clear The Cache\n";
//    $this->iClearTheCache();
      $done = TRUE;
    }
    $this->vars = NULL;
  }

  /**
   * @} End of "defgroup initialization".
   *
   * @defgroup MinkContext overrides
   * @{
   */

  /**
   * Override MinkContext::pressButton().
   */
  public function pressButton($button) {
    // Figure out which button based on the fields most recently filled in.
    if (!empty($this->currentField)) {
      // Clear the current field after any button press.
      $current_field = $this->currentField;
      $this->currentField = NULL;

      // Find the form somewhere up from the current field.
      for ($parent_element = $current_field; $parent_element; $parent_element = $parent_element->getParent()) {
        if ($parent_element->getTagName() == 'form') {
          $button_element = $parent_element->findButton($button);
          if ($button_element) {
            $button_element->press();
            return;
          }
        }
      }
    }

    // If the above didn't work, use Mink's pressButton().
    DrupalContext::pressButton($button);
  }

  /**
   * Override MinkContext::fillField().
   */
  public function fillField($field, $value) {
    // Remember this field, so we can use it to figure out the right button.
    $this->currentField = $this->getSession()->getPage()->findField($field);

    // Fill in the field.
    DrupalContext::fillField($field, $value);
  }

  /**
   * Override MinkContext::fixStepArgument().
   */
  protected function fixStepArgument($argument) {
    $argument = str_replace('\\"', '"', $argument);

    // Initialize the replaceable arguments.
    $this->initVars();

    // Token replace the argument.
    static $random = array();
    for ($start = 0; ($start = strpos($argument, '[', $start)) !== FALSE; ) {
      $end = strpos($argument, ']', $start);
      if ($end === FALSE) {
        break;
      }
      $name = substr($argument, $start + 1, $end - $start - 1);
      if ($name == 'random') {
        $this->vars[$name] = Random::name(8);
        $random[] = $this->vars[$name];
      }
      elseif ($name == 'mail:new') {
        $name = 'mail';
        unset($this->vars[$name]);
        $this->mailCreds = array();
        $this->initVars();
      }
      // In order to test previous random values stored in the form,
      // suppport random:n, where n is the number or random's ago
      // to use, i.e., random:1 is the previous random value.
      elseif (substr($name, 0, 7) == 'random:') {
        $num = substr($name, 7);
        if (is_numeric($num) && $num <= count($random)) {
          $this->vars[$name] = $random[count($random) - $num];
        }
      }
      if (isset($this->vars[$name])) {
        $argument = substr_replace($argument, $this->vars[$name], $start, $end - $start + 1);
        $start += strlen($this->vars[$name]);
      }
      else {
        $start = $end + 1;
      }
    }

    return $argument;
  }

  /**
   * @} End of "defgroup MinkContext overrides".
   *
   * @defgroup DrupalContext overrides
   * @{
   */

  /**
   * Visit a given path, and check for errors.
   *
   * @see DrupalExtension::iAmAt().
   */
  public function iAmAt($path) {
    $this->getSession()->visit($this->locatePath($path));

    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $body = $page_element->getText();
    $parse_error = strstr($body, 'Parse error:') === FALSE;
    if (stristr($body, 'Parse error:') !== FALSE) {
      throw new \Exception('The page has a "' . substr($body, $parse_error, 512) . '"');
    }

    // @todo: add checks for other errors.
  }

  /**
   * Override DrupalContext::iClick().
   */
  public function iClick($link) {
    $this->iShouldSeeTheLink($link);
    return parent::iClick($link);
  }

  /**
   * Override DrupalContext::iShouldSeeTheLink().
   *
   * Checks for the link on the page.
   * This is overriden so that the link could also be a class or Id.
   *
   * @param string $link
   *   Link text, CSS class, or CSS Id to look for.
   */
  public function iShouldSeeTheLink($link, $affirmative = TRUE) {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $link_element = $this->findLink($page_element, $link);
    if ($affirmative) {
      if (!$link_element) {
        throw new \Exception("No link to '" . $link . "'");
      }
    }
    else {
      if ($link_element) {
        throw new \Exception("The link '" . $link . "' was present and was not supposed to be.");
      }
    }
  }

  /**
   * Override DrupalContext::iShouldNotSeeTheLink().
   */
  public function iShouldNotSeeTheLink($link) {
    $this->iShouldSeeTheLink($link, FALSE);
  }

  /**
   * Override DrupalContext::iShouldSeeTheHeading().
   *
   * @see Tag1Context::iShouldNotSeeTheHeading().
   */
  public function iShouldSeeTheHeading($heading) {
    DrupalContext::iShouldSeeTheHeading($this->fixStepArgument($heading));
  }

  /**
   * @} End of "defgroup DrupalContext overrides".
   *
   * @defgroup Given/When actions
   * @{
   */

  /**
   * Wait for a number of seconds.
   *
   * @Given /^(?:|I )wait (?:|for )"(?P<seconds>[^"]*)" second(?:|s)$/
   */
  public function iWaitForSeconds($seconds) {
    print "    Wait $seconds";
    while ($seconds --) {
      usleep(1000000);
      print $seconds ? ((($seconds % 10) == 0) ? $seconds : ".") : "\n";
    }
  }

  /**
   * Mouse over a link.
   *
   * @Given /^I mouse over "(?P<link>[^"]*)"$/
   *
   * @param string $link
   *   Link text, CSS class, or CSS Id to look for.
   */
  public function iMouseOver($link) {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $link_element = $this->findLink($page_element, $link);
    if (!$link_element) {
      throw new \Exception('Link "' . $link . '" not found.');
    }
    $link_element->mouseOver();
  }

  /**
   * Goto a page without checking for errors.
   *
   * @Given /^I goto "(?P<path>[^"]*)"$/
   */
  public function iGoto($path) {
    $this->getSession()->visit($this->locatePath($path));
  }

  /**
   * Click on the link in a block with the title or class.
   *
   * @When /^I click "(?P<link>[^"]*)" in block "(?P<block>[^"]*)"$/
   *
   * @param string $link
   *   Link text, CSS class, or CSS Id to look for.
   * @param string $block
   *   Block to search, identified by CSS Id. 
   */
  public function iClickLinkInBlock($link, $block) {
    // Get the page.
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }

    // Get the block.
    $block_element = $page_element->find('xpath', "//div[@id='$block']");
    if (!$block_element) {
      throw new \Exception('Block "' . $block . '" not found.');
    }

    // Get the link in the block.
    $link_element = $this->findLink($block_element, $link);
    if (!$link_element) {
      throw new \Exception('Main link "' . $link . '" not found in "' . $block . '".');
    }

    // Click the link.
    $link_element->click();
  }

  /**
   * @When /^I check (?:the box )?"(?P<option>[^"]*)" in "(?P<block>[^"]*)"$/
   *
   * @param string $option
   *   Checkbox option text.
   * @param string $block
   *   Block to search, identified by CSS Id. 
   */
  public function iCheckOptionInBlock($option, $block) {
    // Get the page.
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }

    // Get the block.
    $block_element = $page_element->find('xpath', "//div[@id='$block']");
    if (!$block_element) {
      throw new \Exception('Block "' . $block . '" not found.');
    }

    $block_element->checkField($this->fixStepArgument($option));
  }

  /**
   * @When /^I uncheck (?:the box )?"(?P<option>[^"]*)" in "(?P<block>[^"]*)"$/
   *
   * @param string $option
   *   Checkbox option text.
   * @param string $block
   *   Block to search, identified by CSS Id. 
   */
  public function iUncheckOptionInBlock($option, $block) {
    // Get the page.
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }

    // Get the block.
    $block_element = $page_element->find('xpath', "//div[@id='$block']");
    if (!$block_element) {
      throw new \Exception('Block "' . $block . '" not found.');
    }

    $block_element->uncheckField($this->fixStepArgument($option));
  }

  /**
   * Clear the cache.
   *
   * @Given /^I clear the cache$/
   */
  public function iClearTheCache() {
    $driver = $this->getDriver('drush');
    $driver->drush('cache-clear', array('all'));
  }

  /**
   * @} End of "defgroup Given/When actions".
   *
   * @defgroup Then conditionals
   * @{
   */

  /**
   * Check for the dropdown link.
   *
   * @Then /^I (?:should )?see the dropdown link "(?P<link>[^"]*)"$/
   *
   * @param string $link
   *   Link text, CSS class, or CSS Id to look for.
   * @param $affirmative
   *   When set to TRUE, checks for the link.
   *   When set to FALSE, checks that the link does not exist.
   */
  public function iShouldSeeTheDropdownLink($link, $affirmative = TRUE) {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $link_element = $this->findLink($page_element, $link);
    if (!$link_element) {
      throw new \Exception('No link to "' . $link . '" on "' . $this->getSession()->getCurrentUrl() . '".');
    }
    for ($parent_element = $link_element; $parent_element; $parent_element = $parent_element->getParent()) {
      $class = $parent_element->getAttribute('class');
      if ($class == 'single_dropdown_wrapper') {
        $style = $parent_element->getAttribute('style');
        if ($style) {
          $styles = explode(';', $style);
          if ($affirmative == empty($styles[1])) {
            throw new \Exception('Link to "' . $link . '" is not hidden by CSS and should be.');
          }
        }
        break;
      }
    }
  }

  /**
   * Check that the dropdown link does not exist.
   *
   * @Then /^I (?:do|should) not see the dropdown link "(?P<link>[^"]*)"$/
   */
  public function iShouldNotSeeTheDropdownLink($link) {
    return $this->iShouldSeeTheDropdownLink($link, FALSE);
  }

  /**
   * Check that the breadcrumb exists.
   *
   * @Then /^I (?:should )?see the breadcrumb "([^"]*)"$/
   *
   * @param string $breadcrumb
   *   Breadcrumb text to look for.
   * @param $affirmative
   *   When set to TRUE, checks for the breadcrumb
   *   When set to FALSE, checks that the breadcrumb does not exist.
   */
  public function iShouldSeeTheBreadcrumb($breadcrumb, $affirmative = TRUE) {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $breadcrumb_element = $page_element->find('xpath', "//span[@class='panel_breadcrumbs']");
    if (!$breadcrumb_element) {
      if ($affirmative) {
        throw new \Exception('No breadcrumb found on the page.');
      }
      return;
    }
    if ($breadcrumb_element->getText() == $breadcrumb) {
      if (!$affirmative) {
        throw new \Exception('The breadcrumb "' . $breadcrumb . '" was found on the page.');
      }
    }
    elseif ($affirmative) {
      throw new \Exception('The breadcrumb "' . $breadcrumb . '" was not found on the page.');
    }
  }

  /**
   * Check that the breadcrumb does not exist.
   *
   * @Then /^I (?:do|should) not see the breadcrumb "([^"]*)"$/
   */
  public function iShouldNotSeeTheBreadcrub($breadcrumb) {
    $this->iShouldSeeTheBreadcrumb($breadcrumb, FALSE);
  }

  /**
   * Check that there are Drupal errors.
   *
   * @Then /^I (?:should )?see ([Dd]rupal )?errors$/
   */
  public function iShouldSeeDrupalErrors() {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $body = $page_element->getText();
    $parse_error = strstr($body, 'Parse error:') === FALSE;
    if (stristr($body, 'Parse error:') !== FALSE) {
      return;
    }
    if ($this->getErrorMessages()) {
      return;
    }
    throw new \Exception('I should see errors.');
  }

  /**
   * Check that there are no Drupal errors.
   *
   * @Then /^I (?:do|should) not see ([Dd]rupal )?errors$/
   */
  public function iShouldNotSeeDrupalErrors() {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    $body = $page_element->getText();
    $parse_error = strstr($body, 'Parse error:') === FALSE;
    if (stristr($body, 'Parse error:') !== FALSE) {
      throw new \Exception('The page has a "' . substr($body, $parse_error, 512) . '".');
    }
    $errors = $this->getErrorMessages();
    if ($errors) {
      throw new \Exception('The page Drupal errors "' . $errors . '".');
    }
  }

  /**
   * Empty mail sent to the current mail account.
   *
   * @Given /^I have no mail$/
   * @Given /^I empty my mail$/
   */
  public function iHaveNoMail() {
    if (!function_exists('imap_open')) {
      return;
    }

    // Open the IMAP mailbox.
    $mail_creds = $this->getMailCreds();
    $mbox = imap_open('{' . $mail_creds['imap'] . '/imap/ssl}INBOX', $mail_creds['user'], $mail_creds['pass']);
    if ($mbox) {
      // Read all of the messages.
      $all = imap_check($mbox);
      if ($all->Nmsgs) {
        foreach (imap_fetch_overview($mbox, "1:$all->Nmsgs") as $msg) {
          if ($msg->to == $mail_creds['email']) {
            imap_delete($mbox, $msg->msgno);
          }
        }
      }

      // Close the mailbox.
      imap_close($mbox);
    }
  }

  /**
   * Check that the mail is received.
   *
   * @Then /^I (?:(?:do|should) )?receive mail (titled )?"(?P<title>[^"]*)"$/
   *
   * @param $title
   *   The email title to look for.
   * @param $affirmative
   *   When set to TRUE, checks for the link.
   *   When set to FALSE, checks that the link does not exist.
   */
  public function iShouldReceiveMail($title, $affirmative = TRUE) {
    if (!function_exists('imap_open')) {
      throw new \Exception('PHP imap not installed.');
    }
    $title = $this->fixStepArgument($title);

    // Open the IMAP mailbox.
    $mail_creds = $this->getMailCreds();
    $mbox = imap_open('{' . $mail_creds['imap'] . '/imap/ssl}INBOX', $mail_creds['user'], $mail_creds['pass']);
    if ($mbox) {
      $wait = 300;
      if (!$affirmative) {
        $wait = 15;
        print "    Waiting $wait seconds for mail to " . $mail_creds['email'] . " (that should never come)\n";
      }
      for ($attempts = 0; $attempts++ < $wait; ) {
        // Read all of the messages.
        $all = imap_check($mbox);
        if ($all->Nmsgs) {
          foreach (imap_fetch_overview($mbox, "1:$all->Nmsgs") as $msg) {
            if ($msg->to == $mail_creds['email'] && $msg->subject == $title) {
              $msg->body['text'] = imap_fetchbody($mbox, $msg->msgno, 1);
              $msg->body['html'] = imap_fetchbody($mbox, $msg->msgno, 2);
              $this->mailMessages[] = $msg;
              imap_delete($mbox, $msg->msgno);
              break 2;
            }
          }
        }

        // Wait a second and try again.
        usleep(1000000);
        if ($affirmative && ($attempts % 60) == 1) {
          print "    Waiting for mail to " . $mail_creds['email'] . "\n";
        }
      }

      // Close the mailbox.
      imap_close($mbox);

      // Throw Exception when the message is not found.
      if ($attempts >= $wait) {
        if ($affirmative) {
          throw new \Exception('Email "' . $title . '" not found.');
        }
      }
      elseif (!$affirmative) {
        throw new \Exception('Email "' . $title . '" received, and should not of been.');
      }
    }
  }

  /**
   * Check that the mail is not received.
   *
   * @Then /^I (?:do|should) not receive mail (titled )?"(?P<title>[^"]*)"$/
   */
  public function iShouldNotReceiveMail($title) {
    $this->iShouldReceiveMail($title, FALSE);
  }

  /**
   * @When /^I click "(?P<link>[^"]*)" in mail(?: (?:titled )?"(?P<title>[^"]*)")?$/
   */
  public function iClickLinkInMail($link, $title = NULL) {
    $link = $this->fixStepArgument($link);
    if ($title) {
      $title = $this->fixStepArgument($title);
    }
    for ($i = 0; $i < 255; ++$i) {
      $hex = substr("0" . sprintf("%X", $i), -2);
      $encoded["=$hex"] = chr($i);
    }
    foreach ($this->mailMessages as $msg) {
      if (!$title || $msg->subject == $title) {
        if (!empty($msg->body['html'])) {
          // Concatenate long lines.
          $html = str_replace(array_keys($encoded), $encoded, preg_replace('/=\r?\n/', '', $msg->body['html']));

          // Look for all anchor tags.
          if (preg_match_all(',<a .*</a>,i', $html, $matches)) {
            // Look for matching link text.
            foreach ($matches[0] as $match) {
              if (strip_tags($match) == $link) {
                if (preg_match('/href="([^"]*)"/', $match, $matches2)) {
                  if (empty($matches2[1])) {
                    throw new \Exception('Email "' . $msg->subject . '" malformed link with the text "' . $link . '".');
                  }
                  $link_url = $matches2[1];
                  $this->getSession()->visit($this->getRawClickURL($link_url));
                  return;
                }
              }
            }
            throw new \Exception('Email "' . $msg->subject . '" does not have a link with the text "' . $link . '".');
          }
        }
        if (!empty($msg->body['text'])) {
          // Look for matching link text.
          $body = str_replace('\n', '', $msg->body['text']);
          if (preg_match_all('/' . str_replace('.', '\.', $link) . '\*\s\[(\w+)\]/', $body, $matches)) {
            // Look for matching link URL.
            $link_number = $matches[1][0];
            if (preg_match_all('/\[' . $link_number . '\]\s+([^\s]+)/', $body, $matches)) {
              $link_url = $matches[1][1];
              $this->getSession()->visit($this->getRawClickURL($link_url));
              return;
            }
          }
          throw new \Exception('Email "' . $msg->subject . '" does not have a link with the text "' . $link . '".');
        }
        throw new \Exception('Email "' . $msg->subject . '" does not have any links.');
      }
    }
    throw new \Exception('Email "' . $title . '" not read yet.');
  }

  /**
   * Fills in form field with specified id|name|label|value.
   *
   * @When /^(?:|I )fill in "(?P<field>[^"]*)" in frame "(?P<frame>[^"]*)" with "(?P<value>(?:[^"]|\\")*)"$/
   * @When /^(?:|I )fill in "(?P<value>[^"]*)" for "(?P<field>[^"]*)" in frame "(?P<frame>(?:[^"]|\\")*)"$/
   */
  public function fillFrameField($frame, $field, $value) {
    $session = $this->getSession();
    $session->switchToIFrame($this->fixStepArgument($frame));
    $session->getPage()->fillField($field, $value);
    $session->switchToIFrame();
  }

  /**
   * @Then /^I (?:do|should) not see the heading "(?P<heading>[^"]*)"$/
   */
  public function iShouldNotSeeTheHeading($heading) {
    $heading = $this->fixStepArgument($heading);
    $element = $this->getSession()->getPage();
    foreach (array('h1', 'h2', 'h3', 'h4', 'h5', 'h6') as $tag) {
      $results = $element->findAll('css', $tag);
      foreach ($results as $result) {
        if ($result->getText() == $heading) {
          throw new \Exception("The heading text '" . $heading . "' was found and was not supposed to be.");
        }
      }
    }
  }

  /**
   * @} End of "defgroup Then conditionals".
   *
   * @defgroup Login functions
   */

  /**
   * @Given /^I am logged out$/
   * @Given /^I logout$/
   * @Given /^I am [Aa]nonymous$/
   */
  public function iLogout() {
    if ($this->loggedIn()) {
      $this->logout();
    }
  }

  /**
   * @Then /^I should be logged out$/
   * @Then /^I should be an [Aa]nonymous user$/
   * @Then /^I should be [Aa]nonymous$/
   */
  public function iShouldBeLoggedOut() {
    $user = $this->whoami();
    if ($user != $this->tag1Parameters['user_account']) {
      throw new \Exception("Logged in as $user, but should be Anonymous.");
    }
  }

  /**
   * Creates and authenticates a user with the given role via Drush.
   *
   * @Given /^I log in as a new "(?P<role>[^"]*)"$/
   * @Given /^I log in as a new "(?P<role>[^"]*)" user$/
   * @Given /^I login as a new "(?P<role>[^"]*)"$/
   * @Given /^I login as a new "(?P<role>[^"]*)" user$/
   */
  public function iLogInAsANewUser($role) {
    // Check if a user with this role is already logged in.
    if ($this->user && isset($this->user->role) && $this->user->role == $role) {
      return TRUE;
    }

    // Create user (and project)
    $user = (object) array(
      'name' => Random::name(8),
      'pass' => Random::name(16),
      'role' => $role,
    );
    $user->mail = "{$user->name}@example.com";

    // Create a new user.
    $this->getDriver()->userCreate($user);

    $this->users[] = $this->user = $user;

    if ($role == 'authenticated user') {
      // Nothing to do.
    }
    else {
      $this->getDriver()->userAddRole($user, $role);
    }

    // Login.
    $this->userLogin();

    // Save the newly logged in username as their email.
    $this->getMailCreds();
    $this->mailCreds['email'] = $this->user->name;
    $this->vars['mail'] = $this->user->name;

    return TRUE;
  }

  /**
   * Authenticates a role with a user and password from configuration.
   *
   * @Given /^I log in as an existing "(?P<role>[^"]*)"$/
   * @Given /^I log in as an existing "(?P<role>[^"]*) user"$/
   * @Given /^I login as an existing "(?P<role>[^"]*)"$/
   * @Given /^I login as an existing "(?P<role>[^"]*) user"$/
   */
  public function iLogInAsAUser($role) {
    $user_password = $this->fetchUserPassword('drupal', $role);
    if ($user_password) {
      $login_info = explode('/', $user_password);
      if ($login_info && count($login_info) == 2) {
        $this->user = (object) array(
          'name' => $login_info[0],
          'pass' => $login_info[1],
          'role' => $role,
        );
        return $this->userLogin();
      }
      throw new \Exception('Role not defined properly in behat.yml, expects username/password.');
    }
    throw new \Exception('Role not found in behat.yml.');
  }

  /**
   * @Then /^I log in as username "(?P<username>[^"]*)" with password "(?P<password>[^"]*)"$/
   *
   * Login with the username and password. This hacks the DrupalExtension login framework
   * to handle the login. It would probably be better to further extend that framework
   * to make this request simpler.
   *
   * @param $username
   * @param $password
   */
  public function iLoginAsUsernameWithPassword($username, $password) {
    // Create a new DrupalExtension login user,
    // for the username, password pair.
    // Note that we do not need to set the role for login.
    $this->user = (object) array(
      'name' => $this->fixStepArgument($username),
      'pass' => $this->fixStepArgument($password),
    );

    // Login using DrupalExtension, trapping any exceptions.
    $e = NULL;
    try {
      $ret = $this->userLogin();
    }
    catch (\Exception $e) {
    }

    // Save the newly logged in username as their email.
    $this->getMailCreds();
    $this->mailCreds['email'] = $this->user->name;
    $this->vars['mail'] = $this->user->name;

    // If DrupalExtension threw an exception,
    // throw it up the function stack now.
    if ($e) {
      throw $e;
    }

    return $ret;
  }

  /**
   * Helper function to login the current user.
   */
  public function userLogin() {
    if (empty($this->user)) {
      throw new \Exception('User must be set before userLogin.');
    }

    $user = $this->whoami();
    if (strtolower($user) == strtolower($this->user->name)) {
      // Already logged in.
      return;
    }

    $page_element = $this->getSession()->getPage();
    if (empty($page_element)) {
      throw new \Exception('Page not found.');
    }
    $user_account_text = $this->tag1Parameters['user_account'];
    if ($user != $user_account_text) {
      // Logout.
      $this->logout();
    }
   $this->getSession()->visit($this->locatePath('/user')); 

    if ($this->isLoginForm()) {
      // If I see this, I'm not logged in at all so log in.
      $this->customLogin();

      // Check that the login was successful.
      if ($this->loggedIn()) {
        // Successfully logged in.
        return;
      }
      throw new \Exception('Not logged in.');
    }
    throw new \Exception('Failed to reach the login page.');
  }

  public function isLoginForm() {
    $page_element = $this->getSession()->getPage();
    $button = $page_element->findButton($this->getDrupalText('log_in'));
    return (bool) $button;
  }

  /**
   * Helper function to logout.
   */
  public function logout() {
    $this->getSession()->visit($this->locatePath('/user/logout'));
  }

  /**
   * Determine if the a user is already logged in.
   */
  public function loggedIn() {
    return empty($this->user) ? $this->whoami() != $this->tag1Parameters['user_account'] : $this->whoami() == $this->user->name;
  }

  /**
   * Overrideable function for customizable login steps.
   *
   * This is generic enough and configurable enough for most sites.
   * But it is also overrideable for those cases where the login page
   * requires a bit more clicking.
   */
  public function customLogin() {
    $page_element = $this->getSession()->getPage();
    $page_element->fillField($this->getDrupalText('username_field'), $this->user->name);
    $page_element->fillField($this->getDrupalText('password_field'), $this->user->pass);
    $submit = $page_element->findButton($this->getDrupalText('log_in'));
    if (!$submit) {
      throw new \Exception('No submit button on "' . $this->getSession()->getCurrentUrl() . '".');
    }
    $submit->click();
  }

  /**
   * Get the current page title.
   *
   * @todo: this depends on theme markup, and should not. However sites which
   * deviate from standard markup can override the method.
   */
  public function getPageTitle() {
    $page_element = $this->getSession()->getPage();
    $title_element = $page_element->findByID('page-title');
    if ($title_element) {
      $page_title = $title_element->getText();
      if ($page_title) {
        return $page_title;
      }
    }
    throw new \Exception('Page title not present on page.');
  }

  /**
   * Helper function to fetch user passwords stored in behat.local.yml.
   *
   * @param string $type
   *   The user type, e.g. drupal or git.
   *
   * @param string $name
   *   The role to fetch the password for.
   *
   * @return string
   *   The matching username/password or FALSE on error.
   */
  public function fetchUserPassword($type, $name) {
    $property_name = $type . '_users';
    try {
      $property = $this->tag1Parameters[$property_name];
      return $property[$name];
    }
    catch (\Exception $e) {
      throw new \Exception("Non-existant user/password for $property_name:$name please check behat.local.yml.");
    }
  }

  /**
   * Helper function returns who the current user is.
   *
   * There is no good solution for this with standard Drupal.
   *
   * The following HTML should be added to the footer:
   *
   * @code
   *   <div class="username"><!--username--></div>
   * @endcode
   *
   * One solution is to do this in template.php:
   *
   * @code
   *   function yourtheme_preprocess_page(&$vars) {
   *     if (user_is_logged_in()) {
   *       $vars['page']['footer']['username'] = array(
   *         '#prefix' => '<div class="username"><!--',
   *         '#markup' => $GLOBALS['user']->name,
   *         '#suffix' => '--></div>',
   *       );
   *     }
   *   }
   * @endcode
   */
  public function whoami() {
    $page_element = $this->getSession()->getPage();
    if (!$page_element) {
      throw new \Exception('Page not found.');
    }
    try {
      $username_element = $page_element->find('xpath', "//div[@class='username']");
      if ($username_element) {
        // Strip name inside HTML comment <!--username-->.
        $username = $username_element->getHtml();
        if ($username) {
          return substr($username, 4, strlen($username) - 7);
        }
      }
    }
    catch (\Exception $e) {
    }
    return $this->tag1Parameters['user_account'];
  }

  /* @} End of "defgroup Login functions".
   *
   * @defgroup Helper functions
   * @{
   */

  /**
   * Return Drupal error messages.
   */
  public function getErrorMessages() {
    $page_element = $this->getSession()->getPage();
    if ($page_element) {
      $message_element = $page_element->find('xpath', "//div[@class='messages error']");
      return $message_element ? $message_element->getText() : NULL;
    }
    return NULL;
  }

  /**
   * Return Drupal status messages.
   */
  public function getStatusMessages() {
    $page_element = $this->getSession()->getPage();
    if ($page_element) {
      $message_element = $page_element->find('xpath', "//div[@class='messages status']");
      return $message_element ? $message_element->getText() : NULL;
    }
    return NULL;
  }

  /**
   * Return a valid mail address.
   */
  protected function getMailCreds() {
    if (empty($this->mailCreds)) {
      $creds = $this->fetchUserPassword('drupal', 'mail');
      if ($creds) {
        // Split the parameter based creds on a / separator.
        $creds_array = explode('/', $creds);
        $creds_array += array(
          2 => 'gmail.com',
          3 => 'imap.gmail.com:993',
        );

        // Create keyed array of mail credentials.
        $this->mailCreds = array(
          'user' => $creds_array[0],
          'pass' => $creds_array[1],
          'host' => $creds_array[2],
          'imap' => $creds_array[3],
        );
        $this->mailCreds['email'] = $this->mailCreds['user'] . '+' . Random::name(8) . '@' . $this->mailCreds['host'];
      }
    }
    return $this->mailCreds;
  }

  /**
   * Return a link by text or class name.
   *
   * @param Element $parent
   *   Element object to search.
   * @param string $link
   *   Link text, CSS class, or CSS Id to look for.
   */
  public function findLink($parent, $link) {
    $element = $parent->findLink($link);
    if (!$element) {
      $element = $parent->find('xpath', "//a[@class='$link']");
      if (!$element) {
        $element = $parent->find('xpath', "//a[@id='$link']");
      }
    }
    if ($element) {
      $this->initVars();
      $this->vars['link-text'] = $element->getText();
    }
    return $element;
  }

  /**
   * Initialize the replaceable arguments.
   */
  private function initVars() {
    if (!isset($this->vars['host'])) {
      $this->vars['host'] = parse_url($this->getSession()->getCurrentUrl(), PHP_URL_HOST);
    }
    if (!isset($this->vars['mail'])) {
      $mail_creds = $this->getMailCreds();
      $this->vars['mail'] = $mail_creds['email'];

      // Remove @host from username.
      $this->vars['username'] = preg_replace(array('/@.*$/'), array(''), $this->vars['mail']);
    }
  }

  /**
   * Splits a URL-encoded query string into an array.
   *
   * @see http://api.drupal.org/api/drupal/includes%21common.inc/function/drupal_get_query_array/7
   */
  private function drupalGetQueryArray($query) {
    $result = array();
    if (!empty($query)) {
      foreach (explode('&', $query) as $param) {
        $param = explode('=', $param);
        $result[$param[0]] = isset($param[1]) ? rawurldecode($param[1]) : '';
      }
    }
    return $result;
  }

  /**
   * Get the raw-click URL from the URL.
   *
   * When the URL is encoded for Mandrill, it keeps track of clicks by passing our URL
   * through their server. In this test environment, let's skip link tracking and get
   * the raw URL.
   */
  private function getRawClickURL($url) {
    $parsed = parse_url($url);
    if (isset($parsed['host']) && $parsed['host'] == 'mandrillapp.com') {
      $query = $this->drupalGetQueryArray($parsed['query']);
      if (!empty($query['url'])) {
        $url = $query['url'];
      }
    }
    return $url;
  }

  /**
   * @} End of "defgroup Helper functions".
   */
};
