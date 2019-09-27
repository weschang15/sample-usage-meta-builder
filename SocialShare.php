<?php

namespace App\Features;

use App\Setup\Options;
use App\Helpers\Utils;
use App\Meta\MetaBuilder;
use App\Setup\Environment;

/**
 * SocialShare class
 *
 * Object responsible for containing and handling of social share links that are generating via Bitlink when
 * a user activates social share per blog post. This class is also responsible for rendering metaboxes that are
 * directly related to said social share.
 *
 * @author Wesley Chang
 * @version 2.0.0
 */
class SocialShare
{
  /**
   * Option name for user defined activation status, this controls the front-end visibility of the social share widget.
   * The value of this option defaults to true
   *
   * @var string
   */
  const STATUS = 'social_share_status';

  /**
   * Option name for automatically generated bitlinks.
   * The value of this option returns an associative array
   *
   * @var string
   */
  const SHORTLINKS = 'share_post_bitlinks';

  /**
   * Default short URL used for generating bitly shortlinks.
   * This defaults to production short url for undisclosed.com
   *
   * @var string
   */
  const BITLY_DOMAIN = 'bit.ly';

  /**
   * Default prefix for assets - used in place of actual prefix to remedy NDA
   * 
   * @var string
   */
  const ASSET_PREFIX = 'social-share';

  private $pagenow;

  public function __construct()
  {
    global $pagenow;

    $this->pagenow = $pagenow;
  }

  public function register()
  {
    if (get_current_blog_id() !== 2) {
      return;
    }

    // Action to enqueue assets
    add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    // Action responsible for creating new metabox for records of post_type 'post'
    add_action('add_meta_boxes_post', [$this, 'fields'], 10);
    // Action responsible for determining if the post is published and automatically generates short URLs
    add_action('transition_post_status', [$this, 'generate_short_urls'], 10, 3);
  }

  public function enqueue()
  {
    wp_enqueue_style(self::ASSET_PREFIX . '-admin-social-share-metabox');

    if (
      !Utils::is_plugin_admin_page() &&
      !Utils::is_post_overview($this->pagenow)
    ) {
      wp_enqueue_script(self::ASSET_PREFIX . '-admin-social-share-metabox');
      wp_localize_script(
        self::ASSET_PREFIX . '-admin-social-share-metabox',
        'AppSocialShare',
        [
          'restApi' => esc_url_raw(rest_url()),
          'nonce' => wp_create_nonce('wp_rest'),
          'endpoint' => 'undisclosed/v1/socialshare',
          'postId' => get_the_ID()
        ]
      );
    }
  }

  public function fields(\WP_Post $post = null): void
  {
    if (\is_null($post)) {
      return;
    }

    \add_meta_box(
      'mp_social_share',
      'Social Share',
      [$this, 'render'],
      'post',
      'side'
    );
  }

  public function render(\WP_Post $post = null): void
  {
    if (\is_null($post)) {
      return;
    }

    $hidden = get_post_status($post) !== 'publish';

    $metaBuilder = new MetaBuilder();
    $select = $metaBuilder->select()->setPostId($post->ID);

    $metaStatusDoesntExists = $select->doesNotExist(
      Options::get_prefixed_option_name(self::STATUS)
    );

    $metaStatusValue = $select->meta(
      Options::get_prefixed_option_name(self::STATUS)
    );

    $checked =
      $metaStatusDoesntExists || \absint($metaStatusValue)
        ? 'checked="checked"'
        : '';

    $data = [
      // Current post metadata containing Bitly links if already generated
      'links' => $select->meta(
        Options::get_prefixed_option_name(self::SHORTLINKS)
      ),
      // Boolean to determine if social share is activated on post
      'checked' => $checked,
      // Status of current post used to control display of "Yes, Regenerate" button
      'hidden' => $hidden
    ];

    \load_template('templates/metaboxes/social-share.php', $data);
  }

  /**
   * Trigger API request to generate new Bitly Short URLs when current post has transitioned to "publish" status.
   * This function only runs for post post_types
   *
   * @param string $new - New post status from transition
   * @param string $old - Old post status from transition
   * @param \WP_Post $post - Object of current WP_Post
   * @return void
   */
  public function generate_short_urls(
    string $new = null,
    string $old = null,
    \WP_Post $post = null
  ) {
    $is_post = $post->post_type === 'post';

    if (!$is_post) {
      return;
    }

    $changed = $new !== $old;
    $publish = $new === 'publish' && $old !== 'publish';

    // Post Updates will not change status so we must check to see if the status has actually changed from it's previous
    if (!$changed) {
      return;
    }

    // Check if the status has changed from anything but publish to publish
    if (!$publish) {
      return;
    }

    $postId = absint($post->ID);
    $postTitle = $post->post_title;
    $postUrl = get_permalink($postId);

    // Internal API route request that generates bitlinks concurrently
    $request = new \WP_REST_Request(
      'POST',
      '/undisclosed/v1/socialshare/bitlinks'
    );

    $request->set_body_params([
      'postId' => $postId,
      'postUrl' => $postUrl,
      'postTitle' => $postTitle
    ]);

    $response = rest_do_request($request);
  }

  /**
   * Can't use conditional to set constant var so we use a static helper method instead to get the appropiate short URL
   *
   * @return string domain to use for generating shortlinks
   */
  public static function get_shortlink_base()
  {
    return Environment::is_development() ? 'bit.ly' : self::BITLY_DOMAIN;
  }
}