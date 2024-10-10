<?php

/**
 * @file
 * Comment Statistics Custom Block.
 */

namespace Drupal\amara_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Cache\Cache;

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
   * DB connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Database\Connection $database
   *   Database connection.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    /* Get total comments count */
    $query = $this->database->select('comment', 'c');
    $query->addExpression('COUNT(*)', 'total');
    $total_comments = $query->execute()->fetchField();

    /* Get total approved count */
    $query_approved = $this->database->select('comment_field_data', 'cfd');
    $query_approved->condition('cfd.status', 1);
    $query_approved->addExpression('COUNT(*)', 'approved');
    $approved_comments = $query_approved->execute()->fetchField();

    /* Get total pending count */
    $query_pending = $this->database->select('comment_field_data', 'cfd');
    $query_pending->condition('cfd.status', 0);
    $query_pending->addExpression('COUNT(*)', 'pending');
    $pending_comments = $query_pending->execute()->fetchField();

    $build = [
      '#theme' => 'item_list',
      '#items' => [
        $this->t('Total de comentarios: @total', ['@total' => $total_comments]),
        $this->t('Comentarios aprobados: @approved', ['@approved' => $approved_comments]),
        $this->t('Comentarios pendientes: @pending', ['@pending' => $pending_comments]),
      ],
      '#title' => $this->t('Estadísticas de Comentarios'),
      '#cache' => [
        'max-age' => Cache::PERMANENT,
      ],
    ];

    return $build;
  }
}
