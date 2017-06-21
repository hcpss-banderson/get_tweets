Get Tweets
======

Provides functionality for import tweets in Drupal node.

Installation
-------------
This module needs to be installed via Composer, which will download the required libraries.

1. Add the Drupal Packagist repository

    ```sh
    composer config repositories.drupal composer https://packages.drupal.org/8
    ```
This allows Composer to find Get Tweets and the other Drupal modules.

2. Download Get Tweets

   ```sh
   composer require "drupal/get_tweets ~1.0"
   ```
This will download the latest release of Get Tweets.
Use 1.x-dev instead of ~1.0 to get the -dev release instead.

3. Config your credential for Twitter application.


See https://www.drupal.org/node/2404989 for more information.
