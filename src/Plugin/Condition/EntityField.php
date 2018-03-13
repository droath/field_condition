<?php

namespace Drupal\field_condition\Plugin\Condition;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Define an entity field condition plugin.
 *
 * @Condition(
 *   id = "field_condition:entity_field",
 *   label = @Translation("Entity field"),
 *   context = {
 *     "entity" = @ContextDefinition("entity:node", label = @Translation("Entity"))
 *   }
 * )
 */
class EntityField extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundle;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Field widget manager.
   *
   * @var \Drupal\Component\Plugin\PluginManagerInterface
   */
  protected $fieldWidgetManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $configuration,
    $plugin_id,
    $plugin_defintion,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle,
    PluginManagerInterface $field_widget_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_defintion);

    $this->entityTypeBundle = $entity_type_bundle;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fieldWidgetManager = $field_widget_manager;
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
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('plugin.manager.field.widget'),
      $container->get('context.repository'),
      $container->get('ctools.typed_data.resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function summary() {
    $field_condition = $this->configuration['field_condition'];

    return $this->t(
      'The entity field condition is based on Entity type: @entity
        Entity bundle: @bundle and Entity field: @field', [
          '@entity' => $field_condition['entity_type'],
          '@bundle' => $field_condition['entity_bundle'],
          '@field' => $field_condition['entity_field'],
        ]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field_condition' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['field_condition'] = [
      '#type' => 'container',
      '#prefix' => '<div id="entity-field-condition">',
      '#suffix' => '</div>',
      '#tree' => TRUE,
    ];

    // Build conditional drop-down for entity type.
    $configs = [
      'title' => $this->t('Entity type'),
      'options' => $this->getContentEntityTypeOptions(),
      'required' => FALSE,
    ];
    $entity_type = $this->buildConditionalDropdown('entity_type', $configs, $form, $form_state);

    if (isset($entity_type)
      && !empty($entity_type)
      && isset($configs['options'][$entity_type])) {

      // Build conditional drop-down for entity bundle.
      $configs = [
        'title' => $this->t('Entity bundle'),
        'options' => $this->getEntityBundleOptions($entity_type),
        'multiple' => TRUE,
      ];
      $entity_bundle = $this->buildConditionalDropdown('entity_bundle', $configs, $form, $form_state);

      $diff_options = array_diff_key($entity_bundle, $configs['options']);

      if (isset($entity_bundle)
        && !empty($entity_bundle)
        && count($diff_options) === 0) {

        $configs = [
          'title' => $this->t('Entity field'),
          'options' => $this->getEntityFieldOptions($entity_type, $entity_bundle),
        ];
        $entity_field = $this->buildConditionalDropdown('entity_field', $configs, $form, $form_state);

        if (isset($entity_field)
          && !empty($entity_field)
          && isset($configs['options'][$entity_field])) {

          $entity = $this->createDummyEntity($entity_type, reset($entity_bundle));

          if (!$entity->hasField($entity_field)) {
            return [];
          }
          $items = $entity->get($entity_field);
          $field_storage = $items
            ->getFieldDefinition()
            ->getFieldStorageDefinition();

          $form['field_condition']['form_display'] = $this
            ->renderEntityWidgetForm(
              $items,
              $form_state
            );

          if ($field_storage->getCardinality() === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
            $compare_method = $this->getVisibilityValues(['field_condition', 'compare_method'], $form_state);
            $form['field_condition']['compare_method'] = [
              '#type' => 'select',
              '#title' => $this->t('Compare Method'),
              '#description' => $this->t('Determine the method to use when 
              comparing multiple field values.'),
              '#default_value' => $compare_method,
              '#required' => TRUE,
              '#options' => [
                'match_all' => $this->t('Match all'),
                'match_one' => $this->t('Match one')
              ],
            ];
          }
        }
      }
    }

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * Get block plugins condition value.
   *
   * Values are first retrieve from the form state values array; otherwise
   * defaults to the configuration array.
   *
   * @param array $parents
   *   An array of parent keys unique to the form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   An array of the plugins condition configurations.
   */
  protected function getVisibilityValues(array $parents, FormStateInterface $form_state) {
    $state_value = $form_state->getValue(
      array_merge(['visibility', $this->getPluginId()], $parents)
    );

    return isset($state_value)
      ? $state_value
      : NestedArray::getValue($this->configuration, $parents);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state
      ->addCleanValueKey('negate')
      ->cleanValues()
      ->getValues();

    // Process the field condition configurations.
    if (isset($values['field_condition'])
      && !empty($values['field_condition'])) {
      $configuration = &$values['field_condition'];

      if (!empty($configuration['entity_type'])) {

        // Check form display widget items.
        if (isset($configuration['form_display'])
          && $form_state->has('field_item')) {
          $form_display = &$configuration['form_display'];

          $widget_items = &$form_display['widget'];
          $field_item = $form_state->get('field_item');

          // Set field widget items.
          $field_item->setValue($widget_items);

          // Remove empty widget item values from items array.
          foreach ($field_item as $delta => $item) {
            if (isset($widget_items[$delta]) && $item->isEmpty()) {
              unset($widget_items[$delta]);
            }
          }
        }
      }
      else {
        unset($configuration['entity_type']);
      }

      $this->configuration = array_merge($this->configuration, $values);
    }

    return parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $field_condition = $this->configuration['field_condition'];

    if (empty($field_condition) && $this->isNegated()) {
      return FALSE;
    }
    $entity = $this->getContextValue('entity');

    if (!$entity) {
      return FALSE;
    }

    if (!isset($field_condition['entity_type'])
      || $entity->getEntityTypeId() !== $field_condition['entity_type']) {
      return FALSE;
    }

    if (!isset($field_condition['entity_bundle'])) {
      return FALSE;
    }

    // Normalize the entity bundles in case it's comes in as string. Which is
    // needed for backward compatibility.
    $bundles = is_array($field_condition['entity_bundle'])
      ? array_values($field_condition['entity_bundle'])
      : [$field_condition['entity_bundle']];

    if (!in_array($entity->getType(), $bundles)) {
      return FALSE;
    }

    if (!isset($field_condition['entity_field'])
      || !$entity->hasField($field_condition['entity_field'])) {
      return FALSE;
    }

    if (!isset($field_condition['form_display'])) {
      return FALSE;
    }
    $items = $entity->get($field_condition['entity_field']);

    $compare_values = isset($field_condition['form_display']['widget'])
      ? $field_condition['form_display']['widget']
      : [];

    $compare_method = isset($field_condition['compare_method'])
      ? $field_condition['compare_method']
      : 'match_all';

    return $this->compareFieldItemValues($items, $compare_values, $compare_method);
  }

  /**
   * Ajax callback for the entity field elements.
   *
   * @param array $form
   *   A renderable array of the form elements.
   * @param FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   The form element based on the trigger element parents.
   */
  public function ajaxEntityFieldCallback(array $form, FormStateInterface $form_state) {
    $trigger_element = $form_state->getTriggeringElement();
    $element_parents = $trigger_element['#array_parents'];
    array_splice($element_parents, -1);

    return NestedArray::getValue($form, $element_parents);
  }

  /**
   * Compare entity field values with the widget values in the visibility rule.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field_item
   *   The field item object.
   * @param array $widget_values
   *   An array of widget values.
   * @param string $compare_method
   *   Determine how values should be compared (match_all, or match_one).
   *
   * @return bool
   *   Return TRUE if the widget values match entity field values; otherwise
   *   FALSE.
   */
  protected function compareFieldItemValues(FieldItemListInterface $items, array $widget_values, $compare_method) {
    if ($items->isEmpty() && empty($widget_values)) {
      return TRUE;
    }
    $storage = $items
      ->getFieldDefinition()
      ->getFieldStorageDefinition();
    $property_name = $storage->getMainPropertyName();

    $item_values = $items->getValue();
    array_walk($item_values, function(&$value) use ($property_name) {
      $value = trim($value[$property_name]);
    });

    switch ($compare_method) {
      case 'match_all':
        foreach ($widget_values as $value) {
          if (!in_array(trim($value[$property_name]), $item_values)) {
            return FALSE;
          }
        }
        return TRUE;

      case 'match_one':
        foreach ($widget_values as $value) {
          if (in_array(trim($value[$property_name]), $item_values)) {
            return TRUE;
          }
        }
        return FALSE;
    }
  }

  /**
   * Create a dummy entity based on bundle type.
   *
   * @param string $entity_type_id
   *   The entity type identifier.
   * @param string $bundle
   *   The entity bundle on which to render.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Return the entity object.
   */
  protected function createDummyEntity($entity_type_id, $bundle = NULL) {
    $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);
    $bundle_key = $entity_storage->getEntityType()->getKey('bundle');

    return isset($bundle) && !empty($bundle_key)
      ? $entity_storage->create([$bundle_key => $bundle])
      : $entity_storage->create();
  }

  /**
   * Render entity widget form element.
   *
   * @param FieldItemListInterface $items
   *   An field item list object.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return array
   *   A renderable array of the field widget element.
   */
  protected function renderEntityWidgetForm(FieldItemListInterface $items, FormStateInterface $form_state) {
    $field_definition = $items->getFieldDefinition();

    // Set the field widget value based on imputed config value.
    $this->setFieldItemValue($items);

    // Create a custom field widget instance based on the field definition.
    $widget = $this->fieldWidgetManager->getInstance([
      'field_definition' => $field_definition,
      'form_mode' => 'default',
      'prepare' => FALSE,
      'configuration' => [
        'type' => 'hidden',
        'settings' => [],
        'third_party_settings' => [],
      ],
    ]);

    $parent_form = ['#parents' => []];
    $form_state->set('field_item', $items);
    $form = $widget->form($items, $parent_form, $form_state);

    // Remove the parents properties on the field widget element, as it causes
    // problems with capturing the widget value, as we're saving the value
    // independently from the widget.
    unset($form['#parents']);
    unset($form['widget']['#parents']);

    return $form;
  }

  /**
   * Set field widget value based on configuration.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field_item
   *   The field item object.
   */
  protected function setFieldItemValue(FieldItemListInterface $field_item) {
    $field_condition = $this->configuration['field_condition'];
    $display_form = $field_condition['form_display'];

    if (isset($display_form['widget'])) {
      $widget_values = $display_form['widget'];

      if (isset($widget_values) && !empty($widget_values)) {

        $definition = $field_item->getFieldDefinition();

        // Ensure the other entity configurations didn't change.
        if ($definition->getTargetEntityTypeId() === $field_condition['entity_type']
          && $definition->getName() === $field_condition['entity_field']) {
          $field_item->setValue($widget_values);
        }
      }
    }

    return $this;
  }

  /**
   * Build conditional drop-down element.
   *
   * @param string $field_name
   *   A unique field name.
   * @param array $configs
   *   An array of element configurations.
   * @param array &$form
   *   A render array of form elements.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return mixed
   *   The element value.
   * @throws \Exception
   */
  protected function buildConditionalDropdown($field_name, array $configs, array &$form, FormStateInterface $form_state) {
    if (isset($form['field_condition'][$field_name])) {
      throw new \Exception(
        'Conditional form element already exists.'
      );
    }
    $element_value = $this->getVisibilityValues(['field_condition', $field_name], $form_state);

    $element_options = isset($configs['options'])
      ? $configs['options']
      : [];

    $form['field_condition'][$field_name] = [
      '#type' => 'select',
      '#title' => isset($configs['title'])
      ? $configs['title']
      : $this->t('Element @name', [
        '@name' => ucfirst(strstr($field_name, '_', ' ')),
      ]),
      '#options' => $element_options,
      '#empty_option' => $this->t('- None -'),
      '#required' => isset($configs['required']) ? $configs['required'] : TRUE,
      '#default_value' => $element_value,
      '#multiple' => isset($configs['multiple']) ? $configs['multiple'] : FALSE,
      '#ajax' => [
        'method' => 'replace',
        'wrapper' => 'entity-field-condition',
        'callback' => [$this, 'ajaxEntityFieldCallback'],
      ],
    ];

    return $element_value;
  }

  /**
   * Get content entity type.
   *
   * @return array
   *   An array of content entity keyed by type.
   */
  protected function getContentEntityTypes() {
    $types = &drupal_static(__METHOD__, []);

    if (empty($types)) {
      foreach ($this->entityTypeManager->getDefinitions() as $type => $entity) {
        if ($entity->getGroup() !== 'content') {
          continue;
        }

        $types[$type] = $entity;
      }
    }

    return $types;
  }

  /**
   * Get content entity  options.
   *
   * @return array
   *   An array of content entity options.
   */
  protected function getContentEntityTypeOptions() {
    $options = [];

    foreach ($this->getContentEntityTypes() as $type => $entity) {
      // @todo: Expand this to other entity types other than node content.
      if (!$entity instanceof ContentEntityTypeInterface
        || $type !== 'node') {
        continue;
      }

      $options[$type] = $entity->getLabel();
    }

    return $options;
  }

  /**
   * Get entity bundle options.
   *
   * @param string $entity_type
   *   The entity type.
   *
   * @return array
   *   An array of bundles related to the entity type.
   */
  protected function getEntityBundleOptions($entity_type) {
    $options = [];

    foreach ($this->entityTypeBundle->getBundleInfo($entity_type) as $name => $info) {
      if (!isset($info['label'])) {
        continue;
      }
      $options[$name] = $info['label'];
    }

    return $options;
  }

  /**
   * Get entity field options for given bundles.
   *
   * If one entity bundle were given then fields related to that bundle are
   * returned. If multiple bundles are given then only fields that are
   * persistent between the selected bundles will be returned.
   *
   * @param string $entity_type
   *   The entity type.
   * @param array $entity_bundles
   *   An array of entity bundles.
   *
   * @return array
   *   An array of field options related to the entity type and bundles.
   */
  protected function getEntityFieldOptions($entity_type, array $entity_bundles) {
    $groups = $this->getEntityFieldGroupByBundle($entity_type, $entity_bundles);

    return count($groups) === 1
      ? reset($groups)
      : call_user_func_array('array_intersect', $groups);
  }

  /**
   * Get entity field grouped by bundle.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param array $entity_bundles
   *   An array of entity bundles.
   *
   * @return array
   *   An array of entity fields grouped by bundle.
   */
  protected function getEntityFieldGroupByBundle($entity_type, array $entity_bundles) {
    $groups = [];

    foreach ($entity_bundles as $bundle_name) {
      $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle_name);

      foreach ($fields as $field_name => $definition) {
        if (!$definition instanceof DataDefinitionInterface
          && $definition->isComputed()) {
          continue;
        }

        $groups[$bundle_name][$field_name] = $definition->getLabel();
      }
    }

    return $groups;
  }

}
