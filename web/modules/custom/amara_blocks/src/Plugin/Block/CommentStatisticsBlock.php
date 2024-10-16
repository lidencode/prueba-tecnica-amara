<?php

/**
 * @file
 * Comment Statistics Custom Block.
 */

namespace Drupal\amara_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;

/**
 * Custom block to display comment statistics on the user page.
 * - If the block is displayed on a user's page, it will show the statistics of that user's comments.
 * - If the block is displayed on any other page, it will show the logged-in user's comment statistics.
 * - If the block is displayed on any other page and the user is anonymous, it will display an error message.
 *
 * @Block(
 *   id = "comment_statistics_block",
 *   admin_label = @Translation("Comment Statistics"),
 *   category = @Translation("Amara Blocks")
 * )
 */
class CommentStatisticsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current User.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The user entity service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager,
                              AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['url'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    /* Get the current /user/{uid} page or current user (id) or anonymous (0) */
    $current_route = \Drupal::routeMatch();

    if ($current_route->getRouteName() === 'entity.user.canonical') {
      $uid = $current_route->getParameter('user')->id();
    } else {
      $uid = $this->currentUser->id();
    }

    /* Get comments count */
    $comments = $this->getUserComments($uid);
    $totalCommentsCount = count($comments);

    /* Get comments total words. */
    $totalCommentsWords = $this->getUserCommentsWordCount($uid);

    /* Get the last five comments for the user */
    $comments = $this->getLastFiveComments($uid);
    $totalShowCommentsCount = count($comments);

    /* Get the block lines to show */
    if ($uid == 0) {
      /* If user is anonymous, display a message asking them to log in */
      $login_url = Url::fromRoute('user.login')->toString();

      $lines = [
        Markup::create('<a href="'.$login_url.'">'.$this->t('Identifíquese').'</a> '.$this->t('para mostrar estadísticas de sus comentarios.'))
      ];
    } else {
      /* If user is logged in or we are on a /user page, show the corresponding statistics */
      $lines = [
        'USER ID: '.$uid
      ];

      /* Add the last comments statistics and the last five comments (preview) */
      if ($totalShowCommentsCount) {
        $lines[] = $this->t('Número total de comentarios: @total', ['@total' => $totalCommentsCount]);
        $lines[] = t('Total de palabras en comentarios: @total', ['@total' => $totalCommentsWords]);
        $lines[] = $this->t('Últimos @total comentarios:', ['@total' => $totalShowCommentsCount]);

        foreach ($comments as $comment) {
          $comment_body = $comment->get('comment_body')->value;
          $preview = mb_substr($comment_body, 0, 100) . (mb_strlen($comment_body) > 100 ? '...' : '');

          $nid = $comment->getCommentedEntity()->id();
          $nodeTitle = $comment->getCommentedEntity()->label();
          $nodeUrl = Url::fromRoute('entity.node.canonical', ['node' => $nid])->toString();
          $nodeLink = '<a href="'.$nodeUrl.'">'.$nodeTitle.'</a>';

          $lines[] = Markup::create($preview . ' ' . $nodeLink);
        }
      } else {
        /* This user hasn't comments yet */
        $lines[] = $this->t('Este usuario/a no ha escrito comentarios aún.');
      }
    }

    $build = [
      '#theme' => 'item_list',
      '#items' => $lines,
      '#title' => $this->t('Estadísticas de Comentarios'),
      '#cache' => [
        'max-age' => Cache::PERMANENT,
      ],
    ];

    return $build;
  }

  /**
   * Get the total word count of all comments made by a specific user.
   *
   * @param int $uid
   *   The user ID for which we are calculating the word count.
   *
   * @return int
   *   The total word count.
   */
  protected function getUserCommentsWordCount(int $uid): int {
    /* if $uid is anonymous, then return 0 */
    if ($uid == 0) {
      return 0;
    }

    /* Get comments from $uid user. */
    $comment_ids = $this->getUserComments($uid);

    /* Count words */
    $totalWordsCount = 0;

    if (!empty($comment_ids)) {
      $comments = $this->entityTypeManager->getStorage('comment')->loadMultiple($comment_ids);

      foreach ($comments as $comment) {
        $commentBody = $comment->get('comment_body')->value;
        $wordsCount = str_word_count(strip_tags($commentBody));

        $totalWordsCount += $wordsCount;
      }
    }

    return $totalWordsCount;
  }

  /**
   * Get the user $uid comments or return an empty array.
   *
   * @param int $uid
   *   The comment's owner.
   *
   * @return array
   *   An array with all comment_id from the $uid comments.
   */
  protected function getUserComments(int $uid): array {
    /* if $uid is anonymous, then return empty array */
    if ($uid == 0) {
      return [];
    }

    /* Get comments from $uid user. */
    $query = $this->entityTypeManager->getStorage('comment')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('uid', $uid);
    $comment_ids = $query->execute();

    return $comment_ids;
  }

  /**
   * Get the last five comments of a specific user.
   *
   * @param int $uid
   *   The user ID for which we are retrieving the comments.
   *
   * @return \Drupal\comment\CommentInterface[]
   *   An array of comment entities.
   */
  protected function getLastFiveComments($uid): array {
    /* get the last five $uid user comment_ids */
    $query = $this->entityTypeManager->getStorage('comment')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('uid', $uid);
    $query->condition('status', 1);
    $query->sort('created', 'DESC');
    $query->range(0, 5);
    $comment_ids = $query->execute();

    /* Load comments and return the query result */
    if (!empty($comment_ids)) {
      return $this->entityTypeManager->getStorage('comment')->loadMultiple($comment_ids);
    }

    return [];
  }
}
