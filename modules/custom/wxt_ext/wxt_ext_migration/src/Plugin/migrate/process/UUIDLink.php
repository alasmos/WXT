<?php

namespace Drupal\wxt_ext_migration\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrateProcessInterface;
use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Core\Database\Database;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Convert a Drupal 7 UUID Link to a Drupal 8 UUID Link.
 *
 * @MigrateProcessPlugin(
 *   id = "wxt_uuid_link",
 * )
 */
class UUIDLink extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The migration process plugin, configured for lookups in od_ext_db_file.
   *
   * @var \Drupal\migrate\Plugin\MigrateProcessInterface
   */
  protected $migrationPlugin;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, MigrateProcessInterface $migration_plugin) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->migrationPlugin = $migration_plugin;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    // Default required migration configuration.
    $migration_configuration = [
      'migration' => [
        'wxt_ext_db_file',
      ],
    ];

    // Handle any custom migrations leveraging this plugin.
    $migration_dependencies = $migration->getMigrationDependencies();
    if (isset($migration_dependencies['required'])) {
      foreach ($migration_dependencies['required'] as $dependency) {
        if (strpos($dependency, 'node') !== FALSE) {
          $migration_configuration['migration'][] = $dependency;
        }
      }
    }

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.migrate.process')->createInstance('migration', $migration_configuration, $migration)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (!$value) {
      throw new MigrateSkipProcessException();
    }
    $value = ' ' . $value . ' ';
    $value = preg_replace_callback(
      '/\[uuid-link:(.*?)\]/s',
      function ($match) use ($migrate_executable, $row, $destination_property) {
        return $this->replaceToken($match, $migrate_executable, $row, $destination_property);
      },
      $value
    );

    return $value;
  }

  /**
   * Replace callback to convert a Drupal 7 UUID link into a Drupal 8 UUID Link.
   *
   * @param string $match
   *   Takes a match of tag code
   * @param \Drupal\migrate\MigrateExecutableInterface $migrate_executable
   *   The migrate executable helper class.
   * @param \Drupal\migrate\Row $row
   *   The current row after processing.
   * @param string $destination_property
   *   The destination propery.
   */
  private function replaceToken($match, $migrate_executable, $row, $destination_property) {
    $match = str_replace("[", "", $match);
    $match = str_replace("]", "", $match);
    $match = substr($match[0], 15);
    $uuid = $match;
    $output = '';

    try {
      if (!is_string($uuid)) {
        throw new MigrateException('Unable to grab UUID Link');
      }

      Database::setActiveConnection('source_migration');
      $db = Database::getConnection();
      $query = $db->select('node', 'n')
        ->fields('n')
        ->condition('n.uuid', $uuid);
      $nid = $query->execute()->fetchCol();
      Database::setActiveConnection();

      // Lookup the new Node.
      $nid = $this->migrationPlugin
        ->transform($nid[0], $migrate_executable, $row, $destination_property);

      $output = '/node/' . $nid;
    }
    catch (Exception $e) {
      $msg = t('Unable to render link from %link. Error: %error', ['%link' => $uuid, '%error' => $e->getMessage()]);
      \Drupal::logger('Migration')->error($msg);
      return '';
    }
    return $output;
  }

}
