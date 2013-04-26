Getting started
---------------

To get started, in /usr/local/behat create composor.json.

```
composor.json:
{
    "require": {
        "behat/behat": "2.4.*@stable",
        "behat/mink": "*",
        "behat/mink-goutte-driver": "*",
        "behat/mink-sahi-driver": "*",
        "drupal/drupal-extension": "*",
        "tag1consulting/behat-drupalextension": "*"
    },
    "minimum-stability": "dev",
    "config": {
        "bin-dir": "bin/"
    }
}
```

Find and download the current Selenium standalone server from https://code.google.com/p/selenium/downloads/list.
It's probably that the version listed below is no longer the most current.

```
$ curl http://getcomposer.org/installer | php
$ php composer.phar install
$ curl http://selenium.googlecode.com/files/selenium-server-standalone-2.31.0.jar > selenium-server-standalone-2.31.0.jar
```

You will also most likely want the latest version of Firefox.

Start the Selenium server:

```
$ java --jar selenium-server-standalone-2.25.0.jar
```

Sahi
----

If you want to use the Sahi driver:

 * download and install Sahi from http://sourceforge.net/projects/sahi/
 * download and install phantomjs from http://phantomjs.org/
 * copy browser_types.xml to the Sahi install directory, and edit the paths (the current setup is for a Mac).
 * uncomment the line in behat.yml for javascript_session.

The Sahi driver will likely be used in performance tests. However, since it does not support iframes, it can not be used to perform some functional tests.

Start Sahi:

```
$ /usr/local/sahi/userdata/bin/start_sahi.sh
```

behat.local.yml
---------------
In this directory, create a file named behat.local.yml as follow:
```
default:
  context:
    parameters:
      drupal_users:
        subscriber:
          catch/password
        admin:
          douggreen/password
        mail:
          douggreentest/password/gmail.com/imap.gmail.com:993

  extensions:
    Behat\MinkExtension\Extension:
      base_url: http://local.example.com/
```
When using gmail mail accounts, The mail user parameter can be just username/password. However when using other IMAP accounts, you must also specify the host and IMAP url.

Then run behat:

```
$ behat
$ behat --name login
```

Tag1 Context
------------

We use http://drupal.org/project/drupalextension and sub-class the DrupalContext class with Tag1Context. Tag1Context adds the following capabilities:

* Role based login system should be used instead of DrupalContext login.
* Mail handling.
* Actions in Frames.
* Actions in Blocks.
* Replacable arguments.
* Mouseover dropdown links.
* A few Drupal helpers.

For a complete list, please look for @Then, @Given, and @When in features/bootstrap/Tag1Context.php.

Tag1 replacable arguments
-------------------------
Anywhere an argument is given, we support a few replacable arguments
* [host] - the current host being tested
* [mail] - the actual [mail] address is created from behat.local.yml mail parameter by adding a mail alias, this defaults to gmail, see http://support.google.com/mail/bin/answer.py?hl=en&answer=12096
* [random] - a random 8 character string, every time [random] is used in the script, a new random string is generated.
* [random:1], [random:2], - a way to reference back to used random strings, so that you can put a random string on a form, and then check that it appears somewhere later. [random:1] refers to the last random value, [random:2] refers to the second to last random value.
* [link-text] - the text of the previous clicked link

Tag1 Behat enhancements
-----------------------

* A link can be quoted text, a CSS class, or a CSS Id. The default Behat behavior is to only look for text.
* You can click a link without first checking that it exists, the check is implied.

Test writing
------------
Tests are written in https://github.com/cucumber/cucumber/wiki/Gherkin, a simple human readable language,
which hopefully subverts the need for user documentation. But here are a few hints:

1. copy one of the existing tests to a new file in the features/ directory.
2. indentation is important in Gherkin, use 2 spaces, do not change it.
3. the name you put in the "Feature" line is mostly for readability, but can be used to reference the test from the command line. So the shorter the test name, the easier it will be to run the test by itself. It is recommended that you keep this to one or two words rather than the sentence used in the Gherkin example:
```
$ behat --name 'terse descriptive'
```
4. The next three lines ("In order to", "As an", and "I want") are only used for test readability.
5. When writing scenerios, remember:
 * "Given" and "When" statements can perform actions.
 * "Then" statements only check conditions.
 * "And" statements inherit the preceding "Given", "When" or "Then".
 * The above distinction is pretty much just a guideline for better readability, so don't worry too much if you get it wrong.
6. Items in quotes are "replaceable arguments".
7. It's likely we'll need to support additional language (especially for third party integration). These are easy to write, but for now, something that Tag1 should probably continue to do.
8. If you have any problems, ping douggreen on skype or by email douggreen@tag1consulting.com.
