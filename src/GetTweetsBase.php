<?php

namespace Drupal\get_tweets;

use Abraham\TwitterOAuth\TwitterOAuth;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactory;

/**
 * Class GetTweetsImport.
 */
class GetTweetsBase {

  /**
   * Drupal logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */

  protected $logger;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a GetTweetsBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger
   *   The logger.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager, ConfigFactoryInterface $config_factory, LoggerChannelFactory $logger) {
    $this->entityManager = $entity_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Import tweets.
   */
  public function import() {
    $config = $this->configFactory->get('get_tweets.settings');

    if (!$config->get('import')) {
      return;
    }

    $connection = new TwitterOAuth($config->get('consumer_key'), $config->get('consumer_secret'));

    $count = $config->get('count');
    $storage = $this->entityManager->getStorage('node');

    foreach ($config->get('usernames') as $username) {
      if (strpos($username, '#') == 0) {
        $parameters = [
          "q" => $username,
          "count" => $count,
        ];
        $tweet_type = 'hashtag';
        $endpoint = 'search/tweets';
      }
      else {
        $parameters = [
          "screen_name" => $username,
          "count" => $count,
        ];
        $tweet_type = 'username';
        $endpoint = 'statuses/user_timeline';
      }

      $query = $storage->getAggregateQuery();
      $query->condition('field_tweet_author.title', trim($username, '@'));
      $query->aggregate('field_tweet_id', 'MAX');
      $result = $query->execute();

      if (isset($result[0]['field_tweet_id_max'])) {
        $parameters['since_id'] = $result[0]['field_tweet_id_max'];
      }

      $tweets = $connection->get($endpoint, $parameters);

      if (isset($connection->getLastBody()->errors)) {
        $this->logger('get_tweets')
          ->error($connection->getLastBody()->errors[0]->message);
      }

      // If we're pulling by hashtag, we need to access the statuses.
      if ($endpoint == 'search/tweets') {
        $tweets = $tweets->statuses;
      }

      if ($tweets && empty($tweets->errors)) {
        foreach ($tweets as $tweet) {
          $this->createNode($tweet, $tweet_type, $username);
        }
      }
    }
  }

  /**
   * Creating node.
   *
   * @param \stdClass $tweet
   *   Tweet for import.
   * @param string $tweet_type
   *   Tweet type.
   * @param string $query_name
   *   Query name.
   */
  public function createNode(\stdClass $tweet, $tweet_type = 'username', $query_name = '') {
    $storage = $this->entityManager->getStorage('node');
    $render_tweet = new RenderTweet($tweet);

    /** @var \Drupal\node\NodeInterface $node */
    $node = $storage->create([
      'type' => 'tweet',
      'field_tweet_id' => $tweet->id,
      'field_tweet_author' => [
        'uri' => $tweet_type == 'username' ? 'https://twitter.com/' . $tweet->user->screen_name : 'https://twitter.com/search?q=' . str_replace('#', '%23', $query_name),
        'title' => $tweet_type == 'username' ? $tweet->user->screen_name : $query_name,
      ],
      'title' => 'Tweet #' . $tweet->id,
      'field_tweet_content' => [
        'value' => $render_tweet->build(),
        'format' => 'full_html',
      ],
      'created' => strtotime($tweet->created_at),
      'uid' => '1',
      'status' => 1,
    ]);

    if (isset($tweet->entities->user_mentions)) {
      foreach ($tweet->entities->user_mentions as $user_mention) {
        $node->field_tweet_mentions->appendItem($user_mention->screen_name);
      }
    }

    if (isset($tweet->entities->hashtags)) {
      foreach ($tweet->entities->hashtags as $hashtag) {
        $node->field_tweet_hashtags->appendItem($hashtag->text);
      }
    }

    if (isset($tweet->entities->media)) {
      foreach ($tweet->entities->media as $media) {
        if ($media->type == 'photo') {
          $path_info = pathinfo($media->media_url_https);
          $data = file_get_contents($media->media_url_https);
          $dir = 'public://tweets/';
          if ($data && file_prepare_directory($dir, FILE_CREATE_DIRECTORY)) {
            $file = file_save_data($data, $dir . $path_info['basename'], FILE_EXISTS_RENAME);
            $node->set('field_tweet_local_image', $file);
            $node->set('field_tweet_external_image', $media->media_url);
          }
        }
      }
    }
    $node->save();
  }

  /**
   * Run all tasks.
   */
  public function runAll() {
    $this->import();
    $this->cleanup();
  }

  /**
   * Delete old tweets.
   */
  public function cleanup() {
    $config = $this->configFactory->get('get_tweets.settings');
    $expire = $config->get('expire');

    if (!$expire) {
      return;
    }

    $storage = $this->entityManager->getStorage('node');
    $query = $storage->getQuery();
    $query->condition('created', time() - $expire, '<');
    $query->condition('type', 'tweets');
    $result = $query->execute();
    $nodes = $storage->loadMultiple($result);

    foreach ($nodes as $node) {
      $node->delete();
    }
  }

}
