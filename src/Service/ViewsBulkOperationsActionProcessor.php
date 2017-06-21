<?php

namespace Drupal\views_bulk_operations\Service;

use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Views;

/**
 * Defines VBO action processor.
 */
class ViewsBulkOperationsActionProcessor {

  protected $entityTypeManager;

  protected $actionManager;

  protected $actionDefinition;

  protected $action;

  protected $entityType;

  protected $entityStorage;

  protected $viewData;

  protected $queue;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ViewsBulkOperationsActionManager $actionManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->actionManager = $actionManager;
  }

  /**
   * Set values.
   *
   * @param array $view_data
   *   Data concerning the view that will be processed.
   */
  public function initialize(array $view_data) {
    if (!isset($view_data['configuration'])) {
      $view_data['configuration'] = [];
    }
    if (!empty($view_data['preconfiguration'])) {
      $view_data['configuration'] += $view_data['preconfiguration'];
    }

    // Initialize action object.
    $this->actionDefinition = $this->actionManager->getDefinition($view_data['action_id']);
    $this->action = $this->actionManager->createInstance($view_data['action_id'], $view_data['configuration']);

    // Set action context.
    $this->setActionContext($view_data);

    // Set-up action processor.
    $this->entityType = $view_data['entity_type'];
    $this->entityStorage = $this->entityTypeManager->getStorage($this->entityType);

    // Set entire view data as object parameter for future reference.
    $this->viewData = $view_data;
  }

  /**
   * Populate entity queue for processing.
   */
  public function populateQueue($list, $data, &$context = []) {
    $this->queue = [];

    // Get the view if entity list is empty
    // or we have to pass rows to the action.
    if (empty($list) || $this->actionDefinition['pass_view']) {
      $view = Views::getView($data['view_id']);
      $view->setDisplay($data['display_id']);
      if (!empty($data['arguments'])) {
        $view->setArguments($data['arguments']);
      }
      if (!empty($data['exposed_input'])) {
        $view->setExposedInput($data['exposed_input']);
      }
      $view->build();
    }

    // Extra processing if this is a batch operation.
    if (!empty($context)) {
      $batch_size = empty($data['batch_size']) ? 10 : $data['batch_size'];
      if (!isset($context['sandbox']['offset'])) {
        $context['sandbox']['offset'] = 0;
      }
      $offset = &$context['sandbox']['offset'];

      if (!isset($context['sandbox']['total'])) {
        if (empty($list)) {
          $context['sandbox']['total'] = $view->query->query()->countQuery()->execute()->fetchField();
        }
        else {
          $context['sandbox']['total'] = count($list);
        }
      }
      if ($this->actionDefinition['pass_context']) {
        $this->action->setContext($context);
      }
    }
    else {
      $offset = 0;
      $batch_size = 0;
    }

    // Get view results if required.
    if (empty($list)) {
      if ($batch_size) {
        $view->query->setLimit($batch_size);
      }
      $view->query->setOffset($offset);
      $view->query->execute($view);
      foreach ($view->result as $delta => $row) {
        $this->queue[] = $this->getEntityTranslation($row);
      }
    }
    else {
      if ($batch_size) {
        $list = array_slice($list, $offset, $batch_size);
      }
      foreach ($list as $item) {
        $this->queue[] = $this->getEntity($item);
      }

      // Get view rows if required.
      if ($this->actionDefinition['pass_view']) {

        // TODO: Include language support here.
        $ids = array();
        foreach ($this->queue as $entity) {
          $id = $entity->id();
          $nids[$id] = $id;
        }

        $base_table = $view->storage->get('base_table');
        $alias = $view->query->tables[$base_table][$base_table]['alias'];
        $view->build_info['query']->condition($alias . '.' . $view->storage->get('base_field'), $nids, 'in');
        $view->query->execute($view);
      }
    }

    if ($batch_size) {
      $offset += $batch_size;
    }

    if ($this->actionDefinition['pass_view']) {
      $this->action->setView($view);
    }

    return count($this->queue);
  }

  /**
   * Set action context if action method exists.
   *
   * @param array $context
   *   The context to be set.
   */
  public function setActionContext(array $context) {
    if (isset($this->action) && method_exists($this->action, 'setContext')) {
      $this->action->setContext($context);
    }
  }

  /**
   * Process result.
   */
  public function process() {
    $result = $this->action->executeMultiple($this->queue);
    if (empty($result)) {
      $count = count($this->queue);
      for ($i = 0; $i < $count; $i++) {
        $output[] = $this->actionDefinition['label'];
      }
    }
    else {
      $output = $result;
    }
    return $output;
  }

  /**
   * Get entity for processing.
   */
  public function getEntity($entity_data) {
    $revision_id = NULL;

    // If there are 3 items, vid will be last.
    if (count($entity_data) === 3) {
      $revision_id = array_pop($entity_data);
    }

    // The first two items will always be langcode and ID.
    $id = array_pop($entity_data);
    $langcode = array_pop($entity_data);

    // Load the entity or a specific revision depending on the given key.
    $entity = $revision_id ? $this->entityStorage->loadRevision($revision_id) : $this->entityStorage->load($id);

    if ($entity instanceof TranslatableInterface) {
      $entity = $entity->getTranslation($langcode);
    }

    return $entity;
  }

  /**
   * Get entity translation from views row.
   */
  public function getEntityTranslation($row) {
    if ($row->_entity->isTranslatable()) {
      $language_field = $this->entityType . '_field_data_langcode';
      if ($row->_entity instanceof TranslatableInterface && isset($row->{$language_field})) {
        return $row->_entity->getTranslation($row->{$language_field});
      }
    }
    return $row->_entity;
  }

}
