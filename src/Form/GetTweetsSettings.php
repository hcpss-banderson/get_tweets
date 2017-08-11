<?php

namespace Drupal\get_tweets\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Abraham\TwitterOAuth\TwitterOAuth;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Build Get Tweets settings form.
 */
class GetTweetsSettings extends ConfigFormBase {

  /**
   * Date formatter.
   *
   * @var \Drupal\core\datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * Class constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, $date_formatter) {
    parent::__construct($config_factory);
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'get_tweets_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['get_tweets.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('get_tweets.settings');

    $form['import'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Import tweets'),
      '#default_value' => $config->get('import'),
    ];

    $form['usernames'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Users for import'),
      '#default_value' => $config->get('usernames'),
      '#description' => $this->t('Enter users through a space.'),
      '#required' => TRUE,
    ];

    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Tweets count'),
      '#default_value' => $config->get('count'),
      '#min' => 1,
      '#max' => 200,
    ];

    $intervals = [604800, 2592000, 7776000, 31536000];
    $form['expire'] = [
      '#type' => 'select',
      '#title' => $this->t('Delete old statuses'),
      '#default_value' => $config->get('expire'),
      '#options' => [0 => $this->t('Never')] + array_map([$this->dateFormatter,'formatInterval'], array_combine($intervals, $intervals)),
    ];

    $form['oauth'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OAuth Settings'),
      '#description' => $this->t('To enable OAuth based access for twitter, you must <a href="@url">register your application</a> with Twitter and add the provided keys here.', ['@url' => 'https://dev.twitter.com/apps/new']),
    ];

    $form['oauth']['callback_url'] = [
      '#type' => 'item',
      '#title' => $this->t('Callback URL'),
      '#markup' => Url::fromUri('base:twitter/oauth', ['absolute' => TRUE])
        ->toString(),
    ];

    $form['oauth']['consumer_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth Consumer key'),
      '#default_value' => $config->get('consumer_key'),
      '#required' => TRUE,
    ];

    $form['oauth']['consumer_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth Consumer secret'),
      '#default_value' => $config->get('consumer_secret'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $form_state->cleanValues()->getValues();
    $users = trim($config['usernames']);
    $users = explode(' ', $users);

    $connection = new TwitterOAuth($config['consumer_key'], $config['consumer_secret']);

    foreach ($users as $user) {
      $user = trim($user);
      if (!$user) {
        $form_state->setErrorByName('usernames', $this->t('Invalid user name.'));
      }
      else {
        $connection->get("statuses/user_timeline", [
          "screen_name" => trim($user),
          "count" => 1,
        ]);
        if (isset($connection->getLastBody()->errors)) {
          $form_state->setErrorByName('usernames', $this->t('Error: "@error" on user: "@user"', [
            '@error' => $connection->getLastBody()->errors[0]->message,
            '@user' => $user,
          ]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->cleanValues()->getValues();

    $values['usernames'] = trim($values['usernames']);
    $values['usernames'] = explode(' ', $values['usernames']);

    $this->config('get_tweets.settings')
      ->setData($values)
      ->save();

    drupal_set_message($this->t('Changes saved.'));
  }

}
